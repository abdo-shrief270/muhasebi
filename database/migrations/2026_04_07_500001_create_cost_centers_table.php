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

            $table->string('code', 30)->comment('كود مركز التكلفة');
            $table->string('name_ar')->comment('اسم مركز التكلفة بالعربية');
            $table->string('name_en')->nullable()->comment('اسم مركز التكلفة بالإنجليزية');
            $table->string('type', 20)->default('department')->comment('نوع مركز التكلفة');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Unique constraint
            $table->unique(['tenant_id', 'code']);

            // Indexes
            $table->index(['tenant_id', 'type', 'is_active']);
            $table->index(['tenant_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
