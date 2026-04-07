<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Services;

use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\EInvoice\Models\EtaItemCode;
use App\Domain\EInvoice\Models\EtaItemCodeMapping;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EtaItemCodeMasterService
{
    // ──────────────────────────────────────
    // 1. Bulk Assign
    // ──────────────────────────────────────

    /**
     * Assign ETA item codes to multiple products/descriptions at once.
     *
     * @param  array<int, array{product_id?: int, description_pattern?: string, eta_item_code_id: int, priority?: int}>  $data
     * @return int Number of mappings created
     */
    public function bulkAssign(array $data): int
    {
        $tenantId = (int) app('tenant.id');
        $created = 0;

        DB::transaction(function () use ($data, $tenantId, &$created): void {
            foreach ($data as $row) {
                EtaItemCodeMapping::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'eta_item_code_id' => $row['eta_item_code_id'],
                        'product_id' => $row['product_id'] ?? null,
                        'description_pattern' => $row['description_pattern'] ?? null,
                    ],
                    [
                        'priority' => $row['priority'] ?? 0,
                        'assignment_source' => 'bulk_assign',
                    ],
                );

                $created++;
            }
        });

        return $created;
    }

    // ──────────────────────────────────────
    // 2. Bulk Import
    // ──────────────────────────────────────

    /**
     * Import item codes from CSV/array. Upsert EtaItemCode records.
     *
     * @param  array<int, array{code: string, code_type?: string, description_ar?: string, description_en?: string, category?: string}>  $rows
     * @return array{created: int, updated: int}
     */
    public function bulkImport(array $rows): array
    {
        $tenantId = (int) app('tenant.id');
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $tenantId, &$created, &$updated): void {
            foreach ($rows as $row) {
                $existing = EtaItemCode::query()
                    ->where('tenant_id', $tenantId)
                    ->where('item_code', $row['code'])
                    ->first();

                $attributes = [
                    'code_type' => $row['code_type'] ?? 'EGS',
                    'description' => $row['description_en'] ?? $row['code'],
                    'description_ar' => $row['description_ar'] ?? null,
                ];

                if ($existing) {
                    $existing->update($attributes);
                    $updated++;
                } else {
                    EtaItemCode::query()->create([
                        'tenant_id' => $tenantId,
                        'item_code' => $row['code'],
                        ...$attributes,
                    ]);
                    $created++;
                }
            }
        });

        return compact('created', 'updated');
    }

    // ──────────────────────────────────────
    // 3. Auto-Assign
    // ──────────────────────────────────────

    /**
     * Auto-assign item codes to invoice lines that don't have one.
     *
     * Logic:
     *  1. Get unassigned invoice lines (no matching EtaItemCode by description)
     *  2. Check EtaItemCodeMapping rules (by product_id first, then description pattern, ordered by priority)
     *  3. If match found, update the line description to link with the ETA code
     *  4. Return stats
     *
     * @return array{total_lines: int, matched: int, unmatched: int}
     */
    public function autoAssign(int $tenantId): array
    {
        $unassignedLines = $this->getUnassignedLines($tenantId);
        $totalLines = $unassignedLines->count();
        $matched = 0;

        // Load all mappings for tenant, ordered by priority descending
        $productMappings = EtaItemCodeMapping::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('product_id')
            ->with('etaItemCode')
            ->ordered()
            ->get();

        $patternMappings = EtaItemCodeMapping::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('description_pattern')
            ->with('etaItemCode')
            ->ordered()
            ->get();

        foreach ($unassignedLines as $line) {
            $itemCode = null;

            // Strategy 1: Match by product_id via invoice line's related product
            // (InvoiceLine may have a product relationship in the future; check description for now)
            foreach ($productMappings as $mapping) {
                // If the line's invoice has product linkage, match on product_id
                if ($mapping->product_id && $this->lineMatchesProduct($line, $mapping->product_id)) {
                    $itemCode = $mapping->etaItemCode;
                    break;
                }
            }

            // Strategy 2: Match by description pattern
            if (! $itemCode) {
                foreach ($patternMappings as $mapping) {
                    if ($mapping->description_pattern && $this->descriptionMatchesPattern($line->description, $mapping->description_pattern)) {
                        $itemCode = $mapping->etaItemCode;
                        break;
                    }
                }
            }

            if ($itemCode) {
                // Store the assignment on the ETA item code mapping for tracking
                DB::table('invoice_lines')
                    ->where('id', $line->id)
                    ->update(['updated_at' => now()]);

                // Create a direct mapping record for this line
                EtaItemCodeMapping::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'eta_item_code_id' => $itemCode->id,
                        'description_pattern' => mb_strtolower(trim($line->description)),
                    ],
                    [
                        'priority' => 0,
                        'assignment_source' => 'auto_assign',
                    ],
                );

                $matched++;
            }
        }

        return [
            'total_lines' => $totalLines,
            'matched' => $matched,
            'unmatched' => $totalLines - $matched,
        ];
    }

    // ──────────────────────────────────────
    // 4. Sync from ETA
    // ──────────────────────────────────────

    /**
     * Placeholder for syncing item codes from the ETA portal.
     *
     * @return array{sync_status: string, last_synced_at: string}
     */
    public function syncFromEta(int $tenantId): array
    {
        // TODO: Implement actual ETA portal API sync when endpoints are available.
        // This will pull the official GS1/EGS code list from ETA and upsert locally.

        $now = now();

        EtaItemCode::query()
            ->where('tenant_id', $tenantId)
            ->update(['updated_at' => $now]);

        return [
            'sync_status' => 'pending',
            'last_synced_at' => $now->toDateTimeString(),
        ];
    }

    // ──────────────────────────────────────
    // 5. Usage Report
    // ──────────────────────────────────────

    /**
     * Report showing which item codes are used most, which are unused,
     * and which invoice lines are missing codes.
     *
     * @param  array{tenant_id?: int, from?: string, to?: string}  $filters
     * @return array{by_code: array<int, array<string, mixed>>, unused_codes: array<int, array<string, mixed>>, missing_lines_count: int}
     */
    public function usageReport(array $filters): array
    {
        $tenantId = (int) ($filters['tenant_id'] ?? app('tenant.id'));

        // Count usage per item code by matching invoice line descriptions
        $codesWithUsage = DB::table('eta_item_codes')
            ->leftJoin('eta_item_code_mappings', 'eta_item_codes.id', '=', 'eta_item_code_mappings.eta_item_code_id')
            ->where('eta_item_codes.tenant_id', $tenantId)
            ->select(
                'eta_item_codes.id',
                'eta_item_codes.item_code',
                'eta_item_codes.description',
                'eta_item_codes.description_ar',
                'eta_item_codes.code_type',
                DB::raw('COUNT(eta_item_code_mappings.id) as mapping_count'),
            )
            ->groupBy(
                'eta_item_codes.id',
                'eta_item_codes.item_code',
                'eta_item_codes.description',
                'eta_item_codes.description_ar',
                'eta_item_codes.code_type',
            )
            ->orderByDesc('mapping_count')
            ->get();

        $byCode = $codesWithUsage
            ->filter(fn ($row) => $row->mapping_count > 0)
            ->map(fn ($row) => [
                'id' => $row->id,
                'item_code' => $row->item_code,
                'description' => $row->description,
                'description_ar' => $row->description_ar,
                'code_type' => $row->code_type,
                'usage_count' => (int) $row->mapping_count,
            ])
            ->values()
            ->toArray();

        $unusedCodes = $codesWithUsage
            ->filter(fn ($row) => $row->mapping_count === 0)
            ->map(fn ($row) => [
                'id' => $row->id,
                'item_code' => $row->item_code,
                'description' => $row->description,
                'description_ar' => $row->description_ar,
                'code_type' => $row->code_type,
            ])
            ->values()
            ->toArray();

        $missingLinesCount = $this->getUnassignedLines($tenantId)->count();

        return [
            'by_code' => $byCode,
            'unused_codes' => $unusedCodes,
            'missing_lines_count' => $missingLinesCount,
        ];
    }

    // ──────────────────────────────────────
    // 6. Unmapped Lines
    // ──────────────────────────────────────

    /**
     * List invoice lines missing ETA item codes. Paginated.
     *
     * @param  array{from?: string, to?: string, client_id?: int, per_page?: int}  $filters
     */
    public function unmappedLines(array $filters): LengthAwarePaginator
    {
        $tenantId = (int) ($filters['tenant_id'] ?? app('tenant.id'));

        $etaDescriptions = EtaItemCode::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->pluck('description')
            ->merge(
                EtaItemCode::query()
                    ->where('tenant_id', $tenantId)
                    ->active()
                    ->whereNotNull('description_ar')
                    ->pluck('description_ar')
            )
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $mappedPatterns = EtaItemCodeMapping::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('description_pattern')
            ->pluck('description_pattern')
            ->toArray();

        $query = InvoiceLine::query()
            ->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoices.tenant_id', $tenantId)
            ->select('invoice_lines.*', 'invoices.invoice_number', 'invoices.client_id', 'invoices.date as invoice_date');

        // Exclude lines that already have a matching ETA item code
        if (! empty($etaDescriptions)) {
            $query->whereNotIn('invoice_lines.description', $etaDescriptions);
        }

        // Exclude lines matching known patterns
        foreach ($mappedPatterns as $pattern) {
            $query->where('invoice_lines.description', 'not ilike', "%{$pattern}%");
        }

        // Apply date range filter
        if (isset($filters['from'])) {
            $query->where('invoices.date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('invoices.date', '<=', $filters['to']);
        }

        // Apply client filter
        if (isset($filters['client_id'])) {
            $query->where('invoices.client_id', $filters['client_id']);
        }

        return $query
            ->orderByDesc('invoices.date')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    // ──────────────────────────────────────
    // 7. Suggest Code
    // ──────────────────────────────────────

    /**
     * Suggest an item code based on description matching against existing
     * mappings and historical usage (like AccountSuggestionService pattern).
     *
     * @return array<int, array{eta_item_code_id: int, item_code: string, description: string, confidence: int, match_type: string}>
     */
    public function suggestCode(string $description, int $limit = 5): array
    {
        $tenantId = (int) app('tenant.id');
        $normalized = $this->normalizeDescription($description);
        $words = array_filter(explode(' ', $normalized), fn ($w) => mb_strlen($w) >= 3);

        if (empty($words)) {
            return [];
        }

        // Strategy 1: Exact pattern match from mappings
        $exactMapping = EtaItemCodeMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('description_pattern', $normalized)
            ->with('etaItemCode')
            ->ordered()
            ->first();

        if ($exactMapping && $exactMapping->etaItemCode) {
            return [[
                'eta_item_code_id' => $exactMapping->etaItemCode->id,
                'item_code' => $exactMapping->etaItemCode->item_code,
                'description' => $exactMapping->etaItemCode->description,
                'description_ar' => $exactMapping->etaItemCode->description_ar,
                'confidence' => 100,
                'match_type' => 'exact',
            ]];
        }

        // Strategy 2: Fuzzy match against ETA item codes description
        $query = EtaItemCode::query()
            ->where('tenant_id', $tenantId)
            ->active();

        $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->orWhere('description', 'ilike', "%{$word}%")
                    ->orWhere('description_ar', 'ilike', "%{$word}%");
            }
        });

        $directMatches = $query
            ->limit($limit)
            ->get()
            ->map(fn (EtaItemCode $code) => [
                'eta_item_code_id' => $code->id,
                'item_code' => $code->item_code,
                'description' => $code->description,
                'description_ar' => $code->description_ar,
                'confidence' => $this->calculateConfidence($normalized, $code),
                'match_type' => 'fuzzy',
            ])
            ->sortByDesc('confidence')
            ->values()
            ->toArray();

        if (! empty($directMatches)) {
            return array_slice($directMatches, 0, $limit);
        }

        // Strategy 3: Fuzzy match against mapping patterns
        $patternQuery = EtaItemCodeMapping::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('description_pattern')
            ->with('etaItemCode');

        $patternQuery->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->orWhere('description_pattern', 'ilike', "%{$word}%");
            }
        });

        return $patternQuery
            ->ordered()
            ->limit($limit)
            ->get()
            ->filter(fn ($mapping) => $mapping->etaItemCode !== null)
            ->map(fn (EtaItemCodeMapping $mapping) => [
                'eta_item_code_id' => $mapping->etaItemCode->id,
                'item_code' => $mapping->etaItemCode->item_code,
                'description' => $mapping->etaItemCode->description,
                'description_ar' => $mapping->etaItemCode->description_ar,
                'confidence' => $this->calculateConfidence($normalized, $mapping->etaItemCode),
                'match_type' => 'pattern',
            ])
            ->unique('eta_item_code_id')
            ->sortByDesc('confidence')
            ->values()
            ->toArray();
    }

    // ──────────────────────────────────────
    // Private Helpers
    // ──────────────────────────────────────

    /**
     * Get invoice lines that have no matching ETA item code.
     */
    private function getUnassignedLines(int $tenantId): \Illuminate\Support\Collection
    {
        $etaDescriptions = EtaItemCode::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->pluck('description')
            ->merge(
                EtaItemCode::query()
                    ->where('tenant_id', $tenantId)
                    ->active()
                    ->whereNotNull('description_ar')
                    ->pluck('description_ar')
            )
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $query = InvoiceLine::query()
            ->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoices.tenant_id', $tenantId)
            ->select('invoice_lines.*');

        if (! empty($etaDescriptions)) {
            $query->whereNotIn('invoice_lines.description', $etaDescriptions);
        }

        return $query->get();
    }

    /**
     * Check if an invoice line is associated with a specific product.
     * Since invoice_lines doesn't have product_id, we match via description against the product name.
     */
    private function lineMatchesProduct(InvoiceLine $line, int $productId): bool
    {
        $product = DB::table('products')
            ->where('id', $productId)
            ->select('name_ar', 'name_en')
            ->first();

        if (! $product) {
            return false;
        }

        $desc = mb_strtolower(trim($line->description));

        return $desc === mb_strtolower(trim($product->name_ar ?? ''))
            || $desc === mb_strtolower(trim($product->name_en ?? ''));
    }

    /**
     * Check if a description matches a pattern (case-insensitive contains).
     */
    private function descriptionMatchesPattern(string $description, string $pattern): bool
    {
        return mb_stripos($description, $pattern) !== false;
    }

    /**
     * Normalize description for matching (mirrors AccountSuggestionService approach).
     */
    private function normalizeDescription(string $description): string
    {
        $text = mb_strtolower(trim($description));
        $text = preg_replace('/\s+/', ' ', $text);
        // Remove invoice/reference numbers to generalize patterns
        $text = preg_replace('/\b(inv|فاتورة رقم)\s*[-#]?\s*\d+\b/u', '', $text);
        $text = preg_replace('/\b\d{4,}\b/', '', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Calculate a confidence score (0-100) for how well a description matches an ETA item code.
     */
    private function calculateConfidence(string $normalized, EtaItemCode $code): int
    {
        $codeDesc = mb_strtolower(trim($code->description ?? ''));
        $codeDescAr = mb_strtolower(trim($code->description_ar ?? ''));

        // Exact match
        if ($normalized === $codeDesc || $normalized === $codeDescAr) {
            return 100;
        }

        // Calculate word overlap
        $inputWords = array_filter(explode(' ', $normalized), fn ($w) => mb_strlen($w) >= 3);
        $codeWords = array_filter(
            explode(' ', $codeDesc . ' ' . $codeDescAr),
            fn ($w) => mb_strlen($w) >= 3,
        );

        if (empty($inputWords) || empty($codeWords)) {
            return 0;
        }

        $matchedWords = 0;
        foreach ($inputWords as $word) {
            foreach ($codeWords as $codeWord) {
                if (mb_stripos($codeWord, $word) !== false || mb_stripos($word, $codeWord) !== false) {
                    $matchedWords++;
                    break;
                }
            }
        }

        return (int) round(($matchedWords / count($inputWords)) * 80);
    }
}
