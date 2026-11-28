<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
class CompanyInvitationController extends Controller {
 private function invitation(string $token): object {
  $i=DB::table('company_invitations')->where('token_hash',hash('sha256',$token))->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at','>',now())->first();
  abort_unless($i,404,'This invitation is invalid, expired or revoked.'); return $i;
 }
 public function show(Request $r,string $token) {
  $i=$this->invitation($token); $existing=User::where('email',$i->email)->first();
  if($existing&&!$r->user()) return redirect()->guest(route('login',['intended'=>route('invitations.accept',$token)]));
  if($existing&&$r->user()->email!==$i->email) abort(403,'Sign in with the email address that received this invitation.');
  if(!$existing&&$r->user()) abort(403,'Sign out before creating the invited account.');
  return view('companies.accept-invitation',['invitation'=>$i,'company'=>Company::findOrFail($i->company_id),'existingUser'=>(bool)$existing,'token'=>$token]);
 }
 public function accept(Request $r,string $token) {
  $i=$this->invitation($token); $existing=User::where('email',$i->email)->first();
  if($existing) { abort_unless($r->user()&&(int)$r->user()->id===(int)$existing->id,403); $user=$existing; }
  else {
   abort_if($r->user(),403,'Sign out before creating the invited account.');
   $d=$r->validate(['name'=>['required','string','max:255'],'password'=>['required','string','min:12','confirmed']]);
   $user=User::create(['name'=>$d['name'],'email'=>$i->email,'password'=>$d['password']]);
   $user->forceFill(['email_verified_at'=>now()])->save();
  }
  DB::transaction(function()use($i,$user){
   $locked=DB::table('company_invitations')->where('id',$i->id)->lockForUpdate()->first();
   abort_unless($locked&&!$locked->accepted_at&&!$locked->revoked_at&&now()->lt($locked->expires_at),409,'Invitation already used or expired.');
   $membership=DB::table('company_user')->where('company_id',$locked->company_id)->where('user_id',$user->id)->first();
   $values=['branch_id'=>$locked->branch_id,'role_code'=>$locked->role_code,'is_active'=>true,'updated_at'=>now()];
   if($membership) DB::table('company_user')->where('company_id',$locked->company_id)->where('user_id',$user->id)->update($values);
   else DB::table('company_user')->insert($values+['company_id'=>$locked->company_id,'user_id'=>$user->id,'created_at'=>now()]);
   DB::table('company_invitations')->where('id',$locked->id)->update(['accepted_at'=>now(),'updated_at'=>now()]);
   DB::table('audit_events')->insert(['company_id'=>$locked->company_id,'user_id'=>$user->id,'event'=>'INVITATION ACCEPTED','auditable_type'=>'company_user','auditable_id'=>$user->id,'before_data'=>null,'after_data'=>json_encode(['role_code'=>$locked->role_code,'branch_id'=>$locked->branch_id]),'ip_address'=>request()->ip(),'created_at'=>now()]);
  });
  if(!$r->user()) auth()->login($user);
  $r->session()->regenerate(); $r->session()->put('active_company_id',(int)$i->company_id); $r->session()->forget('mfa_verified_user_id');
  return redirect()->route('dashboard')->with('status','Invitation accepted. Complete MFA setup if your role requires it.');
 }
}
