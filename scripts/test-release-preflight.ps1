[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$preflight = Join-Path $PSScriptRoot 'release-preflight.ps1'
$ciWorkflow = Join-Path $workspace '.github/workflows/ci.yml'
$inventoryScript = Join-Path $workspace 'scripts/release-candidate-inventory.ps1'
$internalManifestSchema = Join-Path $workspace 'docs/INTERNAL_CORE_ARTIFACT_MANIFEST_SCHEMA.md'
$internalManifestTemplate = Join-Path $workspace 'docs/INTERNAL_CORE_ARTIFACT_MANIFEST_TEMPLATE.json'
$commit = (& git -C $workspace rev-parse HEAD 2>$null).Trim().ToLowerInvariant()
$tempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('release-preflight-test-' + [guid]::NewGuid().ToString('N'))
$generatedInventoryOutputPaths = @()

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "ASSERTION FAILED: $Message" }
}

function Invoke-OwnerInventory([string]$ManifestPath) {
    $output = (& pwsh -NoLogo -NoProfile -File $inventoryScript -OwnerArtifactManifest $ManifestPath 2>&1 | Out-String)
    Assert-True ($LASTEXITCODE -eq 0) "owner artifact inventory invocation should complete: $output"
    try {
        return ($output | ConvertFrom-Json)
    } catch {
        throw "owner artifact inventory must emit JSON: $($_.Exception.Message)`n$output"
    }
}

function Write-Manifest(
    [string]$Path,
    [string]$JunitManifestPath,
    [string]$MigrationManifestPath,
    [string]$JunitHash,
    [string]$MigrationHash,
    [string]$MySqlVersionManifestPath,
    [string]$MySqlVersionHash,
    [string]$BackendManifestPath,
    [string]$BackendHash,
    [string]$CiRunId = 'test-run-001'
) {
    $manifest = [ordered]@{
        schema_version = 1
        release_commit = $commit
        ci_run_id = $CiRunId
        artifacts = [ordered]@{
            mysql_control_smoke_junit = [ordered]@{
                path = $JunitManifestPath
                sha256 = $JunitHash
            }
            mysql_migration_status = [ordered]@{
                path = $MigrationManifestPath
                sha256 = $MigrationHash
            }
            mysql_server_version = [ordered]@{
                path = $MySqlVersionManifestPath
                sha256 = $MySqlVersionHash
            }
            backend_full_suite_junit = [ordered]@{
                path = $BackendManifestPath
                sha256 = $BackendHash
            }
        }
    }
    if (-not [string]::IsNullOrWhiteSpace($script:BackendLogManifestPath)) {
        $manifest.artifacts.backend_full_suite_console_log = [ordered]@{
            path = $script:BackendLogManifestPath
            sha256 = $script:BackendLogHash
        }
    }
    [System.IO.File]::WriteAllText($Path, ($manifest | ConvertTo-Json -Depth 8))
}

function Invoke-Preflight([string]$Manifest, [string]$JunitPath, [string]$MigrationPath, [string]$MySqlVersionPath, [string]$BackendPath, [string]$ExpectedCiRunId = '') {
    $backendLogPath = Join-Path (Split-Path -Parent $BackendPath) 'backend.log'
    $arguments = @(
        '-NoLogo', '-NoProfile', '-File', $preflight,
        '-ReleaseCommit', $commit,
        '-EvidenceManifest', $Manifest,
        '-MySqlSmokeJUnit', $JunitPath,
        '-MigrationStatusEvidence', $MigrationPath,
        '-MySqlServerVersionEvidence', $MySqlVersionPath,
        '-BackendTestJUnit', $BackendPath,
        '-BackendTestLog', $backendLogPath,
        '-RequireBackendTest',
        '-AllowDirtyWorktree'
    )
    if (-not [string]::IsNullOrWhiteSpace($ExpectedCiRunId)) {
        $arguments += @('-ExpectedCiRunId', $ExpectedCiRunId)
    }
    $output = (& pwsh @arguments 2>&1 | Out-String)
    [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = $output }
}

New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
try {
    Assert-True (Test-Path -LiteralPath $ciWorkflow -PathType Leaf) 'CI workflow is present for evidence-discovery regression'
    Assert-True (Test-Path -LiteralPath $inventoryScript -PathType Leaf) 'release-candidate inventory script is present'
    Assert-True (Test-Path -LiteralPath $internalManifestSchema -PathType Leaf) 'internal-core manifest schema is present'
    Assert-True (Test-Path -LiteralPath $internalManifestTemplate -PathType Leaf) 'internal-core manifest template is present'
    $inventoryText = [System.IO.File]::ReadAllText($inventoryScript)
    $preflightText = [System.IO.File]::ReadAllText($preflight)
    $smokeConfigText = [System.IO.File]::ReadAllText((Join-Path $workspace 'backend/phpunit.mysql-control-smoke.xml'))
    $internalTemplate = Get-Content -Raw -LiteralPath $internalManifestTemplate | ConvertFrom-Json
    $internalTemplateIds = @($internalTemplate.artifacts | ForEach-Object { $_.id })
    Assert-True ($inventoryText -match 'INTERNAL_OWNER_UAT') 'inventory must define the internal owner UAT evidence row'
    Assert-True ($inventoryText -match 'DBA_BACKUP_RESTORE_REHEARSAL') 'inventory must define the DBA rehearsal evidence row'
    Assert-True ($internalTemplate.release_profile -eq 'internal-core') 'internal evidence template must bind the internal-core profile'
    Assert-True (@($internalTemplate.artifacts).Count -eq 2) 'internal evidence template must contain exactly two rows'
    Assert-True (($internalTemplateIds -contains 'INTERNAL_OWNER_UAT') -and ($internalTemplateIds -contains 'DBA_BACKUP_RESTORE_REHEARSAL')) 'internal evidence template must expose the canonical IDs'
    foreach ($requiredInventoryPath in @(
        'docs/OWNER_DECISION_EVIDENCE_REGISTER_2026-08-23.md',
        'docs/OWNER_ARTIFACT_EVIDENCE_TEMPLATES_2026-08-24.md',
        'docs/OWNER_ARTIFACT_MANIFEST_SCHEMA_2026-08-24.md',
        'docs/OWNER_ARTIFACT_MANIFEST_TEMPLATE_2026-08-25.json',
        'docs/ODR-05_TAX_EINVOICE_REGULATORY_DEPENDENCY_CHECKLIST_2026-08-24.md',
        'docs/TT99_MISA_P0_P1_GAP_REGISTER_2026-08-23.md',
        'docs/ACCOUNTING_CHAIN_REVIEW_2026-08-23.md',
        'docs/TECHNICAL_EVIDENCE_STATUS_SNAPSHOT_2026-08-25.md',
        'docs/SMB_CORE_PROCESS_ACCEPTANCE_MATRIX_2026-08-25.md',
        'docs/SME_PILOT_DECISION_2026-08-25.md',
        'docs/TT99_APPENDIX_I_IV_COVERAGE_AUDIT_2026-08-25.md',
        'frontend/package.json',
        'frontend/package-lock.json'
    )) {
        Assert-True ($inventoryText -match [regex]::Escape($requiredInventoryPath)) "release-candidate inventory must include $requiredInventoryPath"
    }
    foreach ($requiredSmokeTestPath in @(
        'tests/Feature/CommercialDraftAccountEvidenceGateTest.php',
        'tests/Feature/InventoryValuationClosedPeriodIntegrityTest.php',
        'tests/Feature/InventoryValuationTenantBoundaryTest.php'
    )) {
        Assert-True ($smokeConfigText -match ("<file>" + [regex]::Escape($requiredSmokeTestPath) + "</file>")) "MySQL smoke configuration must include $requiredSmokeTestPath"
        Assert-True ($preflightText -match [regex]::Escape($requiredSmokeTestPath)) "release preflight must require $requiredSmokeTestPath"
    }
    $inventorySnapshot = (& pwsh -NoLogo -NoProfile -File $inventoryScript | ConvertFrom-Json)
    $ownerRows = @($inventorySnapshot.owner_artifacts)
    $ownerIds = @($ownerRows | ForEach-Object { $_.id })
    Assert-True ($inventorySnapshot.release_profile -eq 'internal-core') 'inventory must default to the internal-core release profile'
    Assert-True ($ownerRows.Count -eq 2) 'internal-core inventory must expose exactly two canonical owner rows'
    Assert-True (($ownerIds -contains 'INTERNAL_OWNER_UAT') -and ($ownerIds -contains 'DBA_BACKUP_RESTORE_REHEARSAL')) 'internal-core inventory must expose the UAT and DBA evidence IDs'
    Assert-True (-not ($ownerIds -contains 'ODR-01')) 'internal-core inventory must not require statutory ODR evidence'
    $internalCriticalPathNames = @($inventorySnapshot.critical_paths | ForEach-Object { $_.path })
    Assert-True (-not ($internalCriticalPathNames -contains 'backend/storage/test-results/phpunit-full-release-gate-2026-08-25.xml')) 'internal-core inventory must not require generated backend JUnit output to be tracked at HEAD'
    Assert-True (-not ($internalCriticalPathNames -contains 'backend/storage/test-results/phpunit-full-release-gate-2026-08-25.log')) 'internal-core inventory must not require generated backend console output to be tracked at HEAD'
    Assert-True (@($ownerRows | Where-Object { $_.status -eq 'APPROVED' }).Count -eq 0) 'owner inventory must not infer approval'
    Assert-True ($inventorySnapshot.owner_artifacts_gate_passed -eq $false) 'release-candidate inventory must fail closed while canonical owner rows are not APPROVED'
    Assert-True (@($ownerRows | Where-Object { $_.PSObject.Properties.Name -contains 'evidence_path' -and $_.PSObject.Properties.Name -contains 'provenance' -and $_.PSObject.Properties.Name -contains 'sha256' }).Count -eq $ownerRows.Count) 'owner inventory rows must expose evidence path, provenance and sha256 fields'
    Assert-True (@($inventorySnapshot.owner_artifact_validation_failures).Count -gt 0) 'open owner rows must produce explicit validation failures'
    Assert-True ($inventorySnapshot.release_ready -eq $false) 'release-candidate inventory must not report release readiness while owner evidence is open'
    Assert-True ($inventoryText -match '\[string\]\$OwnerArtifactManifest') 'release-candidate inventory must support an optional owner artifact manifest'
    Assert-True ($inventoryText -match 'Test-SafeOwnerRelativePath') 'owner evidence paths must be validated as safe workspace-relative paths'
    Assert-True ($inventoryText -match 'release_commit does not match') 'owner manifest commit provenance must fail closed on mismatch'
    Assert-True ($inventoryText -match 'sha256 does not match evidence_path') 'owner manifest hash binding must fail closed on mismatch'
    Assert-True ($inventoryText -match 'IsPathRooted\(\$outputPathText\)') 'inventory output must distinguish explicit absolute paths from workspace-relative paths'

    # Use an existing tracked parent directory even in a fresh checkout.
    $relativeInventoryOutput = "release-inventory-regression-$PID.json"
    $relativeInventoryOutputFullPath = Join-Path $workspace $relativeInventoryOutput
    $generatedInventoryOutputPaths += $relativeInventoryOutputFullPath
    $relativeInventoryOutputText = (& pwsh -NoLogo -NoProfile -File $inventoryScript -OutputPath $relativeInventoryOutput 2>&1 | Out-String)
    Assert-True ($LASTEXITCODE -eq 0) "relative inventory output path should succeed: $relativeInventoryOutputText"
    Assert-True (Test-Path -LiteralPath $relativeInventoryOutputFullPath -PathType Leaf) 'relative inventory output must resolve from the repository root'
    $relativeInventoryJson = Get-Content -Raw -LiteralPath $relativeInventoryOutputFullPath | ConvertFrom-Json
    Assert-True ($relativeInventoryJson.schema_version -eq 1) 'relative inventory output must contain the inventory schema'

    $absoluteInventoryOutputFullPath = Join-Path $tempRoot 'inventory-absolute.json'
    $generatedInventoryOutputPaths += $absoluteInventoryOutputFullPath
    $absoluteInventoryOutputText = (& pwsh -NoLogo -NoProfile -File $inventoryScript -OutputPath $absoluteInventoryOutputFullPath 2>&1 | Out-String)
    Assert-True ($LASTEXITCODE -eq 0) "absolute inventory output path should succeed: $absoluteInventoryOutputText"
    Assert-True (Test-Path -LiteralPath $absoluteInventoryOutputFullPath -PathType Leaf) 'absolute inventory output must be preserved instead of joined to the repository root'
    $absoluteInventoryJson = Get-Content -Raw -LiteralPath $absoluteInventoryOutputFullPath | ConvertFrom-Json
    Assert-True ($absoluteInventoryJson.schema_version -eq 1) 'absolute inventory output must contain the inventory schema'

    # Exercise the optional owner package against synthetic files outside the
    # checkout. These fixtures are test inputs only: they contain no owner
    # approval, accounting mapping or regulatory decision and are deleted in
    # the finally block below.
    $ownerPackageRoot = Join-Path $tempRoot 'owner-artifact-package'
    $ownerEvidenceRoot = Join-Path $ownerPackageRoot 'evidence'
    New-Item -ItemType Directory -Path $ownerEvidenceRoot -Force | Out-Null
    $ownerManifestPath = Join-Path $ownerPackageRoot 'owner-artifacts.json'
    $ownerManifestRows = @()
    foreach ($ownerRow in $ownerRows) {
        $evidenceName = ([string]$ownerRow.id).Replace('/', '_') + '.txt'
        $evidencePath = 'evidence/' + $evidenceName
        $evidenceFullPath = Join-Path $ownerPackageRoot ($evidencePath.Replace('/', [System.IO.Path]::DirectorySeparatorChar))
        [System.IO.File]::WriteAllText($evidenceFullPath, "synthetic owner-package fixture for $($ownerRow.id)`n")
        $ownerManifestRows += [ordered]@{
            id = [string]$ownerRow.id
            artifact = [string]$ownerRow.artifact
            status = 'APPROVED'
            evidence_path = $evidencePath
            provenance = "test://synthetic-owner-package/$($ownerRow.id)"
            sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $evidenceFullPath).Hash.ToLowerInvariant()
        }
    }
    $ownerManifestObject = [ordered]@{
        schema_version = 1
        release_profile = 'internal-core'
        release_commit = $commit
        artifacts = @($ownerManifestRows)
    }
    [System.IO.File]::WriteAllText($ownerManifestPath, ($ownerManifestObject | ConvertTo-Json -Depth 8))
    $ownerValid = Invoke-OwnerInventory $ownerManifestPath
    Assert-True ($ownerValid.owner_artifacts_gate_passed -eq $true) 'valid internal owner artifact package should pass metadata gate'
    Assert-True (@($ownerValid.owner_artifacts | Where-Object { $_.gate -eq 'PASS' }).Count -eq $ownerRows.Count) 'valid internal owner package must pass both rows'
    Assert-True ($ownerValid.owner_artifact_manifest.schema_valid -eq $true) 'valid owner package schema must be marked valid'

    $ownerBadHashPath = Join-Path $ownerPackageRoot 'owner-artifacts-bad-hash.json'
    $ownerBadHash = Get-Content -Raw -LiteralPath $ownerManifestPath | ConvertFrom-Json
    $ownerBadHash.artifacts[0].sha256 = ('0' * 64)
    $ownerBadHash | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $ownerBadHashPath -Encoding utf8
    $ownerBadHashResult = Invoke-OwnerInventory $ownerBadHashPath
    Assert-True ($ownerBadHashResult.owner_artifacts_gate_passed -eq $false) 'owner evidence hash mismatch must fail closed'
    Assert-True (@($ownerBadHashResult.owner_artifact_validation_failures | Where-Object { $_ -match 'sha256 does not match evidence_path' }).Count -gt 0) 'owner evidence hash mismatch must be explicit'

    $ownerBadCommitPath = Join-Path $ownerPackageRoot 'owner-artifacts-bad-commit.json'
    $ownerBadCommit = Get-Content -Raw -LiteralPath $ownerManifestPath | ConvertFrom-Json
    $ownerBadCommit.release_commit = '0' * 40
    $ownerBadCommit | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $ownerBadCommitPath -Encoding utf8
    $ownerBadCommitResult = Invoke-OwnerInventory $ownerBadCommitPath
    Assert-True ($ownerBadCommitResult.owner_artifacts_gate_passed -eq $false) 'owner manifest commit mismatch must fail closed'
    Assert-True (@($ownerBadCommitResult.owner_artifact_validation_failures | Where-Object { $_ -match 'release_commit does not match' }).Count -gt 0) 'owner manifest commit mismatch must be explicit'

    $ownerDuplicatePath = Join-Path $ownerPackageRoot 'owner-artifacts-duplicate.json'
    $ownerDuplicate = Get-Content -Raw -LiteralPath $ownerManifestPath | ConvertFrom-Json
    $ownerDuplicate.artifacts = @($ownerDuplicate.artifacts) + @($ownerDuplicate.artifacts[0])
    $ownerDuplicate | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $ownerDuplicatePath -Encoding utf8
    $ownerDuplicateResult = Invoke-OwnerInventory $ownerDuplicatePath
    Assert-True ($ownerDuplicateResult.owner_artifacts_gate_passed -eq $false) 'duplicate owner artifact IDs must fail closed'
    Assert-True (@($ownerDuplicateResult.owner_artifact_validation_failures | Where-Object { $_ -match 'duplicate artifact id|exactly 2 canonical' }).Count -gt 0) 'duplicate owner artifact IDs must be explicit'

    $ownerProfileMismatchPath = Join-Path $ownerPackageRoot 'owner-artifacts-profile-mismatch.json'
    $ownerProfileMismatch = Get-Content -Raw -LiteralPath $ownerManifestPath | ConvertFrom-Json
    $ownerProfileMismatch.release_profile = 'statutory-odr'
    $ownerProfileMismatch | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $ownerProfileMismatchPath -Encoding utf8
    $ownerProfileMismatchResult = Invoke-OwnerInventory $ownerProfileMismatchPath
    Assert-True ($ownerProfileMismatchResult.owner_artifacts_gate_passed -eq $false) 'owner manifest profile mismatch must fail closed'
    Assert-True (@($ownerProfileMismatchResult.owner_artifact_validation_failures | Where-Object { $_ -match 'release_profile does not match' }).Count -gt 0) 'owner manifest profile mismatch must be explicit'

    $ownerMissingPath = Join-Path $ownerPackageRoot 'owner-artifacts-missing.json'
    $ownerMissingResult = Invoke-OwnerInventory $ownerMissingPath
    Assert-True ($ownerMissingResult.owner_artifacts_gate_passed -eq $false) 'missing owner manifest must fail closed'
    Assert-True (@($ownerMissingResult.owner_artifact_validation_failures | Where-Object { $_ -match 'does not exist' }).Count -gt 0) 'missing owner manifest must be explicit'

    $ownerTraversalPath = Join-Path $ownerPackageRoot 'owner-artifacts-traversal.json'
    $ownerTraversal = Get-Content -Raw -LiteralPath $ownerManifestPath | ConvertFrom-Json
    $ownerTraversal.artifacts[0].evidence_path = '../outside-owner-evidence.txt'
    $ownerTraversal | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $ownerTraversalPath -Encoding utf8
    $ownerTraversalResult = Invoke-OwnerInventory $ownerTraversalPath
    Assert-True ($ownerTraversalResult.owner_artifacts_gate_passed -eq $false) 'owner evidence traversal path must fail closed'
    Assert-True (@($ownerTraversalResult.owner_artifact_validation_failures | Where-Object { $_ -match 'evidence_path is missing, unsafe or does not exist' }).Count -gt 0) 'owner evidence traversal path must be explicit'

    $statutorySnapshot = (& pwsh -NoLogo -NoProfile -File $inventoryScript -ReleaseProfile statutory-odr | ConvertFrom-Json)
    $statutoryIds = @($statutorySnapshot.owner_artifacts | ForEach-Object { $_.id })
    Assert-True ($statutorySnapshot.release_profile -eq 'statutory-odr') 'statutory profile must identify itself'
    Assert-True (@($statutorySnapshot.owner_artifacts).Count -eq 7) 'statutory profile must retain seven compatibility rows'
    Assert-True ($statutoryIds -contains 'ODR-01') 'statutory profile must retain ODR-01'
    Assert-True (-not (@($statutorySnapshot.critical_paths | Where-Object { $_.path -eq 'scripts/run-internal-core-uat.ps1' }).Count -gt 0)) 'statutory profile must not inherit the internal UAT runner'

    $internalSchemaText = [System.IO.File]::ReadAllText($internalManifestSchema)
    Assert-True ($internalSchemaText -match '(?s)"id": "INTERNAL_OWNER_UAT".*?"id": "DBA_BACKUP_RESTORE_REHEARSAL"') 'internal manifest schema example must include both canonical artifact rows'

    $ciText = [System.IO.File]::ReadAllText($ciWorkflow)
    $findEvidenceBlock = [regex]::Match($ciText, '(?s)function Find-Evidence\(\[string\]\$Root, \[string\]\$Name\) \{.*?\r?\n\s*\}', [System.Text.RegularExpressions.RegexOptions]::None)
    Assert-True $findEvidenceBlock.Success 'CI workflow contains the expected evidence-discovery function'
    Assert-True ($findEvidenceBlock.Value -match '\$matches\s*=\s*@\(') 'evidence discovery must collect every matching artifact before selecting one'
    Assert-True ($findEvidenceBlock.Value -match '\$matches\.Count\s*-gt\s*1') 'evidence discovery must detect duplicate artifact names'
    Assert-True ($findEvidenceBlock.Value -match 'Ambiguous release evidence') 'duplicate artifact names must fail with an explicit ambiguity error'
    Assert-True ($ciText -match '\$backendConsoleLog\s*=\s*Find-Evidence[\s\S]*?phpunit\.log') 'CI release preflight must discover the backend console log'
    Assert-True ($ciText -match 'backend_full_suite_console_log') 'CI release preflight must hash-bind the backend console log'
    Assert-True ($ciText -match '-BackendTestLog\s+\$backendConsoleLog') 'CI release preflight must pass the backend console log to the validator'
    Assert-True ($ciText -match 'tee\s+storage/test-results/phpunit\.log') 'backend PHPUnit must emit the console log at the release-contract path'
    Assert-True ($ciText -match 'backend/storage/test-results') 'backend evidence artifact must upload the release-contract test-results directory'
    Assert-True ($ciText -match 'release-candidate-inventory\.ps1') 'CI release preflight must execute the release-candidate inventory gate'
    Assert-True ($ciText -match '-ReleaseProfile' -and $ciText -match "'internal-core'") 'CI release preflight must explicitly select the internal-core release profile'
    Assert-True ($ciText -match 'RELEASE_OWNER_ARTIFACT_MANIFEST') 'CI release preflight must support an optional owner artifact manifest input'
    Assert-True ($ciText -match 'RELEASE_OWNER_ARTIFACT_MANIFEST:\s*\$\{\{\s*vars\.RELEASE_OWNER_ARTIFACT_MANIFEST\s*\}\}') 'CI release preflight must expose the owner manifest repository variable'
    Assert-True ($ciText -match 'owner_artifacts_gate_passed\s*-ne\s*\$true') 'CI release preflight must fail closed when owner artifacts are not approved'
    Assert-True ($ciText -match 'owner_artifact_validation_failures') 'CI release preflight must surface owner artifact gate failures'

    # Entirely synthetic validator inputs, created only in this test's unique
    # temporary directory. These do not record any MySQL/PHPUnit execution and
    # must never be published as CI or owner evidence. Historical local
    # artifacts are neither required nor modified by this regression harness.
    $junitFixture = Join-Path $tempRoot 'mysql-control-smoke-valid.junit.xml'
    $requiredConcurrencyCase = '<testcase name="test_account_hierarchy_lock_blocks_a_second_mariadb_connection" class="Tests\Feature\AccountHierarchyConcurrencyTest" classname="Tests.Feature.AccountHierarchyConcurrencyTest" assertions="1" time="0.01" />'
    $syntheticCases = 1..110 | ForEach-Object {
        '<testcase name="test_synthetic_validator_input_{0}" classname="SyntheticValidatorFixture" assertions="1" time="0.01" />' -f $_
    }
    $junitText = @"
<?xml version="1.0" encoding="UTF-8"?>
<!-- SYNTHETIC VALIDATOR FIXTURE ONLY. Not a database or PHPUnit execution result. -->
<testsuites tests="111" assertions="111" errors="0" failures="0" skipped="0" time="1.11">
  <testsuite name="Synthetic validator fixture" tests="111" assertions="111" errors="0" failures="0" skipped="0" time="1.11">
    $requiredConcurrencyCase
    $($syntheticCases -join [Environment]::NewLine)
  </testsuite>
</testsuites>
"@
    [System.IO.File]::WriteAllText($junitFixture, $junitText)
    $junitHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $junitFixture).Hash.ToLowerInvariant()
    # Generate synthetic migration rows from filenames, without a database.
    $migrationFixture = Join-Path $tempRoot 'mysql-migrate-status-all-ran.log'
    $migrationText = "SYNTHETIC VALIDATOR FIXTURE ONLY: no migrations were executed.`n  Migration name ................................................ Batch / Status`n"
    $sourceMigrationFiles = @(Get-ChildItem (Join-Path $workspace 'backend/database/migrations') -File -Filter '*.php')
    foreach ($migrationFile in $sourceMigrationFiles) {
        $migrationName = [System.IO.Path]::GetFileNameWithoutExtension($migrationFile.Name)
        $migrationText += "  $migrationName ...................................................................................... [1] Ran`n"
    }
    [System.IO.File]::WriteAllText($migrationFixture, $migrationText)
    $migrationHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $migrationFixture).Hash.ToLowerInvariant()
    $mysqlVersionFixture = Join-Path $tempRoot 'mysql-server-version.log'
    [System.IO.File]::WriteAllText($mysqlVersionFixture, "8.4.7`n")
    $mysqlVersionHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $mysqlVersionFixture).Hash.ToLowerInvariant()
    $backendFixture = Join-Path $tempRoot 'backend.junit.xml'
    $backendXml = @'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites tests="1" assertions="1" errors="0" failures="0" skipped="0" time="0.01">
  <testsuite name="Backend" tests="1" assertions="1" errors="0" failures="0" skipped="0" time="0.01">
    <testcase name="test_backend_contract" classname="BackendContractTest" assertions="1" time="0.01" />
  </testsuite>
</testsuites>
'@
    [System.IO.File]::WriteAllText($backendFixture, $backendXml.TrimStart())
    $backendHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $backendFixture).Hash.ToLowerInvariant()
    $backendLogFixture = Join-Path $tempRoot 'backend.log'
    [System.IO.File]::WriteAllText($backendLogFixture, "OK (1 test, 1 assertion)`n")
    $script:BackendLogManifestPath = 'backend.log'
    $script:BackendLogHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $backendLogFixture).Hash.ToLowerInvariant()
    $validManifest = Join-Path $tempRoot 'release-evidence.json'
    Write-Manifest $validManifest 'mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-all-ran.log' $junitHash $migrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash

    $valid = Invoke-Preflight $validManifest $junitFixture $migrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($valid.ExitCode -eq 0) "valid evidence should pass: $($valid.Output)"
    Assert-True ($valid.Output -match 'testcase nodes') 'valid run reports testcase-node validation'

    # PHPUnit 11 may classify warning-only tests before the passed count. The
    # validator must accept that green summary while still requiring the
    # warning-plus-passed total to match the authoritative JUnit count.
    [System.IO.File]::WriteAllText($backendLogFixture, "Tests:    0 warnings, 1 passed (1 assertion)`n")
    $script:BackendLogHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $backendLogFixture).Hash.ToLowerInvariant()
    $warningSummaryManifest = Join-Path $tempRoot 'release-evidence-warning-summary.json'
    $warningSummaryObject = Get-Content -Raw -LiteralPath $validManifest | ConvertFrom-Json
    $warningSummaryObject.artifacts.backend_full_suite_console_log.sha256 = $script:BackendLogHash
    $warningSummaryObject | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $warningSummaryManifest -Encoding utf8
    $warningSummary = Invoke-Preflight $warningSummaryManifest $junitFixture $migrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($warningSummary.ExitCode -eq 0) "warning-only PHPUnit summary should pass: $($warningSummary.Output)"
    Assert-True ($warningSummary.Output -match 'console log matches JUnit') 'warning-only summary reports the JUnit cross-check'
    [System.IO.File]::WriteAllText($backendLogFixture, "OK (1 test, 1 assertion)`n")
    $script:BackendLogHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $backendLogFixture).Hash.ToLowerInvariant()

    # Replacing the required case preserves aggregate counts and green suite
    # totals. A count-only validator must not accept the resulting coverage gap.
    $concurrencyMutations = @(
        @{ Name = 'missing'; Case = $requiredConcurrencyCase.Replace('Tests\Feature\AccountHierarchyConcurrencyTest', 'SyntheticUnrelatedFixture').Replace('Tests.Feature.AccountHierarchyConcurrencyTest', 'SyntheticUnrelatedFixture'); Failure = 'required MySQL testcase' },
        @{ Name = 'wrong-method'; Case = $requiredConcurrencyCase.Replace('test_account_hierarchy_lock_blocks_a_second_mariadb_connection', 'test_unrelated_method'); Failure = 'required MySQL testcase' },
        @{ Name = 'zero-assertions'; Case = $requiredConcurrencyCase.Replace('assertions="1"', 'assertions="0"'); Failure = 'required MySQL testcase' },
        @{ Name = 'skipped'; Case = $requiredConcurrencyCase.Replace(' />', '><skipped message="synthetic skipped fixture" /></testcase>'); Failure = 'skipped tests|required MySQL testcase' },
        @{ Name = 'failed'; Case = $requiredConcurrencyCase.Replace(' />', '><failure message="synthetic failed fixture" /></testcase>'); Failure = 'MySQL control-smoke failed|required MySQL testcase' },
        @{ Name = 'duplicated'; Case = $requiredConcurrencyCase + $requiredConcurrencyCase; Failure = 'required MySQL testcase' }
    )
    foreach ($mutation in $concurrencyMutations) {
        $mutationName = 'mysql-concurrency-' + $mutation.Name + '.junit.xml'
        $mutationPath = Join-Path $tempRoot $mutationName
        $mutatedJunit = $junitText.Replace($requiredConcurrencyCase, $mutation.Case)
        if ($mutation.Name -eq 'duplicated') {
            # Replace one unrelated node with the duplicate so aggregate
            # counts remain valid and only testcase identity can reject it.
            $mutatedJunit = $mutatedJunit.Replace($syntheticCases[0], '')
        }
        [System.IO.File]::WriteAllText($mutationPath, $mutatedJunit)
        $mutationHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $mutationPath).Hash.ToLowerInvariant()
        $mutationManifest = Join-Path $tempRoot ('release-evidence-concurrency-' + $mutation.Name + '.json')
        Write-Manifest $mutationManifest $mutationName 'mysql-migrate-status-all-ran.log' $mutationHash $migrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash
        $mutationResult = Invoke-Preflight $mutationManifest $mutationPath $migrationFixture $mysqlVersionFixture $backendFixture
        Assert-True ($mutationResult.ExitCode -ne 0) "required MySQL concurrency case $($mutation.Name) must fail closed"
        Assert-True ($mutationResult.Output -match $mutation.Failure) "required MySQL concurrency case $($mutation.Name) must produce an explicit coverage failure: $($mutationResult.Output)"
    }

    # A green-looking one-test JUnit with no assertions is not substantive
    # release evidence. Bind the zero-assertion fixture to its own transcript
    # hash so this regression exercises the validator, not hash failure.
    $zeroAssertionBackendFixture = Join-Path $tempRoot 'backend-zero-assertions.junit.xml'
    $zeroAssertionBackendXml = $backendXml -replace 'assertions="1"', 'assertions="0"'
    [System.IO.File]::WriteAllText($zeroAssertionBackendFixture, $zeroAssertionBackendXml.TrimStart())
    $zeroAssertionBackendHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $zeroAssertionBackendFixture).Hash.ToLowerInvariant()
    [System.IO.File]::WriteAllText($backendLogFixture, "OK (1 test, 0 assertions)`n")
    $script:BackendLogHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $backendLogFixture).Hash.ToLowerInvariant()
    $zeroAssertionManifest = Join-Path $tempRoot 'release-evidence-zero-assertions.json'
    Write-Manifest $zeroAssertionManifest 'mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-all-ran.log' $junitHash $migrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend-zero-assertions.junit.xml' $zeroAssertionBackendHash
    $zeroAssertion = Invoke-Preflight $zeroAssertionManifest $junitFixture $migrationFixture $mysqlVersionFixture $zeroAssertionBackendFixture
    Assert-True ($zeroAssertion.ExitCode -ne 0) 'backend JUnit with zero assertions should fail'
    Assert-True ($zeroAssertion.Output -match 'zero assertions') 'zero-assertion evidence failure should be explicit'
    [System.IO.File]::WriteAllText($backendLogFixture, "OK (1 test, 1 assertion)`n")
    $script:BackendLogHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $backendLogFixture).Hash.ToLowerInvariant()

    # Bind a deliberately inconsistent transcript to its own manifest hash so
    # this regression proves the console/JUnit cross-check, not only hashing.
    [System.IO.File]::WriteAllText($backendLogFixture, "OK (1 test, 2 assertions)`n")
    $consoleMismatchManifest = Join-Path $tempRoot 'release-evidence-console-mismatch.json'
    $consoleMismatchObject = Get-Content -Raw -LiteralPath $validManifest | ConvertFrom-Json
    $consoleMismatchObject.artifacts.backend_full_suite_console_log.sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $backendLogFixture).Hash.ToLowerInvariant()
    $consoleMismatchObject | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $consoleMismatchManifest -Encoding utf8
    $consoleMismatch = Invoke-Preflight $consoleMismatchManifest $junitFixture $migrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($consoleMismatch.ExitCode -ne 0) 'backend console/JUnit count mismatch should fail'
    Assert-True ($consoleMismatch.Output -match 'console/JUnit count mismatch') 'backend console/JUnit mismatch failure should be explicit'
    [System.IO.File]::WriteAllText($backendLogFixture, "OK (1 test, 1 assertion)`n")

    $missingCiRunManifest = Join-Path $tempRoot 'release-evidence-missing-ci-run.json'
    $manifestWithoutCiRun = Get-Content -Raw -LiteralPath $validManifest | ConvertFrom-Json
    $manifestWithoutCiRun.PSObject.Properties.Remove('ci_run_id')
    $manifestWithoutCiRun | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $missingCiRunManifest -Encoding utf8
    $missingCiRun = Invoke-Preflight $missingCiRunManifest $junitFixture $migrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($missingCiRun.ExitCode -ne 0) 'manifest without ci_run_id should fail'
    Assert-True ($missingCiRun.Output -match 'ci_run_id provenance') 'missing ci_run_id failure should be explicit'

    $wrongCiRun = Invoke-Preflight $validManifest $junitFixture $migrationFixture $mysqlVersionFixture $backendFixture 'different-run-999'
    Assert-True ($wrongCiRun.ExitCode -ne 0) 'manifest with a mismatched expected CI run should fail'
    Assert-True ($wrongCiRun.Output -match 'ci_run_id does not match') 'CI run provenance mismatch should be explicit'

    $latestMigrationName = ($sourceMigrationFiles | Sort-Object Name | Select-Object -Last 1).BaseName
    $staleMigrationFixture = Join-Path $tempRoot 'mysql-migrate-status-stale-count.log'
    $latestMigrationPattern = '(?im)^\s*' + [regex]::Escape($latestMigrationName) + '\s+\.{2,}\s+\[\s*\d+\s*\]\s*Ran\s*(?:\r?\n|$)'
    $staleMigrationText = [regex]::Replace($migrationText, $latestMigrationPattern, '')
    Assert-True ($staleMigrationText.Length -lt $migrationText.Length) 'synthetic migration fixture contains the latest source migration row'
    [System.IO.File]::WriteAllText($staleMigrationFixture, $staleMigrationText)
    $staleMigrationHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $staleMigrationFixture).Hash.ToLowerInvariant()
    $staleMigrationManifest = Join-Path $tempRoot 'release-evidence-stale-migration-count.json'
    Write-Manifest $staleMigrationManifest 'mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-stale-count.log' $junitHash $staleMigrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash
    $staleMigration = Invoke-Preflight $staleMigrationManifest $junitFixture $staleMigrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($staleMigration.ExitCode -ne 0) 'migration evidence with a stale applied-row count should fail'
    Assert-True ($staleMigration.Output -match 'applied rows') 'stale migration count failure should be explicit'

    $renamedMigrationFixture = Join-Path $tempRoot 'mysql-migrate-status-renamed-row.log'
    $renamedMigrationText = $migrationText -replace [regex]::Escape($latestMigrationName), '9999_01_01_000000_unexpected_migration'
    [System.IO.File]::WriteAllText($renamedMigrationFixture, $renamedMigrationText)
    $renamedMigrationHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $renamedMigrationFixture).Hash.ToLowerInvariant()
    $renamedMigrationManifest = Join-Path $tempRoot 'release-evidence-renamed-migration.json'
    Write-Manifest $renamedMigrationManifest 'mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-renamed-row.log' $junitHash $renamedMigrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash
    $renamedMigration = Invoke-Preflight $renamedMigrationManifest $junitFixture $renamedMigrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($renamedMigration.ExitCode -ne 0) 'migration evidence with an unexpected applied migration name should fail'
    Assert-True ($renamedMigration.Output -match 'migration names do not exactly match') 'migration-name mismatch failure should be explicit'

    $missingBackend = Invoke-Preflight $validManifest $junitFixture $migrationFixture $mysqlVersionFixture (Join-Path $tempRoot 'missing-backend.junit.xml')
    Assert-True ($missingBackend.ExitCode -ne 0) 'missing backend JUnit should fail'
    Assert-True ($missingBackend.Output -match 'Backend full-suite JUnit artifact') 'missing backend evidence failure should be explicit'

    $invalidJUnit = Join-Path $tempRoot 'mysql-accounting-controls-invalid.junit.xml'
    $junitText = [System.IO.File]::ReadAllText($junitFixture)
    $firstCase = [regex]::Match($junitText, '<testcase\b[^>]*/>')
    Assert-True $firstCase.Success 'JUnit fixture contains a self-closing testcase node'
    $junitText = $junitText.Remove($firstCase.Index, $firstCase.Length)
    [System.IO.File]::WriteAllText($invalidJUnit, $junitText)
    $invalidHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $invalidJUnit).Hash.ToLowerInvariant()
    $invalidManifest = Join-Path $tempRoot 'release-evidence-invalid.json'
    Write-Manifest $invalidManifest 'mysql-accounting-controls-invalid.junit.xml' 'mysql-migrate-status-all-ran.log' $invalidHash $migrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash

    $countMismatch = Invoke-Preflight $invalidManifest $invalidJUnit $migrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($countMismatch.ExitCode -ne 0) 'JUnit with a missing testcase node should fail'
    Assert-True ($countMismatch.Output -match 'test count does not match') 'count mismatch should be explicit'

    $unsafeManifest = Join-Path $tempRoot 'release-evidence-unsafe.json'
    Write-Manifest $unsafeManifest '../mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-all-ran.log' $junitHash $migrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash
    $unsafePath = Invoke-Preflight $unsafeManifest $junitFixture $migrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($unsafePath.ExitCode -ne 0) 'manifest traversal path should fail'
    Assert-True ($unsafePath.Output -match 'non-empty relative path') 'unsafe path failure should be explicit'

    $badHashManifest = Join-Path $tempRoot 'release-evidence-bad-hash.json'
    Write-Manifest $badHashManifest 'mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-all-ran.log' 'not-a-sha256' $junitHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash
    $badHash = Invoke-Preflight $badHashManifest $junitFixture $migrationFixture $mysqlVersionFixture $backendFixture
    Assert-True ($badHash.ExitCode -ne 0) 'malformed manifest hash should fail'
    Assert-True ($badHash.Output -match 'exactly 64 hexadecimal') 'malformed hash failure should be explicit'

    $wrongVersionFixture = Join-Path $tempRoot 'mysql-server-version-wrong.log'
    [System.IO.File]::WriteAllText($wrongVersionFixture, "8.0.41`n")
    $wrongVersionHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $wrongVersionFixture).Hash.ToLowerInvariant()
    $wrongVersionManifest = Join-Path $tempRoot 'release-evidence-wrong-mysql-version.json'
    Write-Manifest $wrongVersionManifest 'mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-all-ran.log' $junitHash $migrationHash 'mysql-server-version-wrong.log' $wrongVersionHash 'backend.junit.xml' $backendHash
    $wrongVersion = Invoke-Preflight $wrongVersionManifest $junitFixture $migrationFixture $wrongVersionFixture $backendFixture
    Assert-True ($wrongVersion.ExitCode -ne 0) 'non-8.4 MySQL server evidence should fail'
    Assert-True ($wrongVersion.Output -match 'must report an 8\.4\.x server') 'server-version failure should be explicit'

    $headerOnlyMigration = Join-Path $tempRoot 'mysql-migrate-status-header-only.log'
    [System.IO.File]::WriteAllText($headerOnlyMigration, "  Migration name ................................................ Batch / Status`n")
    $headerOnlyMigrationHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $headerOnlyMigration).Hash.ToLowerInvariant()
    $headerOnlyManifest = Join-Path $tempRoot 'release-evidence-header-only-migration.json'
    Write-Manifest $headerOnlyManifest 'mysql-control-smoke-valid.junit.xml' 'mysql-migrate-status-header-only.log' $junitHash $headerOnlyMigrationHash 'mysql-server-version.log' $mysqlVersionHash 'backend.junit.xml' $backendHash
    $headerOnly = Invoke-Preflight $headerOnlyManifest $junitFixture $headerOnlyMigration $mysqlVersionFixture $backendFixture
    Assert-True ($headerOnly.ExitCode -ne 0) 'header-only migration status should fail closed'
    Assert-True ($headerOnly.Output -match 'not recognizable Laravel migrate:status output') 'header-only migration failure should be explicit'

    Write-Host 'Release preflight regression checks passed using synthetic fixtures only: owner manifest valid/hash/commit/duplicate/missing/traversal gates, relative/absolute inventory output paths, required MySQL concurrency coverage, backend console/JUnit count mismatch, missing/mismatched CI run provenance, migration count/name mismatch, testcase-count mismatch, unsafe path, malformed hash, MySQL-version mismatch, header-only migration status, and ambiguous CI artifact discovery guard. This is validator test coverage, not current CI or owner evidence.' -ForegroundColor Green
} finally {
    foreach ($generatedInventoryOutputPath in $generatedInventoryOutputPaths) {
        if (Test-Path -LiteralPath $generatedInventoryOutputPath -PathType Leaf) {
            Remove-Item -LiteralPath $generatedInventoryOutputPath -Force
        }
    }
    if (Test-Path -LiteralPath $tempRoot) {
        $resolvedTempRoot = (Resolve-Path -LiteralPath $tempRoot).Path
        $expectedTempRoot = [System.IO.Path]::GetFullPath($tempRoot)
        if ($resolvedTempRoot -ne $expectedTempRoot -or (Split-Path -Leaf $resolvedTempRoot) -notmatch '^release-preflight-test-[a-f0-9]{32}$') {
            throw "Refusing cleanup outside the generated regression directory: $resolvedTempRoot"
        }
        Remove-Item -LiteralPath $resolvedTempRoot -Recurse -Force
    }
}
