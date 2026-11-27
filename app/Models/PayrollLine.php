<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PayrollLine extends Model {
    protected $guarded = [];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function run(): BelongsTo { return $this->belongsTo(PayrollRun::class,'payroll_run_id'); }
}