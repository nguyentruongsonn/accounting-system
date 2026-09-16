<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-readable classification for legacy report surfaces.
 *
 * This is deliberately policy-neutral: it identifies operational-draft,
 * non-certifying output and never turns a report into a statutory result.
 */
final class ReportOutputClassification
{
    public static function apply(Response $response): Response
    {
        $response->headers->set('X-Accounting-Report-Classification', 'operational-draft');
        $response->headers->set('X-Accounting-Statutory-Output-Available', 'false');
        $response->headers->set('X-Accounting-Report-Non-Certifying', 'true');

        return $response;
    }
}
