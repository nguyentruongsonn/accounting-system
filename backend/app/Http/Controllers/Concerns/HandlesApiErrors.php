<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ApiErrorResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

trait HandlesApiErrors
{
    protected function apiError(Request $request, Throwable $exception, int $businessStatus = 400): JsonResponse
    {
        return app(ApiErrorResponder::class)->toResponse($exception, $request, $businessStatus);
    }
}
