<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditCompanyActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        $route = $request->route();
        $routeName = $route?->getName() ?: $request->path();
        $shouldAudit = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ($method === 'GET' && $routeName === 'reports.export');

        if (!$shouldAudit) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            $this->writeEvent($request, $routeName, $method, $status, 'denied', $exception::class);
            throw $exception;
        }

        $this->writeEvent(
            $request,
            $routeName,
            $method,
            $response->getStatusCode(),
            $response->getStatusCode() < 400 ? 'completed' : 'rejected'
        );

        return $response;
    }

    private function writeEvent(
        Request $request,
        string $routeName,
        string $method,
        int $status,
        string $outcome,
        ?string $exceptionClass = null
    ): void {
        $company = $request->attributes->get('activeCompany');
        $routeParameters = $request->route()?->parameters() ?? [];
        $recordIds = [];

        foreach ($routeParameters as $key => $value) {
            if (is_scalar($value) && ctype_digit((string) $value)) {
                $recordIds[$key] = (int) $value;
            } elseif (is_object($value) && isset($value->id)) {
                $recordIds[$key] = (int) $value->id;
            }
        }

        DB::table('audit_events')->insert([
            'company_id' => $company?->id,
            'user_id' => $request->user()?->id,
            'event' => $method . ' ' . $routeName . ' [' . $outcome . ']',
            'auditable_type' => $routeName,
            'auditable_id' => count($recordIds) === 1 ? (int) reset($recordIds) : null,
            'before_data' => null,
            'after_data' => json_encode([
                'http_status' => $status,
                'route_parameters' => $recordIds,
                'exception' => $exceptionClass,
            ], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
