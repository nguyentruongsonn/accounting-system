[CmdletBinding()]
param(
    # A full commit object ID, never a branch name or a moving ref.
    [string]$ReleaseCommit = $env:RELEASE_COMMIT_SHA,

    # CI-published metadata which binds the smoke evidence to ReleaseCommit.
    [string]$EvidenceManifest = $env:RELEASE_EVIDENCE_MANIFEST,

    # The MySQL job writes its GitHub Actions run ID into the manifest. CI
    # passes GITHUB_RUN_ID here so an artifact bundle from another run cannot
    # be presented as this run's evidence. Local diagnostics may omit the
    # expected value, but the manifest field is always required.
    [string]$ExpectedCiRunId = $env:RELEASE_CI_RUN_ID,

    [string]$MySqlSmokeJUnit = $env:MYSQL_SMOKE_JUNIT,
    [string]$MigrationStatusEvidence = $env:MYSQL_MIGRATION_STATUS_LOG,
    [string]$MySqlServerVersionEvidence = $env:MYSQL_SERVER_VERSION_LOG,
    [string]$BackendTestJUnit = $env:BACKEND_TEST_JUNIT,
    [string]$BackendTestLog = $env:BACKEND_TEST_LOG,
    [string]$FrontendBuildEvidence = $env:FRONTEND_BUILD_LOG,

    # The script never runs npm. This only makes an explicitly supplied build
    # log a release requirement.
    [switch]$RequireFrontendBuild,

    # A release candidate must carry the full backend PHPUnit result, not only
    # the narrower MySQL control smoke. This is opt-in for local diagnostics
    # and mandatory in the CI release-preflight job.
    [switch]$RequireBackendTest,

    # A release candidate must be built from an exact clean checkout. This is
    # intentionally an opt-out only for diagnostic use, never CI.
    [switch]$AllowDirtyWorktree
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$failures = [System.Collections.Generic.List[string]]::new()
$notes = [System.Collections.Generic.List[string]]::new()

function Add-Failure([string]$Message) {
    $script:failures.Add($Message)
    Write-Host "FAIL: $Message" -ForegroundColor Red
}

function Add-Note([string]$Message) {
    $script:notes.Add($Message)
    Write-Host "PASS: $Message" -ForegroundColor Green
}

function Resolve-ExistingFile([string]$PathValue, [string]$Label) {
    if ([string]::IsNullOrWhiteSpace($PathValue)) {
        Add-Failure "$Label is required. This preflight will not create or run it."
        return $null
    }

    try {
        $resolved = (Resolve-Path -LiteralPath $PathValue -ErrorAction Stop).Path
        if (-not (Test-Path -LiteralPath $resolved -PathType Leaf)) {
            Add-Failure "$Label is not a file: $PathValue"
            return $null
        }
        return $resolved
    } catch {
        Add-Failure "$Label does not exist: $PathValue"
        return $null
    }
}

function Get-Sha256([string]$PathValue) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $PathValue).Hash.ToLowerInvariant()
}

function Get-ManifestArtifact($ManifestValue, [string]$Name) {
    if ($null -eq $ManifestValue -or $null -eq $ManifestValue.PSObject.Properties['artifacts']) { return $null }
    $artifacts = $ManifestValue.artifacts
    if ($null -eq $artifacts) { return $null }
    $property = $artifacts.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Read-SafeXml([string]$PathValue) {
    $settings = [System.Xml.XmlReaderSettings]::new()
    $settings.DtdProcessing = [System.Xml.DtdProcessing]::Prohibit
    $settings.XmlResolver = $null
    $document = [System.Xml.XmlDocument]::new()
    $reader = [System.Xml.XmlReader]::Create($PathValue, $settings)
    try {
        $document.Load($reader)
    } finally {
        $reader.Dispose()
    }
    return $document
}

function Get-XmlIntAttribute([System.Xml.XmlElement]$Element, [string]$Name) {
    $value = $Element.GetAttribute($Name)
    $number = 0
    if (-not [int]::TryParse($value, [ref]$number)) {
        throw "JUnit attribute '$Name' is missing or invalid."
    }
    return $number
}

Write-Host 'Release preflight is read-only: it does not run migrations, tests, builds, git writes, or database commands.' -ForegroundColor Cyan

# --- Exact release source ----------------------------------------------------
Push-Location $workspace
try {
    $insideGit = (& git rev-parse --is-inside-work-tree 2>$null)
    if ($LASTEXITCODE -ne 0 -or $insideGit.Trim() -ne 'true') {
        Add-Failure 'Workspace is not a Git worktree.'
    } else {
        $head = (& git rev-parse HEAD 2>$null).Trim().ToLowerInvariant()
        $branch = (& git symbolic-ref --quiet --short HEAD 2>$null)
        if ($LASTEXITCODE -eq 0) {
            Add-Note "Git branch metadata read: $($branch.Trim()) at $head"
        } else {
            Add-Note "Git detached-HEAD metadata read at $head"
        }

        if ([string]::IsNullOrWhiteSpace($ReleaseCommit)) {
            Add-Failure 'RELEASE_COMMIT_SHA (or -ReleaseCommit) is required; a branch name is not immutable release evidence.'
        } elseif ($ReleaseCommit -notmatch '^[0-9a-fA-F]{40}$') {
            Add-Failure 'ReleaseCommit must be a full 40-character Git commit SHA, not a short SHA, tag, or branch.'
        } else {
            $resolvedCommit = (& git rev-parse "$ReleaseCommit^{commit}" 2>$null)
            if ($LASTEXITCODE -ne 0) {
                Add-Failure "ReleaseCommit does not resolve to a commit object: $ReleaseCommit"
            } else {
                $resolvedCommit = $resolvedCommit.Trim().ToLowerInvariant()
                if ($resolvedCommit -ne $ReleaseCommit.ToLowerInvariant()) {
                    Add-Failure "ReleaseCommit must be a canonical full commit SHA. Resolved value differs: $resolvedCommit"
                } elseif ($head -ne $resolvedCommit) {
                    Add-Failure "Checked-out HEAD ($head) does not equal ReleaseCommit ($resolvedCommit)."
                } else {
                    Add-Note "Release source is pinned to immutable commit object $resolvedCommit"
                }
            }
        }

        $dirty = (& git status --porcelain=v1 2>$null)
        if (-not $AllowDirtyWorktree -and -not [string]::IsNullOrWhiteSpace(($dirty -join "`n"))) {
            Add-Failure 'Git worktree has uncommitted or untracked files. Build a release candidate from a clean checkout.'
        } elseif ($AllowDirtyWorktree) {
            Write-Host 'WARN: dirty-worktree check was explicitly bypassed for diagnostics.' -ForegroundColor Yellow
        } else {
            Add-Note 'Git worktree is clean.'
        }
    }
} finally {
    Pop-Location
}

# --- Deployment-control defaults (static only; no config bootstrap) ---------
$accountingConfig = Join-Path $workspace 'backend/config/accounting.php'
$envExample = Join-Path $workspace 'backend/.env.example'
$ciWorkflow = Join-Path $workspace '.github/workflows/ci.yml'
$requiredDeploymentFlags = @(
    'PURCHASE_INVOICE_POSTING_POLICY',
    'SALES_INVOICE_POSTING_POLICY',
    'PURCHASE_INVOICE_POSTING_APPROVAL',
    'PURCHASE_INVOICE_POSTING_DIMENSIONS',
    'PURCHASE_INVOICE_POSTING_ACCOUNT_MAPPINGS',
    'SALES_INVOICE_POSTING_APPROVAL',
    'SALES_INVOICE_POSTING_DIMENSIONS',
    'SALES_INVOICE_POSTING_ACCOUNT_MAPPINGS',
    'CASH_BANK_POSTING_POLICY',
    'CASH_BANK_POSTING_APPROVAL',
    'CASH_BANK_POSTING_ACCOUNT_MAPPINGS',
    'VOUCHER_REFERENCE_ACCOUNT_MAPPINGS',
    'INVENTORY_POSTING_ACCOUNT_MAPPINGS',
    'INVENTORY_STOCK_AVAILABILITY',
    'FIXED_ASSET_POSTING_ACCOUNT_MAPPINGS',
    'RETURN_DISCOUNT_POSTING_ACCOUNT_MAPPINGS',
    'COSTING_INTEGER_ALLOCATION_EVIDENCE',
    'PERIOD_CLOSE_SIGNOFF',
    'PERIOD_CLOSE_ACCOUNT_MAPPINGS'
)
# The all-controls smoke includes the six-family commercial draft evidence
# boundary (26 tests) in addition to the retained control suite. Keep this
# minimum synchronized with phpunit.mysql-control-smoke.xml so an older green
# artifact cannot silently pass release preflight.
$minimumMySqlSmokeTests = 102
$requiredMySqlSmokeTestFiles = @(
    # These files define the current smoke-suite expansion. Keep the
    # selection contract explicit so a stale/partially edited XML cannot
    # satisfy the numeric floor with an unrelated set of tests.
    'tests/Feature/AccountHierarchyConcurrencyTest.php',
    'tests/Feature/CommercialDraftAccountEvidenceGateTest.php',
    'tests/Feature/InventoryValuationClosedPeriodIntegrityTest.php',
    'tests/Feature/InventoryValuationTenantBoundaryTest.php'
)

if (-not (Test-Path -LiteralPath $accountingConfig -PathType Leaf)) {
    Add-Failure "Deployment config is missing: $accountingConfig"
} else {
    $configText = Get-Content -Raw -LiteralPath $accountingConfig
    $configFailureCount = $failures.Count
    foreach ($flag in $requiredDeploymentFlags) {
        $envName = "ACCOUNTING_ENFORCE_$flag"
        $pattern = "env\(\s*'$([regex]::Escape($envName))'\s*,\s*true\s*,?\s*\)"
        if ($configText -notmatch $pattern) {
            Add-Failure "Deployment flag must default fail-closed to true in backend/config/accounting.php: $envName"
        }
    }

    if ($configText -notmatch "env\(\s*'ACCOUNTING_ENABLE_EINVOICE_PROVIDER_ADAPTER'\s*,\s*false\s*,?\s*\)") {
        Add-Failure 'E-invoice provider transport must default to false until a separately approved adapter is deployed.'
    }

    if ($failures.Count -eq $configFailureCount) {
        Add-Note 'Static deployment-accounting defaults were inspected; no application config was bootstrapped.'
    }
}

if (-not (Test-Path -LiteralPath $ciWorkflow -PathType Leaf)) {
    Add-Failure "CI workflow is missing: $ciWorkflow"
} else {
    $ciText = Get-Content -Raw -LiteralPath $ciWorkflow
    $ciFailureCount = $failures.Count
    foreach ($flag in $requiredDeploymentFlags) {
        $envName = "ACCOUNTING_ENFORCE_$flag"
        $pattern = "(?im)^\s*$([regex]::Escape($envName))\s*:\s*'?true'?\s*$"
        if ($ciText -notmatch $pattern) {
            Add-Failure "CI workflow must force the release control true: $envName"
        }
    }

    if ($failures.Count -eq $ciFailureCount) {
        Add-Note 'CI workflow accounting-control flags match the fail-closed release contract.'
    }
}

$mysqlSmokeConfig = Join-Path $workspace 'backend/phpunit.mysql-control-smoke.xml'
if (-not (Test-Path -LiteralPath $mysqlSmokeConfig -PathType Leaf)) {
    Add-Failure "MySQL control-smoke PHPUnit configuration is missing: $mysqlSmokeConfig"
} else {
    $smokeConfigText = Get-Content -Raw -LiteralPath $mysqlSmokeConfig
    foreach ($requiredTestFile in $requiredMySqlSmokeTestFiles) {
        $escapedTestFile = [regex]::Escape($requiredTestFile)
        if ($smokeConfigText -notmatch "<file>\s*$escapedTestFile\s*</file>") {
            Add-Failure "MySQL control-smoke configuration must include $requiredTestFile."
        }
    }
}

if (Test-Path -LiteralPath $envExample -PathType Leaf) {
    $unsafeExampleFlags = Select-String -LiteralPath $envExample -Pattern '^ACCOUNTING_ENFORCE_.*=false\s*$|^ACCOUNTING_ENABLE_EINVOICE_PROVIDER_ADAPTER=true\s*$' -CaseSensitive:$false
    if ($unsafeExampleFlags) {
        Add-Failure 'backend/.env.example explicitly weakens a release control. Remove the false/true override before release.'
    } else {
        Add-Note 'backend/.env.example contains no explicit unsafe accounting-control override.'
    }
}

# --- CI evidence manifest and migration status (file evidence only) ----------
$manifestPath = Resolve-ExistingFile $EvidenceManifest 'Release evidence manifest'
$junitPath = Resolve-ExistingFile $MySqlSmokeJUnit 'MySQL control-smoke JUnit artifact'
$migrationPath = Resolve-ExistingFile $MigrationStatusEvidence 'MySQL migration-status evidence'
$mysqlServerVersionPath = Resolve-ExistingFile $MySqlServerVersionEvidence 'MySQL server-version evidence'
$backendJUnitPath = $null
$backendLogPath = $null
if ($RequireBackendTest -or -not [string]::IsNullOrWhiteSpace($BackendTestJUnit)) {
    $backendJUnitPath = Resolve-ExistingFile $BackendTestJUnit 'Backend full-suite JUnit artifact'
    # The console transcript is required alongside the JUnit whenever the
    # full backend evidence is required. This prevents a later/stale JUnit
    # from being described as the same run as an unrelated console log.
    $backendLogPath = Resolve-ExistingFile $BackendTestLog 'Backend full-suite console log'
}
$manifest = $null

if ($manifestPath) {
    try {
        $manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json -ErrorAction Stop
        if ($manifest.schema_version -ne 1) {
            Add-Failure 'Release evidence manifest schema_version must be 1.'
        }
        if ([string]::IsNullOrWhiteSpace($ReleaseCommit) -or $manifest.release_commit -ne $ReleaseCommit.ToLowerInvariant()) {
            Add-Failure 'Release evidence manifest release_commit does not exactly match ReleaseCommit.'
        } else {
            Add-Note 'Evidence manifest is bound to the requested release commit.'
        }

        $ciRunProperty = $manifest.PSObject.Properties['ci_run_id']
        $manifestCiRunId = if ($null -eq $ciRunProperty) { '' } else { [string]$ciRunProperty.Value }
        if ([string]::IsNullOrWhiteSpace($manifestCiRunId)) {
            Add-Failure 'Release evidence manifest must contain a non-empty ci_run_id provenance value.'
        } elseif (-not [string]::IsNullOrWhiteSpace($ExpectedCiRunId) -and $manifestCiRunId -ne $ExpectedCiRunId) {
            Add-Failure "Release evidence manifest ci_run_id does not match the expected CI run: manifest=$manifestCiRunId expected=$ExpectedCiRunId."
        } elseif ([string]::IsNullOrWhiteSpace($ExpectedCiRunId)) {
            Add-Note "Evidence manifest has CI run provenance $manifestCiRunId (no expected run ID supplied for local diagnostics)."
        } else {
            Add-Note "Evidence manifest is bound to CI run $ExpectedCiRunId."
        }
    } catch {
        Add-Failure "Release evidence manifest is invalid JSON: $($_.Exception.Message)"
    }
}

function Confirm-ManifestPath($Artifact, [string]$PathValue, [string]$Label) {
    if (-not $Artifact -or [string]::IsNullOrWhiteSpace($Artifact.path)) {
        Add-Failure "Release evidence manifest has no declared path for $Label."
        return
    }

    $declared = ([string]$Artifact.path).Replace('\', '/')
    $actual = ([string]$PathValue).Replace('\', '/')

    # Manifest paths are artifact names, never filesystem locations. Reject
    # rooted paths and traversal segments before doing the suffix comparison;
    # otherwise a path that happens to end in the declared text could bind an
    # artifact from an unexpected directory.
    $segments = $declared.Split('/')
    if ([System.IO.Path]::IsPathRooted($declared) -or
        $declared -match '^[A-Za-z]:|:' -or
        $segments -contains '' -or
        $segments -contains '.' -or
        $segments -contains '..') {
        Add-Failure "$Label manifest path must be a non-empty relative path without rooted/traversal segments: $($Artifact.path)"
        return
    }

    $pathMatches = $actual.Equals($declared, [System.StringComparison]::OrdinalIgnoreCase) -or
        $actual.EndsWith('/' + $declared, [System.StringComparison]::OrdinalIgnoreCase)
    if (-not $pathMatches) {
        Add-Failure "$Label path does not match the manifest declaration: $($Artifact.path)"
    } else {
        Add-Note "$Label path matches the release evidence manifest."
    }
}

function Confirm-ManifestHash($Artifact, [string]$PathValue, [string]$Label) {
    if (-not $Artifact -or [string]::IsNullOrWhiteSpace($Artifact.sha256)) {
        Add-Failure "Release evidence manifest has no SHA-256 for $Label."
        return
    }

    $declaredHash = [string]$Artifact.sha256
    if ($declaredHash -notmatch '^[0-9a-fA-F]{64}$') {
        Add-Failure "$Label manifest SHA-256 must be exactly 64 hexadecimal characters."
        return
    }

    $actualHash = Get-Sha256 $PathValue
    if ($actualHash -ne $declaredHash.ToLowerInvariant()) {
        Add-Failure "$Label SHA-256 does not match the release evidence manifest."
    } else {
        Add-Note "$Label SHA-256 matches the release evidence manifest."
    }
}

if ($manifest -and $junitPath) {
    $junitArtifact = Get-ManifestArtifact $manifest 'mysql_control_smoke_junit'
    Confirm-ManifestPath $junitArtifact $junitPath 'MySQL control-smoke JUnit artifact'
    Confirm-ManifestHash $junitArtifact $junitPath 'MySQL control-smoke JUnit artifact'
}
if ($manifest -and $migrationPath) {
    $migrationArtifact = Get-ManifestArtifact $manifest 'mysql_migration_status'
    Confirm-ManifestPath $migrationArtifact $migrationPath 'MySQL migration-status evidence'
    Confirm-ManifestHash $migrationArtifact $migrationPath 'MySQL migration-status evidence'
}
if ($manifest -and $mysqlServerVersionPath) {
    $mysqlVersionArtifact = Get-ManifestArtifact $manifest 'mysql_server_version'
    Confirm-ManifestPath $mysqlVersionArtifact $mysqlServerVersionPath 'MySQL server-version evidence'
    Confirm-ManifestHash $mysqlVersionArtifact $mysqlServerVersionPath 'MySQL server-version evidence'
}

if ($manifest -and $backendJUnitPath) {
    $backendArtifact = Get-ManifestArtifact $manifest 'backend_full_suite_junit'
    Confirm-ManifestPath $backendArtifact $backendJUnitPath 'Backend full-suite JUnit artifact'
    Confirm-ManifestHash $backendArtifact $backendJUnitPath 'Backend full-suite JUnit artifact'
}
if ($manifest -and $backendLogPath) {
    $backendLogArtifact = Get-ManifestArtifact $manifest 'backend_full_suite_console_log'
    Confirm-ManifestPath $backendLogArtifact $backendLogPath 'Backend full-suite console log'
    Confirm-ManifestHash $backendLogArtifact $backendLogPath 'Backend full-suite console log'
}

if ($migrationPath) {
    $migrationText = Get-Content -Raw -LiteralPath $migrationPath
    $migrationDirectory = Join-Path $workspace 'backend/database/migrations'
    $sourceMigrationCount = 0
    if (Test-Path -LiteralPath $migrationDirectory -PathType Container) {
        $sourceMigrationCount = @(Get-ChildItem -LiteralPath $migrationDirectory -File -Filter '*.php').Count
    }
    # Laravel versions format the second column as either `Ran?` or the
    # current `Batch / Status`; both are valid migrate:status table headers.
    $hasMigrationNameHeader = $migrationText -match '(?im)^\s*Migration name'
    $hasStatusHeader = $migrationText -match '(?im)Ran\?' -or $migrationText -match '(?im)Batch\s*/\s*Status'
    # A header-only file is not migration evidence. Accept the two Laravel
    # status spellings used by supported versions (`[1] Ran` and table-style
    # `Ran`/`Yes`), but require at least one actual applied row.
    $hasAppliedRow = $migrationText -match '(?im)\[\s*\d+\s*\]\s*Ran\b' -or
        $migrationText -match '(?im)\|\s*(?:Ran|Yes)\s*\|'
    if (-not $hasMigrationNameHeader -or -not $hasStatusHeader -or -not $hasAppliedRow) {
        Add-Failure 'Migration-status evidence is not recognizable Laravel migrate:status output.'
    } elseif ($migrationText -match '(?im)^\s*\|\s*No\s*\||\bPending\b|No migrations found') {
        Add-Failure 'Migration-status evidence reports pending/unapplied migrations.'
    } elseif ($sourceMigrationCount -le 0) {
        Add-Failure 'The checked-out source has no migration files; migration evidence cannot be bound to a schema.'
    } else {
        # Laravel's compact renderer shortens the dot separator when a
        # migration name is long.  Depending on the terminal width, a row can
        # therefore contain many dots, one dot, or only whitespace before the
        # `[batch] Ran` marker.  Match the row boundary and status marker
        # rather than assuming a fixed separator width.
        $compactMigrationMatches = [regex]::Matches(
            $migrationText,
            '(?im)^\s*(?<name>\S+)\s+(?:\.{1,}\s+)?\[\s*\d+\s*\]\s*Ran\s*$'
        )
        $appliedMigrationNames = [System.Collections.Generic.List[string]]::new()
        if ($compactMigrationMatches.Count -gt 0) {
            foreach ($match in $compactMigrationMatches) {
                [void]$appliedMigrationNames.Add($match.Groups['name'].Value)
            }
        } else {
            # Laravel table output on some versions uses a pipe-delimited
            # `Yes`/`Ran` status rather than the compact `[1] Ran` form. Accept
            # both observed column orders, but still extract the migration
            # identity instead of relying on a row count alone.
            $tableMigrationMatches = [regex]::Matches(
                $migrationText,
                '(?im)^\s*\|\s*(?<name>[^|\r\n]+?)\s*\|\s*\d+\s*\|\s*(?:Ran|Yes)\s*\|\s*$'
            )
            if ($tableMigrationMatches.Count -eq 0) {
                $tableMigrationMatches = [regex]::Matches(
                    $migrationText,
                    '(?im)^\s*\|\s*(?:Ran|Yes)\s*\|\s*(?<name>[^|\r\n]+?)\s*\|(?:\s*\d+\s*\|)?\s*$'
                )
            }
            foreach ($match in $tableMigrationMatches) {
                [void]$appliedMigrationNames.Add($match.Groups['name'].Value.Trim())
            }
        }
        $appliedMigrationRows = $appliedMigrationNames.Count
        if ($appliedMigrationRows -ne $sourceMigrationCount) {
            Add-Failure "Migration-status evidence has $appliedMigrationRows applied rows, but the checked-out source contains $sourceMigrationCount migration files. Rerun the disposable migration smoke on this exact source."
        } elseif ($appliedMigrationRows -gt 0) {
            $sourceMigrationNames = @(Get-ChildItem -LiteralPath $migrationDirectory -File -Filter '*.php' | ForEach-Object { $_.BaseName })
            $sourceNameSet = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
            $appliedNameSet = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
            foreach ($name in $sourceMigrationNames) { [void]$sourceNameSet.Add($name) }
            foreach ($name in $appliedMigrationNames) { [void]$appliedNameSet.Add($name) }
            $missingNames = @($sourceMigrationNames | Where-Object { -not $appliedNameSet.Contains($_) })
            $unexpectedNames = @($appliedMigrationNames | Where-Object { -not $sourceNameSet.Contains($_) })
            $duplicateNames = @($appliedMigrationNames | Group-Object | Where-Object { $_.Count -gt 1 } | ForEach-Object { $_.Name })
            if ($missingNames.Count -gt 0 -or $unexpectedNames.Count -gt 0 -or $duplicateNames.Count -gt 0) {
                $missingSummary = if ($missingNames.Count -gt 0) { $missingNames -join ', ' } else { '<none>' }
                $unexpectedSummary = if ($unexpectedNames.Count -gt 0) { $unexpectedNames -join ', ' } else { '<none>' }
                $duplicateSummary = if ($duplicateNames.Count -gt 0) { $duplicateNames -join ', ' } else { '<none>' }
                Add-Failure "Migration-status evidence migration names do not exactly match the checked-out source. Missing=$missingSummary; unexpected=$unexpectedSummary; duplicate=$duplicateSummary."
            } else {
                Add-Note "Migration status evidence names exactly match all $appliedMigrationRows checked-out migration files. No migration command was executed by preflight."
            }
        } else {
            Add-Failure 'Migration-status evidence has no extractable applied migration names.'
        }
    }
}

if ($mysqlServerVersionPath) {
    $mysqlServerVersion = (Get-Content -Raw -LiteralPath $mysqlServerVersionPath).Trim()
    if ($mysqlServerVersion -notmatch '^8\.4\.\d+(?:[-+].*)?$') {
        Add-Failure "MySQL server-version evidence must report an 8.4.x server, received: $mysqlServerVersion"
    } else {
        Add-Note "MySQL server-version evidence confirms MySQL $mysqlServerVersion."
    }
}

# --- MySQL smoke JUnit --------------------------------------------------------
if ($junitPath) {
    try {
        $xml = Read-SafeXml $junitPath
        $root = $xml.DocumentElement
        if ($null -eq $root -or ($root.Name -ne 'testsuites' -and $root.Name -ne 'testsuite')) {
            throw 'Root element must be testsuites or testsuite.'
        }

        $summary = $root
        if (-not $summary.HasAttribute('tests')) {
            $summary = $xml.SelectSingleNode('/testsuites/testsuite[1]')
        }
        if ($null -eq $summary) { throw 'JUnit has no suite summary.' }
        $tests = Get-XmlIntAttribute $summary 'tests'
        $assertions = Get-XmlIntAttribute $summary 'assertions'
        $errors = Get-XmlIntAttribute $summary 'errors'
        $failed = Get-XmlIntAttribute $summary 'failures'
        $skipped = 0
        if ($summary.HasAttribute('skipped')) {
            $skipped = Get-XmlIntAttribute $summary 'skipped'
        }
        $testcaseNodes = $xml.SelectNodes('//testcase')
        $testcaseCount = $testcaseNodes.Count
        # SQLite cannot execute this row-lock test. Its explicit MySQL
        # selection is therefore a release coverage requirement, not merely
        # one interchangeable test counted toward the aggregate smoke floor.
        $requiredConcurrencyName = 'test_account_hierarchy_lock_blocks_a_second_mariadb_connection'
        $requiredConcurrencyCases = @($testcaseNodes | Where-Object {
            $_.GetAttribute('classname').Replace('\', '.') -ceq 'Tests.Feature.AccountHierarchyConcurrencyTest' -and
            $_.GetAttribute('name') -ceq $requiredConcurrencyName
        })
        $failureNodes = $xml.SelectNodes('//failure|//error').Count
        $skippedNodes = $xml.SelectNodes('//testcase/skipped').Count
        if ($tests -le 0) {
            Add-Failure 'MySQL control-smoke JUnit records zero tests.'
        } elseif ($assertions -le 0) {
            Add-Failure 'MySQL control-smoke JUnit records zero assertions; release evidence must contain substantive assertion results.'
        } elseif ($tests -lt $minimumMySqlSmokeTests) {
            Add-Failure "MySQL control-smoke JUnit is incomplete: expected at least $minimumMySqlSmokeTests tests, received $tests."
        } elseif ($testcaseCount -ne $tests) {
            Add-Failure "MySQL control-smoke JUnit test count does not match its testcase nodes: summary=$tests, testcase_nodes=$testcaseCount."
        } elseif ($skipped -ne 0 -or $skippedNodes -ne 0) {
            Add-Failure "MySQL control-smoke JUnit contains skipped tests: summary=$skipped, skipped_nodes=$skippedNodes."
        } elseif ($errors -ne 0 -or $failed -ne 0 -or $failureNodes -ne 0) {
            Add-Failure "MySQL control-smoke failed: tests=$tests, failures=$failed, errors=$errors, failure/error nodes=$failureNodes."
        } elseif ($requiredConcurrencyCases.Count -ne 1) {
            Add-Failure "MySQL control-smoke must contain exactly one required MySQL testcase AccountHierarchyConcurrencyTest::$requiredConcurrencyName; found $($requiredConcurrencyCases.Count)."
        } elseif ((Get-XmlIntAttribute $requiredConcurrencyCases[0] 'assertions') -le 0) {
            Add-Failure "The required MySQL testcase AccountHierarchyConcurrencyTest::$requiredConcurrencyName must record a positive assertion count."
        } else {
            Add-Note "MySQL control-smoke JUnit is valid and green: $tests testcase nodes, zero failures/errors/skips, including the required hierarchy concurrency test."
        }
    } catch {
        Add-Failure "MySQL control-smoke JUnit is invalid: $($_.Exception.Message)"
    }
}

# --- Full backend PHPUnit JUnit and matching console transcript --------------
$backendJUnitTests = $null
$backendJUnitAssertions = $null
if ($RequireBackendTest -or $backendJUnitPath) {
    if ($backendJUnitPath) {
        try {
            $xml = Read-SafeXml $backendJUnitPath
            $root = $xml.DocumentElement
            if ($null -eq $root -or ($root.Name -ne 'testsuites' -and $root.Name -ne 'testsuite')) {
                throw 'Root element must be testsuites or testsuite.'
            }

            $summary = $root
            if (-not $summary.HasAttribute('tests')) {
                $summary = $xml.SelectSingleNode('/testsuites/testsuite[1]')
            }
            if ($null -eq $summary) { throw 'JUnit has no suite summary.' }
            $tests = Get-XmlIntAttribute $summary 'tests'
            $backendJUnitTests = $tests
            if (-not $summary.HasAttribute('assertions')) {
                throw 'JUnit suite summary has no assertions count.'
            }
            $backendJUnitAssertions = Get-XmlIntAttribute $summary 'assertions'
            $errors = Get-XmlIntAttribute $summary 'errors'
            $failed = Get-XmlIntAttribute $summary 'failures'
            $skipped = 0
            if ($summary.HasAttribute('skipped')) {
                $skipped = Get-XmlIntAttribute $summary 'skipped'
            }
            $testcaseNodes = $xml.SelectNodes('//testcase')
            $testcaseCount = $testcaseNodes.Count
            $failureNodes = $xml.SelectNodes('//failure|//error').Count
            $skippedNodes = $xml.SelectNodes('//testcase/skipped').Count
            if ($tests -le 0) {
                Add-Failure 'Backend full-suite JUnit records zero tests.'
            } elseif ($backendJUnitAssertions -le 0) {
                Add-Failure 'Backend full-suite JUnit records zero assertions; release evidence must contain substantive assertion results.'
            } elseif ($testcaseCount -ne $tests) {
                Add-Failure "Backend full-suite JUnit test count does not match its testcase nodes: summary=$tests, testcase_nodes=$testcaseCount."
            } elseif ($skipped -ne 0 -or $skippedNodes -ne 0) {
                Add-Failure "Backend full-suite JUnit contains skipped tests: summary=$skipped, skipped_nodes=$skippedNodes."
            } elseif ($errors -ne 0 -or $failed -ne 0 -or $failureNodes -ne 0) {
                Add-Failure "Backend full-suite failed: tests=$tests, failures=$failed, errors=$errors, failure/error nodes=$failureNodes."
            } else {
                Add-Note "Backend full-suite JUnit is valid and green: $tests testcase nodes, zero failures/errors/skips."
            }
        } catch {
            Add-Failure "Backend full-suite JUnit is invalid: $($_.Exception.Message)"
        }
    }

    if ($backendLogPath -and $null -ne $backendJUnitTests -and $null -ne $backendJUnitAssertions) {
        try {
            $consoleText = Get-Content -Raw -LiteralPath $backendLogPath
            $consoleTests = $null
            $consoleAssertions = $null
            $summaryPatterns = @(
                # PHPUnit 11 reports warning-only tests before the passed
                # count (for example: `Tests: 1160 warnings, 15 passed`).
                # Warnings are not failures; still bind the complete test
                # total to JUnit so a truncated transcript cannot pass.
                @{ Pattern = '(?im)^\s*Tests:\s*(?:(\d+)\s+warnings?,\s*)?(\d+)\s+passed\s+\((\d+)\s+assertions?\)\s*$'; WarningsGroup = 1; PassedGroup = 2; AssertionsGroup = 3 },
                @{ Pattern = '(?im)^\s*OK\s+\((\d+)\s+tests?,\s+(\d+)\s+assertions?\)\s*$'; WarningsGroup = $null; PassedGroup = 1; AssertionsGroup = 2 }
            )
            foreach ($summaryPattern in $summaryPatterns) {
                $consoleMatch = [regex]::Match($consoleText, $summaryPattern.Pattern)
                if ($consoleMatch.Success) {
                    $passedTests = [int]$consoleMatch.Groups[$summaryPattern.PassedGroup].Value
                    $warningTests = 0
                    if ($null -ne $summaryPattern.WarningsGroup -and $consoleMatch.Groups[$summaryPattern.WarningsGroup].Success) {
                        $warningTests = [int]$consoleMatch.Groups[$summaryPattern.WarningsGroup].Value
                    }
                    $consoleTests = $warningTests + $passedTests
                    $consoleAssertions = [int]$consoleMatch.Groups[$summaryPattern.AssertionsGroup].Value
                    break
                }
            }
            if ($null -eq $consoleTests -or $null -eq $consoleAssertions) {
                Add-Failure 'Backend full-suite console log has no green PHPUnit summary in a supported form: Tests: N passed (M assertions) or OK (N tests, M assertions).'
            } else {
                if ($consoleTests -ne $backendJUnitTests -or $consoleAssertions -ne $backendJUnitAssertions) {
                    Add-Failure "Backend full-suite console/JUnit count mismatch: console=$consoleTests tests/$consoleAssertions assertions, JUnit=$backendJUnitTests tests/$backendJUnitAssertions assertions."
                } else {
                    Add-Note "Backend full-suite console log matches JUnit: $consoleTests tests/$consoleAssertions assertions."
                }
            }
        } catch {
            Add-Failure "Backend full-suite console log is invalid: $($_.Exception.Message)"
        }
    }
}

# --- Optional frontend build evidence; never invoke npm ----------------------
if ($RequireFrontendBuild -or -not [string]::IsNullOrWhiteSpace($FrontendBuildEvidence)) {
    $frontendPath = Resolve-ExistingFile $FrontendBuildEvidence 'Frontend production-build evidence'
    if ($frontendPath) {
        if ($manifest) {
            $frontendArtifact = Get-ManifestArtifact $manifest 'frontend_build'
            Confirm-ManifestPath $frontendArtifact $frontendPath 'Frontend production-build evidence'
            Confirm-ManifestHash $frontendArtifact $frontendPath 'Frontend production-build evidence'
        } else {
            Add-Failure 'Frontend production-build evidence cannot be release-bound without a valid evidence manifest.'
        }
        $buildText = Get-Content -Raw -LiteralPath $frontendPath
        if ($buildText -match '(?im)\b(error TS\d+|build failed|failed to compile)\b' -or $buildText -notmatch '(?im)built in|vite v.+building') {
            Add-Failure 'Frontend build evidence does not show a successful Vite production build.'
        } else {
            Add-Note 'Frontend production-build evidence was supplied and indicates success.'
        }
    }
} else {
    Write-Host 'INFO: frontend build was not requested; preflight did not run npm or inspect dist.' -ForegroundColor Yellow
}

Write-Host ''
if ($failures.Count -gt 0) {
    Write-Host "RELEASE PREFLIGHT FAILED ($($failures.Count) finding(s))." -ForegroundColor Red
    exit 1
}

Write-Host 'RELEASE PREFLIGHT PASSED. This proves only the supplied evidence, not production deployment, backup/restore, signing, or regulatory approval.' -ForegroundColor Green
exit 0
