<?php

namespace Tests\Unit;

use App\Support\CompanyPermissionMap;
use PHPUnit\Framework\TestCase;

final class CompanyPermissionMapTest extends TestCase
{
    public function test_owner_and_admin_have_tenant_permissions(): void
    {
        foreach (['owner', 'admin'] as $role) {
            self::assertTrue(CompanyPermissionMap::allows($role, 'finance.post'));
            self::assertTrue(CompanyPermissionMap::allows($role, 'payroll.approve'));
            self::assertTrue(CompanyPermissionMap::allows($role, 'roles.manage'));
        }
    }

    public function test_inventory_manager_cannot_post_finance_or_approve_procurement(): void
    {
        self::assertTrue(CompanyPermissionMap::allows('inventory_manager', 'inventory.manage'));
        self::assertFalse(CompanyPermissionMap::allows('inventory_manager', 'finance.post'));
        self::assertFalse(CompanyPermissionMap::allows('inventory_manager', 'procurement.approve'));
    }

    public function test_procurement_approver_is_separate_from_creator_permissions(): void
    {
        self::assertTrue(CompanyPermissionMap::allows('procurement_approver', 'procurement.approve'));
        self::assertFalse(CompanyPermissionMap::allows('procurement_approver', 'procurement.create'));
        self::assertFalse(CompanyPermissionMap::allows('procurement_manager', 'procurement.approve'));
    }

    public function test_viewer_is_read_only_and_unknown_roles_deny_by_default(): void
    {
        self::assertTrue(CompanyPermissionMap::allows('viewer', 'inventory.view'));
        self::assertFalse(CompanyPermissionMap::allows('viewer', 'inventory.manage'));
        self::assertFalse(CompanyPermissionMap::allows('viewer', 'payroll.view'));
        self::assertFalse(CompanyPermissionMap::allows('unrecognized_role', 'inventory.view'));
        self::assertFalse(CompanyPermissionMap::allows('owner', ''));
    }
}
