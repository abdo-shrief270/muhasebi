<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // wht_certificates is owned by 2026_04_07_600001_create_wht_certificates_table.php
        // (which has a more complete schema: total_taxable_amount, wht_rate, wht_amount,
        // notes, created_by, tenant+certificate_number unique). A duplicate create was
        // landing here ahead of the vendors table (both share timestamp 2026_04_07_100001;
        // alphabetical order put tax_module before vendors), causing migrate:fresh to fail.

        Schema::create('tax_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->string('type');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status')->default('draft');
            $table->decimal('tax_due', 15, 2)->default(0);
            $table->decimal('tax_paid', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamp('filed_at')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'fiscal_year_id']);
            $table->index(['tenant_id', 'period_from', 'period_to']);
        });

        Schema::create('tax_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->string('type');
            $table->string('description_ar');
            $table->string('description_en');
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'fiscal_year_id']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_adjustments');
        Schema::dropIfExists('tax_returns');
    }
};
