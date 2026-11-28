<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ImportAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_assessment_uses_actual_customs_duty_and_posts_eligible_import_gst_through_landed_cost(): void
    {
        [$company, $branch, $warehouse, $supplier, $product, $creator, $approver, $po, $line] = $this->fixture();
        InventoryLedger::record($company->id, $warehouse->id, $product->id, 'purchase_receipt', 10, 10, $creator->id, 'import_test_receipt');

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.import-assessments.store'), [
                'purchase_order_id' => $po->id, 'supplier_id' => $supplier->id,
                'customs_assessment_reference' => 'CUS-ENTRY-001', 'import_declaration_number' => 'IM4-001',
                'hs_code' => '8471.30', 'customs_value' => 100, 'customs_duty_amount' => 10,
                'excise_amount' => 2, 'freight_insurance_amount' => 3,
                'input_tax_credit_eligible' => 1, 'assessment_date' => '2026-11-28',
                'tariff_source_url' => 'https://www.customs.gov.pg/tev/tariff', 'tariff_verified_on' => '2026-11-28',
            ])->assertRedirect(route('procurement.matching.index'))->assertSessionHasNoErrors();

        $bill = DB::table('supplier_bills')->where('gst_source', 'import')->first();
        self::assertNotNull($bill);
        self::assertSame(15.0, (float) $bill->net_amount);
        self::assertSame(11.5, (float) $bill->gst_amount);
        self::assertSame(26.5, (float) $bill->amount);
        self::assertSame(115.0, (float) $bill->gst_base);
        self::assertTrue((bool) $bill->input_tax_credit_eligible);

        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.matching.match', $bill->id), [
                'purchase_order_id' => $po->id, 'invoice_type' => 'landed_cost',
                'allocation_basis' => 'line_value',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('procurement.matching.approve', $bill->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $posted = DB::table('supplier_bills')->where('id', $bill->id)->first();
        self::assertSame('posted', $posted->status);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => Account::where('company_id', $company->id)->where('account_code', '1300')->value('id'),
            'debit' => '11.50', 'credit' => '0.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'account_id' => Account::where('company_id', $company->id)->where('account_code', '2000')->value('id'),
            'debit' => '0.00', 'credit' => '26.50',
        ]);
    }

    public function test_import_gst_is_capitalised_when_importer_cannot_claim_input_credit(): void
    {
        [$company, $branch, $warehouse, $supplier, $product, $creator, $approver, $po, $line] = $this->fixture();
        $company->update(['gst_registered' => false]);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('procurement.import-assessments.store'), [
                'purchase_order_id' => $po->id, 'supplier_id' => $supplier->id,
                'customs_assessment_reference' => 'CUS-ENTRY-002', 'import_declaration_number' => 'IM4-002',
                'hs_code' => '8471.30', 'customs_value' => 100, 'customs_duty_amount' => 10,
                'excise_amount' => 2, 'freight_insurance_amount' => 3,
                'input_tax_credit_eligible' => 0, 'assessment_date' => '2026-11-28',
                'tariff_source_url' => 'https://www.customs.gov.pg/tev/tariff', 'tariff_verified_on' => '2026-11-28',
            ])->assertRedirect(route('procurement.matching.index'))->assertSessionHasNoErrors();

        $bill = DB::table('supplier_bills')->where('customs_assessment_reference', 'CUS-ENTRY-002')->first();
        self::assertNotNull($bill);
        self::assertSame(26.5, (float) $bill->net_amount);
        self::assertSame(0.0, (float) $bill->gst_amount);
        self::assertSame(26.5, (float) $bill->amount);
        self::assertSame(11.5, (float) $bill->import_gst_amount);
        self::assertFalse((bool) $bill->input_tax_credit_eligible);
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Import Co', 'is_active' => true, 'gst_registered' => true, 'gst_registration_number' => 'GST-IMP-001']);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $warehouse = Warehouse::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'IMP-WH', 'name' => 'Import Warehouse', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'code' => 'CUSTOMS', 'name' => 'PNG Customs', 'is_active' => true]);
        $product = Product::create(['company_id' => $company->id, 'sku' => 'IMP-001', 'name' => 'Imported Product', 'unit' => 'each', 'standard_cost' => 10, 'selling_price' => 20, 'track_stock' => true, 'is_active' => true]);
        $creator = $this->owner($company, 'import-creator');
        $approver = $this->owner($company, 'import-approver');
        Account::create(['company_id' => $company->id, 'account_code' => '6400', 'name' => 'Landed costs', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_control_account' => false, 'is_active' => true]);
        $po = PurchaseOrder::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'order_number' => 'PO-IMP-001', 'order_date' => '2026-11-01', 'status' => 'received',
            'currency' => 'PGK', 'subtotal' => 100, 'tax_total' => 0, 'total' => 100, 'created_by' => $creator->id,
        ]);
        $line = PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'product_id' => $product->id,
            'quantity_ordered' => 10, 'quantity_received' => 10, 'unit_cost' => 10, 'line_total' => 100,
        ]);
        return [$company, $branch, $warehouse, $supplier, $product, $creator, $approver, $po, $line];
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
