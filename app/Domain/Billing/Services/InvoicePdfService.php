<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceSettings;
use App\Domain\Tenant\Services\TenantBrandingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class InvoicePdfService
{
    public function __construct(
        private readonly TenantBrandingService $branding,
    ) {}


    /**
     * Generate and download an invoice PDF using the tenant's configured template.
     */
    public function download(Invoice $invoice): Response
    {
        $data = $this->buildTemplateData($invoice);

        $pdf = Pdf::loadView("reports.invoice-{$data['template']}", $data);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }

    /**
     * Generate PDF as a stream (for inline viewing / preview).
     */
    public function stream(Invoice $invoice): Response
    {
        $data = $this->buildTemplateData($invoice);

        $pdf = Pdf::loadView("reports.invoice-{$data['template']}", $data);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("invoice-{$invoice->invoice_number}.pdf");
    }

    /**
     * Generate PDF as raw string (for email attachment or storage).
     */
    public function generateRaw(Invoice $invoice): string
    {
        $data = $this->buildTemplateData($invoice);

        $pdf = Pdf::loadView("reports.invoice-{$data['template']}", $data);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    /**
     * Build template data array from invoice + tenant settings.
     */
    private function buildTemplateData(Invoice $invoice): array
    {
        $invoice->load(['client', 'lines', 'payments', 'tenant']);
        $tenant = $invoice->tenant ?? app('tenant');

        $settings = InvoiceSettings::where('tenant_id', $tenant->id)->first();

        // Determine template (falls back to 'modern' if view doesn't exist)
        $template = $settings?->pdf_template ?? 'modern';
        if (! view()->exists("reports.invoice-{$template}")) {
            $template = 'modern';
        }

        return [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'settings' => $settings,
            'template' => $template,
            'generatedAt' => now()->format('Y-m-d H:i'),
            // Template options
            'showLogo' => $settings?->pdf_show_logo ?? true,
            'showVatBreakdown' => $settings?->pdf_show_vat_breakdown ?? true,
            'showPaymentTerms' => $settings?->pdf_show_payment_terms ?? true,
            'footerText' => $settings?->pdf_footer_text ?? null,
            'headerText' => $settings?->pdf_header_text ?? null,
            // Resolution order:
            //   1. InvoiceSettings::pdf_accent_color (per-tenant override for PDFs)
            //   2. Tenant SPA branding primary (set via /settings/branding)
            //   3. Legacy tenant.primary_color (Blade landing-page column, kept as fallback)
            //   4. Hardcoded default
            'accentColor' => $settings?->pdf_accent_color
                ?? $this->branding->getEffective($tenant)['colors']['primary'],
            'logoUrl' => $tenant->logo_path ? storage_path('app/public/'.$tenant->logo_path) : null,
        ];
    }
}
