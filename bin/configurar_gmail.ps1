$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = (Get-Command php -ErrorAction Stop).Source

if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'vendor\autoload.php'))) {
    throw 'Falta PHPMailer. Ejecute composer install en el directorio del proyecto.'
}

$address = (Read-Host 'Cuenta Gmail que enviará las confirmaciones').Trim().ToLowerInvariant()
if ($address -notmatch '^[^\s@]+@gmail\.com$') {
    throw 'Ingrese una cuenta Gmail válida.'
}

$securePassword = Read-Host 'Contraseña de aplicación de Google (entrada oculta)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $appPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer).Replace(' ', '')
    if ($appPassword -notmatch '^[A-Za-z0-9]{16}$') {
        throw 'La contraseña de aplicación debe tener 16 caracteres. No use su contraseña normal de Google.'
    }

    $env:GMAIL_ADDRESS = $address
    $env:GMAIL_APP_PASSWORD = $appPassword
    & $php (Join-Path $PSScriptRoot 'probar_gmail.php') $address
    if ($LASTEXITCODE -ne 0) { throw 'Google no aceptó el envío; no se guardó la configuración.' }

    [Environment]::SetEnvironmentVariable('GMAIL_ADDRESS', $address, 'User')
    [Environment]::SetEnvironmentVariable('GMAIL_APP_PASSWORD', $appPassword, 'User')
    Write-Host 'Prueba aceptada por Gmail. La configuración quedó guardada solo para este usuario de Windows.'
    Write-Host 'Reinicie el servidor PHP para cargarla y compruebe la bandeja de entrada.'
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    Remove-Item Env:GMAIL_APP_PASSWORD -ErrorAction SilentlyContinue
    $appPassword = $null
}
