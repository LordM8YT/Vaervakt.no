param(
    [string]$EnvFile = ".env",
    [string]$OutDir = "."
)

function Get-EnvVarValue($name, $envFile) {
    if (-not (Test-Path $envFile)) { return $null }
    $lines = Get-Content $envFile
    foreach ($line in $lines) {
        $trim = $line.Trim()
        if ($trim -eq '' -or $trim.StartsWith('#')) { continue }
        if ($trim -match '^(.*?)=(.*)$') {
            $k = $matches[1].Trim()
            $v = $matches[2].Trim().Trim("'\"")
            if ($k -eq $name) { return $v }
        }
    }
    return $null
}

$DB_NAME = Get-EnvVarValue 'DB_NAME' $EnvFile
if (-not $DB_NAME) { Write-Error 'DB_NAME not set in .env'; exit 2 }

$DB_HOST = Get-EnvVarValue 'DB_HOST' $EnvFile
if (-not $DB_HOST) { $DB_HOST = 'localhost' }
$DB_USER = Get-EnvVarValue 'DB_USER' $EnvFile
if (-not $DB_USER) { $DB_USER = 'root' }
$DB_PASS = Get-EnvVarValue 'DB_PASS' $EnvFile
$DB_PORT = Get-EnvVarValue 'DB_PORT' $EnvFile

$timestamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$outfile = Join-Path $OutDir ("backup_${DB_NAME}_$timestamp.sql")
$portArg = ''
if ($DB_PORT) { $portArg = "-P $DB_PORT" }

$cmd = "mysqldump -h $DB_HOST $portArg -u $DB_USER -p$DB_PASS $DB_NAME > `"$outfile`""
Write-Host "Running: $cmd"
cmd.exe /c $cmd
Write-Host "Backup written to $outfile"
