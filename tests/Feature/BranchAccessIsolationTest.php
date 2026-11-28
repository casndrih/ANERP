<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BranchAccessIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_workspace_and_employee_creation_are_limited_to_assigned_branch(): void
    {
        [$user, $company, $branchA, $branchB] = $this->branchUser('hr_manager');
        $employeeA = $this->employee($company, $branchA, 'EMP-A');
        $this->employee($company, $branchB, 'EMP-B');

        $response = $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->get(route('hr.index'));

        $response->assertOk()->assertViewHas('employees', function ($employees) {
            return $employees->contains('employee_number', 'EMP-A')
                && !$employees->contains('employee_number', 'EMP-B');
        });

        $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->post(route('hr.employees.store'), [
                'employee_number' => 'EMP-FORCED-A',
                'first_name' => 'Branch',
                'last_name' => 'NewHire',
                'branch_id' => $branchB->id,
                'base_salary' => 1200,
                'pay_frequency' => 'monthly',
                'tax_residency' => 'resident',
            ])->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'company_id' => $company->id,
            'employee_number' => 'EMP-FORCED-A',
            'branch_id' => $branchA->id,
        ]);
    }

    public function test_branch_user_cannot_submit_leave_for_another_branch_employee(): void
    {
        [$user, $company, $branchA, $branchB] = $this->branchUser('hr_manager');
        $employeeB = $this->employee($company, $branchB, 'EMP-LEAVE-B');
        $leaveType = \App\Models\LeaveType::create([
            'company_id' => $company->id, 'code' => 'ANNUAL', 'name' => 'Annual Leave',
            'annual_entitlement' => 14, 'is_paid' => true, 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->post(route('hr.leave.store'), [
                'employee_id' => $employeeB->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-11-30',
                'end_date' => '2026-12-01',
                'days' => 2,
            ])->assertSessionHasErrors('employee_id');
    }

    public function test_warehouse_and_procurement_workspaces_only_show_assigned_branch_records(): void
    {
        [$user, $company, $branchA, $branchB] = $this->branchUser('procurement_manager');
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'SUP-BR', 'name' => 'Shared Supplier']);
        $warehouseA = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branchA->id, 'code' => 'WH-A', 'name' => 'Branch A Warehouse']);
        $warehouseB = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branchB->id, 'code' => 'WH-B', 'name' => 'Branch B Warehouse']);

        PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouseA->id, 'order_number' => 'PO-BR-A',
            'order_date' => '2026-11-20', 'status' => 'draft', 'currency' => 'PGK',
            'subtotal' => 10, 'tax_total' => 0, 'total' => 10, 'created_by' => $user->id,
        ]);
        PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouseB->id, 'order_number' => 'PO-BR-B',
            'order_date' => '2026-11-20', 'status' => 'draft', 'currency' => 'PGK',
            'subtotal' => 20, 'tax_total' => 0, 'total' => 20, 'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->get(route('procurement.orders.index'))
            ->assertOk()
            ->assertViewHas('orders', fn($orders) => $orders->contains('order_number', 'PO-BR-A')
                && !$orders->contains('order_number', 'PO-BR-B'))
            ->assertViewHas('warehouses', fn($warehouses) => $warehouses->contains('id', $warehouseA->id)
                && !$warehouses->contains('id', $warehouseB->id));

        $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->get(route('inventory.warehouses.index'))
            ->assertOk()
            ->assertViewHas('warehouses', fn($warehouses) => $warehouses->contains('id', $warehouseA->id)
                && !$warehouses->contains('id', $warehouseB->id));
    }

    public function test_stock_movement_workspace_only_shows_assigned_branch_movements(): void
    {
        [$user, $company, $branchA, $branchB] = $this->branchUser('inventory_manager');
        $warehouseA = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branchA->id, 'code' => 'ST-A', 'name' => 'Stock A']);
        $warehouseB = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branchB->id, 'code' => 'ST-B', 'name' => 'Stock B']);
        $product = Product::create([
            'company_id' => $company->id, 'sku' => 'BR-STOCK', 'name' => 'Shared Product',
            'unit' => 'each', 'standard_cost' => 5, 'selling_price' => 8, 'track_stock' => true,
        ]);
        $movementA = StockMovement::create([
            'company_id' => $company->id, 'warehouse_id' => $warehouseA->id,
            'product_id' => $product->id, 'movement_type' => 'opening',
            'quantity' => 10, 'unit_cost' => 5, 'occurred_at' => now(),
            'created_by' => $user->id,
        ]);
        $movementB = StockMovement::create([
            'company_id' => $company->id, 'warehouse_id' => $warehouseB->id,
            'product_id' => $product->id, 'movement_type' => 'opening',
            'quantity' => 20, 'unit_cost' => 5, 'occurred_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->get(route('inventory.movements.index'))
            ->assertOk()
            ->assertViewHas('movements', fn($movements) => $movements->contains('id', $movementA->id)
                && !$movements->contains('id', $movementB->id))
            ->assertViewHas('balances', fn($balances) => $balances->contains('warehouse_name', 'Stock A')
                && !$balances->contains('warehouse_name', 'Stock B'));
    }

    public function test_branch_payroll_report_only_aggregates_branch_employee_lines(): void
    {
        [$user, $company, $branchA, $branchB] = $this->branchUser('payroll_manager');
        $employeeA = $this->employee($company, $branchA, 'PAY-A');
        $employeeB = $this->employee($company, $branchB, 'PAY-B');
        $run = PayrollRun::create([
            'company_id' => $company->id, 'run_number' => 'PAY-BRANCH-MIXED',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-14',
            'created_by' => $user->id, 'currency' => 'PGK', 'pay_frequency' => 'fortnightly',
            'status' => 'approved',
        ]);
        PayrollLine::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employeeA->id,
            'base_pay' => 1000, 'gross_pay' => 1000, 'tax_deduction' => 100,
            'employee_super' => 60, 'employer_super' => 84, 'net_pay' => 840,
        ]);
        PayrollLine::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employeeB->id,
            'base_pay' => 2000, 'gross_pay' => 2000, 'tax_deduction' => 200,
            'employee_super' => 120, 'employer_super' => 168, 'net_pay' => 1680,
        ]);

        $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->get(route('reports.index', [
                'report' => 'payroll', 'from' => '2026-11-01', 'to' => '2026-11-30',
            ]))
            ->assertOk()
            ->assertViewHas('rows', function ($rows) {
                return $rows->count() === 1
                    && $rows->first()->employees === 1
                    && (float) $rows->first()->gross_pay === 1000.0;
            });
    }

    public function test_branch_restricted_finance_user_can_open_branch_scoped_finance_workspace(): void
    {
        [$user, $company] = $this->branchUser('finance_manager');

        $this->actingAs($user)
            ->withSession($this->companySession($user, $company))
            ->get(route('finance.index'))
            ->assertOk();
    }

    private function branchUser(string $role): array
    {
        $user = User::create([
            'name' => 'Branch Security Test',
            'email' => 'branch-' . uniqid() . '@example.test',
            'password' => 'test-password',
        ]);
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_secret' => 'test-encrypted-secret'])->save();
        $company = Company::create(['name' => 'Branch Test Company', 'is_active' => true]);
        $branchA = Branch::create(['company_id' => $company->id, 'code' => 'A', 'name' => 'Branch A', 'is_active' => true]);
        $branchB = Branch::create(['company_id' => $company->id, 'code' => 'B', 'name' => 'Branch B', 'is_active' => true]);
        $user->companies()->attach($company->id, [
            'branch_id' => $branchA->id, 'role_code' => $role, 'is_active' => true,
        ]);

        return [$user, $company, $branchA, $branchB];
    }

    private function employee(Company $company, Branch $branch, string $number): Employee
    {
        return Employee::create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'employee_number' => $number, 'first_name' => 'Test', 'last_name' => $number,
            'base_salary' => 1000, 'pay_frequency' => 'fortnightly',
            'tax_residency' => 'resident', 'status' => 'active',
        ]);
    }

    private function companySession(User $user, Company $company): array
    {
        return ['active_company_id' => $company->id, 'mfa_verified_user_id' => $user->id];
    }
}
