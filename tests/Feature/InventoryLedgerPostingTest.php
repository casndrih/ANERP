<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryLedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_and_receipt_movements_generate_balanced_inventory_journals(): void
    {
        [$company, $user, $branch] = $this->fixture();
        $product = $this->product($company);
        $warehouse = $this->warehouse($company, $branch, 'WH-A');

        $opening = InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 5, $user->id);
        $issue = InventoryLedger::record($company->id, $warehouse->id, $product->id, 'issue', 2, 5, $user->id);

        $this->assertDatabaseHas('journal_entries', [
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'source' => 'inventory_movement', 'status' => 'posted',
            'journal_number' => 'INV-GL-'.$opening->id,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'source' => 'inventory_movement', 'status' => 'posted',
            'journal_number' => 'INV-GL-'.$issue->id,
        ]);
        $this->assertDatabaseCount('stock_movement_journals', 3);
        $this->assertSame(0.0, (float) \DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_entries.source', 'inventory_movement')
            ->selectRaw('ROUND(SUM(debit) - SUM(credit), 2) as difference')
            ->value('difference'));
    }

    public function test_cross_branch_transfer_posts_two_branch_balanced_clearing_journals(): void
    {
        [$company, $user, $branchA, $branchB] = $this->fixture(true);
        $product = $this->product($company);
        $source = $this->warehouse($company, $branchA, 'WH-SOURCE');
        $destination = $this->warehouse($company, $branchB, 'WH-DEST');
        InventoryLedger::record($company->id, $source->id, $product->id, 'opening', 10, 5, $user->id);
        $transfer = StockTransfer::create([
            'company_id' => $company->id, 'transfer_number' => 'TRF-LEDGER-01',
            'from_warehouse_id' => $source->id, 'to_warehouse_id' => $destination->id,
            'status' => 'posted', 'created_by' => $user->id,
        ]);

        InventoryLedger::record($company->id, $source->id, $product->id, 'transfer_out', 4, 5, $user->id, 'stock_transfer', $transfer->id);
        InventoryLedger::record($company->id, $destination->id, $product->id, 'transfer_in', 4, 5, $user->id, 'stock_transfer', $transfer->id);

        $this->assertDatabaseCount('stock_movement_journals', 2);
        $this->assertDatabaseHas('journal_entries', [
            'company_id' => $company->id, 'branch_id' => $branchA->id,
            'source' => 'stock_transfer', 'status' => 'posted',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'company_id' => $company->id, 'branch_id' => $branchB->id,
            'source' => 'stock_transfer', 'status' => 'posted',
        ]);
    }

    private function fixture(bool $twoBranches = false): array
    {
        $company = Company::create(['name' => 'Inventory Ledger Co', 'is_active' => true]);
        $user = User::create(['name' => 'Inventory Operator', 'email' => uniqid().'@example.test', 'password' => 'password']);
        $branchA = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $branchB = $twoBranches
            ? Branch::create(['company_id' => $company->id, 'code' => 'HGN', 'name' => 'Hagen', 'is_active' => true])
            : $branchA;
        return [$company, $user, $branchA, $branchB];
    }

    private function product(Company $company): Product
    {
        return Product::create([
            'company_id' => $company->id, 'sku' => 'INV-GL-'.uniqid(),
            'name' => 'Ledger Product', 'unit' => 'each',
            'standard_cost' => 5, 'selling_price' => 8, 'track_stock' => true,
        ]);
    }

    private function warehouse(Company $company, Branch $branch, string $code): Warehouse
    {
        return Warehouse::create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'code' => $code, 'name' => $code,
        ]);
    }
}
