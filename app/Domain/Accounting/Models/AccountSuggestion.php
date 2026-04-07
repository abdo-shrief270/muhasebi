<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('account_suggestions')]
#[Fillable([
    'tenant_id',
    'pattern',
    'account_id',
    'confidence',
])]
class AccountSuggestion extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
