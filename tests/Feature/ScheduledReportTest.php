<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\ScheduledReport;
use App\Domain\Accounting\Services\ReportSchedulerService;
use App\Mail\ScheduledReportMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->tenant = createTenant();
    $this->admin = createAdminUser($this->tenant);
    actingAsUser($this->admin);
});

// ── Next Send Calculation ────────────────────────────────────────────

describe('calculateNextSend', function (): void {

    it('calculates next daily send as tomorrow when time has passed', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00'));

        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'schedule_type' => 'daily',
            'schedule_time' => '08:00',
            'next_send_at' => now(),
        ]);

        $service = app(ReportSchedulerService::class);
        $next = $service->calculateNextSend($report);

        expect($next->toDateString())->toBe('2026-04-08')
            ->and($next->format('H:i'))->toBe('08:00');

        Carbon::setTestNow();
    });

    it('calculates next weekly send as next Monday', function (): void {
        // 2026-04-07 is a Tuesday
        Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00'));

        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'schedule_type' => 'weekly',
            'schedule_day' => 1, // Monday
            'schedule_time' => '08:00',
            'next_send_at' => now(),
        ]);

        $service = app(ReportSchedulerService::class);
        $next = $service->calculateNextSend($report);

        expect($next->toDateString())->toBe('2026-04-13')
            ->and($next->isMonday())->toBeTrue()
            ->and($next->format('H:i'))->toBe('08:00');

        Carbon::setTestNow();
    });

    it('calculates next monthly send as 1st of next month', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00'));

        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'schedule_type' => 'monthly',
            'schedule_day' => 1,
            'schedule_time' => '08:00',
            'next_send_at' => now(),
        ]);

        $service = app(ReportSchedulerService::class);
        $next = $service->calculateNextSend($report);

        expect($next->toDateString())->toBe('2026-05-01')
            ->and($next->format('H:i'))->toBe('08:00');

        Carbon::setTestNow();
    });

    it('calculates next quarterly send correctly', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00'));

        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'schedule_type' => 'quarterly',
            'schedule_day' => 1,
            'schedule_time' => '09:00',
            'next_send_at' => now(),
        ]);

        $service = app(ReportSchedulerService::class);
        $next = $service->calculateNextSend($report);

        // Next quarter starts July 2026
        expect($next->toDateString())->toBe('2026-07-01')
            ->and($next->format('H:i'))->toBe('09:00');

        Carbon::setTestNow();
    });
});

// ── processDue ───────────────────────────────────────────────────────

describe('processDue', function (): void {

    it('sends email for due scheduled reports', function (): void {
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00'));

        ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'trial_balance',
            'report_config' => ['from' => '2026-01-01', 'to' => '2026-03-31'],
            'schedule_type' => 'monthly',
            'schedule_day' => 1,
            'schedule_time' => '08:00',
            'recipients' => ['accountant@example.com', 'manager@example.com'],
            'is_active' => true,
            'next_send_at' => Carbon::parse('2026-04-07 08:00:00'),
        ]);

        $service = app(ReportSchedulerService::class);
        $processed = $service->processDue();

        expect($processed)->toBe(1);

        Mail::assertSent(ScheduledReportMail::class, 2); // 2 recipients

        Carbon::setTestNow();
    });

    it('does not send email for inactive reports', function (): void {
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00'));

        ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'trial_balance',
            'report_config' => ['from' => '2026-01-01', 'to' => '2026-03-31'],
            'schedule_type' => 'monthly',
            'schedule_day' => 1,
            'schedule_time' => '08:00',
            'recipients' => ['test@example.com'],
            'is_active' => false,
            'next_send_at' => Carbon::parse('2026-04-07 08:00:00'),
        ]);

        $service = app(ReportSchedulerService::class);
        $processed = $service->processDue();

        expect($processed)->toBe(0);
        Mail::assertNothingSent();

        Carbon::setTestNow();
    });

    it('does not send email for future reports', function (): void {
        Mail::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-07 07:00:00'));

        ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'report_type' => 'trial_balance',
            'report_config' => ['from' => '2026-01-01', 'to' => '2026-03-31'],
            'schedule_type' => 'monthly',
            'schedule_day' => 1,
            'schedule_time' => '08:00',
            'recipients' => ['test@example.com'],
            'is_active' => true,
            'next_send_at' => Carbon::parse('2026-04-07 08:00:00'),
        ]);

        $service = app(ReportSchedulerService::class);
        $processed = $service->processDue();

        expect($processed)->toBe(0);
        Mail::assertNothingSent();

        Carbon::setTestNow();
    });
});

// ── Toggle ───────────────────────────────────────────────────────────

describe('toggle', function (): void {

    it('deactivates an active report', function (): void {
        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'next_send_at' => now()->addDay(),
        ]);

        $service = app(ReportSchedulerService::class);
        $toggled = $service->toggle($report);

        expect($toggled->is_active)->toBeFalse();
    });

    it('activates an inactive report and recalculates next_send_at', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-04-07 10:00:00'));

        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => false,
            'schedule_type' => 'daily',
            'schedule_time' => '08:00',
            'next_send_at' => Carbon::parse('2026-04-01 08:00:00'), // Old date
        ]);

        $service = app(ReportSchedulerService::class);
        $toggled = $service->toggle($report);

        expect($toggled->is_active)->toBeTrue()
            ->and($toggled->next_send_at->toDateString())->toBe('2026-04-08');

        Carbon::setTestNow();
    });
});

// ── API Endpoints ────────────────────────────────────────────────────

describe('API', function (): void {

    it('can list scheduled reports', function (): void {
        ScheduledReport::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'next_send_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/v1/scheduled-reports');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('can create a scheduled report', function (): void {
        $response = $this->postJson('/api/v1/scheduled-reports', [
            'report_type' => 'trial_balance',
            'report_config' => ['from' => '2026-01-01', 'to' => '2026-03-31'],
            'schedule_type' => 'monthly',
            'schedule_day' => 1,
            'schedule_time' => '08:00',
            'format' => 'pdf',
            'recipients' => ['accountant@example.com'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.report_type', 'trial_balance')
            ->assertJsonPath('data.schedule_type', 'monthly');

        $this->assertDatabaseHas('scheduled_reports', [
            'tenant_id' => $this->tenant->id,
            'report_type' => 'trial_balance',
            'schedule_type' => 'monthly',
        ]);
    });

    it('validates required fields on create', function (): void {
        $response = $this->postJson('/api/v1/scheduled-reports', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['report_type', 'report_config', 'schedule_type', 'recipients']);
    });

    it('validates report_type enum', function (): void {
        $response = $this->postJson('/api/v1/scheduled-reports', [
            'report_type' => 'invalid_type',
            'report_config' => [],
            'schedule_type' => 'monthly',
            'recipients' => ['test@example.com'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['report_type']);
    });

    it('can toggle a scheduled report', function (): void {
        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'next_send_at' => now()->addDay(),
        ]);

        $response = $this->postJson("/api/v1/scheduled-reports/{$report->id}/toggle");

        $response->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('can delete a scheduled report', function (): void {
        $report = ScheduledReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'next_send_at' => now()->addDay(),
        ]);

        $response = $this->deleteJson("/api/v1/scheduled-reports/{$report->id}");

        $response->assertOk();
        $this->assertSoftDeleted('scheduled_reports', ['id' => $report->id]);
    });
});
