<?php

declare(strict_types=1);

use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

describe('Employees CRUD', function (): void {

    it('creates an employee record', function (): void {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/employees', [
                'user_id' => $user->id,
                'hire_date' => '2026-01-01',
                'department' => 'محاسبة',
                'job_title' => 'محاسب',
                'base_salary' => 8000,
                'is_insured' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.base_salary', '8000.00')
            ->assertJsonPath('data.is_insured', true);
    });

    it('lists employees', function (): void {
        Employee::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/employees');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('rejects duplicate employee for same user', function (): void {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/employees', [
                'user_id' => $user->id,
                'hire_date' => '2026-01-01',
                'base_salary' => 5000,
            ])
            ->assertUnprocessable();
    });
});

describe('Payroll Runs', function (): void {

    it('creates a payroll run', function (): void {
        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payroll', [
                'month' => 4,
                'year' => 2026,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.month', 4)
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.status', 'draft');
    });

    it('rejects duplicate payroll run for same month/year', function (): void {
        PayrollRun::factory()->create([
            'tenant_id' => $this->tenant->id,
            'month' => 4,
            'year' => 2026,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/payroll', [
                'month' => 4,
                'year' => 2026,
            ])
            ->assertUnprocessable();
    });

    it('calculates payroll with correct social insurance and tax', function (): void {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'base_salary' => 10000,
            'is_insured' => true,
        ]);

        $run = PayrollRun::factory()->create([
            'tenant_id' => $this->tenant->id,
            'month' => 4,
            'year' => 2026,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/payroll/{$run->id}/calculate");

        $response->assertOk()
            ->assertJsonPath('data.status', 'calculated');

        $run->refresh();
        expect((float) $run->total_gross)->toBeGreaterThan(0);
        expect((float) $run->total_net)->toBeGreaterThan(0);
        expect((float) $run->total_social_insurance)->toBeGreaterThan(0);

        // Verify item was created
        expect($run->items()->count())->toBe(1);

        $item = $run->items()->first();
        // SI employee: min(10000, 12600) * 0.11 = 1100
        expect((float) $item->social_insurance_employee)->toBe(1100.0);
        // SI employer: min(10000, 12600) * 0.1875 = 1875
        expect((float) $item->social_insurance_employer)->toBe(1875.0);
    });

    it('approves a calculated run', function (): void {
        $run = PayrollRun::factory()->calculated()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/payroll/{$run->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved');
    });

    it('marks an approved run as paid', function (): void {
        $run = PayrollRun::factory()->approved()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/payroll/{$run->id}/mark-paid");

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid');
    });

    it('prevents calculating a non-draft run', function (): void {
        $run = PayrollRun::factory()->calculated()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/payroll/{$run->id}/calculate")
            ->assertUnprocessable();
    });

    it('deletes a draft payroll run', function (): void {
        $run = PayrollRun::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => PayrollStatus::Draft,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/payroll/{$run->id}")
            ->assertOk();

        $this->assertSoftDeleted('payroll_runs', ['id' => $run->id]);
    });

    it('prevents deleting non-draft run', function (): void {
        $run = PayrollRun::factory()->calculated()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson("/api/v1/payroll/{$run->id}")
            ->assertUnprocessable();
    });
});
