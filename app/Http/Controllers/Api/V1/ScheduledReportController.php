<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\ScheduledReport;
use App\Domain\Accounting\Services\ReportSchedulerService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduledReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScheduledReportController extends Controller
{
    public function __construct(
        private readonly ReportSchedulerService $schedulerService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->schedulerService->list([
            'is_active' => $request->query('is_active'),
            'report_type' => $request->query('report_type'),
            'schedule_type' => $request->query('schedule_type'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return response()->json($data);
    }

    public function store(StoreScheduledReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = app('tenant.id');
        $data['created_by'] = $request->user()?->id;

        $report = $this->schedulerService->create($data);

        return response()->json([
            'data' => $report,
            'message' => 'Scheduled report created.',
        ], Response::HTTP_CREATED);
    }

    public function show(ScheduledReport $scheduledReport): JsonResponse
    {
        return response()->json([
            'data' => $scheduledReport->load('creator:id,name,email'),
        ]);
    }

    public function update(StoreScheduledReportRequest $request, ScheduledReport $scheduledReport): JsonResponse
    {
        $report = $this->schedulerService->update($scheduledReport, $request->validated());

        return response()->json([
            'data' => $report,
            'message' => 'Scheduled report updated.',
        ]);
    }

    public function destroy(ScheduledReport $scheduledReport): JsonResponse
    {
        $this->schedulerService->delete($scheduledReport);

        return response()->json([
            'message' => 'Scheduled report deleted.',
        ]);
    }

    public function toggle(ScheduledReport $scheduledReport): JsonResponse
    {
        $report = $this->schedulerService->toggle($scheduledReport);

        return response()->json([
            'data' => $report,
            'message' => $report->is_active ? 'Scheduled report activated.' : 'Scheduled report deactivated.',
        ]);
    }

    public function sendNow(ScheduledReport $scheduledReport): JsonResponse
    {
        $this->schedulerService->sendNow($scheduledReport);

        return response()->json([
            'message' => 'Report sent successfully.',
        ]);
    }
}
