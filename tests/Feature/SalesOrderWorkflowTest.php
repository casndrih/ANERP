<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SalesOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_order_reserves_stock_fulfils_to_invoice_and_posts_cost_of_sales(): void
    {
        [$company, $branch, $warehouse, $customer, $product, $creator, $approver, $fulfiller, $poster] = $this->fixture();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 3, $creator->id, 'test_opening');

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('sales.orders.store'), [
                'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id,
                'order_number' => 'SO-001', 'order_date' => '2026-11-28',
                'gst_treatment' => 'standard', 'lines' => [
                    ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $order = SalesOrder::where('order_number', 'SO-001')->firstOrFail();
        self::assertSame(200.0, (float) $order->net_amount);
        self::assertSame(20.0, (float) $order->gst_amount);
        self::assertSame(220.0, (float) $order->total_amount);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('sales.orders.approve', $order->id))->assertForbidden();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('sales.orders.approve', $order->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'approved']);

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('sales.orders.fulfill', $order->id))->assertForbidden();
        $this->actingAs($fulfiller)->withSession($this->sessionData($company, $fulfiller))
            ->post(route('sales.orders.fulfill', $order->id))->assertRedirect()->assertSessionHasNoErrors();

        $order->refresh();
        self::assertSame('fulfilled', $order->status);
        self::assertNotNull($order->customer_invoice_id);
        $invoice = DB::table('customer_invoices')->where('id', $order->customer_invoice_id)->first();
        self::assertSame('draft', $invoice->status);
        self::assertSame(220.0, (float) $invoice->amount);
        self::assertSame(1, DB::table('customer_invoice_lines')->where('customer_invoice_id', $invoice->id)->count());
        self::assertSame(8.0, \App\Support\InventoryLedger::onHand($company->id, $warehouse->id, $product->id));

        $this->actingAs($poster)->withSession($this->sessionData($company, $poster))
            ->post(route('finance.invoices.post', $invoice->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_invoices', ['id' => $invoice->id, 'status' => 'posted']);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => Account::where('company_id', $company->id)->where('account_code', '2100')->value('id'),
            'debit' => 0, 'credit' => 20,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => Account::where('company_id', $company->id)->where('account_code', '5000')->value('id'),
            'debit' => 6, 'credit' => 0,
        ]);
    }

    public function test_approved_orders_reserve_stock_against_overbooking(): void
    {
        [$company, $branch, $warehouse, $customer, $product, $creator, $approver, $fulfiller, $poster] = $this->fixture();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 3, $creator->id, 'test_opening');

        foreach ([['SO-RES-1', 7], ['SO-RES-2', 4]] as [$number, $quantity]) {
            $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
                ->post(route('sales.orders.store'), [
                    'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id,
                    'order_number' => $number, 'order_date' => '2026-11-28',
                    'gst_treatment' => 'out_of_scope', 'lines' => [
                        ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => 10],
                    ],
                ])->assertRedirect()->assertSessionHasNoErrors();
            $order = SalesOrder::where('order_number', $number)->firstOrFail();
            $response = $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
                ->post(route('sales.orders.approve', $order->id));
            if ($number === 'SO-RES-1') {
                $response->assertRedirect()->assertSessionHasNoErrors();
            } else {
                $response->assertSessionHasErrors('stock');
            }
        }
        $this->assertDatabaseHas('sales_orders', ['order_number' => 'SO-RES-1', 'status' => 'approved']);
        $this->assertDatabaseHas('sales_orders', ['order_number' => 'SO-RES-2', 'status' => 'draft']);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Sales Workflow Co', 'is_active' => true, 'gst_registered' => true, 'gst_registration_number' => 'GST-CO-100']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH-SALES', 'name' => 'Sales Warehouse', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CUST-SALES', 'name' => 'Sales Customer', 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'sku' => 'SKU-SALES', 'name' => 'Test Product',
            'unit' => 'each', 'standard_cost' => 3, 'selling_price' => 100, 'track_stock' => true, 'is_active' => true,
        ]);
        $creator = $this->owner($company, 'sales-creator');
        $approver = $this->owner($company, 'sales-approver');
        $fulfiller = $this->owner($company, 'sales-fulfiller');
        $poster = $this->owner($company, 'sales-poster');
        return [$company, $branch, $warehouse, $customer, $product, $creator, $approver, $fulfiller, $poster];
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
