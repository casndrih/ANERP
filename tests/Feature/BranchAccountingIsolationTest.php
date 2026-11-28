<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BranchAccountingIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_finance_workspace_excludes_other_branches_and_unassigned_legacy_records(): void
    {
        [$user,$company,$branchA,$branchB]=$this->branchFinanceUser();
        $account=$this->account($company);
        $a=$this->journal($company,$branchA,$user,$account,'BR-A',100);
        $b=$this->journal($company,$branchB,$user,$account,'BR-B',900);
        $legacy=$this->journal($company,null,$user,$account,'LEGACY',500);
        $this->actingAs($user)->withSession($this->session($user,$company))->get(route('finance.index'))
            ->assertOk()->assertViewHas('journals',fn($journals)=>$journals->contains('id',$a->id)&&!$journals->contains('id',$b->id)&&!$journals->contains('id',$legacy->id));
    }

    public function test_branch_user_cannot_create_journal_for_another_branch(): void
    {
        [$user,$company,$branchA,$branchB]=$this->branchFinanceUser();
        $account=$this->account($company);
        $this->actingAs($user)->withSession($this->session($user,$company))->post(route('finance.journals.store'),[
            'branch_id'=>$branchB->id,'journal_date'=>'2026-11-28','description'=>'Cross branch attempt',
            'lines'=>[['account_id'=>$account->id,'debit'=>100,'credit'=>0],['account_id'=>$account->id,'debit'=>0,'credit'=>100]],
        ])->assertForbidden();
        $this->assertDatabaseMissing('journal_entries',['company_id'=>$company->id,'description'=>'Cross branch attempt']);
    }

    public function test_branch_trial_balance_only_uses_posted_entries_for_assigned_branch(): void
    {
        [$user,$company,$branchA,$branchB]=$this->branchFinanceUser();
        $account=$this->account($company);
        $this->journal($company,$branchA,$user,$account,'TB-A',100);
        $this->journal($company,$branchB,$user,$account,'TB-B',900);
        $this->journal($company,null,$user,$account,'TB-LEGACY',500);
        $this->actingAs($user)->withSession($this->session($user,$company))
            ->get(route('reports.index',['report'=>'trial_balance','from'=>'2026-11-01','to'=>'2026-11-30']))
            ->assertOk()->assertViewHas('rows',fn($rows)=>$rows->count()===1&&(float)$rows->first()->debit===100.0);
    }

    public function test_company_wide_finance_user_can_still_view_consolidated_ledger(): void
    {
        [$user,$company,$branchA,$branchB]=$this->branchFinanceUser(true);
        $account=$this->account($company);
        $this->journal($company,$branchA,$user,$account,'CON-A',100);
        $this->journal($company,$branchB,$user,$account,'CON-B',900);
        $this->journal($company,null,$user,$account,'CON-LEGACY',500);
        $this->actingAs($user)->withSession($this->session($user,$company))->get(route('finance.index'))
            ->assertOk()->assertViewHas('journals',fn($journals)=>$journals->contains('journal_number','CON-A')&&$journals->contains('journal_number','CON-B')&&$journals->contains('journal_number','CON-LEGACY'));
    }

    private function branchFinanceUser(bool $companyWide=false): array
    {
        $user=User::create(['name'=>'Branch Accountant','email'=>'branch-accountant-'.uniqid().'@example.test','password'=>'test-password']);
        $user->forceFill(['mfa_enabled_at'=>now(),'mfa_secret'=>'test-encrypted-secret'])->save();
        $company=Company::create(['name'=>'Branch Accounting Co','is_active'=>true]);
        $a=Branch::create(['company_id'=>$company->id,'code'=>'LAE','name'=>'Lae','is_active'=>true]);
        $b=Branch::create(['company_id'=>$company->id,'code'=>'HGN','name'=>'Mount Hagen','is_active'=>true]);
        $user->companies()->attach($company->id,['branch_id'=>$companyWide?null:$a->id,'role_code'=>'finance_manager','is_active'=>true]);
        return [$user,$company,$a,$b];
    }

    private function account(Company $company): Account
    {
        return Account::create(['company_id'=>$company->id,'account_code'=>'1000','name'=>'Cash','account_type'=>'asset','normal_balance'=>'debit','is_control_account'=>false,'is_active'=>true]);
    }

    private function journal(Company $company,?Branch $branch,User $user,Account $account,string $number,float $amount): JournalEntry
    {
        $entry=JournalEntry::create(['company_id'=>$company->id,'branch_id'=>$branch?->id,'journal_number'=>$number,'journal_date'=>'2026-11-20','source'=>'manual','status'=>'posted','description'=>$number,'created_by'=>$user->id,'posted_by'=>$user->id,'posted_at'=>now()]);
        JournalLine::create(['journal_entry_id'=>$entry->id,'account_id'=>$account->id,'description'=>$number,'debit'=>$amount,'credit'=>0]);
        JournalLine::create(['journal_entry_id'=>$entry->id,'account_id'=>$account->id,'description'=>$number,'debit'=>0,'credit'=>$amount]);
        return $entry;
    }

    private function session(User $user,Company $company): array
    {
        return ['active_company_id'=>$company->id,'mfa_verified_user_id'=>$user->id];
    }
}
