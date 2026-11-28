<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CustomerSalesReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_resellable_return_reverses_stock_cost_gst_and_receivable_with_maker_checker(): void
    {
        [$company, $branch, $warehouse, $customer, $product, $invoice, $invoiceLine, $creator, $poster, $accounts] = $this->fixture();
        $this->createDraftReturn($company, $warehouse, $invoice, $invoiceLine, $creator, 'SR-RESALE-001', 'resellable', 1)
            ->assertRedirect()->assertSessionHasNoErrors();

        $salesReturn = DB::table('customer_sales_returns')->where('return_number', 'SR-RESALE-001')->first();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('sales.returns.post', $salesReturn->id))->assertForbidden();

        $this->actingAs($poster)->withSession($this->sessionData($company, $poster))
            ->post(route('sales.returns.post', $salesReturn->id))->assertRedirect()->assertSessionHasNoErrors();

        $salesReturn = DB::table('customer_sales_returns')->where('id', $salesReturn->id)->first();
        self::assertSame('posted', $salesReturn->status);
        self::assertEquals(110.00, (float) $salesReturn->total_amount);
        self::assertEquals(100.00, (float) $salesReturn->net_amount);
        self::assertEquals(10.00, (float) $salesReturn->gst_amount);
        self::assertSame(6.0, InventoryLedger::onHand($company->id, $warehouse->id, $product->id));

        $returnLine = DB::table('customer_sales_return_lines')->where('customer_sales_return_id', $salesReturn->id)->first();
        self::assertNotNull($returnLine->original_issue_movement_id);
        self::assertNotNull($returnLine->stock_movement_id);
        $movement = DB::table('stock_movements')->where('id', $returnLine->stock_movement_id)->first();
        self::assertSame('customer_return', $movement->movement_type);
        self::assertEquals(3.00, (float) $movement->cost_value);

        $creditNote = DB::table('customer_credit_notes')->where('source_sales_return_id', $salesReturn->id)->first();
        self::assertNotNull($creditNote);
        self::assertSame('posted', $creditNote->status);
        self::assertEquals(110.00, (float) $creditNote->amount);

        $this->assertDatabaseHas('journal_lines', ['account_id' => $accounts['revenue']->id, 'debit' => '100.00', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $accounts['gst']->id, 'debit' => '10.00', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $accounts['ar']->id, 'debit' => '0.00', 'credit' => '110.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $accounts['inventory']->id, 'debit' => '3.00', 'credit' => '0.00']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $accounts['cogs']->id, 'debit' => '0.00', 'credit' => '3.00']);

        $this->actingAs($poster)->withSession($this->sessionData($company, $poster))
            ->get(route('reports.index', ['report' => 'receivables', 'to' => '2026-11-28']))
            ->assertOk()->assertSee('440.00');
    }

    public function test_paid_invoice_return_credits_customer_deposit_for_amount_above_open_ar_and_damaged_goods_are_not_restocked(): void
    {
        [$company, $branch, $warehouse, $customer, $product, $invoice, $invoiceLine, $creator, $poster, $accounts] = $this->fixture();
        $bank = Account::create([
            'company_id' => $company->id, 'account_code' => '1000', 'name' => 'Operating bank',
            'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true,
        ]);
        $bankAccount = BankAccount::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'account_id' => $bank->id,
            'code' => 'BANK-RET', 'name' => 'Return Test Bank', 'currency' => 'PGK', 'is_active' => true,
        ]);
        $receiptId = DB::table('customer_receipts')->insertGetId([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'bank_account_id' => $bankAccount->id, 'receipt_number' => 'RCPT-RET-500',
            'receipt_date' => '2026-11-20', 'description' => 'Existing customer settlement',
            'amount' => 500, 'currency' => 'PGK', 'status' => 'posted',
            'created_by' => $creator->id, 'posted_by' => $poster->id, 'posted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customer_receipt_allocations')->insert([
            'customer_receipt_id' => $receiptId, 'customer_invoice_id' => $invoice->id,
            'amount' => 500, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->createDraftReturn($company, $warehouse, $invoice, $invoiceLine, $creator, 'SR-DAMAGED-001', 'damaged', 1)
            ->assertRedirect()->assertSessionHasNoErrors();
        $salesReturn = DB::table('customer_sales_returns')->where('return_number', 'SR-DAMAGED-001')->first();

        $this->actingAs($poster)->withSession($this->sessionData($company, $poster))
            ->post(route('sales.returns.post', $salesReturn->id))->assertRedirect()->assertSessionHasNoErrors();

        self::assertSame(5.0, InventoryLedger::onHand($company->id, $warehouse->id, $product->id));
        $this->assertDatabaseHas('customer_sales_return_lines', [
            'customer_sales_return_id' => $salesReturn->id, 'condition' => 'damaged',
            'stock_movement_id' => null,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $accounts['ar']->id, 'debit' => '0.00', 'credit' => '50.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => $accounts['deposits']->id, 'debit' => '0.00', 'credit' => '60.00',
        ]);
    }

    public function test_return_quantity_cannot_exceed_invoiced_quantity_and_cancelled_draft_releases_reservation(): void
    {
        [$company, $branch, $warehouse, $customer, $product, $invoice, $invoiceLine, $creator] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('sales.returns.store'), [
                'invoice_id' => $invoice->id, 'warehouse_id' => $warehouse->id,
                'return_number' => 'SR-TOO-MANY', 'return_date' => '2026-11-28',
                'reason' => 'Excess return', 'lines' => [[
                    'invoice_line_id' => $invoiceLine->id, 'quantity' => 6,
                    'condition' => 'resellable',
                ]],
            ])->assertSessionHasErrors('lines');

        $this->createDraftReturn($company, $warehouse, $invoice, $invoiceLine, $creator, 'SR-CANCEL-001', 'resellable', 4)
            ->assertRedirect()->assertSessionHasNoErrors();
        $draft = DB::table('customer_sales_returns')->where('return_number', 'SR-CANCEL-001')->first();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('sales.returns.cancel', $draft->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_sales_returns', ['id' => $draft->id, 'status' => 'cancelled']);

        $this->createDraftReturn($company, $warehouse, $invoice, $invoiceLine, $creator, 'SR-AFTER-CANCEL', 'resellable', 5)
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function createDraftReturn(Company $company, Warehouse $warehouse, CustomerInvoice $invoice, CustomerInvoiceLine $invoiceLine, User $creator, string $number, string $condition, float $quantity)
    {
        return $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('sales.returns.store'), [
                'invoice_id' => $invoice->id,
                'warehouse_id' => $warehouse->id,
                'return_number' => $number,
                'return_date' => '2026-11-28',
                'reason' => 'Customer return test',
                'lines' => [[
                    'invoice_line_id' => $invoiceLine->id,
                    'quantity' => $quantity,
                    'condition' => $condition,
                    'condition_notes' => 'Test condition assessment',
                ]],
            ]);
    }

    private function fixture(): array
    {
        $company = Company::create([
            'name' => 'Sales Returns Co', 'is_active' => true,
            'gst_registered' => true, 'gst_registration_number' => 'GST-RET-001',
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH-RET', 'name' => 'Returns Warehouse', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CUST-RET', 'name' => 'Return Customer', 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'sku' => 'SKU-RET', 'name' => 'Returned Product',
            'unit' => 'each', 'standard_cost' => 3, 'selling_price' => 110, 'track_stock' => true, 'is_active' => true,
        ]);
        $creator = $this->owner($company, 'return-creator');
        $poster = $this->owner($company, 'return-poster');

        $revenue = Account::create(['company_id' => $company->id, 'account_code' => '4000', 'name' => 'Sales revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]);
        $ar = Account::create(['company_id' => $company->id, 'account_code' => '1100', 'name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $gst = Account::create(['company_id' => $company->id, 'account_code' => '2100', 'name' => 'GST output', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);
        $deposits = Account::create(['company_id' => $company->id, 'account_code' => '2500', 'name' => 'Customer deposits and advances', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_active' => true]);

        $order = SalesOrder::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id, 'order_number' => 'SO-RET-001',
            'order_date' => '2026-11-01', 'status' => 'fulfilled', 'gst_treatment' => 'standard',
            'net_amount' => 500, 'gst_rate' => 10, 'gst_amount' => 50, 'total_amount' => 550,
            'created_by' => $creator->id,
        ]);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 3, $creator->id, 'test_opening');
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'issue', 5, 3, $creator->id, 'sales_order', $order->id, 'Original sale fulfilment');

        $invoice = CustomerInvoice::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'sales_order_id' => $order->id, 'revenue_account_id' => $revenue->id,
            'invoice_number' => 'INV-RET-001', 'invoice_date' => '2026-11-01',
            'amount' => 550, 'net_amount' => 500, 'gst_treatment' => 'standard',
            'gst_rate' => 10, 'gst_amount' => 50, 'currency' => 'PGK',
            'description' => 'Original sales invoice', 'status' => 'posted',
            'created_by' => $creator->id, 'posted_by' => $poster->id, 'posted_at' => now(),
        ]);
        $invoiceLine = CustomerInvoiceLine::create([
            'customer_invoice_id' => $invoice->id, 'product_id' => $product->id,
            'description' => 'Returned Product', 'quantity' => 5, 'unit_price' => 100,
            'net_amount' => 500, 'gst_rate' => 10, 'gst_amount' => 50,
            'total_amount' => 550,
        ]);

        return [$company, $branch, $warehouse, $customer, $product, $invoice, $invoiceLine, $creator, $poster, [
            'revenue' => $revenue, 'ar' => $ar, 'gst' => $gst, 'deposits' => $deposits,
            'inventory' => Account::where('company_id', $company->id)->where('account_code', '1200')->firstOrFail(),
            'cogs' => Account::where('company_id', $company->id)->where('account_code', '5000')->firstOrFail(),
        ]];
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
