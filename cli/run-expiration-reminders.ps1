param([Parameter(Mandatory=$true)][int]$UserId)
$ErrorActionPreference = 'Stop'
$workerPath = Join-Path $PSScriptRoot 'expiration-reminders.php'
& 'C:\xampp\php\php.exe' $workerPath $UserId
exit $LASTEXITCODE
