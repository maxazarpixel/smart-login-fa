<#
    Builds an installable plugin zip from the committed HEAD into release\.
    Nothing is written to the repo root; release\ is gitignored.

    Usage (from the repo root):
        .\bin\build-release.ps1            # build from HEAD
        .\bin\build-release.ps1 -Ref v1.13.0
#>
param(
    [string]$Ref = "HEAD"
)

$ErrorActionPreference = "Stop"
$repo = Split-Path -Parent $PSScriptRoot
Set-Location $repo

# Plugin version from the header line "Version: x.y.z"
$header  = Get-Content "smart-login.php" -TotalCount 40
$verLine = $header | Where-Object { $_ -match '^\s*\*\s*Version:\s*(.+)$' }
if (-not $verLine) { throw "Could not read Version from smart-login.php" }
$version = ($Matches[1]).Trim()

$releaseDir = Join-Path $repo "release"
New-Item -ItemType Directory -Force -Path $releaseDir | Out-Null

$out = Join-Path $releaseDir "smart-login-$version.zip"
if (Test-Path $out) { Remove-Item $out -Force }

# git archive already honours .gitattributes export-ignore (CLAUDE.md,
# .gitignore, .gitattributes, .claude, release, bin are all excluded).
git archive --format=zip --prefix=smart-login/ -o "$out" $Ref
if ($LASTEXITCODE -ne 0) { throw "git archive failed" }

Write-Host "Built $out"
