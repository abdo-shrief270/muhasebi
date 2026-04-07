<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Enums;

enum ItemCodeAssignmentSource: string
{
    case Manual = 'manual';
    case BulkImport = 'bulk_import';
    case AutoSync = 'auto_sync';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::BulkImport => 'Bulk Import',
            self::AutoSync => 'Auto Sync',
            self::Api => 'API',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Manual => 'يدوي',
            self::BulkImport => 'استيراد مجمّع',
            self::AutoSync => 'مزامنة تلقائية',
            self::Api => 'واجهة برمجية',
        };
    }
}
