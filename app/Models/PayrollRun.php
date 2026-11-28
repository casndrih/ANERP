<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PayrollRun extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['period_start'=>'date','period_end'=>'date','payment_date'=>'date','approved_at'=>'datetime']; }
    public function lines(): HasMany { return $this->hasMany(PayrollLine::class); }
    public function journalEntries(): BelongsToMany { return $this->belongsToMany(JournalEntry::class, 'payroll_run_journals')->withPivot('branch_id')->withTimestamps(); }
}