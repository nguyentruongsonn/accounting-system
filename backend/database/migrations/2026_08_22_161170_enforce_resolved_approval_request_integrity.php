<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Approval-request integrity triggers are not implemented for database driver [{$driver}].");
        }

        DB::unprepared('DROP TRIGGER IF EXISTS approval_requests_resolved_integrity');
        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER approval_requests_resolved_integrity
BEFORE UPDATE ON approval_requests
WHEN OLD.status <> 'pending'
  OR NEW.status NOT IN ('approved', 'rejected')
  OR OLD.company_id IS NOT NEW.company_id
  OR OLD.approval_policy_id IS NOT NEW.approval_policy_id
  OR OLD.approval_key IS NOT NEW.approval_key
  OR OLD.subject_type IS NOT NEW.subject_type
  OR OLD.subject_id IS NOT NEW.subject_id
  OR OLD.separation_of_duties_required IS NOT NEW.separation_of_duties_required
  OR OLD.policy_snapshot IS NOT NEW.policy_snapshot
  OR OLD.request_evidence IS NOT NEW.request_evidence
  OR OLD.requested_by IS NOT NEW.requested_by
  OR OLD.requested_at IS NOT NEW.requested_at
  OR NEW.resolved_by IS NULL
  OR NEW.resolved_at IS NULL
BEGIN
  SELECT RAISE(ABORT, 'Approval requests may only transition once from pending to a resolved immutable state');
END
SQL);
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER approval_requests_resolved_integrity
BEFORE UPDATE ON approval_requests
FOR EACH ROW
BEGIN
  IF OLD.status <> 'pending'
     OR NEW.status NOT IN ('approved', 'rejected')
     OR NOT (OLD.company_id <=> NEW.company_id)
     OR NOT (OLD.approval_policy_id <=> NEW.approval_policy_id)
     OR NOT (OLD.approval_key <=> NEW.approval_key)
     OR NOT (OLD.subject_type <=> NEW.subject_type)
     OR NOT (OLD.subject_id <=> NEW.subject_id)
     OR NOT (OLD.separation_of_duties_required <=> NEW.separation_of_duties_required)
     OR NOT (OLD.policy_snapshot <=> NEW.policy_snapshot)
     OR NOT (OLD.request_evidence <=> NEW.request_evidence)
     OR NOT (OLD.requested_by <=> NEW.requested_by)
     OR NOT (OLD.requested_at <=> NEW.requested_at)
     OR NEW.resolved_by IS NULL
     OR NEW.resolved_at IS NULL
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approval requests may only transition once from pending to a resolved immutable state';
  END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS approval_requests_resolved_integrity');
    }
};
