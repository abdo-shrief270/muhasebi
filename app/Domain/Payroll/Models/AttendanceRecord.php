<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\AttendanceStatus;
use App\Domain\Shared\Traits\BelongsToTenant;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('attendance_records')]
#[Fillable([
    'tenant_id',
    'employee_id',
    'date',
    'check_in',
    'check_out',
    'hours_worked',
    'overtime_hours',
    'status',
])]
class AttendanceRecord extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /** Standard working hours per day */
    public const STANDARD_HOURS = 8;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours_worked' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'status' => AttendanceStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Calculate hours worked and overtime from check_in/check_out times.
     */
    public function calculateHours(): void
    {
        if ($this->check_in && $this->check_out) {
            $checkIn = \Carbon\Carbon::parse($this->date->format('Y-m-d').' '.$this->check_in);
            $checkOut = \Carbon\Carbon::parse($this->date->format('Y-m-d').' '.$this->check_out);

            $totalHours = $checkOut->diffInMinutes($checkIn) / 60;
            $this->hours_worked = number_format($totalHours, 2, '.', '');

            $overtime = max(0, $totalHours - self::STANDARD_HOURS);
            $this->overtime_hours = number_format($overtime, 2, '.', '');
        }
    }

    protected static function newFactory(): AttendanceRecordFactory
    {
        return AttendanceRecordFactory::new();
    }
}
