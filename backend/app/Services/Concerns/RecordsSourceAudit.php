<?php

namespace App\Services\Concerns;

use App\Models\AuditLog;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;

trait RecordsSourceAudit
{
    protected function recordSourceAudit(
        Model $source,
        string $event,
        array $before = [],
        array $after = [],
        array $metadata = []
    ): void {
        $journalEntryId = $source->getAttribute('journal_entry_id') ?? ($metadata['journal_entry_id'] ?? null);
        $correlationId = null;

        if ($journalEntryId) {
            $correlationId = AuditLog::withoutGlobalScope('company')
                ->where('company_id', (int) $source->getAttribute('company_id'))
                ->where('model_type', (new JournalEntry)->getMorphClass())
                ->where('model_id', $journalEntryId)
                ->latest('id')
                ->value('correlation_id');
        }

        $this->auditService->record(
            $source,
            $event,
            $before,
            $after,
            $correlationId,
            array_merge([
                'source_id' => $source->getKey(),
                'source_type' => $source->getMorphClass(),
                'journal_entry_id' => $journalEntryId,
            ], $metadata)
        );
    }
}
