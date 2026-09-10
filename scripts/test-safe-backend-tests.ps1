[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$runner = Join-Path $PSScriptRoot 'run-safe-backend-tests.ps1'
$sourceConfig = Join-Path $workspace 'backend/phpunit.xml'
$tempConfig = Join-Path ([System.IO.Path]::GetTempPath()) "safe-backend-tests-contract-$PID.xml"

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "ASSERTION FAILED: $Message" }
}

try {
    Assert-True (Test-Path -LiteralPath $runner -PathType Leaf) 'safe runner exists'
    Assert-True (Test-Path -LiteralPath $sourceConfig -PathType Leaf) 'forced SQLite PHPUnit config exists'

    $tokens = $null
    $errors = $null
    [System.Management.Automation.Language.Parser]::ParseFile($runner, [ref]$tokens, [ref]$errors) | Out-Null
    Assert-True ($errors.Count -eq 0) 'safe runner has no PowerShell parser errors'

    $dryRunOutput = (& pwsh -NoLogo -NoProfile -File $runner -DryRun -ListTests 2>&1 | Out-String)
    Assert-True ($LASTEXITCODE -eq 0) "safe dry-run should pass: $dryRunOutput"
    Assert-True ($dryRunOutput -match 'DB_CONNECTION=sqlite') 'dry-run reports SQLite contract'
    Assert-True ($dryRunOutput -match 'DB_DATABASE=:memory:') 'dry-run reports memory database contract'
    Assert-True ($dryRunOutput -match 'DB_URL=\(empty\)') 'dry-run reports empty DSN contract'
    Assert-True ($dryRunOutput -match 'DRY-RUN: PHPUnit was not started') 'dry-run does not start PHPUnit'

    # Prove a dangerous configuration is rejected before PHPUnit is invoked.
    $configText = [System.IO.File]::ReadAllText($sourceConfig)
    $unsafeConfigText = $configText.Replace(
        '<env name="DB_CONNECTION" value="sqlite" force="true"/>',
        '<env name="DB_CONNECTION" value="mysql" force="true"/>'
    )
    [System.IO.File]::WriteAllText($tempConfig, $unsafeConfigText)
    $blockedOutput = (& pwsh -NoLogo -NoProfile -File $runner -ConfigurationPath $tempConfig -DryRun 2>&1 | Out-String)
    Assert-True ($LASTEXITCODE -ne 0) 'unsafe database configuration should be blocked'
    Assert-True ($blockedOutput -match 'SAFE BACKEND TEST BLOCKED') 'unsafe configuration failure is explicit'

    Write-Output 'Safe backend runner contract checks passed: parser, SQLite dry-run, and unsafe DB configuration rejection.'
} finally {
    if (Test-Path -LiteralPath $tempConfig -PathType Leaf) {
        Remove-Item -LiteralPath $tempConfig -Force
    }
}
