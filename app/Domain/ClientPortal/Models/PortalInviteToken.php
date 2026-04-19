<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('portal_invite_tokens')]
#[Fillable(['user_id', 'token_hash', 'expires_at', 'used_at'])]
class PortalInviteToken extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
