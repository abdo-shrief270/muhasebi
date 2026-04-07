<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wht_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('certificate_number')->nullable()->unique();
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('total_payments', 15, 2)->default(0);
            $table->decimal('total_wht', 15, 2)->default(0);
            $table->string('status')->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'vendor_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'period_from', 'period_to']);
        });

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
        Schema::dropIfExists('wht_certificates');
    }
};
