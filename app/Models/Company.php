<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class Company extends Model {
    use SoftDeletes;
    protected $guarded = [];
    public function branches(): HasMany { return $this->hasMany(Branch::class); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class)->withPivot(['branch_id','role_code','is_active'])->withTimestamps(); }
}
