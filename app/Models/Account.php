<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Account extends Model {
    protected $guarded = [];
    public function journalLines(): HasMany { return $this->hasMany(JournalLine::class); }
}