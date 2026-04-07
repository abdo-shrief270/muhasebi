<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            $table->string('code', 50)->comment('رمز مركز التكلفة');
            $table->string('name_ar')->comment('اسم مركز التكلفة بالعربية');
            $table->string('name_en')->nullable()->comment('اسم مركز التكلفة بالإنجليزية');
            $table->string('type', 30)->comment('department, project, branch, product');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->decimal('budget_amount', 15, 2)->nullable()->comment('المبلغ المخصص');

            $table->timestamps();
            $table->softDeletes();

            // Each tenant has unique cost center codes
            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index('parent_id');
            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
