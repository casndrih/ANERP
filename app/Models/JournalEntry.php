<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class JournalEntry extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['journal_date'=>'date','posted_at'=>'datetime']; }
    public function lines(): HasMany { return $this->hasMany(JournalLine::class); }
}