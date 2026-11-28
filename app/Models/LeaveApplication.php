<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class LeaveApplication extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['start_date'=>'date','end_date'=>'date','approved_at'=>'datetime','days'=>'decimal:2']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
}