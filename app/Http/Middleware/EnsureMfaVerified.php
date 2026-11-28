<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaVerified
{
    private const PRIVILEGED_ROLES = [
        'owner', 'admin', 'inventory_manager', 'procurement_manager',
        'procurement_approver', 'finance_manager', 'accountant',
        'payroll_manager', 'hr_manager',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $company = $request->attributes->get('activeCompany');
        $role = $company?->pivot?->role_code;
        $privileged = in_array($role, self::PRIVILEGED_ROLES, true);
        $enabled = (bool) $user->mfa_enabled_at;

        if ($enabled && (int) $request->session()->get('mfa_verified_user_id') !== (int) $user->id) {
            if ($request->routeIs('mfa.challenge', 'mfa.challenge.verify')) {
                return $next($request);
            }

            return redirect()->route('mfa.challenge');
        }

        if ($privileged && !$enabled) {
            if ($request->routeIs('mfa.settings', 'mfa.setup', 'mfa.confirm')) {
                return $next($request);
            }

            return redirect()->route('mfa.settings')
                ->with('status', 'Set up an authenticator app before accessing company workspaces.');
        }

        return $next($request);
    }
}
