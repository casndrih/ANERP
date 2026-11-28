<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_note_reduces_invoice_receivable_and_posts_balanced_journal(): void
    {
        [$company, $branch, $customer, $invoice, $creator, $approver, $revenue, $ar] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.credit-notes.store'), [
                'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-001',
                'credit_note_date' => '2026-11-28', 'description' => 'Service credit', 'amount' => 30,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $note = \App\Models\CustomerCreditNote::where('company_id', $company->id)->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.credit-notes.post', $note->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_credit_notes', ['id' => $note->id, 'status' => 'posted']);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $revenue->id, 'debit' => 30, 'credit' => 0]);
        $this->assertDatabaseHas('journal_lines', ['account_id' => $ar->id, 'debit' => 0, 'credit' => 30]);
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->get(route('reports.index', ['report' => 'receivables', 'to' => '2026-11-28']))
            ->assertOk()->assertSee('70.00');
    }

    public function test_credit_note_cannot_exceed_invoice_balance_and_creator_cannot_post(): void
    {
        [$company, $branch, $customer, $invoice, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.credit-notes.store'), [
                'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-OVER',
                'credit_note_date' => '2026-11-28', 'description' => 'Over-credit', 'amount' => 101,
            ])->assertSessionHasErrors('amount');
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.credit-notes.store'), [
                'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-SELF',
                'credit_note_date' => '2026-11-28', 'description' => 'Self-post guard', 'amount' => 20,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $note = \App\Models\CustomerCreditNote::where('credit_note_number', 'CN-SELF')->firstOrFail();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.credit-notes.post', $note->id))->assertForbidden();
    }

    public function test_cancelled_draft_releases_invoice_credit_limit(): void
    {
        [$company, $branch, $customer, $invoice, $creator, $approver] = $this->fixture();
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.credit-notes.store'), [
                'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-CANCEL-1',
                'credit_note_date' => '2026-11-28', 'description' => 'Cancelled draft', 'amount' => 40,
            ])->assertRedirect()->assertSessionHasNoErrors();
        $note = \App\Models\CustomerCreditNote::where('credit_note_number', 'CN-CANCEL-1')->firstOrFail();
        $this->actingAs($approver)->withSession($this->sessionData($company, $approver))
            ->post(route('finance.credit-notes.cancel', $note->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_credit_notes', ['id' => $note->id, 'status' => 'cancelled']);
        $this->actingAs($creator)->withSession($this->sessionData($company, $creator))
            ->post(route('finance.credit-notes.store'), [
                'invoice_id' => $invoice->id, 'credit_note_number' => 'CN-CANCEL-2',
                'credit_note_date' => '2026-11-28', 'description' => 'Replacement draft', 'amount' => 100,
            ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Credit Notes Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'code' => 'LAE', 'name' => 'Lae', 'is_active' => true]);
        $creator = $this->owner($company, 'credit-creator'); $approver = $this->owner($company, 'credit-approver');
        $revenue = Account::create(['company_id' => $company->id, 'account_code' => '4000', 'name' => 'Sales revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'is_control_account' => false, 'is_active' => true]);
        $ar = Account::create(['company_id' => $company->id, 'account_code' => '1100', 'name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'CUST-1', 'name' => 'Test Customer', 'is_active' => true]);
        $invoice = CustomerInvoice::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'revenue_account_id' => $revenue->id, 'invoice_number' => 'INV-'.uniqid(),
            'invoice_date' => '2026-11-01', 'due_date' => '2026-11-30', 'amount' => 100,
            'currency' => 'PGK', 'description' => 'Test invoice', 'status' => 'posted',
            'created_by' => $creator->id, 'posted_by' => $approver->id, 'posted_at' => now(),
        ]);
        return [$company, $branch, $customer, $invoice, $creator, $approver, $revenue, $ar];
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
