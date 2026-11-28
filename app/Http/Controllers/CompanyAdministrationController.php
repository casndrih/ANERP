<?php
namespace App\Http\Controllers;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyAuthorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompanyAdministrationController extends Controller {
 private const ROLES=['owner','admin','inventory_manager','procurement_manager','procurement_approver','finance_manager','accountant','payroll_manager','hr_manager','viewer'];
 private function company(Request $r): Company {
  CompanyAuthorizer::requirePermission($r,'users.manage');
  CompanyAuthorizer::requirePermission($r,'roles.manage');
  CompanyAuthorizer::requireCompanyWideAccess($r);
  return $r->attributes->get('activeCompany');
 }
 private function isOwner(Request $r): bool {
  return $r->user()->companies()->where('companies.id',CompanyAuthorizer::activeCompanyId($r))->wherePivot('is_active',true)->first()?->pivot?->role_code==='owner';
 }
 public function index(Request $r) {
  $company=$this->company($r); $users=$company->users()->orderBy('name')->get();
  $branchNames=Branch::where('company_id',$company->id)->pluck('name','id');
  $invitations=DB::table('company_invitations')->where('company_id',$company->id)->whereNull('accepted_at')->whereNull('revoked_at')->orderByDesc('created_at')->get();
  return view('companies.administration',['company'=>$company,'users'=>$users,'branchNames'=>$branchNames,'branches'=>Branch::where('company_id',$company->id)->where('is_active',true)->orderBy('name')->get(),'invitations'=>$invitations,'roles'=>self::ROLES,'isOwner'=>$this->isOwner($r)]);
 }
 public function invite(Request $r) {
  $company=$this->company($r);
  $d=$r->validate(['email'=>['required','email','max:255'],'role_code'=>['required',Rule::in(self::ROLES)],'branch_id'=>['nullable','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where('company_id',$company->id)->where('is_active',true))]]);
  if(in_array($d['role_code'],['admin','owner'],true)&&!$this->isOwner($r)) throw ValidationException::withMessages(['role_code'=>'Only a company owner can invite an administrator or another owner.']);
  $existing=User::where('email',$d['email'])->first();
  if($existing&&$company->users()->where('users.id',$existing->id)->exists()) throw ValidationException::withMessages(['email'=>'This user already has a company membership.']);
  $token=Str::random(64);
  DB::table('company_invitations')->where('company_id',$company->id)->where('email',$d['email'])->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at'=>now(),'updated_at'=>now()]);
  DB::table('company_invitations')->insert(['company_id'=>$company->id,'branch_id'=>$d['branch_id']??null,'email'=>$d['email'],'role_code'=>$d['role_code'],'token_hash'=>hash('sha256',$token),'invited_by'=>$r->user()->id,'expires_at'=>now()->addDays(7),'created_at'=>now(),'updated_at'=>now()]);
  $url=route('invitations.accept',['token'=>$token]);
  try {
   Mail::raw("You have been invited to join {$company->name} on ANERP.\n\nAccept within 7 days: {$url}\n\nIgnore this message if unexpected.",fn($m)=>$m->to($d['email'])->subject("ANERP invitation — {$company->name}"));
   return redirect()->route('company.users.index')->with('status','Invitation created and sent.');
  } catch(Throwable $e) {
   report($e);
   return redirect()->route('company.users.index')->with('status','Invitation created, but email delivery failed. Copy and send this one-time link securely.')->with('invitation_url',$url);
  }
 }
 public function updateMembership(Request $r,int $user) {
  $company=$this->company($r);
  $d=$r->validate(['role_code'=>['required',Rule::in(array_merge(['owner'],self::ROLES))],'branch_id'=>['nullable','integer',Rule::exists('branches','id')->where(fn($q)=>$q->where('company_id',$company->id)->where('is_active',true))],'is_active'=>['required','boolean']]);
  $target=$company->users()->where('users.id',$user)->firstOrFail();
  if(in_array($target->pivot->role_code,['owner','admin'],true)&&!$this->isOwner($r)) abort(403,'Only a company owner can change an owner or administrator membership.');
  if(in_array($d['role_code'],['owner','admin'],true)&&!$this->isOwner($r)) abort(403,'Only a company owner can grant owner or administrator access.');
  if($target->pivot->role_code==='owner'&&($d['role_code']!=='owner'||!$d['is_active'])) {
   $others=$company->users()->wherePivot('is_active',true)->wherePivot('role_code','owner')->where('users.id','!=',$target->id)->count();
   abort_if($others===0,409,'A company must retain at least one active owner.');
  }
  if((int)$target->id===(int)$r->user()->id&&!$d['is_active']) abort(409,'You cannot suspend your own active company membership.');
  $before=['role_code'=>$target->pivot->role_code,'branch_id'=>$target->pivot->branch_id,'is_active'=>(bool)$target->pivot->is_active];
  $after=['role_code'=>$d['role_code'],'branch_id'=>$d['branch_id']??null,'is_active'=>(bool)$d['is_active']];
  DB::transaction(function() use($company,$target,$after,$before,$r) {
   DB::table('company_user')->where('company_id',$company->id)->where('user_id',$target->id)->update($after+['updated_at'=>now()]);
   DB::table('audit_events')->insert(['company_id'=>$company->id,'user_id'=>$r->user()->id,'event'=>'COMPANY MEMBERSHIP UPDATED','auditable_type'=>'users','auditable_id'=>$target->id,'before_data'=>json_encode($before),'after_data'=>json_encode($after),'ip_address'=>$r->ip(),'created_at'=>now()]);
  });
  return back()->with('status','Company membership updated.');
 }
 public function revokeInvitation(Request $r,int $invitation) {
  $company=$this->company($r);
  $n=DB::table('company_invitations')->where('company_id',$company->id)->where('id',$invitation)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at'=>now(),'updated_at'=>now()]);
  abort_unless($n===1,404); return back()->with('status','Invitation revoked.');
 }
 public function audit(Request $r) {
  $company=$this->company($r); CompanyAuthorizer::requirePermission($r,'audit.view');
  $events=DB::table('audit_events')->leftJoin('users','users.id','=','audit_events.user_id')->where('audit_events.company_id',$company->id)->orderByDesc('audit_events.created_at')->select(['audit_events.id','audit_events.event','audit_events.auditable_type','audit_events.auditable_id','audit_events.after_data','audit_events.ip_address','audit_events.created_at','users.name as actor_name'])->paginate(30);
  return view('companies.audit',['company'=>$company,'events'=>$events]);
 }
}

