"""Mutating HTTP acceptance: only the restored performance instance on port 58250."""
import concurrent.futures
from decimal import Decimal
import html
import json
from pathlib import Path
import re
import sys
import time
import urllib.parse
import urllib.request

root = Path(sys.argv[1]).resolve()
assert "history_performance_" in str(root)
config = json.loads((root / "http.json").read_text())
base = "http://127.0.0.1:58250/"
results = []


def request(route, values=None, user=0, prefix=""):
    started = time.perf_counter()
    data = urllib.parse.urlencode(values).encode() if values is not None else None
    req = urllib.request.Request(base + prefix + route, data=data,
                                 headers={"Cookie": "PHPSESSID=" + config["sessions"][1][user]})
    with urllib.request.urlopen(req, timeout=180) as response:
        body = response.read()
        url = response.url
    assert not any(value in body for value in [b"Fatal error", b"Warning:", b"Uncaught"]), body[-2000:]
    return body, url, round((time.perf_counter() - started) * 1000)


def form(identity, kind="masuk", user=0, prefix=""):
    body, _, _ = request("tabungan/" + kind + ".php?" + urllib.parse.urlencode(identity), user=user, prefix=prefix)
    for attrs, content in re.findall(r"<form\b([^>]*)>(.*?)</form>", body.decode(), re.S | re.I):
        if 'method="post"' not in attrs or 'action="proses.php"' not in attrs:
            continue
        values = {}
        for tag in re.findall(r"<input\b([^>]*)>", content, re.S | re.I):
            fields = {name: html.unescape(value) for name, value in re.findall(r'([\w_-]+)="([^"]*)"', tag)}
            if "name" in fields:
                values[fields["name"]] = fields.get("value", "")
        assert values["_operational_unit"] == "1"
        values["keterangan"] = "Isolated performance acceptance"
        return values
    raise AssertionError("Savings form missing")


def balance(identity):
    data, _, _ = request("tabungan/get_saldo.php?" + urllib.parse.urlencode(identity))
    # The existing native ledger stores DOUBLE. Compare authoritative cents,
    # not the binary JSON representation (for example 1.8199999999999998).
    return Decimal(str(json.loads(data)["raw_saldo"])).quantize(Decimal("0.01"))


def save(identity, amount, kind="masuk", user=0, prefix="", values=None):
    values = values or form(identity, kind, user, prefix)
    values["nominal"] = str(amount)
    body, url, elapsed = request("tabungan/proses.php", values, user, prefix)
    success = "berhasil disimpan" in body.decode()
    return {"success": success, "ms": elapsed, "url": url}, values


owners = json.loads(request("tabungan/pemilik.php?format=json&owner_type=account")[0])["rows"]
owners = [{"owner_id": int(row["owner_id"])} for row in owners if row["state"] == "ready"]
students = json.loads(request("tabungan/pemilik.php?format=json&owner_type=student")[0])["rows"]
student = {"student_id": int(next(row for row in students if row["state"] == "ready")["student_id"])}
assert len(owners) >= 5

for identity in [owners[0], student]:
    for prefix in ["before/", ""]:
        before = balance(identity)
        result, values = save(identity, Decimal("0.13"), prefix=prefix)
        assert result["success"] and balance(identity) == before + Decimal("0.13"), result
        if not prefix:
            assert "after_save=1" in result["url"]
        replay, _ = save(identity, Decimal("0.13"), prefix=prefix, values=values)
        assert balance(identity) == before + Decimal("0.13")
        results.append({"identity": identity, "prefix": prefix, "save_and_detail": result, "retry_once": True})
        print(results[-1], flush=True)
    invalid = form(identity)
    invalid["csrf_token"] = "invalid"
    before = balance(identity)
    save(identity, Decimal("1"), values=invalid)
    assert balance(identity) == before
    result, _ = save(identity, before + Decimal("1"), kind="keluar")
    assert not result["success"] and balance(identity) == before
    results.append({"identity": identity, "csrf_and_insufficient_balance": True})

for targets, label in [([owners[0]] * 5, "same_account"), (owners[:5], "different_accounts"), ([student] * 5, "student_account")]:
    initial = {tuple(target.items()): balance(target) for target in targets}
    forms = [form(target, user=index) for index, target in enumerate(targets)]
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        parallel = list(pool.map(lambda index: save(targets[index], Decimal("0.13"), user=index, values=forms[index])[0], range(5)))
    assert all(result["success"] for result in parallel), parallel
    for key, before in initial.items():
        count = sum(tuple(target.items()) == key for target in targets)
        assert balance(dict(key)) == before + count * Decimal("0.13")
    results.append({"concurrency": label, "five_users_ms": [result["ms"] for result in parallel], "exact_balance": True})
    print(results[-1], flush=True)

# Competing withdrawals cannot overdraw one account.
identity = owners[0]
before = balance(identity)
amount = (before * Decimal("0.60")).quantize(Decimal("0.01"))
forms = [form(identity, "keluar", index) for index in range(5)]
with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
    parallel = list(pool.map(lambda index: save(identity, amount, "keluar", index, values=forms[index])[0], range(5)))
accepted = sum(result["success"] for result in parallel)
assert accepted == 1 and balance(identity) == before - amount
results.append({"competing_withdrawals": True, "accepted": accepted, "no_negative_balance": True})

(root / "savings-http.json").write_text(json.dumps({"results": results}, indent=2))
print("PASS", len(results), "savings HTTP/concurrency scenarios")
