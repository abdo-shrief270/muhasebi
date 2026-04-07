<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('leave_types')]
#[Fillable([
    'tenant_id',
    'name_ar',
    'name_en',
    'code',
    'days_per_year',
    'is_paid',
])]
class LeaveType extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'days_per_year' => 'integer',
            'is_paid' => 'boolean',
        ];
    }

    protected static function newFactory(): LeaveTypeFactory
    {
        return LeaveTypeFactory::new();
    }
}
