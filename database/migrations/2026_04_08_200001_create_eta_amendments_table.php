<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eta_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('original_document_id')->constrained('eta_documents')->cascadeOnDelete();
            $table->string('amendment_type', 20);
            $table->text('reason_ar');
            $table->text('reason_en')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('eta_reference', 50)->nullable()->comment('ETA amendment reference');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('response_at')->nullable();
            $table->json('response_data')->nullable()->comment('ETA API response');
            $table->date('deadline')->nullable()->comment('Amendment must be submitted before this');
            $table->foreignId('amended_document_id')->nullable()->constrained('eta_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'original_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eta_amendments');
    }
};
