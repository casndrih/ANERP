<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SupplierStockReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_stock_credit_note_reverses_inventory_gst_and_ap(): void
    {
        [$company, $branch, $warehouse, $supplier, $product, $creator, $approver, $bill, $line] = $this->fixture();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 10, $creator->id, 'test_receipt');

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.stock-returns.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SUP-CN-001',
                'return_date' => '2026-11-28', 'description' => 'Damaged goods return',
                'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 2]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $return = DB::table('supplier_stock_returns')->where('credit_note_number', 'SUP-CN-001')->first();
        self::assertSame(20.0, (float) $return->net_amount);
        self::assertSame(2.0, (float) $return->gst_amount);
        self::assertSame(22.0, (float) $return->total_amount);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.stock-returns.post', $return->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $posted = DB::table('supplier_stock_returns')->where('id', $return->id)->first();
        self::assertSame('posted', $posted->status);
        self::assertNotNull($posted->journal_entry_id);
        self::assertSame(8.0, InventoryLedger::onHand($company->id, $warehouse->id, $product->id));
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $posted->journal_entry_id,
            'account_id' => Account::where('company_id', $company->id)->where('account_code', '2000')->value('id'),
            'debit' => '22.00', 'credit' => '0.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $posted->journal_entry_id,
            'account_id' => Account::where('company_id', $company->id)->where('account_code', '1300')->value('id'),
            'debit' => '0.00', 'credit' => '2.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $posted->journal_entry_id,
            'account_id' => Account::where('company_id', $company->id)->where('account_code', '1200')->value('id'),
            'debit' => '0.00', 'credit' => '20.00',
        ]);
        $this->assertDatabaseHas('stock_movement_journals', ['journal_entry_id' => $posted->journal_entry_id]);
    }

    public function test_supplier_stock_return_cannot_exceed_billed_quantity(): void
    {
        [$company, $branch, $warehouse, $supplier, $product, $creator, $approver, $bill, $line] = $this->fixture();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 10, $creator->id, 'test_receipt');

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.stock-returns.store'), [
                'bill_id' => $bill->id, 'credit_note_number' => 'SUP-CN-OVER',
                'return_date' => '2026-11-28', 'description' => 'Over-return attempt',
                'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 11]],
            ])->assertSessionHasErrors('lines');
        $this->assertDatabaseMissing('supplier_stock_returns', ['credit_note_number' => 'SUP-CN-OVER']);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Supplier Return Co', 'is_active' => true, 'gst_registered' => true, 'gst_registration_number' => 'GST-RET-001']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'RET-WH', 'name' => 'Return Warehouse', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'SUP-RET', 'name' => 'Return Supplier', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'sku' => 'RET-001', 'name' => 'Return Product', 'unit' => 'each', 'standard_cost' => 10, 'selling_price' => 20, 'track_stock' => true, 'is_active' => true]);
        $creator = $this->owner($company, 'return-creator');
        $approver = $this->owner($company, 'return-approver');
        $expense = Account::create(['company_id' => $company->id, 'account_code' => '6400', 'name' => 'Purchases', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]);
        Account::create(['company_id' => $company->id, 'account_code' => '2000', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        Account::create(['company_id' => $company->id, 'account_code' => '1200', 'name' => 'Inventory', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        Account::create(['company_id' => $company->id, 'account_code' => '1300', 'name' => 'GST input tax', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $po = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'order_number' => 'PO-RET-001', 'order_date' => '2026-11-01', 'status' => 'received',
            'currency' => 'PGK', 'subtotal' => 100, 'tax_total' => 0, 'total' => 100, 'created_by' => $creator->id,
        ]);
        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'product_id' => $product->id,
            'quantity_ordered' => 10, 'quantity_received' => 10, 'unit_cost' => 10, 'line_total' => 100,
        ]);
        $bill = SupplierBill::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'expense_account_id' => $expense->id, 'purchase_order_id' => $po->id,
            'bill_number' => 'BILL-RET-001', 'bill_date' => '2026-11-10', 'due_date' => '2026-12-10',
            'amount' => 110, 'net_amount' => 100, 'gst_treatment' => 'standard', 'gst_rate' => 10, 'gst_amount' => 10,
            'supplier_gst_registered' => true, 'supplier_gst_registration_number' => 'GST-SUP-RET',
            'tax_invoice_reference' => 'TAX-RET-001', 'input_tax_credit_eligible' => true,
            'currency' => 'PGK', 'description' => 'PO matched goods', 'invoice_type' => 'goods',
            'match_status' => 'approved', 'status' => 'posted', 'created_by' => $creator->id,
            'posted_by' => $approver->id, 'posted_at' => now(),
        ]);
        DB::table('supplier_bill_match_lines')->insert([
            'supplier_bill_id' => $bill->id, 'purchase_order_line_id' => $line->id,
            'quantity_billed' => 10, 'unit_price' => 10, 'line_total' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$company, $branch, $warehouse, $supplier, $product, $creator, $approver, $bill, $line];
    }

    private function owner(Company $company, string $label): User
    {
        $user = User::create(['name' => $label, 'email' => $label.'-'.uniqid().'@example.test', 'password' => 'password']);
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_secret' => 'encrypted-test-secret'])->save();
        $user->companies()->attach($company->id, ['role_code' => 'owner', 'is_active' => true]);
        return $user;
    }

    private function sessionData(Company $company, User $user): array
    {
        return ['active_company_id' => $company->id, 'mfa_verified_user_id' => $user->id];
    }
}
