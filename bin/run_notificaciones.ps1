param([Parameter(Mandatory = $true)][string]$PhpPath)
$ErrorActionPreference = 'Stop'
foreach ($name in @('GMAIL_ADDRESS', 'GMAIL_APP_PASSWORD', 'RESEND_API_KEY', 'MAIL_FROM')) {
    $value = [Environment]::GetEnvironmentVariable($name, 'User')
    if ($null -ne $value) { [Environment]::SetEnvironmentVariable($name, $value, 'Process') }
}
if (-not (Test-Path -LiteralPath $PhpPath)) { throw 'No se encontró PHP para procesar notificaciones.' }
& $PhpPath (Join-Path $PSScriptRoot 'notificaciones.php')
exit $LASTEXITCODE
