<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use Illuminate\Validation\ValidationException;

/**
 * Read-only access to purchase invoices.
 *
 * Keeping tenant scoping and eager-loading in one place prevents controllers
 * and write services from quietly implementing different read rules.
 */
final class PurchaseInvoiceQueryService
{
    public function getAll(?int $companyId = null)
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($actorCompanyId !== null) {
            if ($companyId !== null && (int) $companyId !== (int) $actorCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => 'The requested company does not belong to the authenticated user.',
                ]);
            }

            $companyId = (int) $actorCompanyId;
        }

        if ($companyId === null) {
            throw ValidationException::withMessages([
                'company_id' => 'A company context is required to list purchase invoices.',
            ]);
        }

        return PurchaseInvoice::query()
            ->with([
                'supplier',
                'lines',
                'employee',
                'receivedExpenseAllocations.sourceInvoice.supplier',
            ])
            ->where('company_id', $companyId)
            ->orderBy('invoice_date', 'desc')
            ->get();
    }

    public function getById(int|string $id): PurchaseInvoice
    {
        $query = $this->scopeToActorCompany(
            PurchaseInvoice::with([
                'lines',
                'supplier',
                'employee',
                'references',
                'referencedBy',
                'expenseAllocations',
                'receivedExpenseAllocations.sourceInvoice.supplier',
            ])
        );

        return $query->findOrFail($id);
    }

    public function generateNextCode(int $companyId): string
    {
        $companyId = $this->requireCompanyId($companyId);
        $year = now()->format('Y');
        $prefix = 'HDMH-'.$year.'-';
        $latest = PurchaseInvoice::where('company_id', $companyId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->value('invoice_number');

        if ($latest && preg_match('/'.preg_quote($prefix, '/').'(\d+)/', $latest, $matches)) {
            $nextSequence = str_pad((int) $matches[1] + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $nextSequence = str_pad(
                (string) (PurchaseInvoice::where('company_id', $companyId)->count() + 1),
                4,
                '0',
                STR_PAD_LEFT,
            );
        }

        return $prefix.$nextSequence;
    }

    private function scopeToActorCompany($query)
    {
        $actor = auth()->user();
        if ($actor !== null) {
            if ($actor->company_id === null) {
                throw ValidationException::withMessages([
                    'company_id' => 'An authenticated company context is required.',
                ]);
            }
            $query->where('company_id', (int) $actor->company_id);
        }

        return $query;
    }

    private function requireCompanyId(int $companyId): int
    {
        $actorCompanyId = auth()->user()?->company_id;
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => 'An authenticated company context is required.',
            ]);
        }
        if ($actorCompanyId !== null && (int) $companyId !== (int) $actorCompanyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The requested company does not belong to the authenticated user.',
            ]);
        }

        return $companyId;
    }
}
