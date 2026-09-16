<?php

namespace App\Http\Middleware;

use App\Support\ReportOutputClassification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the non-statutory status of legacy report endpoints machine-visible.
 *
 * The v1 report payloads are kept backward-compatible, but they are only
 * operational drafts until a controlled statement engine and certification
 * workflow exist.  A consumer must not infer statutory authority merely from
 * a successful HTTP response or a B01/B02-style label.
 */
final class ClassifyOperationalReportOutput
{
    public function handle(Request $request, Closure $next): Response
    {
        return ReportOutputClassification::apply($next($request));
    }
}
