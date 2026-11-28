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

final class SupplierInvoiceMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_goods_invoice_must_match_received_quantity_and_po_price_then_posts_grni_to_ap(): void
    {
        [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense] = $this->fixture();
        $line->update(['quantity_received' => 10]);
        $bill = $this->bill($company, $supplier, $branch, $creator, $expense, 50);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), [
                'purchase_order_id' => $po->id,
                'invoice_type' => 'goods',
                'lines' => [['purchase_order_line_id' => $line->id, 'quantity_billed' => 10, 'unit_price' => 5]],
            ])->assertRedirect();

        $this->assertDatabaseHas('supplier_bills', ['id' => $bill->id, 'match_status' => 'matched']);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id), ['allocation_basis' => 'quantity'])->assertRedirect();

        $this->assertDatabaseHas('supplier_bills', ['id' => $bill->id, 'status' => 'posted', 'match_status' => 'approved']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => Account::where('company_id', $company->id)->where('account_code', '2600')->value('id'), 'debit' => 50, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => Account::where('company_id', $company->id)->where('account_code', '2000')->value('id'), 'debit' => 0, 'credit' => 50]);
    }

    public function test_po_matched_goods_invoice_posts_eligible_input_gst_separately(): void
    {
        [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense] = $this->fixture();
        $company->update(['gst_registered' => true, 'gst_registration_number' => 'GST-CO-001']);
        $line->update(['quantity_received' => 10]);
        $bill = $this->bill($company, $supplier, $branch, $creator, $expense, 55);
        $bill->update([
            'net_amount' => 50, 'gst_treatment' => 'standard', 'gst_rate' => 10, 'gst_amount' => 5,
            'supplier_gst_registered' => true, 'supplier_gst_registration_number' => 'GST-SUP-001',
            'tax_invoice_reference' => 'TAX-INV-001', 'input_tax_credit_eligible' => true,
        ]);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), [
                'purchase_order_id' => $po->id,
                'invoice_type' => 'goods',
                'lines' => [['purchase_order_line_id' => $line->id, 'quantity_billed' => 10, 'unit_price' => 5]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $grni = Account::where('company_id', $company->id)->where('account_code', '2600')->value('id');
        $inputTax = Account::where('company_id', $company->id)->where('account_code', '1300')->value('id');
        $ap = Account::where('company_id', $company->id)->where('account_code', '2000')->value('id');
        $this->assertDatabaseHas('journal_lines', ['account_id' => $grni, 'debit' => '50.00', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $inputTax, 'debit' => '5.00', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ap, 'debit' => '0.00', 'credit' => '55.00']);
    }

    public function test_po_matched_invoice_with_unverified_gst_evidence_is_blocked(): void
    {
        [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense] = $this->fixture();
        $company->update(['gst_registered' => true, 'gst_registration_number' => 'GST-CO-001']);
        $line->update(['quantity_received' => 10]);
        $bill = $this->bill($company, $supplier, $branch, $creator, $expense, 55);
        $bill->update(['net_amount' => 50, 'gst_treatment' => 'standard', 'gst_rate' => 10, 'gst_amount' => 5]);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), [
                'purchase_order_id' => $po->id,
                'invoice_type' => 'goods',
                'lines' => [['purchase_order_line_id' => $line->id, 'quantity_billed' => 10, 'unit_price' => 5]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id))
            ->assertSessionHasErrors('bill');
        $this->assertDatabaseHas('supplier_bills', ['id' => $bill->id, 'status' => 'draft', 'match_status' => 'matched']);
    }

    public function test_landed_cost_is_split_between_stock_on_hand_and_consumed_stock(): void
    {
        [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense] = $this->fixture();
        DB::table('inventory_valuation_settings')->insert([
            'company_id' => $company->id, 'valuation_method' => 'fifo',
            'updated_by' => $creator->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 5, $creator->id, 'purchase_order', $po->id);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'issue', 4, 5, $creator->id);
        $line->update(['quantity_received' => 10]);
        $bill = $this->bill($company, $supplier, $branch, $creator, $expense, 100);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), [
                'purchase_order_id' => $po->id,
                'invoice_type' => 'landed_cost',
            ])->assertRedirect();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id), ['allocation_basis' => 'quantity'])->assertRedirect();

        $balance = DB::table('inventory_cost_balances')->where('company_id', $company->id)->first();
        $this->assertSame(6.0, (float) $balance->quantity_on_hand);
        $this->assertSame(90.0, (float) $balance->inventory_value);
        $this->assertDatabaseHas('landed_cost_allocations', [
            'supplier_bill_id' => $bill->id, 'allocation_basis' => 'quantity', 'allocated_amount' => 100,
            'inventory_amount' => 60, 'cogs_amount' => 40,
        ]);
        $this->assertDatabaseHas('supplier_bills', ['id' => $bill->id, 'status' => 'posted']);
    }

    public function test_landed_cost_can_be_allocated_by_product_weight(): void
    {
        [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense] = $this->fixture();
        $secondProduct = Product::create([
            'company_id' => $company->id, 'sku' => 'SKU-SECOND-'.uniqid(), 'name' => 'Light Stock',
            'unit' => 'each', 'standard_cost' => 5, 'selling_price' => 10, 'track_stock' => true, 'is_active' => true,
            'weight_kg' => 1, 'volume_m3' => 0.25,
        ]);
        $secondLine = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'product_id' => $secondProduct->id,
            'quantity_ordered' => 10, 'quantity_received' => 10, 'unit_cost' => 5, 'line_total' => 50,
        ]);
        $line->update(['quantity_received' => 10]);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 5, $creator->id, 'purchase_order', $po->id);
        InventoryLedger::record($company->id, $warehouse->id, $secondProduct->id, 'purchase_receipt', 10, 5, $creator->id, 'purchase_order', $po->id);
        $bill = $this->bill($company, $supplier, $branch, $creator, $expense, 100);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), ['purchase_order_id' => $po->id, 'invoice_type' => 'landed_cost'])
            ->assertRedirect();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id), ['allocation_basis' => 'weight'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('landed_cost_allocations', ['supplier_bill_id' => $bill->id, 'product_id' => $product->id, 'allocation_basis' => 'weight', 'allocated_amount' => 66.67]);
        $this->assertDatabaseHas('landed_cost_allocations', ['supplier_bill_id' => $bill->id, 'product_id' => $secondProduct->id, 'allocation_basis' => 'weight', 'allocated_amount' => 33.33]);
    }

    public function test_weight_allocation_requires_weight_for_every_received_product(): void
    {
        [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense] = $this->fixture();
        $product->update(['weight_kg' => null]);
        $line->update(['quantity_received' => 10]);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 5, $creator->id, 'purchase_order', $po->id);
        $bill = $this->bill($company, $supplier, $branch, $creator, $expense, 100);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), ['purchase_order_id' => $po->id, 'invoice_type' => 'landed_cost'])
            ->assertRedirect();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id), ['allocation_basis' => 'weight'])
            ->assertRedirect()->assertSessionHasErrors('allocation_basis');
    }

    public function test_landed_cost_can_be_allocated_by_product_volume(): void
    {
        [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense] = $this->fixture();
        $secondProduct = Product::create([
            'company_id' => $company->id, 'sku' => 'SKU-VOLUME-'.uniqid(), 'name' => 'Compact Stock',
            'unit' => 'each', 'standard_cost' => 5, 'selling_price' => 10, 'track_stock' => true, 'is_active' => true,
            'weight_kg' => 1, 'volume_m3' => 0.25,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'product_id' => $secondProduct->id,
            'quantity_ordered' => 10, 'quantity_received' => 10, 'unit_cost' => 5, 'line_total' => 50,
        ]);
        $line->update(['quantity_received' => 10]);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 5, $creator->id, 'purchase_order', $po->id);
        InventoryLedger::record($company->id, $warehouse->id, $secondProduct->id, 'purchase_receipt', 10, 5, $creator->id, 'purchase_order', $po->id);
        $bill = $this->bill($company, $supplier, $branch, $creator, $expense, 100);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), ['purchase_order_id' => $po->id, 'invoice_type' => 'landed_cost'])
            ->assertRedirect();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id), ['allocation_basis' => 'volume'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('landed_cost_allocations', ['supplier_bill_id' => $bill->id, 'product_id' => $product->id, 'allocation_basis' => 'volume', 'allocated_amount' => 66.67]);
        $this->assertDatabaseHas('landed_cost_allocations', ['supplier_bill_id' => $bill->id, 'product_id' => $secondProduct->id, 'allocation_basis' => 'volume', 'allocated_amount' => 33.33]);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Invoice Match Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'SUP-'.uniqid(), 'name' => 'Test Supplier', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH-'.uniqid(), 'name' => 'Test Warehouse', 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'sku' => 'SKU-'.uniqid(), 'name' => 'Test Stock',
            'unit' => 'each', 'standard_cost' => 5, 'selling_price' => 10, 'track_stock' => true, 'is_active' => true,
            'weight_kg' => 2, 'volume_m3' => 0.5,
        ]);
        $creator = $this->owner($company, 'creator');
        $approver = $this->owner($company, 'approver');
        $expense = Account::create(['company_id' => $company->id, 'account_code' => '6400', 'name' => 'Operating expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]);
        $po = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'order_number' => 'PO-'.uniqid(), 'order_date' => '2026-11-28', 'status' => 'approved',
            'currency' => 'PGK', 'subtotal' => 50, 'tax_total' => 0, 'total' => 50,
            'created_by' => $creator->id, 'approved_by' => $approver->id, 'approved_at' => now(),
        ]);
        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'product_id' => $product->id,
            'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_cost' => 5, 'line_total' => 50,
        ]);
        return [$company, $branch, $supplier, $warehouse, $product, $creator, $approver, $po, $line, $expense];
    }

    private function owner(Company $company, string $label): User
    {
        $user = User::create(['name' => $label, 'email' => $label.'-'.uniqid().'@example.test', 'password' => 'password']);
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_secret' => 'encrypted-test-secret'])->save();
        $user->companies()->attach($company->id, ['role_code' => 'owner', 'is_active' => true]);
        return $user;
    }

    private function bill(Company $company, Supplier $supplier, Branch $branch, User $creator, Account $expense, float $amount): SupplierBill
    {
        return SupplierBill::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
            'expense_account_id' => $expense->id, 'bill_number' => 'INV-'.uniqid(),
            'bill_date' => '2026-11-28', 'amount' => $amount, 'currency' => 'PGK',
            'description' => 'Supplier invoice', 'status' => 'draft', 'created_by' => $creator->id,
        ]);
    }

    private function sessionData(Company $company, User $user): array
    {
        return ['active_company_id' => $company->id, 'mfa_verified_user_id' => $user->id];
    }
}
