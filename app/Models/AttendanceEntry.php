<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AttendanceEntry extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['work_date'=>'date','hours'=>'decimal:2']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}