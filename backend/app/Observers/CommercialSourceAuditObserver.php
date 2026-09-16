<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Services\AuditService;
use App\Support\CommercialSourceAuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes an immutable audit event for commercial source documents.
 *
 * This observer deliberately runs inside the caller's database transaction.
 * A failed audit write must therefore abort the source-document mutation rather
 * than leaving an unaudited accounting document behind.
 */
class CommercialSourceAuditObserver
{
    /** @var array<int, array{before: array<string, mixed>, dirty: array<string, mixed>}> */
    private static array $updates = [];

    /** @var array<int, array<string, mixed>> */
    private static array $deletes = [];

    public function updating(Model $source): void
    {
        self::$updates[spl_object_id($source)] = [
            // getRawOriginal is stable during Eloquent's save lifecycle; the
            // cast-aware getOriginal may be synchronized before `updated` runs.
            'before' => $source->getRawOriginal(),
            'dirty' => $source->getDirty(),
        ];
    }

    public function updated(Model $source): void
    {
        $state = self::$updates[spl_object_id($source)] ?? [
            'before' => [],
            'dirty' => [],
        ];
        unset(self::$updates[spl_object_id($source)]);

        $event = CommercialSourceAuditContext::consume($source, 'updated');
        if ($event === 'updated' && array_key_exists('is_posted', $state['dirty'])) {
            $event = (bool) $source->getAttribute('is_posted') ? 'posted' : 'unposted';
        }

        $this->record($source, $event, $state['before'], $source->fresh()?->toArray() ?? $source->toArray(), [
            'changed_fields' => array_keys($state['dirty']),
        ]);
    }

    public function created(Model $source): void
    {
        // A fixture/import may materialise a foreign source while an actor is
        // authenticated in another tenant. Do not write an audit row for that
        // foreign create; the direct AuditService boundary still rejects an
        // authenticated caller, while update/delete events remain strict.
        $actorCompanyId = auth()->user()?->company_id;
        $sourceCompanyId = $source->getAttribute('company_id');
        if ($actorCompanyId !== null && (int) $actorCompanyId !== (int) $sourceCompanyId) {
            return;
        }
        $this->record($source, CommercialSourceAuditContext::consume($source, 'created'), [], $source->toArray());
    }

    public function deleting(Model $source): void
    {
        self::$deletes[spl_object_id($source)] = $source->toArray();
    }

    public function deleted(Model $source): void
    {
        $before = self::$deletes[spl_object_id($source)] ?? $source->toArray();
        unset(self::$deletes[spl_object_id($source)]);

        $this->record($source, 'deleted', $before, []);
    }

    private function record(Model $source, string $event, array $before, array $after, array $metadata = []): void
    {
        $journalEntryId = $source->getAttribute('journal_entry_id');
        $correlationId = null;

        if ($journalEntryId) {
            $correlationId = AuditLog::withoutGlobalScope('company')
                ->where('model_type', (new JournalEntry)->getMorphClass())
                ->where('model_id', $journalEntryId)
                ->latest('id')
                ->value('correlation_id');
        }

        app(AuditService::class)->record(
            $source,
            Str::snake(class_basename($source)).'.'.$event,
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
