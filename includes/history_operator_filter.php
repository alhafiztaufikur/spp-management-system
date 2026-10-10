<?php
require_once __DIR__.'/payment_activity.php';
require_once __DIR__.'/filter_choices.php';

/** Include current accounts and immutable historical identities within the visible units. */
function history_operator_options(mysqli $db): array
{
    $scope = unit_active_id();
    $where = $scope === 0 ? "role IN ('super_admin','admin','kasir','bendahara')" : "(unit_id=".(int)$scope." OR role='super_admin')";
    $actors = [];
    foreach ($db->query('SELECT id,nama,username FROM admin WHERE '.$where)->fetch_all(MYSQLI_ASSOC) as $actor) {
        $actors[(string)$actor['id']] = $actor['nama'].(!empty($actor['username']) ? ' (@'.$actor['username'].')' : '');
    }
    if (payment_activity_ready($db)) {
        $rows = $db->query("SELECT a.actor_id,a.actor_name,a.actor_username FROM pembayaran_aktivitas a
            WHERE a.action='created' AND a.actor_id>0
            AND NOT EXISTS(SELECT 1 FROM pembayaran_aktivitas earlier WHERE earlier.unit_id=a.unit_id
                AND earlier.payment_id=a.payment_id AND earlier.action='created'
                AND (earlier.occurred_at<a.occurred_at OR (earlier.occurred_at=a.occurred_at AND earlier.id<a.id)))
            ORDER BY a.occurred_at DESC,a.id DESC")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $actor) {
            $actors[(string)$actor['actor_id']] ??= ($actor['actor_name'] ?: 'Operator #'.$actor['actor_id'])
                .(!empty($actor['actor_username']) ? ' (@'.$actor['actor_username'].')' : '');
        }
    }
    natcasesort($actors);
    $actors['unknown'] = 'Tidak tercatat';
    return $actors;
}

function history_operator_choices($input, array $options): array
{
    if ($input !== null && !is_array($input) && !is_scalar($input)) throw new InvalidArgumentException('Pilihan operator tidak valid.');
    if (is_array($input)) {
        if (!$input || count($input)>10000) throw new InvalidArgumentException('Pilih minimal satu operator.');
        foreach ($input as $value) {
            if (!is_scalar($value) || ((string)$value!=='*' && !array_key_exists((string)$value,$options))) throw new InvalidArgumentException('Operator tidak tersedia pada unit ini.');
        }
        if (in_array('*',$input,true) && count(array_unique($input))!==1) throw new InvalidArgumentException('Semua operator tidak dapat digabung dengan pilihan lain.');
    }
    $values = filter_register('operator', $input, $options);
    foreach ($values as $value) {
        if ($value !== '*' && !array_key_exists($value, $options)) throw new InvalidArgumentException('Operator tidak tersedia pada unit ini.');
    }
    return $values;
}

/** Match payment_activity_summary's first creation event, never bayar.user_id. */
function history_operator_owner_sql(mysqli $db, string $payment, string $unit): string
{
    if (!payment_activity_ready($db)) return '0';
    return "COALESCE((SELECT creator.actor_id FROM pembayaran_aktivitas creator
        WHERE creator.payment_id=$payment AND creator.unit_id=$unit AND creator.action='created'
        ORDER BY creator.occurred_at,creator.id LIMIT 1),0)";
}

function history_operator_where(mysqli $db, array $operators, string $payment, string $unit): string
{
    if (filter_is_all($operators)) return '';
    $ids = [];
    foreach ($operators as $operator) {
        if ($operator !== 'unknown' && !preg_match('/^[1-9][0-9]*$/D', (string)$operator)) throw new InvalidArgumentException('Pilihan operator tidak valid.');
        $ids[] = $operator === 'unknown' ? 0 : (int)$operator;
    }
    if (!$ids) throw new InvalidArgumentException('Pilih minimal satu operator.');
    return ' AND '.history_operator_owner_sql($db, $payment, $unit).' IN ('.implode(',', $ids).')';
}

function history_operator_field(array $options, string $id): void
{
    ?>
    <div class="field-row history-operator-field"><label class="field-label" for="<?= htmlspecialchars($id,ENT_QUOTES,'UTF-8') ?>">Operator</label>
    <select id="<?= htmlspecialchars($id,ENT_QUOTES,'UTF-8') ?>" name="operator" class="field-input field-select" data-filter-multiple data-select-search="true">
        <option value="">Semua operator</option>
        <?php foreach ($options as $value=>$label): ?><option value="<?= htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8') ?>"><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?>
    </select></div>
    <?php
}
