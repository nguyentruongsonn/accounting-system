[CmdletBinding()]
param(
    [string]$SourceDatabase = 'accounting_backup_restore_source',
    [string]$RestoreDatabase = 'accounting_backup_restore_restore',
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 3306,
    [string]$Username = 'root',
    [string]$Password = $env:DB_PASSWORD,
    [string]$MySqlExe = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$MySqlDumpExe = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$ArtifactDirectory = (Join-Path ([System.IO.Path]::GetTempPath()) 'accounting-disposable-backup-restore')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $workspace 'backend'
New-Item -ItemType Directory -Force -Path $ArtifactDirectory | Out-Null
$dumpPath = Join-Path $ArtifactDirectory 'disposable-evidence.sql'
$sourceDigest = Join-Path $ArtifactDirectory 'source-integrity.tsv'
$restoreDigest = Join-Path $ArtifactDirectory 'restore-integrity.tsv'
$sourceTriggers = Join-Path $ArtifactDirectory 'source-trigger-inventory.tsv'
$restoreTriggers = Join-Path $ArtifactDirectory 'restore-trigger-inventory.tsv'
$sourceRoutines = Join-Path $ArtifactDirectory 'source-routine-inventory.tsv'
$restoreRoutines = Join-Path $ArtifactDirectory 'restore-routine-inventory.tsv'
$sourceEvents = Join-Path $ArtifactDirectory 'source-event-inventory.tsv'
$restoreEvents = Join-Path $ArtifactDirectory 'restore-event-inventory.tsv'
$recoveryContractBlocker = Join-Path $ArtifactDirectory 'recovery-contract-blocker.txt'
$definerPolicyBlocker = Join-Path $ArtifactDirectory 'definer-policy-blocker.txt'
$runStatus = Join-Path $ArtifactDirectory 'run-status.txt'

# Prevent a failed rerun from leaving an older green artifact that can be
# mistaken for current evidence. These are the only named files this harness
# owns; the two disposable databases remain intentionally retained.
foreach ($artifact in @(
    $dumpPath, $sourceDigest, $restoreDigest, $sourceTriggers, $restoreTriggers,
    $sourceRoutines, $restoreRoutines, $sourceEvents, $restoreEvents,
    $recoveryContractBlocker, $definerPolicyBlocker, $runStatus
)) {
    if (Test-Path -LiteralPath $artifact -PathType Leaf) {
        Remove-Item -LiteralPath $artifact -Force
    }
}

# This destructive harness is intentionally incapable of naming an application
# or production database. Its two exact, disposable database names are part of
# the safety contract; do not widen this pattern for convenience. Validate
# those names and client binaries only after stale evidence has been removed,
# so an invalid invocation cannot leave an older green status artifact behind.
function Stop-Blocked([string]$Reason) {
    [System.IO.File]::WriteAllText($runStatus, "STATUS=BLOCKED`nREASON=$Reason`n")
    throw $Reason
}

$allowed = @('accounting_backup_restore_source', 'accounting_backup_restore_restore')
if ($SourceDatabase -notin $allowed -or $RestoreDatabase -notin $allowed -or $SourceDatabase -eq $RestoreDatabase) {
    Stop-Blocked 'Only the distinct disposable databases accounting_backup_restore_source and accounting_backup_restore_restore are allowed.'
}
# Database-name allowlisting alone is not enough: the same names could exist on
# a remote server. This harness is intentionally local-only and destructive for
# its two disposable databases, so fail closed before any client invocation
# unless the target is an explicit loopback host.
$allowedLoopbackHosts = @('127.0.0.1', 'localhost', '::1', '[::1]')
if ($DbHost -notin $allowedLoopbackHosts) {
    Stop-Blocked 'This local-only harness accepts only loopback DbHost values (127.0.0.1, localhost or ::1).'
}
foreach ($binary in @($MySqlExe, $MySqlDumpExe)) {
    if (-not (Test-Path -LiteralPath $binary -PathType Leaf)) { Stop-Blocked "Required MariaDB client binary not found: $binary" }
}

[System.IO.File]::WriteAllText($runStatus, "STATUS=RUNNING`n")

function Invoke-MySql([string]$Database, [string]$Sql, [switch]$NoDatabase) {
    $args = @('--protocol=TCP', "--host=$DbHost", "--port=$Port", "--user=$Username", '--batch', '--skip-column-names')
    if (-not $NoDatabase) { $args += "--database=$Database" }
    $args += @('--execute', $Sql)
    $previous = $env:MYSQL_PWD
    try {
        $env:MYSQL_PWD = $Password
        $result = & $MySqlExe @args 2>&1
        if ($LASTEXITCODE -ne 0) { throw "MariaDB command failed: $result" }
        return ($result | Out-String)
    } finally { $env:MYSQL_PWD = $previous }
}

function Quote-DatabaseName([string]$Database) {
    # Database names are already allowlisted above. Keep quoting explicit so a
    # future edit cannot accidentally turn these DDL statements into a broad
    # target selector.
    return '`' + $Database.Replace('`', '``') + '`'
}

function Assert-TriggerRejects([string]$Database, [string]$Sql, [string]$Label) {
    $args = @('--protocol=TCP', "--host=$DbHost", "--port=$Port", "--user=$Username", "--database=$Database", '--execute', $Sql)
    $previous = $env:MYSQL_PWD
    $output = ''
    $exitCode = 0
    try {
        $env:MYSQL_PWD = $Password
        try {
            $output = (& $MySqlExe @args 2>&1 | Out-String)
            $exitCode = $LASTEXITCODE
        } catch {
            # PowerShell 7 promotes a non-zero native exit code to a
            # NativeCommandError when ErrorActionPreference=Stop. A trigger
            # rejecting the tamper statement is the expected result here, so
            # preserve the native diagnostic and validate it below instead of
            # treating the expected rejection as a harness failure.
            $exitCode = 1
            $output = $_ | Out-String
        }
        if ($exitCode -eq 0 -or $output -notmatch '(?i)append-only|immutable') {
            throw "$Label was not rejected by its database trigger: $output"
        }
    } finally { $env:MYSQL_PWD = $previous }
}

function Get-IntegrityDigest([string]$Database, [string]$Path) {
    $sql = @"
SELECT 'audit_logs', COUNT(*), COALESCE(SHA2(GROUP_CONCAT(CONCAT_WS('|', id, company_id, action, model_type, model_id) ORDER BY id SEPARATOR '\n'), 256), '') FROM audit_logs WHERE action = 'backup_restore.fixture'
UNION ALL SELECT 'e_invoice_documents', COUNT(*), COALESCE(SHA2(GROUP_CONCAT(CONCAT_WS('|', id, company_id, accounting_document_type, accounting_document_id, payload_hash) ORDER BY id SEPARATOR '\n'), 256), '') FROM e_invoice_documents WHERE document_reference = 'backup-restore-fixture'
UNION ALL SELECT 'report_runs', COUNT(*), COALESCE(SHA2(GROUP_CONCAT(CONCAT_WS('|', id, company_id, fiscal_year_id, report, control_hash, output_hash) ORDER BY id SEPARATOR '\n'), 256), '') FROM report_runs WHERE output_identity = 'backup-restore-fixture';
"@
    [System.IO.File]::WriteAllText($Path, (Invoke-MySql $Database $sql).Trim()+"`n")
}

function Get-TriggerInventory([string]$Database, [string]$Path) {
    # Compare the complete trigger metadata/body inventory, not only the
    # representative fixture tables. This still does not prove that every
    # trigger was exercised, but it detects an incomplete or altered restore.
    $sql = @"
    SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION,
       DEFINER, ACTION_STATEMENT
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
ORDER BY TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION;
"@
    [System.IO.File]::WriteAllText($Path, (Invoke-MySql $Database $sql).Trim()+"`n")
}

function Get-RoutineInventory([string]$Database, [string]$Path) {
    # Do not silently omit routines when mysql.proc/information_schema is
    # unavailable. A metadata error is a restore-contract blocker.
    $sql = @"
SELECT ROUTINE_NAME, ROUTINE_TYPE, DEFINER
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
ORDER BY ROUTINE_NAME, ROUTINE_TYPE;
"@
    try {
        [System.IO.File]::WriteAllText($Path, (Invoke-MySql $Database $sql).Trim()+"`n")
    } catch {
        [System.IO.File]::WriteAllText($recoveryContractBlocker, "ROUTINE_METADATA_BLOCKER=$($_.Exception.Message)`n")
        throw "Routine metadata is unavailable; backup/restore evidence is blocked. $($_.Exception.Message)"
    }
}

function Get-EventInventory([string]$Database, [string]$Path) {
    # Event metadata can fail when the server's event scheduler/catalogue is
    # unavailable. That must fail closed rather than certify an events-less
    # dump by assumption.
    $sql = @"
SELECT EVENT_NAME, STATUS, DEFINER
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
ORDER BY EVENT_NAME;
"@
    try {
        [System.IO.File]::WriteAllText($Path, (Invoke-MySql $Database $sql).Trim()+"`n")
    } catch {
        [System.IO.File]::WriteAllText($recoveryContractBlocker, "EVENT_METADATA_BLOCKER=$($_.Exception.Message)`n")
        throw "Event metadata is unavailable; backup/restore evidence is blocked. $($_.Exception.Message)"
    }
}

function Assert-DefinerPolicy([string]$Database, [string]$TriggerPath, [string]$RoutinePath, [string]$EventPath) {
    $rows = @()
    foreach ($path in @($TriggerPath, $RoutinePath, $EventPath)) {
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            $rows += Get-Content -LiteralPath $path | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
        }
    }

    # This repository has no owner-approved production DEFINER policy. Keep
    # integrity comparison separate from approval: matching root@localhost
    # values prove source/restore preservation only, not deployability.
    if ($rows.Count -gt 0) {
        $definerPolicyText = @(
            'STATUS=REVIEW_REQUIRED',
            'REASON=No owner-approved production DEFINER policy is configured.',
            ('SOURCE_DATABASE=' + $Database),
            ('DEFINER_ROWS=' + $rows.Count),
            'ACTION=Approve portable target identity or reviewed definer transformation before production restore.'
        ) -join "`n"
        [System.IO.File]::WriteAllText($definerPolicyBlocker, $definerPolicyText + "`n")
        throw "DEFINER_POLICY_BLOCKER: $($rows.Count) trigger/routine/event metadata rows require owner-approved policy."
    }
}

try {
    $sourceQuoted = Quote-DatabaseName $SourceDatabase
    $restoreQuoted = Quote-DatabaseName $RestoreDatabase

    # This is a MariaDB-only disposable diagnostic harness.  Do not let a
    # caller accidentally point it at the MySQL 8.4 CI service and mistake its
    # result for the separate MySQL recovery contract.
    $serverVersion = (Invoke-MySql '' 'SELECT VERSION()' -NoDatabase).Trim()
    if ($serverVersion -notmatch '(?i)mariadb') {
        throw "This harness requires a MariaDB server; received: $serverVersion"
    }

    Invoke-MySql '' "DROP DATABASE IF EXISTS $restoreQuoted; DROP DATABASE IF EXISTS $sourceQuoted; CREATE DATABASE $sourceQuoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -NoDatabase | Out-Null

    $previousDb = $env:DB_DATABASE; $previousConnection = $env:DB_CONNECTION
    $previousHost = $env:DB_HOST; $previousPort = $env:DB_PORT
    $previousUser = $env:DB_USERNAME; $previousPassword = $env:DB_PASSWORD
    try {
        $env:DB_CONNECTION = 'mysql'; $env:DB_DATABASE = $SourceDatabase
        $env:DB_HOST = $DbHost; $env:DB_PORT = "$Port"; $env:DB_USERNAME = $Username; $env:DB_PASSWORD = $Password
        Push-Location $backend
        try { & php artisan migrate --force --no-interaction } finally { Pop-Location }
        if ($LASTEXITCODE -ne 0) { throw 'Disposable source migration failed.' }
    } finally {
        $env:DB_DATABASE = $previousDb; $env:DB_CONNECTION = $previousConnection
        $env:DB_HOST = $previousHost; $env:DB_PORT = $previousPort
        $env:DB_USERNAME = $previousUser; $env:DB_PASSWORD = $previousPassword
    }

    $seed = @"
INSERT INTO companies (name, tax_code, created_at, updated_at) VALUES ('Backup restore harness', 'HARNESS-BR-001', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @company := LAST_INSERT_ID();
INSERT INTO fiscal_years (company_id, year, start_date, end_date, status, created_at, updated_at) VALUES (@company, 2099, '2099-01-01', '2099-12-31', 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @fy := LAST_INSERT_ID();
INSERT INTO journal_entries (company_id, fiscal_year_id, voucher_type, voucher_number, voucher_date, posting_date, description, total_amount, status, created_at, updated_at) VALUES (@company, @fy, 'general_journal', 'BR-HARNESS-001', '2099-01-01', '2099-01-01', 'Backup restore fixture', 100.00, 'draft', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @je := LAST_INSERT_ID();
INSERT INTO audit_logs (company_id, action, model_type, model_id, old_values, new_values, correlation_id, metadata, created_at, updated_at) VALUES (@company, 'backup_restore.fixture', 'App\\Models\\JournalEntry', @je, JSON_OBJECT(), JSON_OBJECT('journal_entry_id', @je), '00000000-0000-4000-8000-000000000001', JSON_OBJECT('harness', true), UTC_TIMESTAMP(), UTC_TIMESTAMP());
INSERT INTO e_invoice_documents (company_id, accounting_document_type, accounting_document_id, lifecycle_status, document_reference, payload_hash, payload_snapshot, occurred_at, metadata, created_at, updated_at) VALUES (@company, 'App\\Models\\JournalEntry', @je, 'draft', 'backup-restore-fixture', REPEAT('a', 64), JSON_OBJECT('journal_entry_id', @je), UTC_TIMESTAMP(), JSON_OBJECT('harness', true), UTC_TIMESTAMP(), UTC_TIMESTAMP());
INSERT INTO report_runs (uuid, company_id, fiscal_year_id, report, delivery, filters, period, regime, control_hash, output_hash, output_identity, snapshot, issued_at, created_at, updated_at) VALUES ('00000000-0000-4000-8000-000000000002', @company, @fy, 'general_ledger', 'preview', JSON_OBJECT(), JSON_OBJECT('year', 2099), JSON_OBJECT('profile', 'harness'), REPEAT('b', 64), REPEAT('c', 64), 'backup-restore-fixture', JSON_OBJECT('journal_entry_id', @je), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP());
"@
    Invoke-MySql $SourceDatabase $seed | Out-Null
    Get-IntegrityDigest $SourceDatabase $sourceDigest
    Get-TriggerInventory $SourceDatabase $sourceTriggers
    Get-RoutineInventory $SourceDatabase $sourceRoutines
    Get-EventInventory $SourceDatabase $sourceEvents
    Assert-TriggerRejects $SourceDatabase "UPDATE audit_logs SET action = 'tamper' WHERE action = 'backup_restore.fixture'" 'Source audit UPDATE'
    Assert-TriggerRejects $SourceDatabase "DELETE FROM report_runs WHERE output_identity = 'backup-restore-fixture'" 'Source report DELETE'

    $previous = $env:MYSQL_PWD
    try {
        $env:MYSQL_PWD = $Password
        # Routines/events are explicitly included so a metadata/catalogue
        # failure cannot be hidden by a deliberately narrow dump.
        & $MySqlDumpExe '--protocol=TCP' "--host=$DbHost" "--port=$Port" "--user=$Username" '--single-transaction' '--triggers' '--routines' '--events' '--no-tablespaces' $SourceDatabase | Set-Content -LiteralPath $dumpPath -Encoding utf8
        if ($LASTEXITCODE -ne 0) { throw 'Disposable source dump failed.' }
    } finally { $env:MYSQL_PWD = $previous }
    $dumpHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash.ToLowerInvariant()

    Invoke-MySql '' "CREATE DATABASE $restoreQuoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" -NoDatabase | Out-Null
    $previous = $env:MYSQL_PWD
    try {
        $env:MYSQL_PWD = $Password
        Get-Content -Raw -LiteralPath $dumpPath | & $MySqlExe '--protocol=TCP' "--host=$DbHost" "--port=$Port" "--user=$Username" "--database=$RestoreDatabase"
        if ($LASTEXITCODE -ne 0) { throw 'Disposable restore import failed.' }
    } finally { $env:MYSQL_PWD = $previous }
    Get-IntegrityDigest $RestoreDatabase $restoreDigest
    if ((Get-Content -Raw -LiteralPath $sourceDigest) -cne (Get-Content -Raw -LiteralPath $restoreDigest)) { throw 'Restored count/hash digest differs from source.' }
    Get-TriggerInventory $RestoreDatabase $restoreTriggers
    if ((Get-Content -Raw -LiteralPath $sourceTriggers) -cne (Get-Content -Raw -LiteralPath $restoreTriggers)) { throw 'Restored trigger inventory differs from source.' }
    Get-RoutineInventory $RestoreDatabase $restoreRoutines
    Get-EventInventory $RestoreDatabase $restoreEvents
    if ((Get-Content -Raw -LiteralPath $sourceRoutines) -cne (Get-Content -Raw -LiteralPath $restoreRoutines)) { throw 'Restored routine inventory differs from source.' }
    if ((Get-Content -Raw -LiteralPath $sourceEvents) -cne (Get-Content -Raw -LiteralPath $restoreEvents)) { throw 'Restored event inventory differs from source.' }
    $triggerCount = [int](Invoke-MySql $RestoreDatabase "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ('audit_logs','e_invoice_documents','report_runs') AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION IN ('UPDATE','DELETE')")
    if ($triggerCount -lt 6) { throw "Restore is missing required append-only triggers; expected at least 6, found $triggerCount." }
    Assert-TriggerRejects $RestoreDatabase "UPDATE e_invoice_documents SET lifecycle_status = 'tamper' WHERE document_reference = 'backup-restore-fixture'" 'Restored e-invoice UPDATE'
    Assert-TriggerRejects $RestoreDatabase "DELETE FROM audit_logs WHERE action = 'backup_restore.fixture'" 'Restored audit DELETE'

    Assert-DefinerPolicy $RestoreDatabase $restoreTriggers $restoreRoutines $restoreEvents

    [System.IO.File]::WriteAllText($runStatus, "STATUS=PASSED`n")
    Write-Host "DISPOSABLE BACKUP/RESTORE HARNESS PASSED. SHA256=$dumpHash" -ForegroundColor Green
    Write-Host "Artifacts: $ArtifactDirectory" -ForegroundColor Green
} catch {
    # Keep the status artifact single-line and machine-readable even when a
    # native MariaDB command includes a leading/newline-formatted exception.
    $reason = [string]$_.Exception.Message
    $reason = $reason.Replace("`r", ' ').Replace("`n", ' ').Trim()
    $runStatusText = @(
        'STATUS=BLOCKED',
        ('REASON=' + $reason)
    ) -join "`n"
    [System.IO.File]::WriteAllText($runStatus, $runStatusText + "`n")
    throw
} finally {
    # Deliberately retain both named disposable DBs and artifacts for manual inspection.
    # Cleanup is a separate, explicit DBA/operator action.
}
