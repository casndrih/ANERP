<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StockCountApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_count_requires_independent_approval_and_posts_variance(): void
    {
        [$company, $warehouse, $product, $creator, $approver] = $this->fixture();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 5, $creator->id);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('inventory.counts.store'), [
                'warehouse_id' => $warehouse->id,
                'count_date' => '2026-11-28',
                'lines' => [['product_id' => $product->id, 'counted_quantity' => 8.5]],
            ])->assertRedirect();

        $countId = (int) \DB::table('stock_counts')->value('id');
        $this->assertDatabaseHas('stock_count_lines', [
            'stock_count_id' => $countId, 'system_quantity' => 10,
            'counted_quantity' => 8.5, 'variance_quantity' => -1.5,
        ]);

        $this->actingAs($creator)->withSession($this->session($company, $creator))
            ->post(route('inventory.counts.submit', $countId))->assertRedirect();

        $this->actingAs($creator)->withSession($this->session($company, $creator))
            ->post(route('inventory.counts.approve', $countId))->assertForbidden();

        $this->actingAs($approver)->withSession($this->session($company, $approver))
            ->post(route('inventory.counts.approve', $countId))->assertRedirect();

        $this->assertDatabaseHas('stock_counts', ['id' => $countId, 'status' => 'posted', 'approved_by' => $approver->id]);
        $this->assertDatabaseHas('stock_movements', [
            'company_id' => $company->id, 'warehouse_id' => $warehouse->id,
            'product_id' => $product->id, 'movement_type' => 'adjustment_out',
            'quantity' => 1.5, 'reference_type' => 'stock_count', 'reference_id' => $countId,
        ]);
        $this->assertSame(8.5, InventoryLedger::onHand($company->id, $warehouse->id, $product->id));
    }

    public function test_stock_count_approval_rejects_stock_that_changed_after_capture(): void
    {
        [$company, $warehouse, $product, $creator, $approver] = $this->fixture();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'opening', 10, 5, $creator->id);

        $this->actingAs($creator)->withSession($this->session($company, $creator))
            ->post(route('inventory.counts.store'), [
                'warehouse_id' => $warehouse->id,
                'count_date' => '2026-11-28',
                'lines' => [['product_id' => $product->id, 'counted_quantity' => 9]],
            ])->assertRedirect();
        $countId = (int) \DB::table('stock_counts')->value('id');

        $this->actingAs($creator)->withSession($this->session($company, $creator))
            ->post(route('inventory.counts.submit', $countId))->assertRedirect();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'issue', 1, 5, $creator->id);

        $this->actingAs($approver)->withSession($this->session($company, $approver))
            ->post(route('inventory.counts.approve', $countId))
            ->assertSessionHasErrors('count');

        $this->assertDatabaseHas('stock_counts', ['id' => $countId, 'status' => 'submitted']);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Stock Count Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'WH-COUNT', 'name' => 'Count Warehouse']);
        $product = Product::create([
            'company_id' => $company->id, 'sku' => 'COUNT-'.uniqid(),
            'name' => 'Count Product', 'unit' => 'each', 'standard_cost' => 5,
            'selling_price' => 8, 'track_stock' => true,
        ]);
        $creator = $this->owner($company, 'creator');
        $approver = $this->owner($company, 'approver');
        return [$company, $warehouse, $product, $creator, $approver];
    }

    private function owner(Company $company, string $suffix): User
    {
        $user = User::create([
            'name' => 'Stock Count '.$suffix,
            'email' => $suffix.'-'.uniqid().'@example.test',
            'password' => 'password',
        ]);
        $user->forceFill(['mfa_enabled_at' => now(), 'mfa_secret' => 'encrypted-test-secret'])->save();
        $user->companies()->attach($company->id, ['role_code' => 'owner', 'is_active' => true]);
        return $user;
    }

    private function sessionData(Company $company, User $user): array
    {
        return ['active_company_id' => $company->id, 'mfa_verified_user_id' => $user->id];
    }
}
