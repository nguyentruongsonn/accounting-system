[CmdletBinding()]
param(
    [string]$OutputPath,
    [string]$OwnerArtifactManifest,
    [ValidateSet('internal-core', 'statutory-odr')]
    [string]$ReleaseProfile = 'internal-core'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$commonCriticalPaths = @(
    '.github/workflows/ci.yml',
    'backend/phpunit.xml',
    'backend/phpunit.mysql-control-smoke.xml',
    'scripts/release-preflight.ps1',
    'scripts/test-release-preflight.ps1',
    'scripts/release-candidate-inventory.ps1',
    'scripts/run-safe-backend-tests.ps1',
    'scripts/test-safe-backend-tests.ps1',
    'docs/RELEASE_PREFLIGHT.md',
    'docs/SAFE_BACKEND_TESTING.md',
    'frontend/package.json',
    'frontend/package-lock.json',
    'agents/manifest.json'
)
$internalCoreCriticalPaths = @(
    'scripts/run-internal-core-uat.ps1',
    'docs/INTERNAL_CORE_ARTIFACT_MANIFEST_SCHEMA.md',
    'docs/INTERNAL_CORE_ARTIFACT_MANIFEST_TEMPLATE.json',
    'docs/superpowers/plans/2026-09-05-internal-owner-confirmation.md',
    'docs/superpowers/plans/2026-09-05-internal-owner-technical-prefill.md',
    'docs/superpowers/plans/2026-09-05-mariadb-recovery-runbook.md'
)
$statutoryOdrCriticalPaths = @(
    'docs/SMALL_BUSINESS_RELEASE_SCOPE_2026-08-25.md',
    'docs/RELEASE_READINESS_AUDIT.md',
    'docs/OWNER_HANDOFF_QUICK_GUIDE_2026-08-24.md',
    'docs/OWNER_ARTIFACT_MANIFEST_SCHEMA_2026-08-24.md',
    'docs/OWNER_ARTIFACT_MANIFEST_TEMPLATE_2026-08-25.json',
    'docs/OWNER_DECISION_EVIDENCE_REGISTER_2026-08-23.md',
    'docs/OWNER_ARTIFACT_EVIDENCE_TEMPLATES_2026-08-24.md',
    'docs/ODR-05_TAX_EINVOICE_REGULATORY_DEPENDENCY_CHECKLIST_2026-08-24.md',
    'docs/TT99_MISA_P0_P1_GAP_REGISTER_2026-08-23.md',
    'docs/ACCOUNTING_CHAIN_REVIEW_2026-08-23.md',
    'docs/TECHNICAL_EVIDENCE_STATUS_SNAPSHOT_2026-08-25.md',
    'docs/SMB_CORE_PROCESS_ACCEPTANCE_MATRIX_2026-08-25.md',
    'docs/SME_PILOT_DECISION_2026-08-25.md',
    'docs/TT99_APPENDIX_I_IV_COVERAGE_AUDIT_2026-08-25.md'
)
$internalCoreArtifacts = @(
    [ordered]@{ id = 'INTERNAL_OWNER_UAT'; artifact = 'Internal owner-data UAT confirmation'; status = 'BLANK / TBD_BY_OWNER'; source = 'docs/superpowers/plans/2026-09-05-internal-owner-confirmation.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Business owner/admin and accountant complete and approve the real-data internal UAT checklist.' },
    [ordered]@{ id = 'DBA_BACKUP_RESTORE_REHEARSAL'; artifact = 'DBA-approved backup/restore rehearsal'; status = 'BLANK / TBD_BY_OWNER'; source = 'docs/superpowers/plans/2026-09-05-mariadb-recovery-runbook.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'DBA completes the approved MariaDB recovery runbook and records a successful isolated restore rehearsal.' }
)
$statutoryOdrArtifacts = @(
    [ordered]@{ id = 'ODR-01'; artifact = 'APPENDIX_IV_EXECUTION_PACK'; status = 'BLANK / TBD_BY_OWNER'; source = 'docs/OWNER_ARTIFACT_EVIDENCE_TEMPLATES_2026-08-24.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Owner supplies the effective-dated statutory statement/report catalogue, golden tie-outs, signing and retention evidence.' },
    [ordered]@{ id = 'ODR-02'; artifact = 'TT99_COA_VOUCHER_BOOK_CATALOGUE + POSTING_ROUTE_POLICY_COVERAGE'; status = 'BLANK / TBD_BY_OWNER'; source = 'docs/OWNER_ARTIFACT_EVIDENCE_TEMPLATES_2026-08-24.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Owner approves effective-dated tenant/regime mappings, route coverage, roles and hashes.' },
    [ordered]@{ id = 'ODR-03'; artifact = 'Signed migration manifest, exception register and cutover/forward-recovery evidence'; status = 'NOT PRESENT / OWNER-SUPPLIED'; source = 'docs/OWNER_DECISION_EVIDENCE_REGISTER_2026-08-23.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Owner supplies signed cutover, opening-balance tie-outs, deterministic exceptions and recovery authority.' },
    [ordered]@{ id = 'ODR-04'; artifact = 'APAR_SAME_CUTOFF_REDUCER_AND_TIE_OUT_PACK'; status = 'BLANK / TBD_BY_OWNER'; source = 'docs/OWNER_DECISION_EVIDENCE_REGISTER_2026-08-23.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Owner defines population, cutoff, settlement/reversal/FX scope, tolerance and close authority.' },
    [ordered]@{ id = 'ODR-05'; artifact = 'ODR-05_TAX_EINVOICE_REGULATORY_DEPENDENCY_CHECKLIST_2026-08-24.md + provider/legal package'; status = 'BLANK / TBD_BY_OWNER'; source = 'docs/ODR-05_TAX_EINVOICE_REGULATORY_DEPENDENCY_CHECKLIST_2026-08-24.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Owner supplies tax-law, provider, signature and authority evidence before enabling transport.' },
    [ordered]@{ id = 'ODR-06'; artifact = 'SOURCE_DOSSIER_CATALOGUE + approval/SoD, signature and retention policy'; status = 'BLANK / REQ-OPEN'; source = 'docs/OWNER_DECISION_EVIDENCE_REGISTER_2026-08-23.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Owner approves source dossier, maker-checker, legal-hold, export and retention rules.' },
    [ordered]@{ id = 'ZERO_NET_INVOICE_CONTROL_PACK'; artifact = 'ZERO_NET_INVOICE_CONTROL_PACK'; status = 'BLANK / TBD_BY_OWNER'; source = 'docs/OWNER_ARTIFACT_EVIDENCE_TEMPLATES_2026-08-24.md'; evidence_path = $null; provenance = $null; sha256 = $null; next_action = 'Owner decides posted-without-JE policy, AP/AR/report population, correction, reversal and audit semantics.' }
)
$criticalPathsByProfile = @{
    'internal-core' = @($commonCriticalPaths + $internalCoreCriticalPaths)
    'statutory-odr' = @($commonCriticalPaths + $statutoryOdrCriticalPaths)
}
$ownerArtifactsByProfile = @{
    'internal-core' = @($internalCoreArtifacts)
    'statutory-odr' = @($statutoryOdrArtifacts)
}
$criticalPaths = @($criticalPathsByProfile[$ReleaseProfile])
$ownerArtifacts = @($ownerArtifactsByProfile[$ReleaseProfile])

$ownerArtifactRows = @()
$ownerArtifactValidationFailures = @()
$ownerArtifactManifestMeta = [ordered]@{
    supplied = -not [string]::IsNullOrWhiteSpace($OwnerArtifactManifest)
    path = $null
    evidence_root = $null
    schema_version = $null
    release_profile = $null
    release_commit = $null
    schema_valid = $false
}

Push-Location $workspace
try {
    $insideGit = (& git rev-parse --is-inside-work-tree 2>$null).Trim()
    if ($LASTEXITCODE -ne 0 -or $insideGit -ne 'true') {
        throw 'Workspace is not a Git worktree.'
    }

    $head = (& git rev-parse HEAD 2>$null).Trim().ToLowerInvariant()
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($head)) {
        throw 'Unable to resolve the current Git HEAD.'
    }

    $statusLines = @(& git status --porcelain=v1 --untracked-files=all 2>$null)
    # Keep this a real array on clean checkouts. PowerShell unwraps an empty
    # pipeline to `$null`, which would make the later `.Count` gate throw
    # under StrictMode instead of returning a deterministic clean snapshot.
    $status = @(
        foreach ($line in $statusLines) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.Length -lt 4) { continue }
        $path = $line.Substring(3)
        if ($path.StartsWith('"') -and $path.EndsWith('"')) {
            $path = $path.Substring(1, $path.Length - 2)
        }
        [ordered]@{
            code = $line.Substring(0, 2)
            path = $path.Replace('\', '/')
        }
        }
    )

    # `git ls-files` describes the index, which can include staged additions
    # that are not part of the immutable commit being audited. Resolve the
    # paths against HEAD so `tracked_at_head` cannot overstate release scope.
    $trackedAtHead = @(& git ls-tree -r --name-only $head -- $criticalPaths 2>$null) |
        ForEach-Object { $_.Replace('\', '/') }
    $critical = foreach ($path in $criticalPaths) {
        $normalized = $path.Replace('\', '/')
        $entry = @($status | Where-Object { $_.path -eq $normalized }) | Select-Object -First 1
        [ordered]@{
            path = $normalized
            exists = Test-Path -LiteralPath (Join-Path $workspace $normalized) -PathType Leaf
            tracked_at_head = $trackedAtHead -contains $normalized
            worktree_status = if ($null -eq $entry) { 'clean' } else { $entry.code }
        }
    }

    function Test-SafeOwnerRelativePath {
        param([object]$Value)
        if ($null -eq $Value) { return $false }
        $text = ([string]$Value).Trim().Replace('\', '/')
        if ([string]::IsNullOrWhiteSpace($text) -or [System.IO.Path]::IsPathRooted($text)) { return $false }
        if ($text.StartsWith('/') -or $text -match '(^|/)\.(\.?)(/|$)') { return $false }
        return $true
    }

    $ownerArtifactRows = @()
    $ownerArtifactValidationFailures = @()
    $allowedOwnerArtifactStatuses = @(
        'APPROVED',
        'BLANK / TBD_BY_OWNER',
        'NOT PRESENT / OWNER-SUPPLIED',
        'BLANK / REQ-OPEN'
    )
    $ownerArtifactInputRows = @($ownerArtifacts)

    if (-not [string]::IsNullOrWhiteSpace($OwnerArtifactManifest)) {
        $ownerArtifactManifestMeta.supplied = $true
        $manifestPathText = ([string]$OwnerArtifactManifest).Trim().Replace('\', '/')
        $ownerArtifactManifestMeta.path = $manifestPathText
        $manifestFullPath = $null
        if ([System.IO.Path]::IsPathRooted($manifestPathText)) {
            # The owner package may be supplied outside the checkout. The
            # manifest itself is an explicit input; only evidence_path values
            # are constrained below to prevent package traversal.
            $manifestFullPath = [System.IO.Path]::GetFullPath($manifestPathText)
        } elseif (-not (Test-SafeOwnerRelativePath $manifestPathText)) {
            $ownerArtifactValidationFailures += 'Owner artifact manifest path must be workspace-relative or an explicit absolute path; traversal is not allowed.'
        } else {
            $manifestFullPath = [System.IO.Path]::GetFullPath((Join-Path $workspace $manifestPathText))
        }
        if ($null -ne $manifestFullPath) {
            if (-not (Test-Path -LiteralPath $manifestFullPath -PathType Leaf)) {
                $ownerArtifactValidationFailures += "Owner artifact manifest does not exist: $manifestPathText."
            } else {
                try {
                    $ownerArtifactManifestMeta.path = $manifestFullPath.Replace('\', '/')
                    $ownerArtifactManifestMeta.evidence_root = (Split-Path -Parent $manifestFullPath).Replace('\', '/')
                    $manifest = Get-Content -LiteralPath $manifestFullPath -Raw | ConvertFrom-Json
                    $ownerArtifactManifestMeta.schema_version = $manifest.schema_version
                    $ownerArtifactManifestMeta.release_profile = if ($manifest.PSObject.Properties.Name -contains 'release_profile') { [string]$manifest.release_profile } else { '' }
                    $ownerArtifactManifestMeta.release_commit = ([string]$manifest.release_commit).ToLowerInvariant()
                    if ([int]$manifest.schema_version -ne 1) {
                        $ownerArtifactValidationFailures += 'Owner artifact manifest schema_version must be 1.'
                    }
                    if ($ownerArtifactManifestMeta.release_profile -ne $ReleaseProfile) {
                        $ownerArtifactValidationFailures += "Owner artifact manifest release_profile does not match selected profile '$ReleaseProfile'."
                    }
                    if ($ownerArtifactManifestMeta.release_commit -ne $head) {
                        $ownerArtifactValidationFailures += 'Owner artifact manifest release_commit does not match the immutable HEAD.'
                    }
                    $manifestRows = @($manifest.artifacts)
                    if ($manifestRows.Count -ne $ownerArtifacts.Count) {
                        $ownerArtifactValidationFailures += "Owner artifact manifest must contain exactly $($ownerArtifacts.Count) canonical rows for profile '$ReleaseProfile'."
                    }
                    $canonicalById = @{}
                    foreach ($canonical in $ownerArtifacts) { $canonicalById[[string]$canonical.id] = $canonical }
                    $seenIds = @{}
                    $ownerArtifactInputRows = @()
                    foreach ($row in $manifestRows) {
                        if ($null -eq $row) { $ownerArtifactValidationFailures += 'Owner artifact manifest contains a null row.'; continue }
                        $id = if ($row.PSObject.Properties.Name -contains 'id') { [string]$row.id } else { '' }
                        if (-not $canonicalById.ContainsKey($id)) {
                            $ownerArtifactValidationFailures += "Owner artifact manifest contains an unknown artifact id '$id'."
                            continue
                        }
                        if ($seenIds.ContainsKey($id)) {
                            $ownerArtifactValidationFailures += "Owner artifact manifest contains duplicate artifact id '$id'."
                            continue
                        }
                        $seenIds[$id] = $true
                        $canonical = $canonicalById[$id]
                        if ([string]$row.artifact -ne [string]$canonical.artifact) {
                            $ownerArtifactValidationFailures += "${id}: artifact label does not match the canonical owner register."
                        }
                        $ownerArtifactInputRows += [ordered]@{
                            id = $id
                            artifact = [string]$row.artifact
                            status = [string]$row.status
                            source = $canonical.source
                            evidence_path = if ($row.PSObject.Properties.Name -contains 'evidence_path') { $row.evidence_path } else { $null }
                            provenance = if ($row.PSObject.Properties.Name -contains 'provenance') { $row.provenance } else { $null }
                            sha256 = if ($row.PSObject.Properties.Name -contains 'sha256') { $row.sha256 } else { $null }
                            next_action = $canonical.next_action
                        }
                    }
                    foreach ($canonical in $ownerArtifacts) {
                        if (-not $seenIds.ContainsKey([string]$canonical.id)) {
                            $ownerArtifactValidationFailures += "Owner artifact manifest is missing canonical artifact id '$($canonical.id)'."
                        }
                    }
                    if ($ownerArtifactValidationFailures.Count -eq 0) { $ownerArtifactManifestMeta.schema_valid = $true }
                } catch {
                    $ownerArtifactValidationFailures += "Owner artifact manifest is invalid JSON: $($_.Exception.Message)"
                }
            }
        }
    }

    foreach ($ownerArtifact in $ownerArtifactInputRows) {
        $sourcePath = [string]$ownerArtifact.source
        $evidencePath = if ($ownerArtifact.Contains('evidence_path')) { $ownerArtifact.evidence_path } else { $null }
        $provenance = if ($ownerArtifact.Contains('provenance')) { $ownerArtifact.provenance } else { $null }
        $sha256 = if ($ownerArtifact.Contains('sha256')) { $ownerArtifact.sha256 } else { $null }
        $sourceExists = Test-Path -LiteralPath (Join-Path $workspace $sourcePath) -PathType Leaf
        $safeEvidencePath = Test-SafeOwnerRelativePath $evidencePath
        $evidenceRoot = if (-not [string]::IsNullOrWhiteSpace([string]$ownerArtifactManifestMeta.evidence_root)) { [string]$ownerArtifactManifestMeta.evidence_root } else { $workspace }
        $evidenceFullPath = if ($safeEvidencePath) { Join-Path $evidenceRoot ([string]$evidencePath) } else { $null }
        $evidenceExists = $safeEvidencePath -and (Test-Path -LiteralPath $evidenceFullPath -PathType Leaf)
        $hashValid = -not [string]::IsNullOrWhiteSpace([string]$sha256) -and ([string]$sha256 -match '^[0-9a-fA-F]{64}$')
        $hashMatches = $false
        if ($evidenceExists -and $hashValid) {
            try { $hashMatches = ((Get-FileHash -Algorithm SHA256 -LiteralPath $evidenceFullPath).Hash -eq ([string]$sha256).ToUpperInvariant()) } catch { $hashMatches = $false }
        }
        $approved = ([string]$ownerArtifact.status -eq 'APPROVED')
        $gate = if ($approved -and $sourceExists -and $safeEvidencePath -and $evidenceExists -and -not [string]::IsNullOrWhiteSpace([string]$provenance) -and $hashValid -and $hashMatches) { 'PASS' } else { 'BLOCKED' }
        if (-not $sourceExists) { $ownerArtifactValidationFailures += "$($ownerArtifact.id): source register/template is missing ($sourcePath)." }
        if (-not ($allowedOwnerArtifactStatuses -contains [string]$ownerArtifact.status)) { $ownerArtifactValidationFailures += "$($ownerArtifact.id): unsupported owner-artifact status '$($ownerArtifact.status)'." }
        if (-not $approved) { $ownerArtifactValidationFailures += "$($ownerArtifact.id): status is not APPROVED; owner evidence cannot pass the release gate." }
        elseif (-not $safeEvidencePath -or -not $evidenceExists) { $ownerArtifactValidationFailures += "$($ownerArtifact.id): approved artifact evidence_path is missing, unsafe or does not exist." }
        elseif ([string]::IsNullOrWhiteSpace([string]$provenance)) { $ownerArtifactValidationFailures += "$($ownerArtifact.id): approved artifact provenance is missing." }
        elseif (-not $hashValid) { $ownerArtifactValidationFailures += "$($ownerArtifact.id): approved artifact sha256 is not a 64-character hexadecimal hash." }
        elseif (-not $hashMatches) { $ownerArtifactValidationFailures += "$($ownerArtifact.id): approved artifact sha256 does not match evidence_path." }
        $ownerArtifactRows += [ordered]@{ id=$ownerArtifact.id; artifact=$ownerArtifact.artifact; status=$ownerArtifact.status; source=$sourcePath; source_exists=$sourceExists; evidence_path=$evidencePath; evidence_exists=$evidenceExists; provenance=$provenance; sha256=$sha256; sha256_valid=($hashValid -and $hashMatches); gate=$gate; next_action=$ownerArtifact.next_action }
    }
    if ($ownerArtifactInputRows.Count -ne $ownerArtifacts.Count) { $ownerArtifactValidationFailures += "Owner artifact inventory must contain exactly $($ownerArtifacts.Count) canonical rows for profile '$ReleaseProfile'." }
    $ownerArtifactsGatePassed = $ownerArtifactValidationFailures.Count -eq 0 -and @($ownerArtifactRows | Where-Object { $_.gate -ne 'PASS' }).Count -eq 0

    $result = [ordered]@{
        schema_version = 1
        release_profile = $ReleaseProfile
        generated_at = (Get-Date).ToUniversalTime().ToString('o')
        head = $head
        clean = ($status.Count -eq 0)
        status_count = $status.Count
        critical_paths = $critical
        owner_artifacts = $ownerArtifactRows
        owner_artifacts_gate_passed = $ownerArtifactsGatePassed
        owner_artifact_validation_failures = @($ownerArtifactValidationFailures)
        owner_artifact_manifest = $ownerArtifactManifestMeta
        untracked_count = @($status | Where-Object { $_.code -eq '??' }).Count
        modified_or_staged_count = @($status | Where-Object { $_.code -ne '??' }).Count
        release_ready = ($status.Count -eq 0 -and @($critical | Where-Object { -not $_.exists -or -not $_.tracked_at_head }).Count -eq 0 -and $ownerArtifactsGatePassed)
        next_action = 'Review/select intended files, create an immutable release commit, then rerun release-preflight.'
    }

    $json = $result | ConvertTo-Json -Depth 8
    if ([string]::IsNullOrWhiteSpace($OutputPath)) {
        Write-Output $json
    } else {
        # Relative output paths are repository-root relative. Preserve an
        # explicitly absolute path instead of joining it to the workspace;
        # PowerShell otherwise produces an invalid value such as
        # `workspace\C:\tmp\inventory.json` on Windows.
        $outputPathText = ([string]$OutputPath).Trim()
        $resolvedOutput = if ([System.IO.Path]::IsPathRooted($outputPathText)) {
            [System.IO.Path]::GetFullPath($outputPathText)
        } else {
            [System.IO.Path]::GetFullPath((Join-Path $workspace $outputPathText))
        }
        $outputParent = Split-Path -Parent $resolvedOutput
        if (-not (Test-Path -LiteralPath $outputParent -PathType Container)) {
            throw "Output directory does not exist: $outputParent"
        }
        [System.IO.File]::WriteAllText($resolvedOutput, $json)
        Write-Output "Wrote release-candidate inventory: $resolvedOutput"
    }
} finally {
    Pop-Location
}
