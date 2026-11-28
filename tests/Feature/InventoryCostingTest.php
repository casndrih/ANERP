<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InventoryCostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_consumes_oldest_cost_layers_and_posts_exact_issue_value(): void
    {
        [$company, $user, $product, $warehouse] = $this->fixture('fifo');

        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 5, $user->id);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 10, $user->id);
        $issue = InventoryLedger::record($company->id, $warehouse->id, $product->id, 'issue', 12, 999, $user->id);

        $this->assertSame(70.0, (float) $issue->cost_value);
        $this->assertSame(5.83, (float) $issue->unit_cost);
        $layers = DB::table('inventory_cost_layers')->where('company_id', $company->id)->orderBy('id')->get();
        $this->assertSame(0.0, (float) $layers[0]->quantity_remaining);
        $this->assertSame(8.0, (float) $layers[1]->quantity_remaining);

        $balance = DB::table('inventory_cost_balances')->where('company_id', $company->id)->first();
        $this->assertSame(8.0, (float) $balance->quantity_on_hand);
        $this->assertSame(80.0, (float) $balance->inventory_value);
        $this->assertSame(70.0, (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_entries.journal_number', 'INV-GL-'.$issue->id)
            ->where('accounts.account_code', '5000')
            ->sum('journal_lines.debit'));
    }

    public function test_weighted_average_uses_moving_average_for_issue_cost(): void
    {
        [$company, $user, $product, $warehouse] = $this->fixture('weighted_average');

        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 5, $user->id);
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 10, $user->id);
        $issue = InventoryLedger::record($company->id, $warehouse->id, $product->id, 'issue', 10, 999, $user->id);

        $this->assertSame(7.5, (float) $issue->unit_cost);
        $this->assertSame(75.0, (float) $issue->cost_value);
        $balance = DB::table('inventory_cost_balances')->where('company_id', $company->id)->first();
        $this->assertSame(10.0, (float) $balance->quantity_on_hand);
        $this->assertSame(75.0, (float) $balance->inventory_value);
    }

    private function fixture(string $method): array
    {
        $company = Company::create(['name' => 'Costing Test Co', 'is_active' => true]);
        $user = User::create(['name' => 'Costing Tester', 'email' => uniqid().'@example.test', 'password' => 'password']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH-COST', 'name' => 'Cost Warehouse']);
        $product = Product::create([
            'company_id' => $company->id, 'sku' => 'COST-'.uniqid(),
            'name' => 'Costed Product', 'unit' => 'each', 'standard_cost' => 5,
            'selling_price' => 8, 'track_stock' => true,
        ]);
        DB::table('inventory_valuation_settings')->insert([
            'company_id' => $company->id, 'valuation_method' => $method,
            'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$company, $user, $product, $warehouse];
    }
}
