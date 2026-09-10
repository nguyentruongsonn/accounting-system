<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * There is no signed, tenant-local statement-form catalogue available for the
 * requested date. Callers must not fall back to a guessed statutory layout.
 */
final class StatutoryStatementDefinitionUnavailableException extends ConflictHttpException
{
    public const ERROR_CODE = 'STATUTORY_STATEMENT_DEFINITION_UNAVAILABLE';

    public function __construct(string $formKey)
    {
        parent::__construct("A published statutory statement definition is not available for {$formKey}.");
    }
}
