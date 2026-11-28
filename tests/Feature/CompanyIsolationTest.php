<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_listing_never_returns_another_company_products(): void
    {
        [$user, $companyA, $companyB] = $this->twoCompanyUser();

        Product::create([
            'company_id' => $companyA->id, 'sku' => 'A-001', 'name' => 'Company A stock',
            'unit' => 'each', 'standard_cost' => 10, 'selling_price' => 15, 'track_stock' => true,
        ]);
        Product::create([
            'company_id' => $companyB->id, 'sku' => 'B-SECRET', 'name' => 'Company B confidential stock',
            'unit' => 'each', 'standard_cost' => 20, 'selling_price' => 25, 'track_stock' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession($this->verifiedCompanySession($user, $companyA))
            ->get(route('inventory.products.index'));

        $response->assertOk()
            ->assertSee('Company A stock')
            ->assertDontSee('Company B confidential stock');
    }

    public function test_product_creation_uses_active_company_not_submitted_company_id(): void
    {
        [$user, $companyA, $companyB] = $this->twoCompanyUser();

        $response = $this->actingAs($user)
            ->withSession($this->verifiedCompanySession($user, $companyA))
            ->post(route('inventory.products.store'), [
                'company_id' => $companyB->id,
                'sku' => 'ONLY-A',
                'name' => 'Created in active tenant',
                'unit' => 'each',
                'standard_cost' => 2,
                'selling_price' => 3,
                'track_stock' => 1,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('products', ['company_id' => $companyA->id, 'sku' => 'ONLY-A']);
        $this->assertDatabaseMissing('products', ['company_id' => $companyB->id, 'sku' => 'ONLY-A']);
    }

    public function test_user_cannot_select_a_company_without_active_membership(): void
    {
        [$user, , $companyB] = $this->twoCompanyUser();

        $this->actingAs($user)
            ->post(route('company.select'), ['company_id' => $companyB->id])
            ->assertForbidden();
    }

    public function test_inactive_company_cannot_be_selected_even_with_membership(): void
    {
        [$user, $companyA] = $this->twoCompanyUser();
        $inactive = Company::create(['name' => 'Inactive Company', 'is_active' => false]);
        $user->companies()->attach($inactive->id, [
            'role_code' => 'inventory_manager', 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('company.select'), ['company_id' => $inactive->id])
            ->assertForbidden();
    }

    private function twoCompanyUser(): array
    {
        $user = User::create([
            'name' => 'Tenant Test User',
            'email' => 'tenant-' . uniqid() . '@example.test',
            'password' => 'test-password',
        ]);
        $companyA = Company::create(['name' => 'Company A', 'is_active' => true]);
        $companyB = Company::create(['name' => 'Company B', 'is_active' => true]);

        $user->companies()->attach([
            $companyA->id => ['role_code' => 'inventory_manager', 'is_active' => true],
            $companyB->id => ['role_code' => 'inventory_manager', 'is_active' => false],
        ]);

        return [$user, $companyA, $companyB];
    }

    private function verifiedCompanySession(User $user, Company $company): array
    {
        return [
            'active_company_id' => $company->id,
            'mfa_verified_user_id' => $user->id,
        ];
    }
}
