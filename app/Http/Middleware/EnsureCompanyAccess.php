<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureCompanyAccess {
    public function handle(Request $request, Closure $next): Response {
        $user = $request->user();
        if (!$user) return redirect()->route('login');
        $memberships = $user->companies()->wherePivot('is_active', true)->get();
        if ($memberships->isEmpty()) abort(403, 'Your account is not assigned to an active company.');
        $selected = (int) $request->session()->get('active_company_id', 0);
        $company = $memberships->firstWhere('id', $selected);
        if (!$company && $memberships->count() === 1) {
            $company = $memberships->first();
            $request->session()->put('active_company_id', $company->id);
        }
        if (!$company) return response()->view('companies.select', ['companies'=>$memberships], 200);
        $request->attributes->set('activeCompany', $company);
        view()->share('activeCompany', $company);
        return $next($request);
    }
}
