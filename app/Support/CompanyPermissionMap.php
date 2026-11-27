<?php

namespace App\Support;

/**
 * Central company-scoped role matrix. Unknown roles and permissions deny by default.
 * Company owners and admins have all application permissions; platform-level
 * administration is intentionally not represented by a tenant role.
 */
final class CompanyPermissionMap
{
    private const ALL = ['*'];

    private const ROLE_PERMISSIONS = [
        'owner' => self::ALL,
        'admin' => self::ALL,
        'inventory_manager' => [
            'inventory.view', 'inventory.manage', 'inventory.receive',
            'inventory.transfer', 'procurement.view', 'procurement.receive',
            'reports.view',
        ],
        'procurement_manager' => [
            'procurement.view', 'procurement.create', 'procurement.submit',
            'procurement.receive', 'inventory.view', 'reports.view',
        ],
        'procurement_approver' => [
            'procurement.view', 'procurement.approve', 'reports.view',
        ],
        'finance_manager' => [
            'finance.view', 'finance.create', 'finance.post', 'finance.reconcile',
            'reports.view', 'reports.export',
        ],
        'accountant' => [
            'finance.view', 'finance.create', 'finance.post', 'finance.reconcile',
            'reports.view', 'reports.export',
        ],
        'payroll_manager' => [
            'hr.view', 'hr.manage', 'payroll.view', 'payroll.create',
            'payroll.approve', 'reports.view', 'reports.export',
        ],
        'hr_manager' => ['hr.view', 'hr.manage', 'reports.view'],
        'viewer' => [
            'inventory.view', 'procurement.view', 'reports.view',
        ],
    ];

    public static function allows(string $role, string $permission): bool
    {
        if (trim($permission) === '') {
            return false;
        }

        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];

        return in_array('*', $permissions, true)
            || in_array($permission, $permissions, true);
    }

    /** @return array<string, list<string>> */
    public static function matrix(): array
    {
        return self::ROLE_PERMISSIONS;
    }
}
