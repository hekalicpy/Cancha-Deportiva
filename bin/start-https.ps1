param([switch]$NoBrowser)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtime = Join-Path $project '.runtime'
$caddy = Join-Path $runtime 'caddy.exe'
$config = Join-Path $runtime 'Caddyfile'
$php = (Get-Command php -ErrorAction Stop).Source
$phpCgi = Join-Path (Split-Path $php) 'php-cgi.exe'
if (-not (Test-Path -LiteralPath $phpCgi)) { throw 'No se encontró php-cgi.exe junto a php.exe.' }

New-Item -ItemType Directory -Path $runtime -Force | Out-Null
if (-not (Test-Path -LiteralPath $caddy)) {
    Write-Host 'Descargando Caddy desde caddyserver.com...'
    Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile $caddy
}

$webRoot = $project.Replace('\', '/')
@"
{
    http_port 8000
    https_port 8443
    skip_install_trust
    admin off
}

localhost:8443 {
    tls internal
    bind 127.0.0.1 [::1]
    root * "$webRoot"
    header X-Content-Type-Options nosniff
    header Referrer-Policy no-referrer

    @php path /api.php /api.php/* /login.php /listar_reservas.php /guardar_reserva.php /editar_reserva.php /eliminar_reserva.php /ubicacion.php /taypi_webhook.php
    handle @php {
        php_fastcgi 127.0.0.1:9000 {
            try_files {path} =404
        }
    }

    @static path / /index.html /estilo.css /logo.PNG /ubicacion.js /auditoria.js /assets/*
    handle @static {
        file_server
    }

    handle {
        respond 404
    }
}

http://localhost:8000 {
    bind 127.0.0.1 [::1]
    redir https://localhost:8443{uri} 308
}
"@ | Set-Content -LiteralPath $config -Encoding utf8

& $caddy validate --config $config --adapter caddyfile | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'La configuración HTTPS no es válida.' }

$existingHttps = Get-NetTCPConnection -LocalPort 8443 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($existingHttps) {
    $existingProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $($existingHttps.OwningProcess)"
    if ($existingProcess.Name -ne 'caddy.exe' -or $existingProcess.CommandLine -notlike "*$config*") {
        throw 'El puerto HTTPS 8443 está ocupado por otro programa.'
    }
    Write-Host 'Arena 7 ya está funcionando en https://localhost:8443/'
    $rootCert = Join-Path $env:APPDATA 'Caddy\pki\authorities\local\root.crt'
    if (Test-Path -LiteralPath $rootCert) {
        $certificate = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($rootCert)
        $trusted = Get-ChildItem Cert:\CurrentUser\Root | Where-Object Thumbprint -EQ $certificate.Thumbprint
        if (-not $trusted) { Write-Warning 'Windows aún debe aprobar el certificado local.' }
        elseif (-not $NoBrowser) { Start-Process 'https://localhost:8443/' }
    }
    return
}

# Replace only an older PHP development server for this same project.
$oldHttp = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
foreach ($listener in $oldHttp) {
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $($listener.OwningProcess)"
    if ($process.Name -eq 'php.exe' -and $process.CommandLine -like "*$project*" -and $process.CommandLine -match ' -S (localhost|127\.0\.0\.1):8000(?: |$)') {
        Stop-Process -Id $process.ProcessId
    }
}
Start-Sleep -Milliseconds 300
foreach ($port in 8000, 8443, 9000) {
    if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
        throw "El puerto $port está ocupado. Cierre el servidor anterior e inténtelo de nuevo."
    }
}

$cgiProcess = Start-Process -FilePath $phpCgi -ArgumentList '-b', '127.0.0.1:9000' -WorkingDirectory $project -WindowStyle Hidden -PassThru
try {
    Start-Sleep -Seconds 1
    if ($cgiProcess.HasExited) { throw 'PHP FastCGI no pudo iniciarse.' }
    $caddyProcess = Start-Process -FilePath $caddy -ArgumentList 'run', '--config', $config, '--adapter', 'caddyfile' -WorkingDirectory $project -WindowStyle Hidden -PassThru
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        if (Get-NetTCPConnection -LocalPort 8443 -State Listen -ErrorAction SilentlyContinue) { break }
        Start-Sleep -Seconds 1
    }
    if ($caddyProcess.HasExited) { throw 'El servidor HTTPS no pudo iniciarse.' }

    $rootCert = Join-Path $env:APPDATA 'Caddy\pki\authorities\local\root.crt'
    if (-not (Test-Path -LiteralPath $rootCert)) { throw 'No se generó el certificado local de HTTPS.' }
    $certificate = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($rootCert)
    $trusted = Get-ChildItem Cert:\CurrentUser\Root | Where-Object Thumbprint -EQ $certificate.Thumbprint

    $response = Invoke-WebRequest -Uri 'https://localhost:8443/' -SkipCertificateCheck
    if ($response.StatusCode -ne 200) { throw 'La aplicación no respondió por HTTPS.' }
    $handler = [System.Net.Http.HttpClientHandler]::new()
    $handler.AllowAutoRedirect = $false
    $client = [System.Net.Http.HttpClient]::new($handler)
    try {
        $redirect = $client.GetAsync('http://localhost:8000/').GetAwaiter().GetResult()
        $redirectStatus = [int]$redirect.StatusCode
        $redirectLocation = [string]$redirect.Headers.Location
    } finally {
        $client.Dispose()
        $handler.Dispose()
    }
    if (($redirectStatus -notin 301, 308) -or $redirectLocation -notlike 'https://localhost:8443/*') {
        throw "HTTP no redirige correctamente a HTTPS: $redirectStatus $redirectLocation"
    }

    Write-Host 'HTTPS funcionando: https://localhost:8443/'
    Write-Host 'http://localhost:8000/ redirige a HTTPS.'
    if (-not $trusted) {
        Write-Warning "El certificado local aún necesita aprobación de Windows. Ejecute: pwsh -File .\bin\confiar-certificado-https.ps1"
    } elseif (-not $NoBrowser) {
        Start-Process 'https://localhost:8443/'
    }
} catch {
    if ($caddyProcess -and -not $caddyProcess.HasExited) { Stop-Process -Id $caddyProcess.Id -Force }
    if ($cgiProcess -and -not $cgiProcess.HasExited) { Stop-Process -Id $cgiProcess.Id -Force }
    throw
}
