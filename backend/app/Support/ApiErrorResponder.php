<?php

namespace App\Support;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Exceptions\AccountingDimensionUnavailableException;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Exceptions\ReportDefinitionUnavailableException;
use App\Exceptions\StatutoryStatementDefinitionUnavailableException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorResponder
{
    /**
     * These legacy service messages are part of the public accounting workflow
     * contract. All other untyped exception messages remain server-only.
     *
     * @var list<string>
     */
    private const BUSINESS_MESSAGES = [
        'Voucher is already posted',
        'Voucher is not posted yet',
        'Invoice is already posted',
        'Invoice is not posted yet',
        'Thông tin đăng nhập không chính xác.',
        'Chứng từ chưa được ghi sổ',
        'Chứng từ chưa được ghi sổ.',
        'Chứng từ đã được ghi sổ.',
        'Không thể sửa chứng từ đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.',
        'Không thể sửa hóa đơn đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.',
        'WIP ending references must belong to active production orders in the authenticated company.',
        'Không thể sửa phiếu nhập kho đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.',
        'Không thể sửa phiếu xuất kho đã ghi sổ. Vui lòng bỏ ghi sổ trước khi sửa.',
    ];

    public function toResponse(Throwable $exception, Request $request, int $businessStatus = 400): JsonResponse
    {
        $requestId = (string) Str::uuid();
        [$status, $message, $extra] = $this->map($exception, $businessStatus);

        $context = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'route' => $request->route()?->getName() ?? $request->route()?->uri(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'company_id' => $request->user()?->getAttribute('company_id'),
            'exception_class' => $exception::class,
            'status' => $status,
        ];

        if ($status >= 500) {
            Log::error('Accounting API request failed.', $context);
        } else {
            Log::warning('Accounting API request rejected.', $context);
        }

        $response = response()
            ->json(['error' => $message, 'request_id' => $requestId, ...$extra], $status)
            ->header('X-Request-ID', $requestId);

        if ($exception instanceof HttpExceptionInterface) {
            foreach (['Allow', 'Retry-After', 'WWW-Authenticate'] as $header) {
                if (array_key_exists($header, $exception->getHeaders())) {
                    $response->headers->set($header, $exception->getHeaders()[$header]);
                }
            }
        }

        return $response;
    }

    /**
     * @return array{int, string, array<string, mixed>}
     */
    private function map(Throwable $exception, int $businessStatus): array
    {
        if ($exception instanceof AccountingPolicyUnavailableException) {
            return [409, 'Approved accounting policy evidence is not available.', ['error_code' => 'ACCOUNTING_POLICY_UNAVAILABLE']];
        }

        if ($exception instanceof AccountingAccountMappingUnavailableException) {
            // Keep the stable top-level contract while exposing the resolver's
            // safe, actionable reason as a field-level error. These messages
            // contain only mapping keys/roles/counts and never exception traces
            // or SQL details, so the UI can tell the accountant exactly what
            // must be corrected instead of showing a generic empty state.
            return [409, 'Approved posting account mapping evidence is not available.', [
                'error_code' => 'ACCOUNT_MAPPING_UNAVAILABLE',
                'errors' => ['account_mapping' => [$exception->getMessage()]],
            ]];
        }

        if ($exception instanceof AccountingDimensionUnavailableException) {
            return [409, 'Required accounting dimension evidence is not available.', ['error_code' => 'ACCOUNTING_DIMENSION_UNAVAILABLE']];
        }

        if ($exception instanceof StatutoryStatementDefinitionUnavailableException) {
            return [409, 'Approved statutory statement definition is not available.', ['error_code' => 'STATUTORY_STATEMENT_DEFINITION_UNAVAILABLE']];
        }

        if ($exception instanceof ReportDefinitionUnavailableException) {
            return [
                409,
                'The requested report definition is not available.',
                ['error_code' => ReportDefinitionUnavailableException::ERROR_CODE],
            ];
        }

        if ($exception instanceof ValidationException) {
            return [422, 'The given data was invalid.', ['errors' => $exception->errors()]];
        }

        if ($exception instanceof AuthenticationException) {
            return [401, 'Unauthenticated.', []];
        }

        if ($exception instanceof AuthorizationException) {
            return [403, 'This action is unauthorized.', []];
        }

        if ($exception instanceof ModelNotFoundException) {
            return [404, 'Resource not found.', []];
        }

        if ($exception instanceof QueryException && $this->isSettlementAllocationDuplicate($exception)) {
            return [
                409,
                'The request conflicts with existing settlement allocation evidence.',
                ['error_code' => 'SETTLEMENT_ALLOCATION_DUPLICATE'],
            ];
        }

        if ($exception instanceof QueryException && $this->isVoucherNumberDuplicate($exception)) {
            return [
                422,
                'The given data was invalid.',
                ['errors' => ['voucher_number' => ['The voucher number has already been taken.']]],
            ];
        }

        if ($exception instanceof ConflictHttpException) {
            return [409, $exception->getMessage() ?: 'The request conflicts with the current resource state.', []];
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            if (in_array($status, [400, 401, 403, 404, 405, 409, 422, 429], true)) {
                return [$status, $this->safeHttpMessage($status, $exception->getMessage()), []];
            }
        }

        if (in_array($exception->getMessage(), self::BUSINESS_MESSAGES, true)) {
            return [$businessStatus, $exception->getMessage(), []];
        }

        // These messages are emitted by the costing/tool allocation services
        // after their own typed input/period checks. Their month/prefix
        // values are constrained by the service contract, while arbitrary
        // exception details remain server-only below.
        if ($this->isKnownAllocationBusinessMessage($exception->getMessage())) {
            return [$businessStatus, $exception->getMessage(), []];
        }

        if ($exception instanceof InvalidArgumentException) {
            return [422, 'The given data was invalid.', ['errors' => ['data' => ['The given data was invalid.']]]];
        }

        return [500, 'An unexpected error occurred.', []];
    }

    private function safeHttpMessage(int $status, string $message): string
    {
        return match ($status) {
            400 => $message ?: 'Bad request.',
            401 => 'Unauthenticated.',
            403 => 'This action is unauthorized.',
            404 => 'Resource not found.',
            405 => 'Method not allowed.',
            409 => $message ?: 'The request conflicts with the current resource state.',
            422 => 'The given data was invalid.',
            429 => 'Too many requests.',
        };
    }

    private function isSettlementAllocationDuplicate(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'settlement_allocations_source_reference_unique')
            || str_contains($message, 'settlement_allocations_source_target_unique')
            || (str_contains($message, 'settlement_allocations')
                && (str_contains($message, 'source_reference_key')
                    || str_contains($message, 'source_line_type')));
    }

    private function isVoucherNumberDuplicate(QueryException $exception): bool
    {
        if (! str_starts_with((string) $exception->getCode(), '23')) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'voucher_number')
            || str_contains($message, 'voucher number');
    }

    private function isKnownAllocationBusinessMessage(string $message): bool
    {
        return preg_match(
            '/\\A(?:Allocation already run for month|Không có lệnh sản xuất nào trong tháng) \\d{4}-\\d{2}\\z/u',
            $message,
        ) === 1 || preg_match(
            '/\\ACost allocation requires whole-unit (?:621|622|627) source costs; fractional values require an approved allocation rounding policy\\.\\z/',
            $message,
        ) === 1;
    }
}
