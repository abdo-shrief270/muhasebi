<?php

declare(strict_types=1);

use App\Domain\EInvoice\Enums\ItemCodeAssignmentSource;
use App\Domain\EInvoice\Enums\ItemCodeSyncStatus;
use App\Domain\EInvoice\Models\EtaItemCode;
use App\Domain\EInvoice\Models\EtaItemCodeMapping;
use App\Domain\EInvoice\Services\EtaItemCodeMasterService;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

// ──────────────────────────────────────
// Enum labels
// ──────────────────────────────────────

describe('ItemCodeSyncStatus labels', function (): void {
    it('has non-empty labels for all cases', function (): void {
        foreach (ItemCodeSyncStatus::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
            expect($case->labelAr())->toBeString()->not->toBeEmpty();
        }
    });
});

describe('ItemCodeAssignmentSource labels', function (): void {
    it('has non-empty labels for all cases', function (): void {
        foreach (ItemCodeAssignmentSource::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
            expect($case->labelAr())->toBeString()->not->toBeEmpty();
        }
    });
});

// ──────────────────────────────────────
// Bulk Import
// ──────────────────────────────────────

describe('Bulk Import', function (): void {
    it('imports 3 codes and creates all of them', function (): void {
        $service = app(EtaItemCodeMasterService::class);

        $codes = [
            [
                'code' => 'EG-BI-001',
                'code_type' => 'EGS',
                'description_ar' => 'خدمات استشارية',
                'description_en' => 'Consulting services',
            ],
            [
                'code' => 'EG-BI-002',
                'code_type' => 'EGS',
                'description_ar' => 'خدمات محاسبية',
                'description_en' => 'Accounting services',
            ],
            [
                'code' => 'GS1-BI-003',
                'code_type' => 'GS1',
                'description_ar' => 'منتج أ',
                'description_en' => 'Product A',
            ],
        ];

        $result = $service->bulkImport($codes);

        expect($result['created'])->toBe(3);
        expect($result['updated'])->toBe(0);

        $stored = EtaItemCode::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('item_code', ['EG-BI-001', 'EG-BI-002', 'GS1-BI-003'])
            ->get();

        expect($stored)->toHaveCount(3);
    });
});

// ──────────────────────────────────────
// Pattern Matching (via auto-assign internals)
// ──────────────────────────────────────

describe('Pattern Matching', function (): void {
    it('"contains" rule matches description via suggestCode', function (): void {
        $itemCode = EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-PM-001',
            'description' => 'IT consulting services',
            'description_ar' => 'خدمات استشارات تقنية',
            'is_active' => true,
        ]);

        EtaItemCodeMapping::query()->create([
            'tenant_id' => $this->tenant->id,
            'eta_item_code_id' => $itemCode->id,
            'description_pattern' => 'consulting',
            'priority' => 10,
            'assignment_source' => 'manual',
        ]);

        $service = app(EtaItemCodeMasterService::class);
        $suggestions = $service->suggestCode('consulting services for our company');

        expect($suggestions)->not->toBeEmpty();
        expect($suggestions[0]['item_code'])->toBe('EG-PM-001');
    });
});

// ──────────────────────────────────────
// Suggest Code
// ──────────────────────────────────────

describe('Suggest Code', function (): void {
    it('returns suggestion with confidence score', function (): void {
        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-SC-001',
            'description' => 'Software development services',
            'description_ar' => 'خدمات تطوير البرمجيات',
            'is_active' => true,
        ]);

        $service = app(EtaItemCodeMasterService::class);
        $suggestions = $service->suggestCode('software development');

        expect($suggestions)->not->toBeEmpty();

        $first = $suggestions[0];
        expect($first)->toHaveKeys(['eta_item_code_id', 'item_code', 'description', 'confidence', 'match_type']);
        expect($first['confidence'])->toBeGreaterThan(0)->toBeLessThanOrEqual(100);
        expect($first['item_code'])->toBe('EG-SC-001');
    });
});

// ──────────────────────────────────────
// Usage Report
// ──────────────────────────────────────

describe('Usage Report', function (): void {
    it('returns correct structure', function (): void {
        EtaItemCode::query()->create([
            'tenant_id' => $this->tenant->id,
            'code_type' => 'EGS',
            'item_code' => 'EG-UR-001',
            'description' => 'Report test code',
            'is_active' => true,
        ]);

        $service = app(EtaItemCodeMasterService::class);
        $report = $service->usageReport(['tenant_id' => $this->tenant->id]);

        expect($report)->toHaveKeys(['by_code', 'unused_codes', 'missing_lines_count']);
        expect($report['by_code'])->toBeArray();
        expect($report['unused_codes'])->toBeArray();
        expect($report['missing_lines_count'])->toBeInt();
    });
});
