<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'date', 'api_calls', 'invoices_created', 'journal_entries_created', 'documents_uploaded', 'eta_submissions', 'emails_sent', 'storage_bytes'])]
class ApiUsageMeter extends Model
{

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'api_calls' => 'integer',
            'invoices_created' => 'integer',
            'journal_entries_created' => 'integer',
            'documents_uploaded' => 'integer',
            'eta_submissions' => 'integer',
            'emails_sent' => 'integer',
            'storage_bytes' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Tenant\Models\Tenant::class);
    }

    /**
     * Increment a specific meter for a tenant today.
     */
    public static function increment(int $tenantId, string $column, int $amount = 1): void
    {
        $meter = static::firstOrCreate(
            ['tenant_id' => $tenantId, 'date' => now()->toDateString()],
        );

        $meter->increment($column, $amount);
    }

    /**
     * Get usage summary for a tenant over a date range.
     */
    public static function summary(int $tenantId, string $from, string $to): array
    {
        $result = static::where('tenant_id', $tenantId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                SUM(api_calls) as total_api_calls,
                SUM(invoices_created) as total_invoices,
                SUM(journal_entries_created) as total_entries,
                SUM(documents_uploaded) as total_documents,
                SUM(eta_submissions) as total_eta,
                SUM(emails_sent) as total_emails,
                MAX(storage_bytes) as peak_storage
            ')
            ->first();

        return $result ? $result->toArray() : [];
    }

    /**
     * Get daily usage for charts.
     */
    public static function daily(int $tenantId, int $days = 30): array
    {
        return static::where('tenant_id', $tenantId)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get(['date', 'api_calls', 'invoices_created', 'journal_entries_created'])
            ->toArray();
    }
}
