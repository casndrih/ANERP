<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Employee extends Model {
    protected $guarded = [];
    protected function casts(): array {
        return [
            'hire_date'=>'date','termination_date'=>'date','base_salary'=>'decimal:2',
            'employment_declaration_received'=>'boolean','annual_dependant_tax_credit'=>'decimal:2',
            'superannuation_opt_in'=>'boolean','superannuation_exempt'=>'boolean',
        ];
    }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function leaveApplications(): HasMany { return $this->hasMany(LeaveApplication::class); }
    public function attendanceEntries(): HasMany { return $this->hasMany(AttendanceEntry::class); }
}