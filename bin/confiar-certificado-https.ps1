$ErrorActionPreference = 'Stop'
$rootCert = Join-Path $env:APPDATA 'Caddy\pki\authorities\local\root.crt'
if (-not (Test-Path -LiteralPath $rootCert)) {
    throw 'Primero inicie el servidor con bin/start-https.ps1.'
}
$certificate = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($rootCert)
Write-Host "Certificado local de Caddy: $($certificate.Thumbprint)"
Write-Host 'Windows pedirá confirmar la confianza. Apruebe solamente si reconoce este proyecto y la huella mostrada.'
& certutil.exe -user -f -addstore Root $rootCert
if ($LASTEXITCODE -ne 0) { throw 'Windows no instaló el certificado local.' }
Write-Host 'Certificado confiable. Abra https://localhost:8443/'
