<#
Simple deploy helper for Windows (PowerShell).
Usage examples:
  .\deploy.ps1 -RemotePath "user@host:/var/www/vaervakt.no" -LocalPath ".\"

Note: This script prints recommended commands. Use scp/pscp/WinSCP for actual upload.
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$RemotePath,
    [string]$LocalPath = ".\"
)

Write-Host "Deploy helper"
Write-Host "Local: $LocalPath"
Write-Host "Remote: $RemotePath"
Write-Host "Shared exclude list: .github/rsync-exclude.txt"

Write-Host "\nRecommended command (if you have OpenSSH scp available):"
Write-Host "scp -r $LocalPath* $RemotePath"

Write-Host "\nOr use WinSCP/PSCP for more control. Use the same excludes as .github/rsync-exclude.txt."
