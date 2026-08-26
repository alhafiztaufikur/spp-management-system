[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$BaseUrl,
    [Parameter(Mandatory = $true)]
    [string]$AdminUsername,
    [Parameter(Mandatory = $true)]
    [string]$BendaharaUsername,
    [Parameter(Mandatory = $true)]
    [string]$KasirUsername,
    [Parameter(Mandatory = $true)]
    [string]$Password,
    [Parameter(Mandatory = $true)]
    [string]$ProbeNis,
    [Parameter(Mandatory = $true)]
    [int]$ProbePaymentId,
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-f0-9]{32}$')]
    [string]$ProbeBatchToken,
    [Parameter(Mandatory = $true)]
    [string]$EvidenceCsv
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

Add-Type -AssemblyName System.Net.Http

$baseUri = [Uri]($BaseUrl.TrimEnd('/') + '/')
if ($baseUri.Scheme -ne 'http' -or $baseUri.Host -notin @('127.0.0.1', 'localhost')) {
    throw 'Route-role HTTP matrix only accepts a local HTTP server.'
}
if ([string]::IsNullOrWhiteSpace($Password)) {
    throw 'Temporary test password is required.'
}

$evidenceDirectory = Split-Path -Parent $EvidenceCsv
if (-not [string]::IsNullOrWhiteSpace($evidenceDirectory)) {
    New-Item -ItemType Directory -Path $evidenceDirectory -Force | Out-Null
}

function New-UatSession {
    $handler = [System.Net.Http.HttpClientHandler]::new()
    $handler.AllowAutoRedirect = $false
    $handler.UseCookies = $true
    $handler.CookieContainer = [System.Net.CookieContainer]::new()
    $client = [System.Net.Http.HttpClient]::new($handler)
    $client.Timeout = [TimeSpan]::FromSeconds(20)
    return [pscustomobject]@{
        Handler = $handler
        Client = $client
    }
}

function New-FormContent([hashtable]$Fields) {
    $parts = foreach ($entry in $Fields.GetEnumerator()) {
        $name = [Uri]::EscapeDataString([string]$entry.Key)
        $value = [Uri]::EscapeDataString([string]$entry.Value)
        "$name=$value"
    }
    return [System.Net.Http.StringContent]::new(
        ($parts -join '&'),
        [Text.Encoding]::UTF8,
        'application/x-www-form-urlencoded'
    )
}

function Invoke-UatRequest {
    param(
        [Parameter(Mandatory = $true)]$Session,
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Path,
        [hashtable]$Form = $null,
        [string]$ContentType = ''
    )

    $requestUri = [Uri]::new($baseUri, $Path.TrimStart('/'))
    $request = [System.Net.Http.HttpRequestMessage]::new(
        [System.Net.Http.HttpMethod]::new($Method.ToUpperInvariant()),
        $requestUri
    )
    if ($null -ne $Form) {
        $request.Content = New-FormContent $Form
    } elseif (-not [string]::IsNullOrWhiteSpace($ContentType)) {
        $request.Content = [System.Net.Http.StringContent]::new('{}', [Text.Encoding]::UTF8, $ContentType)
    }

    $response = $Session.Client.SendAsync($request).GetAwaiter().GetResult()
    try {
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $headers = @{}
        foreach ($header in $response.Headers) {
            $headers[$header.Key.ToLowerInvariant()] = ($header.Value -join ', ')
        }
        foreach ($header in $response.Content.Headers) {
            $headers[$header.Key.ToLowerInvariant()] = ($header.Value -join ', ')
        }
        $location = ''
        if ($null -ne $response.Headers.Location) {
            $location = [Uri]::new($requestUri, $response.Headers.Location).AbsolutePath
        }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            Body = $body
            Headers = $headers
            Location = $location
            Uri = $requestUri.AbsoluteUri
        }
    } finally {
        $response.Dispose()
        $request.Dispose()
    }
}

function Get-CsrfToken([string]$Html, [string]$Context) {
    $match = [regex]::Match($Html, 'name=["'']csrf_token["''][^>]*value=["'']([^"'']+)["'']', 'IgnoreCase')
    if (-not $match.Success) {
        throw "CSRF token was not found in $Context."
    }
    return [Net.WebUtility]::HtmlDecode($match.Groups[1].Value)
}

$checks = [System.Collections.Generic.List[object]]::new()
function Add-Check {
    param(
        [string]$Id,
        [string]$Role,
        [string]$Method,
        [string]$Path,
        [int]$Status,
        [string]$Location,
        [bool]$Passed,
        [string]$Evidence
    )
    $checks.Add([pscustomobject]@{
        id = $Id
        role = $Role
        method = $Method
        path = $Path
        status = $Status
        location = $Location
        result = if ($Passed) { 'PASS' } else { 'FAIL' }
        evidence = $Evidence
    })
}

function Assert-Status {
    param(
        [string]$Id,
        [string]$Role,
        [string]$Method,
        [string]$Path,
        $Response,
        [int[]]$Expected,
        [string]$Evidence = ''
    )
    $passed = $Response.Status -in $Expected
    Add-Check $Id $Role $Method $Path $Response.Status $Response.Location $passed ($Evidence + " expected=$($Expected -join ',')")
}

function Assert-Redirect {
    param(
        [string]$Id,
        [string]$Role,
        [string]$Method,
        [string]$Path,
        $Response,
        [string]$ExpectedPath
    )
    $passed = $Response.Status -eq 302 -and $Response.Location -eq $ExpectedPath
    Add-Check $Id $Role $Method $Path $Response.Status $Response.Location $passed "expected redirect=$ExpectedPath"
}

function Assert-SecurityHeaders {
    param([string]$Id, [string]$Role, [string]$Path, $Response)
    $required = @(
        'x-request-id',
        'x-content-type-options',
        'x-frame-options',
        'referrer-policy',
        'permissions-policy',
        'cross-origin-opener-policy',
        'content-security-policy',
        'cache-control'
    )
    $missing = @($required | Where-Object { -not $Response.Headers.ContainsKey($_) })
    Add-Check $Id $Role 'GET' $Path $Response.Status $Response.Location ($missing.Count -eq 0) "missing=$($missing -join ',')"
}

function Login-UatRole {
    param([string]$Role, [string]$Username, [string]$ExpectedPath)
    $session = New-UatSession
    $get = Invoke-UatRequest -Session $session -Method GET -Path '/login.php'
    Assert-Status "LOGIN-$Role-GET" $Role 'GET' '/login.php' $get @(200)
    Assert-SecurityHeaders "LOGIN-$Role-HEADERS" $Role '/login.php' $get
    $token = Get-CsrfToken $get.Body "login form for $Role"
    $post = Invoke-UatRequest -Session $session -Method POST -Path '/login.php' -Form @{
        username = $Username
        password = $Password
        csrf_token = $token
    }
    Assert-Redirect "LOGIN-$Role-POST" $Role 'POST' '/login.php' $post $ExpectedPath
    return $session
}

$routeDefinitions = @(
    [pscustomobject]@{ Path = '/dashboard.php'; Allowed = @('admin', 'bendahara'); Kind = 'html' },
    [pscustomobject]@{ Path = '/role_management.php'; Allowed = @('admin'); Kind = 'html' },
    [pscustomobject]@{ Path = '/master_kelas.php'; Allowed = @('admin'); Kind = 'html' },
    [pscustomobject]@{ Path = '/master_biaya_lain.php'; Allowed = @('admin'); Kind = 'html' },
    [pscustomobject]@{ Path = '/master_daftar_ulang.php'; Allowed = @('admin'); Kind = 'html' },
    [pscustomobject]@{ Path = '/siswa/daftar.php'; Allowed = @('admin'); Kind = 'html' },
    [pscustomobject]@{ Path = '/pembayaran/form.php'; Allowed = @('admin', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = '/pembayaran/lihat.php'; Allowed = @('admin', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = "/pembayaran/edit.php?id=$ProbePaymentId"; Allowed = @('admin', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = '/pembayaran/proses.php'; Allowed = @('admin', 'kasir'); Kind = 'post-only' },
    [pscustomobject]@{ Path = '/pembayaran/riwayat_daftar_ulang.php'; Allowed = @('admin', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = "/tabungan/masuk.php?nis=$([Uri]::EscapeDataString($ProbeNis))"; Allowed = @('admin', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = "/tabungan/keluar.php?nis=$([Uri]::EscapeDataString($ProbeNis))"; Allowed = @('admin', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = '/tabungan/proses.php'; Allowed = @('admin', 'kasir'); Kind = 'post-only' },
    [pscustomobject]@{ Path = '/tabungan/riwayat.php'; Allowed = @('admin', 'bendahara', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = "/tabungan/get_saldo.php?nis=$([Uri]::EscapeDataString($ProbeNis))"; Allowed = @('admin', 'kasir'); Kind = 'json' },
    [pscustomobject]@{ Path = '/laporan/index.php'; Allowed = @('admin', 'bendahara'); Kind = 'html' },
    [pscustomobject]@{ Path = '/laporan/global.php'; Allowed = @('admin', 'bendahara', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = '/laporan/template.php?template=status'; Allowed = @('admin', 'bendahara', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = '/laporan/export_global.php?template=status&format=print'; Allowed = @('admin', 'bendahara', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = '/laporan/rekap_kelas.php'; Allowed = @('admin', 'bendahara'); Kind = 'html' },
    [pscustomobject]@{ Path = "/laporan/detail_siswa.php?nis=$([Uri]::EscapeDataString($ProbeNis))"; Allowed = @('admin', 'bendahara'); Kind = 'html' },
    [pscustomobject]@{ Path = "/laporan/cetak_struk.php?id=$ProbePaymentId"; Allowed = @('admin', 'bendahara', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = "/laporan/cetak_struk_tahunan.php?batch=$ProbeBatchToken"; Allowed = @('admin', 'bendahara', 'kasir'); Kind = 'html' },
    [pscustomobject]@{ Path = '/laporan/export_excel.php?bulan=8&tahun=2026'; Allowed = @('admin', 'bendahara'); Kind = 'html' },
    [pscustomobject]@{ Path = '/laporan/export_pdf.php?bulan=8&tahun=2026'; Allowed = @('admin', 'bendahara'); Kind = 'binary-or-redirect' }
)

$anonymous = New-UatSession
$loginGet = Invoke-UatRequest -Session $anonymous -Method GET -Path '/login.php'
Assert-Status 'ANON-LOGIN-GET' 'anonymous' 'GET' '/login.php' $loginGet @(200)
Assert-SecurityHeaders 'ANON-LOGIN-HEADERS' 'anonymous' '/login.php' $loginGet
$badLogin = Invoke-UatRequest -Session $anonymous -Method POST -Path '/login.php' -Form @{
    username = $AdminUsername
    password = $Password
    csrf_token = 'invalid-cross-session-token'
}
Assert-Status 'ANON-LOGIN-BAD-CSRF' 'anonymous' 'POST' '/login.php' $badLogin @(403)

foreach ($route in $routeDefinitions) {
    $response = Invoke-UatRequest -Session $anonymous -Method GET -Path $route.Path
    if ($route.Kind -eq 'json') {
        Assert-Status "ANON-$($route.Path)-GET" 'anonymous' 'GET' $route.Path $response @(401)
    } else {
        Assert-Redirect "ANON-$($route.Path)-GET" 'anonymous' 'GET' $route.Path $response '/login.php'
    }
}

$invalidCookie = New-UatSession
$invalidCookie.Handler.CookieContainer.Add($baseUri, [Net.Cookie]::new('PHPSESSID', 'invalid-session-id-0123456789', '/'))
$invalidResponse = Invoke-UatRequest -Session $invalidCookie -Method GET -Path '/dashboard.php'
Assert-Redirect 'INVALID-COOKIE-DASHBOARD' 'invalid-cookie' 'GET' '/dashboard.php' $invalidResponse '/login.php'

$sessions = @{
    admin = Login-UatRole 'admin' $AdminUsername '/dashboard.php'
    bendahara = Login-UatRole 'bendahara' $BendaharaUsername '/laporan/index.php'
    kasir = Login-UatRole 'kasir' $KasirUsername '/tabungan/masuk.php'
}
$deniedHomes = @{
    bendahara = '/laporan/index.php'
    kasir = '/tabungan/masuk.php'
}

foreach ($role in @('admin', 'bendahara', 'kasir')) {
    $session = $sessions[$role]
    $loginWhileAuthenticated = Invoke-UatRequest -Session $session -Method GET -Path '/login.php'
    $expectedLoginRedirect = if ($role -eq 'admin') { '/dashboard.php' } elseif ($role -eq 'bendahara') { '/laporan/index.php' } else { '/tabungan/masuk.php' }
    Assert-Redirect "AUTH-$role-LOGIN" $role 'GET' '/login.php' $loginWhileAuthenticated $expectedLoginRedirect

    foreach ($route in $routeDefinitions) {
        $response = Invoke-UatRequest -Session $session -Method GET -Path $route.Path
        if ($role -in $route.Allowed) {
            if ($route.Kind -eq 'post-only') {
                Assert-Status "ALLOW-$role-$($route.Path)-GET" $role 'GET' $route.Path $response @(405)
            } elseif ($route.Kind -eq 'json') {
                $jsonValid = $false
                try {
                    $json = $response.Body | ConvertFrom-Json
                    $jsonValid = $null -ne $json.saldo
                } catch {
                    $jsonValid = $false
                }
                Add-Check "ALLOW-$role-$($route.Path)-GET" $role 'GET' $route.Path $response.Status $response.Location ($response.Status -eq 200 -and $jsonValid) 'expected JSON saldo'
            } elseif ($route.Kind -eq 'binary-or-redirect') {
                Assert-Status "ALLOW-$role-$($route.Path)-GET" $role 'GET' $route.Path $response @(200, 302) 'empty periods may redirect'
            } else {
                Assert-Status "ALLOW-$role-$($route.Path)-GET" $role 'GET' $route.Path $response @(200)
            }
        } else {
            if ($route.Kind -eq 'json') {
                Assert-Status "DENY-$role-$($route.Path)-GET" $role 'GET' $route.Path $response @(403)
            } else {
                Assert-Redirect "DENY-$role-$($route.Path)-GET" $role 'GET' $route.Path $response $deniedHomes[$role]
            }
        }
    }
}

foreach ($role in @('admin', 'kasir')) {
    foreach ($path in @('/pembayaran/proses.php', '/tabungan/proses.php')) {
        foreach ($method in @('GET', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'DELETE')) {
            $contentType = if ($method -in @('PUT', 'PATCH', 'DELETE')) { 'application/json' } else { '' }
            $response = Invoke-UatRequest -Session $sessions[$role] -Method $method -Path $path -ContentType $contentType
            Assert-Status "METHOD-$role-$path-$method" $role $method $path $response @(405)
        }
        $noCsrf = Invoke-UatRequest -Session $sessions[$role] -Method POST -Path $path -Form @{ aksi = 'invalid' }
        Assert-Status "CSRF-$role-$path-MISSING" $role 'POST' $path $noCsrf @(403)
        $wrongCsrf = Invoke-UatRequest -Session $sessions[$role] -Method POST -Path $path -Form @{ aksi = 'invalid'; csrf_token = 'wrong-token' }
        Assert-Status "CSRF-$role-$path-WRONG" $role 'POST' $path $wrongCsrf @(403)
    }
}

foreach ($path in @('/role_management.php', '/master_kelas.php', '/master_biaya_lain.php', '/master_daftar_ulang.php', '/siswa/daftar.php')) {
    $response = Invoke-UatRequest -Session $sessions.admin -Method POST -Path $path -Form @{ aksi = 'tambah' }
    Assert-Redirect "CSRF-admin-$path-MISSING" 'admin' 'POST' $path $response $path
}

$balancePost = Invoke-UatRequest -Session $sessions.admin -Method POST -Path '/tabungan/get_saldo.php' -Form @{ nis = $ProbeNis }
Assert-Status 'METHOD-admin-get-saldo-POST' 'admin' 'POST' '/tabungan/get_saldo.php' $balancePost @(405)

$paymentFormAdmin = Invoke-UatRequest -Session $sessions.admin -Method GET -Path '/pembayaran/form.php'
$paymentTokenAdmin = Get-CsrfToken $paymentFormAdmin.Body 'admin payment form'
$crossPayment = Invoke-UatRequest -Session $sessions.kasir -Method POST -Path '/pembayaran/proses.php' -Form @{ aksi = 'invalid'; csrf_token = $paymentTokenAdmin }
Assert-Status 'CSRF-CROSS-SESSION-PAYMENT' 'kasir' 'POST' '/pembayaran/proses.php' $crossPayment @(403)

$savingsFormAdmin = Invoke-UatRequest -Session $sessions.admin -Method GET -Path '/tabungan/masuk.php'
$savingsTokenAdmin = Get-CsrfToken $savingsFormAdmin.Body 'admin savings form'
$crossSavings = Invoke-UatRequest -Session $sessions.kasir -Method POST -Path '/tabungan/proses.php' -Form @{ aksi = 'invalid'; csrf_token = $savingsTokenAdmin }
Assert-Status 'CSRF-CROSS-SESSION-SAVINGS' 'kasir' 'POST' '/tabungan/proses.php' $crossSavings @(403)

$logoutAnonGet = Invoke-UatRequest -Session $anonymous -Method GET -Path '/logout.php'
Assert-Status 'LOGOUT-ANON-GET' 'anonymous' 'GET' '/logout.php' $logoutAnonGet @(405)
$logoutAnonPost = Invoke-UatRequest -Session $anonymous -Method POST -Path '/logout.php' -Form @{}
Assert-Status 'LOGOUT-ANON-POST-NO-CSRF' 'anonymous' 'POST' '/logout.php' $logoutAnonPost @(403)

foreach ($role in @('admin', 'bendahara', 'kasir')) {
    $roleHomePath = if ($role -eq 'admin') { '/dashboard.php' } elseif ($role -eq 'bendahara') { '/laporan/index.php' } else { '/tabungan/masuk.php' }
    $homeResponse = Invoke-UatRequest -Session $sessions[$role] -Method GET -Path $roleHomePath
    $logoutToken = Get-CsrfToken $homeResponse.Body "$role home"
    $logout = Invoke-UatRequest -Session $sessions[$role] -Method POST -Path '/logout.php' -Form @{ csrf_token = $logoutToken }
    Assert-Redirect "LOGOUT-$role-VALID" $role 'POST' '/logout.php' $logout '/login.php'
    $afterLogout = Invoke-UatRequest -Session $sessions[$role] -Method GET -Path $roleHomePath
    Assert-Redirect "LOGOUT-$role-SESSION-REVOKED" $role 'GET' $roleHomePath $afterLogout '/login.php'
}

$checks | Export-Csv -LiteralPath $EvidenceCsv -NoTypeInformation -Encoding utf8
$failed = @($checks | Where-Object result -eq 'FAIL')
Write-Output "ROUTE_HTTP_CHECKS=$($checks.Count)"
Write-Output "ROUTE_HTTP_PASS=$($checks.Count - $failed.Count)"
Write-Output "ROUTE_HTTP_FAIL=$($failed.Count)"
if ($failed.Count -gt 0) {
    $failed | Select-Object id, role, method, path, status, location, evidence | Format-Table -AutoSize
    throw 'Route-role HTTP matrix failed.'
}
Write-Output 'ROUTE_ROLE_HTTP_MATRIX=PASS'

foreach ($session in @($anonymous, $invalidCookie) + @($sessions.Values)) {
    $session.Client.Dispose()
    $session.Handler.Dispose()
}
