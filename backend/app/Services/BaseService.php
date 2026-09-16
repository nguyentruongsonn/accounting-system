<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

abstract class BaseService
{
    /**
     * Wrap execution in a try-catch and log errors
     */
    protected function executeSafely(callable $callback, string $errorMessage = 'An error occurred')
    {
        try {
            return $callback();
        } catch (Exception $e) {
            Log::error($errorMessage, [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
