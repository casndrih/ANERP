<?php
namespace Tests\Feature;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class CompanyAdministrationTest extends TestCase {
 use RefreshDatabase;
 public function test_owner_can_assign_branch_scoped_role_to_member(): void {
  [$owner,$company,$branch]=$this->workspace(); $member=$this->member($company,'viewer');
  $this->actingAs($owner)->withSession($this->companySession($owner,$company))->patch(route('company.users.update',$member->id),['role_code'=>'inventory_manager','branch_id'=>$branch->id,'is_active'=>1])->assertRedirect();
  $this->assertDatabaseHas('company_user',['company_id'=>$company->id,'user_id'=>$member->id,'role_code'=>'inventory_manager','branch_id'=>$branch->id,'is_active'=>true]);
 }
 public function test_admin_cannot_promote_member_to_owner(): void {
  [$owner,$company]=$this->workspace(); $admin=$this->member($company,'admin'); $target=$this->member($company,'viewer');
  $this->actingAs($admin)->withSession($this->companySession($admin,$company))->patch(route('company.users.update',$target->id),['role_code'=>'owner','branch_id'=>'','is_active'=>1])->assertForbidden();
  $this->assertDatabaseHas('company_user',['company_id'=>$company->id,'user_id'=>$target->id,'role_code'=>'viewer']);
 }
 public function test_admin_cannot_change_membership_in_another_tenant(): void {
  [$owner,$company]=$this->workspace(); $other=Company::create(['name'=>'Other tenant','is_active'=>true]); $foreign=$this->member($other,'viewer');
  $this->actingAs($owner)->withSession($this->companySession($owner,$company))->patch(route('company.users.update',$foreign->id),['role_code'=>'viewer','branch_id'=>'','is_active'=>0])->assertNotFound();
 }
 public function test_company_must_retain_one_active_owner(): void {
  [$owner,$company]=$this->workspace();
  $this->actingAs($owner)->withSession($this->companySession($owner,$company))->patch(route('company.users.update',$owner->id),['role_code'=>'admin','branch_id'=>'','is_active'=>1])->assertStatus(409);
  $this->assertDatabaseHas('company_user',['company_id'=>$company->id,'user_id'=>$owner->id,'role_code'=>'owner','is_active'=>true]);
 }
 private function workspace(): array {
  $owner=User::create(['name'=>'Owner','email'=>'owner-'.uniqid().'@example.test','password'=>'test-password']);
  $owner->forceFill(['mfa_enabled_at'=>now(),'mfa_secret'=>'test-encrypted-secret'])->save();
  $company=Company::create(['name'=>'Admin test','is_active'=>true]);
  $branch=Branch::create(['company_id'=>$company->id,'code'=>'HQ','name'=>'Head Office','is_active'=>true]);
  $owner->companies()->attach($company->id,['role_code'=>'owner','is_active'=>true]); return [$owner,$company,$branch];
 }
 private function member(Company $company,string $role): User {
  $u=User::create(['name'=>'Member','email'=>'member-'.uniqid().'@example.test','password'=>'test-password']);
  $u->forceFill(['mfa_enabled_at'=>now(),'mfa_secret'=>'test-encrypted-secret'])->save();
  $u->companies()->attach($company->id,['role_code'=>$role,'is_active'=>true]); return $u;
 }
 private function companySession(User $user,Company $company): array { return ['active_company_id'=>$company->id,'mfa_verified_user_id'=>$user->id]; }
}
