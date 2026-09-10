[CmdletBinding()]
param(
    [ValidateSet('accounting_uat_20260901')]
    [string]$Database = 'accounting_uat_20260901',
    [ValidateSet('127.0.0.1', 'localhost', '::1', '[::1]')]
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 3306,
    [string]$OutputPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$phpunit = Join-Path $workspace 'backend/vendor/bin/phpunit'
$php = $null
$phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
if ($null -ne $phpCommand) { $php = $phpCommand.Source }
if ([string]::IsNullOrWhiteSpace($php) -and (Test-Path -LiteralPath 'C:\xampp\php\php.exe' -PathType Leaf)) {
    $php = 'C:\xampp\php\php.exe'
}
$php = [string]$php
if ([string]::IsNullOrWhiteSpace($php)) { throw 'PHP executable not found. Install/configure PHP before running internal UAT.' }
$config = Join-Path $workspace 'backend/phpunit.internal-uat.xml'
if (-not (Test-Path -LiteralPath $phpunit -PathType Leaf)) { throw "PHPUnit binary not found: $phpunit" }
if (-not (Test-Path -LiteralPath $config -PathType Leaf)) { throw "Internal UAT configuration not found: $config" }

# The config itself force-pins this database. Keep the script's allowlist in
# sync so a typo or a copied command cannot reset an application database.
if ($Database -ne 'accounting_uat_20260901') {
    throw 'Internal core UAT only accepts the explicitly disposable accounting_uat_20260901 database.'
}
# phpunit.internal-uat.xml force-pins the actual connection. Reject overrides
# instead of silently ignoring them and accidentally running against 3306.
if ($DbHost -ne '127.0.0.1' -or $Port -ne 3306) {
    throw 'Internal core UAT is pinned to the disposable loopback target 127.0.0.1:3306; no connection override is accepted.'
}

$tests = @(
    'tests/Feature/MisaE2E/Tier1FeatureCoverageTest.php',
    'tests/Feature/MisaE2E/Tier2BoundaryCornerTest.php',
    'tests/Feature/MisaE2E/Tier3PairwiseCombinationsTest.php',
    'tests/Feature/MisaE2E/Tier4RealWorldCycleTest.php',
    'tests/Feature/MisaE2E/Tier5AdversarialStressTest.php'
)
$artifactDirectory = Join-Path ([System.IO.Path]::GetTempPath()) "accounting-internal-core-uat-$PID"
New-Item -ItemType Directory -Path $artifactDirectory -Force | Out-Null
$rows = @()
$startedAt = (Get-Date).ToUniversalTime()

Push-Location (Join-Path $workspace 'backend')
try {
    foreach ($relativeTest in $tests) {
        $safeName = [System.IO.Path]::GetFileNameWithoutExtension($relativeTest)
        $junit = Join-Path $artifactDirectory "$safeName.junit.xml"
        $arguments = @(
            '--configuration', $config,
            '--colors=never',
            '--log-junit', $junit,
            $relativeTest
        )
        # vendor/bin/phpunit is a PHP script, not a Windows executable. Invoke
        # it through the resolved PHP binary so the runner works consistently
        # in XAMPP PowerShell sessions and CI shells.
        $output = (& $php $phpunit @arguments 2>&1 | Out-String)
        $exitCode = $LASTEXITCODE
        $testsRun = 0; $assertions = 0; $failures = 0; $errors = 0; $skipped = 0
        if (Test-Path -LiteralPath $junit -PathType Leaf) {
            try {
                $xml = [xml](Get-Content -LiteralPath $junit -Raw)
                foreach ($suite in @($xml.SelectNodes('//testsuite'))) {
                    $testsRun += [int]($suite.tests ?? 0)
                    $assertions += [int]($suite.assertions ?? 0)
                    $failures += [int]($suite.failures ?? 0)
                    $errors += [int]($suite.errors ?? 0)
                    $skipped += [int]($suite.skipped ?? 0)
                }
            } catch {
                $output += "`nUnable to parse JUnit output: $($_.Exception.Message)"
            }
        }
        $rows += [ordered]@{
            test = $relativeTest
            exit_code = $exitCode
            tests = $testsRun
            assertions = $assertions
            failures = $failures
            errors = $errors
            skipped = $skipped
            junit = $junit.Replace('\', '/')
            console_tail = (($output -split "`r?`n" | Where-Object { $_.Trim() } | Select-Object -Last 8) -join "`n")
        }
    }
} finally {
    Pop-Location
}

$finishedAt = (Get-Date).ToUniversalTime()
$totalTests = 0
$totalAssertions = 0
foreach ($row in @($rows)) {
    # OrderedDictionary key access is more reliable than Measure-Object's
    # property binder across Windows PowerShell/PowerShell 7 versions.
    $totalTests += [int]$row['tests']
    $totalAssertions += [int]$row['assertions']
}
$failedRows = @($rows | Where-Object { $_.exit_code -ne 0 -or $_.failures -gt 0 -or $_.errors -gt 0 -or $_.skipped -gt 0 })
$result = [ordered]@{
    schema_version = 1
    scope = 'internal-core'
    disposable = $true
    database = $Database
    host = $DbHost
    port = $Port
    started_at = $startedAt.ToString('o')
    finished_at = $finishedAt.ToString('o')
    tests = [int]$totalTests
    assertions = [int]$totalAssertions
    suites = @($rows)
    passed = ($failedRows.Count -eq 0 -and $rows.Count -eq $tests.Count)
    note = 'This is isolated UAT evidence; it is not owner sign-off, production approval or statutory evidence.'
}
$json = $result | ConvertTo-Json -Depth 8
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    Write-Output $json
} else {
    $outputText = $OutputPath.Trim()
    $resolved = if ([System.IO.Path]::IsPathRooted($outputText)) {
        [System.IO.Path]::GetFullPath($outputText)
    } else {
        [System.IO.Path]::GetFullPath((Join-Path $workspace $outputText))
    }
    $parent = Split-Path -Parent $resolved
    if (-not (Test-Path -LiteralPath $parent -PathType Container)) { throw "Output directory does not exist: $parent" }
    [System.IO.File]::WriteAllText($resolved, $json)
    Write-Output "Wrote internal core UAT evidence: $resolved"
}
if (-not $result.passed) { exit 1 }
