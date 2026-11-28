<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Branch;

class CompanyAuthorizer
{
    public static function activeCompanyId(Request $request): int
    {
        $company = $request->attributes->get('activeCompany');
        abort_unless($company, 403, 'No active company selected.');

        return (int) $company->id;
    }

    public static function activeBranchId(Request $request): ?int
    {
        $companyId = self::activeCompanyId($request);
        $membership = $request->user()->companies()
            ->where('companies.id', $companyId)
            ->wherePivot('is_active', true)
            ->first();

        abort_unless($membership, 403, 'Active company membership is required.');

        $branchId = $membership->pivot->branch_id;
        if ($branchId === null) {
            return null;
        }

        $validBranch = Branch::query()
            ->where('company_id', $companyId)
            ->whereKey($branchId)
            ->where('is_active', true)
            ->exists();

        abort_unless($validBranch, 403, 'Your assigned branch is inactive or unavailable.');

        return (int) $branchId;
    }

    public static function scopeToActiveBranch(Builder $query, Request $request, string $column = 'branch_id'): Builder
    {
        $branchId = self::activeBranchId($request);

        if ($branchId !== null) {
            $query->where($column, $branchId);
        }

        return $query;
    }

    public static function requireCompanyWideAccess(Request $request): void
    {
        abort_if(
            self::activeBranchId($request) !== null,
            403,
            'This operation is company-wide. Branch-restricted users cannot access it until branch-level accounting is enabled.'
        );
    }

    public static function requireRole(Request $request, array $roles): void
    {
        $membership = $request->user()->companies()
            ->where('companies.id', self::activeCompanyId($request))
            ->wherePivot('is_active', true)
            ->first();

        abort_unless(
            $membership && in_array($membership->pivot->role_code, $roles, true),
            403,
            'You do not have permission to perform this action.'
        );
    }

    public static function requirePermission(Request $request, string $permission): void
    {
        $membership = $request->user()->companies()
            ->where('companies.id', self::activeCompanyId($request))
            ->wherePivot('is_active', true)
            ->first();

        $role = $membership?->pivot?->role_code;

        abort_unless(
            $role && CompanyPermissionMap::allows($role, $permission),
            403,
            'Your company role does not grant this permission.'
        );
    }
}
