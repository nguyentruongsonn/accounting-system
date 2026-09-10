<?php

use App\Http\Middleware\EnforceTransactionalTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiErrorResponder;
use App\Support\ReportOutputClassification;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'transactional_tenant' => EnforceTransactionalTenant::class,
            'active_account' => \App\Http\Middleware\EnsureActiveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Authentication/authorization can fail before the report route
        // middleware enters. Keep the report classification contract on the
        // final JSON/error response as well as on successful responses.
        $exceptions->respond(function ($response, Throwable $exception, Request $request) {
            if ($request->is('api/v1/reports') || $request->is('api/v1/reports/*')) {
                return ReportOutputClassification::apply($response);
            }

            return $response;
        });

        // Reconciliation is an audit-facing API surface. Route/controller,
        // authentication, authorization and validation failures must all share
        // the same non-leaking, correlated error envelope.
        $exceptions->render(function (Throwable $exception, Request $request) {
            $isReconciliationSurface = $request->is('api/v1/gl/reconciliations*')
                || $request->is('api/v1/gl/periods/*/close-readiness');
            // Management capability discovery and each route it declares must
            // share a small, correlated, non-leaking error envelope. This is
            // deliberately scoped; it does not rewrite legacy report success
            // payloads or expand the global API error contract.
            $isManagementCapabilitySurface = $request->is('api/v1/reports/management-capabilities')
                || $request->is('api/v1/purchase/ap-aging')
                || $request->is('api/v1/sales/ar-aging')
                || $request->is('api/v1/inventory/stock-report')
                || $request->is('api/v1/budgets/report')
                || $request->is('api/v2/management-reports/*');
            $isSettlementAllocationSurface = $request->is('api/v1/settlement-allocations*');
            // Mapping is a posting-control workbench. Its caller must receive
            // the same correlated, non-leaking envelope for tenant, RBAC and
            // lifecycle failures as for successful audit correlation.
            $isAccountMappingSurface = $request->is('api/v1/approved-account-mappings*');

            if (! $isReconciliationSurface && ! $isManagementCapabilitySurface && ! $isSettlementAllocationSurface && ! $isAccountMappingSurface) {
                return null;
            }

            return app(ApiErrorResponder::class)
                ->toResponse($exception, $request);
        });
    })->create();
