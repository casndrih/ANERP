<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class User extends Authenticatable {
    use Notifiable;
    protected $fillable = ['name','email','password'];
    protected $hidden = ['password','remember_token','mfa_secret','mfa_recovery_codes'];
    protected function casts(): array { return ['email_verified_at'=>'datetime','password'=>'hashed','mfa_enabled_at'=>'datetime','mfa_recovery_codes'=>'array']; }
    public function companies(): BelongsToMany { return $this->belongsToMany(Company::class)->withPivot(['branch_id','role_code','is_active'])->withTimestamps(); }
}
