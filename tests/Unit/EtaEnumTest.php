<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\EInvoice\Enums\EtaDocumentStatus;
use App\Domain\EInvoice\Enums\EtaDocumentType;

describe('EtaDocumentStatus', function (): void {

    it('has all expected cases', function (): void {
        expect(EtaDocumentStatus::cases())->toHaveCount(6);
    });

    it('has correct values', function (): void {
        expect(EtaDocumentStatus::Prepared->value)->toBe('prepared');
        expect(EtaDocumentStatus::Submitted->value)->toBe('submitted');
        expect(EtaDocumentStatus::Valid->value)->toBe('valid');
        expect(EtaDocumentStatus::Invalid->value)->toBe('invalid');
        expect(EtaDocumentStatus::Rejected->value)->toBe('rejected');
        expect(EtaDocumentStatus::Cancelled->value)->toBe('cancelled');
    });

    it('has English labels', function (): void {
        expect(EtaDocumentStatus::Prepared->label())->toBe('Prepared');
        expect(EtaDocumentStatus::Valid->label())->toBe('Valid');
    });

    it('has Arabic labels', function (): void {
        expect(EtaDocumentStatus::Prepared->labelAr())->toBe('جاهز للإرسال');
        expect(EtaDocumentStatus::Valid->labelAr())->toBe('صالح');
    });

    it('only allows prepared to submit', function (): void {
        expect(EtaDocumentStatus::Prepared->canSubmit())->toBeTrue();
        expect(EtaDocumentStatus::Submitted->canSubmit())->toBeFalse();
        expect(EtaDocumentStatus::Valid->canSubmit())->toBeFalse();
    });

    it('only allows valid to cancel', function (): void {
        expect(EtaDocumentStatus::Valid->canCancel())->toBeTrue();
        expect(EtaDocumentStatus::Prepared->canCancel())->toBeFalse();
        expect(EtaDocumentStatus::Submitted->canCancel())->toBeFalse();
    });

    it('identifies terminal statuses', function (): void {
        expect(EtaDocumentStatus::Rejected->isTerminal())->toBeTrue();
        expect(EtaDocumentStatus::Cancelled->isTerminal())->toBeTrue();
        expect(EtaDocumentStatus::Valid->isTerminal())->toBeFalse();
        expect(EtaDocumentStatus::Submitted->isTerminal())->toBeFalse();
    });
});

describe('EtaDocumentType', function (): void {

    it('has correct values', function (): void {
        expect(EtaDocumentType::Invoice->value)->toBe('I');
        expect(EtaDocumentType::CreditNote->value)->toBe('C');
        expect(EtaDocumentType::DebitNote->value)->toBe('D');
    });

    it('has Arabic labels', function (): void {
        expect(EtaDocumentType::Invoice->labelAr())->toBe('فاتورة');
        expect(EtaDocumentType::CreditNote->labelAr())->toBe('إشعار دائن');
        expect(EtaDocumentType::DebitNote->labelAr())->toBe('إشعار مدين');
    });

    it('maps from InvoiceType correctly', function (): void {
        expect(EtaDocumentType::fromInvoiceType(InvoiceType::Invoice))->toBe(EtaDocumentType::Invoice);
        expect(EtaDocumentType::fromInvoiceType(InvoiceType::CreditNote))->toBe(EtaDocumentType::CreditNote);
        expect(EtaDocumentType::fromInvoiceType(InvoiceType::DebitNote))->toBe(EtaDocumentType::DebitNote);
    });
});
