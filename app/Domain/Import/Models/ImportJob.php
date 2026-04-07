<?php

declare(strict_types=1);

namespace App\Domain\Import\Models;

use App\Domain\Shared\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'user_id', 'type', 'file_path', 'original_filename', 'status', 'total_rows', 'processed_rows', 'success_count', 'error_count', 'errors', 'options', 'started_at', 'completed_at'])]
class ImportJob extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'options' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'success_count' => 'integer',
            'error_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing', 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => $this->error_count > 0 ? 'completed_with_errors' : 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'errors' => array_merge($this->errors ?? [], [['row' => 0, 'message' => $message]]),
            'completed_at' => now(),
        ]);
    }

    public function addError(int $row, string $field, string $message): void
    {
        $errors = $this->errors ?? [];
        $errors[] = ['row' => $row, 'field' => $field, 'message' => $message];
        $this->update(['errors' => $errors, 'error_count' => count($errors)]);
    }

    public function incrementProgress(): void
    {
        $this->increment('processed_rows');
        $this->increment('success_count');
    }
}
