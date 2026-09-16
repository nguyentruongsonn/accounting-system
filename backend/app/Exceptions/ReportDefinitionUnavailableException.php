<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Raised when a versioned management report has no executable definition.
 *
 * A report is executable only after its source and calculation contracts have
 * both been signed and published. This exception deliberately does not expose
 * draft-definition details to API consumers.
 */
final class ReportDefinitionUnavailableException extends ConflictHttpException
{
    public const ERROR_CODE = 'DEFINITION_UNAVAILABLE';

    public function __construct(string $reportKey)
    {
        parent::__construct("A published calculation definition is not available for {$reportKey}.");
    }
}
