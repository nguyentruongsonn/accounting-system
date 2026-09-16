<?php

namespace App\Services;

/**
 * Resolves the deployment mode used by posting services.
 *
 * Direct posting is a test/local convenience for the two-user workflow. The
 * service repeats the production guard so a stale environment value cannot
 * open the bypass in a production process.
 */
final class AccountingPostingMode
{
    public function isDirectPostingEnabled(): bool
    {
        $environment = strtolower((string) config('app.env', 'production'));

        return ! in_array($environment, ['production', 'prod'], true)
            && (bool) config('accounting.direct_posting_mode', false);
    }
}
