param([switch]$NoBrowser)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtime = Join-Path $project '.runtime'
$caddy = Join-Path $runtime 'caddy.exe'
$config = Join-Path $runtime 'Caddyfile'
$php = (Get-Command php -ErrorAction Stop).Source
$phpCgi = Join-Path (Split-Path $php) 'php-cgi.exe'
if (-not (Test-Path -LiteralPath $phpCgi)) { throw 'No se encontró php-cgi.exe junto a php.exe.' }

function Start-ArenaPhpCgi([int]$Port) {
    # A desktop app or an already-open terminal may have an older environment
    # than the user's saved provider settings. Load them only into this process.
    foreach ($name in @('RENIEC_TOKEN', 'CA_BUNDLE_PATH', 'GMAIL_ADDRESS', 'GMAIL_APP_PASSWORD', 'RESEND_API_KEY', 'MAIL_FROM', 'TAYPI_MODE', 'TAYPI_PUBLIC_KEY', 'TAYPI_SECRET_KEY', 'TAYPI_WEBHOOK_SECRET')) {
        $value = [Environment]::GetEnvironmentVariable($name, 'User')
        if ($null -ne $value) { [Environment]::SetEnvironmentVariable($name, $value, 'Process') }
    }
    Start-Process -FilePath $phpCgi -ArgumentList '-b', "127.0.0.1:$Port" -WorkingDirectory $project -WindowStyle Hidden -PassThru
}

function Test-LocalPort([int]$Port) {
    foreach ($address in @([Net.IPAddress]::Loopback, [Net.IPAddress]::IPv6Loopback)) {
        $client = [Net.Sockets.TcpClient]::new($address.AddressFamily)
        try {
            $connection = $client.ConnectAsync($address, $Port)
            if ($connection.Wait(500) -and $client.Connected) { return $true }
        } catch {
            # An unavailable address family or a refused connection is not a listener.
        } finally {
            $client.Dispose()
        }
    }
    return $false
}

function Get-ProjectCaddy {
    @(Get-CimInstance Win32_Process -Filter "Name='caddy.exe'" | Where-Object {
        $_.ExecutablePath -ieq $caddy -and $_.CommandLine -like "*$config*"
    })
}

function Get-ProjectPhpCgi([int]$Port) {
    @(Get-CimInstance Win32_Process -Filter "Name='php-cgi.exe'" | Where-Object {
        $_.ExecutablePath -ieq $phpCgi -and $_.CommandLine -like "*127.0.0.1:$Port*"
    })
}

New-Item -ItemType Directory -Path $runtime -Force | Out-Null
if (-not (Test-Path -LiteralPath $caddy)) {
    Write-Host 'Descargando Caddy desde caddyserver.com...'
    Invoke-WebRequest -Uri 'https://caddyserver.com/api/download?os=windows&arch=amd64' -OutFile $caddy
}

$webRoot = $project.Replace('\', '/')
# Both PHP workers inherit one short-lived secret. It is never written to the repo or browser.
if (-not $env:ARENA7_ESB_SECRET) {
    [Environment]::SetEnvironmentVariable('ARENA7_ESB_SECRET',
        [Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32)), 'Process')
}
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

localhost:8444 {
    tls internal
    bind 127.0.0.1 [::1]
    root * "$webRoot"
    header X-Content-Type-Options nosniff
    header Referrer-Policy no-referrer

    @esb path /esb.php /esb.php/*
    handle @esb {
        php_fastcgi 127.0.0.1:9001 {
            try_files {path} =404
        }
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

# Replace only an older PHP development server for this same project.
if (Test-LocalPort 8000) {
    $oldHttp = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $_.CommandLine -like "*$project*" -and $_.CommandLine -match ' -S (localhost|127\.0\.0\.1):8000(?: |$)'
    })
    foreach ($process in $oldHttp) { Stop-Process -Id $process.ProcessId }
}
if (Get-ProjectCaddy) {
    foreach ($process in (Get-ProjectCaddy)) { Stop-Process -Id $process.ProcessId }
    foreach ($port in 9000, 9001) {
        foreach ($process in (Get-ProjectPhpCgi $port)) { Stop-Process -Id $process.ProcessId }
    }
}
Start-Sleep -Milliseconds 300
foreach ($port in 8000, 8443, 8444, 9000, 9001) {
    if (Test-LocalPort $port) {
        throw "El puerto $port está ocupado. Cierre el servidor anterior e inténtelo de nuevo."
    }
}

$cgiProcess = $null
$esbProcess = $null
try {
    $cgiProcess = Start-ArenaPhpCgi 9000
    $esbProcess = Start-ArenaPhpCgi 9001
    Start-Sleep -Seconds 1
    if ($cgiProcess.HasExited) { throw 'PHP FastCGI no pudo iniciarse.' }
    if ($esbProcess.HasExited) { throw 'El ESB FastCGI no pudo iniciarse.' }
    $caddyProcess = Start-Process -FilePath $caddy -ArgumentList 'run', '--config', $config, '--adapter', 'caddyfile' -WorkingDirectory $project -WindowStyle Hidden -PassThru
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        if (Test-LocalPort 8443) { break }
        Start-Sleep -Seconds 1
    }
    if ($caddyProcess.HasExited) { throw 'El servidor HTTPS no pudo iniciarse.' }

    $rootCert = Join-Path $env:APPDATA 'Caddy\pki\authorities\local\root.crt'
    if (-not (Test-Path -LiteralPath $rootCert)) { throw 'No se generó el certificado local de HTTPS.' }
    $certificate = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($rootCert)
    $trusted = Get-ChildItem Cert:\CurrentUser\Root | Where-Object Thumbprint -EQ $certificate.Thumbprint

    $response = Invoke-WebRequest -Uri 'https://localhost:8443/' -SkipCertificateCheck
    if ($response.StatusCode -ne 200) { throw 'La aplicación no respondió por HTTPS.' }
    $esbHealth = Invoke-RestMethod -Uri 'https://localhost:8444/esb.php/health' -SkipCertificateCheck
    if ($esbHealth.estado -ne 'operativo') { throw 'La capa ESB no respondió por HTTPS.' }
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
    Write-Host 'ESB funcionando: https://localhost:8444/esb.php/health'
    Write-Host 'http://localhost:8000/ redirige a HTTPS.'
    if (-not $trusted) {
        Write-Warning "El certificado local aún necesita aprobación de Windows. Ejecute: pwsh -File .\bin\confiar-certificado-https.ps1"
    } elseif (-not $NoBrowser) {
        Start-Process 'https://localhost:8443/'
    }
} catch {
    if ($caddyProcess -and -not $caddyProcess.HasExited) { Stop-Process -Id $caddyProcess.Id -Force }
    if ($cgiProcess -and -not $cgiProcess.HasExited) { Stop-Process -Id $cgiProcess.Id -Force }
    if ($esbProcess -and -not $esbProcess.HasExited) { Stop-Process -Id $esbProcess.Id -Force }
    throw
}
