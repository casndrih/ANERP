<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Supplier extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['is_active'=>'boolean']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
