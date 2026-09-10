<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unsupported settlement migration driver [{$driver}].");
        }
        // Validate before DDL: never choose a winner or rewrite conflicting evidence.
        $seen = [];
        DB::table('settlement_allocations')->orderBy('id')->chunkById(500, function ($rows) use (&$seen): void {
            foreach ($rows as $row) {
                $key = $this->key($row);
                $identity = $row->company_id.'|'.$key;
                if (isset($seen[$identity])) {
                    throw new RuntimeException("Duplicate settlement references: rows {$seen[$identity]} and {$row->id}.");
                }
                if (isset($row->source_reference_key) && $row->source_reference_key !== $key) {
                    throw new RuntimeException("Settlement reference mismatch at row {$row->id}.");
                }
                $seen[$identity] = $row->id;
            }
        });
        if (! Schema::hasColumn('settlement_allocations', 'source_reference_key')) {
            Schema::table('settlement_allocations', function (Blueprint $table): void {
                $table->string('source_reference_key', 220)->nullable()->after('source_line_id');
            });
        }
        if (DB::table('settlement_allocations')->whereNull('source_reference_key')->exists()) {
            try {
                $this->installUpdateTrigger($driver, true);
                DB::transaction(function (): void {
                    DB::table('settlement_allocations')->whereNull('source_reference_key')->orderBy('id')->chunkById(500, function ($rows): void {
                        foreach ($rows as $row) {
                            DB::table('settlement_allocations')->where('id', $row->id)->whereNull('source_reference_key')
                                ->update(['source_reference_key' => $this->key($row)]);
                        }
                    });
                });
            } finally {
                $this->installUpdateTrigger($driver, false);
            }
        }
        try {
            Schema::table('settlement_allocations', function (Blueprint $table): void {
                $table->string('source_reference_key', 220)->nullable(false)->change();
            });
            if (! collect(Schema::getIndexes('settlement_allocations'))->contains('name', 'settlement_allocations_source_reference_unique')) {
                Schema::table('settlement_allocations', function (Blueprint $table): void {
                    $table->unique(['company_id', 'source_reference_key'], 'settlement_allocations_source_reference_unique');
                });
            }
        } finally {
            // SQLite rebuilds the table for change(), removing its triggers.
            (require __DIR__.'/2026_08_22_153010_add_settlement_allocation_immutability_triggers.php')->up();
        }
    }

    private function key(object $row): string
    {
        return implode('|', [$row->source_document_type, $row->source_document_id,
            $row->source_line_type ?? '-', $row->source_line_id ?? '-',
            $row->target_document_type, $row->target_document_id]);
    }

    private function installUpdateTrigger(string $driver, bool $backfill): void
    {
        $quote = fn (string $value): string => $driver === 'sqlite'
            ? '"'.str_replace('"', '""', $value).'"' : '`'.str_replace('`', '``', $value).'`';
        $prefix = DB::connection()->getTablePrefix();
        $name = $quote((preg_replace('/[^A-Za-z0-9_]/', '_', $prefix) ?? '').'settlement_allocation_immutable_update');
        $table = $quote($prefix.'settlement_allocations');
        $condition = "OLD.status = 'posted'";
        if ($backfill) {
            $equal = fn (string $left, string $right): string => $driver === 'sqlite'
                ? "$left IS $right" : "BINARY $left <=> BINARY $right";
            $parts = ["OLD.source_reference_key IS NULL", 'NEW.source_reference_key IS NOT NULL'];
            foreach (Schema::getColumnListing('settlement_allocations') as $column) {
                if ($column !== 'source_reference_key') {
                    $parts[] = $equal('NEW.'.$quote($column), 'OLD.'.$quote($column));
                }
            }
            $fields = ['OLD.source_document_type', 'OLD.source_document_id', "COALESCE(OLD.source_line_type, '-')",
                "COALESCE(OLD.source_line_id, '-')", 'OLD.target_document_type', 'OLD.target_document_id'];
            $canonical = $driver === 'sqlite' ? implode(" || '|' || ", $fields) : "CONCAT(".implode(", '|', ", $fields).')';
            $parts[] = $equal('NEW.source_reference_key', '('.$canonical.')');
            $condition .= ' AND NOT ('.implode(' AND ', array_map(fn ($part) => '('.$part.')', $parts)).')';
        }
        // Delete protection stays installed. The temporary update rule permits
        // only the exact derived key and compares every other column unchanged.
        DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        DB::unprepared($driver === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Posted settlement allocations are immutable'); END"
            : "CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted settlement allocations are immutable'; END IF; END");
    }

    public function down(): void
    {
        // The create migration now owns this column on fresh installations.
        // Do not remove it on rollback: that would make the earlier migration
        // state differ between fresh and upgraded environments.
    }
};
