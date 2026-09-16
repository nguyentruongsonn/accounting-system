<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class TenantContext
{
    /**
     * Resolve the active company exclusively from the authenticated principal.
     *
     * Client-supplied company identifiers must never be used as an authority
     * boundary. Users without an assigned company cannot access tenant data.
     */
    public static function companyId(Request $request): int
    {
        $companyId = $request->user()?->company_id;

        if ($companyId === null) {
            throw new AccessDeniedHttpException('Authenticated user is not assigned to a company.');
        }

        return (int) $companyId;
    }
}
