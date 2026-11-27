<?php
namespace App\Support;
use Illuminate\Http\Request;
class CompanyAuthorizer {
    public static function activeCompanyId(Request $request): int {
        $company = $request->attributes->get('activeCompany');
        abort_unless($company,403,'No active company selected.');
        return (int)$company->id;
    }
    public static function requireRole(Request $request, array $roles): void {
        $membership = $request->user()->companies()
            ->where('companies.id',self::activeCompanyId($request))
            ->wherePivot('is_active',true)->first();
        abort_unless($membership && in_array($membership->pivot->role_code,$roles,true),403,'You do not have permission to perform this action.');
    }
}
