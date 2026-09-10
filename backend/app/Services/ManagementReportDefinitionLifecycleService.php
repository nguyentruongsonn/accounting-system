<?php

namespace App\Services;

use App\Models\ManagementReportDefinition;
use App\Models\ManagementReportEffectiveDefinition;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Trusted domain boundary for management-report definition versions.
 *
 * No HTTP endpoint is intentionally exposed here: the current permission
 * catalogue has report-view permissions but no separately approved
 * draft/publish/approve capability or segregation-of-duties policy. Callers
 * must provide an authenticated actor; this service enforces its tenant.
 */
final class ManagementReportDefinitionLifecycleService
{
    public function __construct(private readonly ManagementReportDefinitionContractHasher $hasher) {}

    /**
     * @param  array<string, mixed>|null  $sourceContract
     * @param  array<string, mixed>|null  $calculationContract
     * @param  array<string, mixed>|null  $amountContract
     */
    public function createDraft(
        User $actor,
        string $reportKey,
        string $definitionVersion,
        ?array $sourceContract = null,
        ?array $calculationContract = null,
        ?array $amountContract = null,
    ): ManagementReportDefinition {
        $companyId = $this->requireActorCompany($actor);

        if (trim($reportKey) === '' || trim($definitionVersion) === '') {
            throw new LogicException('A report key and definition version are required for a draft.');
        }

        return ManagementReportDefinition::withoutGlobalScope('company')->create([
            'company_id' => $companyId,
            'report_key' => $reportKey,
            'definition_version' => $definitionVersion,
            'status' => 'draft',
            'source_contract' => $sourceContract,
            'calculation_contract' => $calculationContract,
            'amount_contract' => $amountContract,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $sourceContract
     * @param  array<string, mixed>|null  $calculationContract
     * @param  array<string, mixed>|null  $amountContract
     */
    public function updateDraft(
        User $actor,
        ManagementReportDefinition $definition,
        ?array $sourceContract,
        ?array $calculationContract,
        ?array $amountContract = null,
    ): ManagementReportDefinition {
        $this->assertActorOwnsDefinition($actor, $definition);

        if ($definition->status !== 'draft' || $definition->published_at !== null) {
            throw new LogicException('Only an unpublished management report draft may be edited.');
        }

        $definition->fill([
            'source_contract' => $sourceContract,
            'calculation_contract' => $calculationContract,
            'amount_contract' => $amountContract,
        ])->save();

        return $definition->refresh();
    }

    /**
     * Publish a complete draft and atomically select it as this report key's
     * single effective version for its tenant. This creates no legal, tax, or
     * statutory certification; it records a product-definition approval only.
     */
    public function publish(
        User $actor,
        ManagementReportDefinition $definition,
        ?DateTimeInterface $publishedAt = null,
    ): ManagementReportDefinition {
        $companyId = $this->requireActorCompany($actor);
        $this->assertActorOwnsDefinition($actor, $definition);
        $publishedAt ??= now();

        return DB::transaction(function () use ($actor, $companyId, $definition, $publishedAt): ManagementReportDefinition {
            // Scope before locking so a direct caller cannot select a foreign
            // report definition into the publication transaction.
            $lockedDefinition = ManagementReportDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($definition->getKey());

            $this->assertActorOwnsDefinition($actor, $lockedDefinition);
            if ($lockedDefinition->status !== 'draft' || $lockedDefinition->published_at !== null) {
                throw new LogicException('Only an unpublished management report draft may be published.');
            }

            $sourceContract = $lockedDefinition->source_contract;
            $calculationContract = $lockedDefinition->calculation_contract;
            if (! is_array($sourceContract) || $sourceContract === []
                || ! is_array($calculationContract) || $calculationContract === []) {
                throw new LogicException('A complete source and calculation contract is required before publication.');
            }

            $amountContract = $lockedDefinition->amount_contract;
            if ($amountContract !== null && (! is_array($amountContract) || $amountContract === [])) {
                throw new LogicException('An amount contract, when supplied, must be complete before publication.');
            }

            $contractHash = $this->hasher->hash(
                $lockedDefinition->report_key,
                $lockedDefinition->definition_version,
                $sourceContract,
                $calculationContract,
                $amountContract,
            );

            // This is the sole allowed definition-row lifecycle transition.
            // The DB trigger protects every later update/delete of this row.
            ManagementReportDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereKey($lockedDefinition->getKey())
                ->update([
                    'status' => 'published',
                    'signed_by' => $actor->getKey(),
                    'signed_at' => $publishedAt,
                    'published_at' => $publishedAt,
                    'contract_hash' => $contractHash,
                    'updated_at' => now(),
                ]);

            $effective = ManagementReportEffectiveDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrNew([
                    'company_id' => $companyId,
                    'report_key' => $lockedDefinition->report_key,
                ]);
            $effective->management_report_definition_id = $lockedDefinition->getKey();
            $effective->save();

            return ManagementReportDefinition::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->findOrFail($lockedDefinition->getKey());
        });
    }

    private function requireActorCompany(User $actor): int
    {
        if ($actor->company_id === null) {
            throw new AuthorizationException('The acting user is not assigned to a company.');
        }

        return (int) $actor->company_id;
    }

    private function assertActorOwnsDefinition(User $actor, ManagementReportDefinition $definition): void
    {
        if ($this->requireActorCompany($actor) !== (int) $definition->company_id) {
            throw new AuthorizationException('The acting user cannot manage another company\'s report definitions.');
        }
    }
}
