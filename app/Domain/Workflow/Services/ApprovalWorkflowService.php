<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\ApprovalStatus;
use App\Domain\Workflow\Enums\ApproverType;
use App\Domain\Workflow\Models\ApprovalAction;
use App\Domain\Workflow\Models\ApprovalRequest;
use App\Domain\Workflow\Models\ApprovalStep;
use App\Domain\Workflow\Models\ApprovalWorkflow;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ApprovalWorkflowService
{
    // ──────────────────────────────────────
    // Workflow CRUD
    // ──────────────────────────────────────

    /**
     * Create a workflow with its steps.
     *
     * @param  array<string, mixed>  $data
     */
    public function createWorkflow(array $data): ApprovalWorkflow
    {
        return DB::transaction(function () use ($data): ApprovalWorkflow {
            $workflow = ApprovalWorkflow::create([
                'tenant_id' => $data['tenant_id'] ?? app('tenant.id'),
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'] ?? null,
                'entity_type' => $data['entity_type'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['steps'])) {
                foreach ($data['steps'] as $index => $step) {
                    $workflow->steps()->create([
                        'step_order' => $step['step_order'] ?? ($index + 1),
                        'approver_type' => $step['approver_type'],
                        'approver_id' => $step['approver_id'] ?? null,
                        'approval_limit' => $step['approval_limit'] ?? null,
                        'timeout_hours' => $step['timeout_hours'] ?? null,
                    ]);
                }
            }

            return $workflow->load('steps');
        });
    }

    /**
     * Update a workflow and replace its steps.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateWorkflow(ApprovalWorkflow $workflow, array $data): ApprovalWorkflow
    {
        return DB::transaction(function () use ($workflow, $data): ApprovalWorkflow {
            $workflow->update(collect($data)->only([
                'name_ar', 'name_en', 'entity_type', 'is_active',
            ])->toArray());

            if (isset($data['steps'])) {
                $workflow->steps()->delete();

                foreach ($data['steps'] as $index => $step) {
                    $workflow->steps()->create([
                        'step_order' => $step['step_order'] ?? ($index + 1),
                        'approver_type' => $step['approver_type'],
                        'approver_id' => $step['approver_id'] ?? null,
                        'approval_limit' => $step['approval_limit'] ?? null,
                        'timeout_hours' => $step['timeout_hours'] ?? null,
                    ]);
                }
            }

            return $workflow->load('steps');
        });
    }

    /**
     * Delete a workflow (cascades to steps).
     */
    public function deleteWorkflow(ApprovalWorkflow $workflow): void
    {
        $workflow->delete();
    }

    /**
     * List workflows with pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listWorkflows(array $filters = []): LengthAwarePaginator
    {
        return ApprovalWorkflow::query()
            ->with('steps')
            ->when(isset($filters['entity_type']), fn ($q) => $q->where('entity_type', $filters['entity_type']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    // ──────────────────────────────────────
    // Approval Flow
    // ──────────────────────────────────────

    /**
     * Submit an entity for approval.
     * Finds the matching active workflow, creates an ApprovalRequest.
     */
    public function submitForApproval(string $entityType, int $entityId, ?float $amount = null): ?ApprovalRequest
    {
        $workflow = ApprovalWorkflow::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->first();

        if (! $workflow) {
            return null;
        }

        // If amount is provided, check if any step has an approval_limit
        // and whether the amount exceeds it
        if ($amount !== null) {
            $applicableSteps = $workflow->steps->filter(function (ApprovalStep $step) use ($amount): bool {
                return $step->approval_limit === null || $amount > (float) $step->approval_limit;
            });

            if ($applicableSteps->isEmpty()) {
                return null;
            }
        }

        return ApprovalRequest::create([
            'tenant_id' => app('tenant.id'),
            'workflow_id' => $workflow->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'current_step' => 1,
            'status' => ApprovalStatus::InProgress,
            'requested_by' => Auth::id(),
        ]);
    }

    /**
     * Approve the current step. Advances to next step or marks approved.
     */
    public function approve(ApprovalRequest $request, ?string $comment = null): ApprovalRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['This request is no longer pending approval.'],
            ]);
        }

        return DB::transaction(function () use ($request, $comment): ApprovalRequest {
            ApprovalAction::create([
                'approval_request_id' => $request->id,
                'step_order' => $request->current_step,
                'action' => 'approved',
                'acted_by' => Auth::id(),
                'comment' => $comment,
                'acted_at' => Carbon::now(),
            ]);

            $totalSteps = $request->workflow->steps()->count();

            if ($request->current_step >= $totalSteps) {
                $request->update([
                    'status' => ApprovalStatus::Approved,
                ]);
            } else {
                $request->update([
                    'current_step' => $request->current_step + 1,
                    'status' => ApprovalStatus::InProgress,
                ]);
            }

            return $request->fresh(['workflow.steps', 'actions']);
        });
    }

    /**
     * Reject the request with a required comment.
     */
    public function reject(ApprovalRequest $request, string $comment): ApprovalRequest
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => ['This request is no longer pending approval.'],
            ]);
        }

        return DB::transaction(function () use ($request, $comment): ApprovalRequest {
            ApprovalAction::create([
                'approval_request_id' => $request->id,
                'step_order' => $request->current_step,
                'action' => 'rejected',
                'acted_by' => Auth::id(),
                'comment' => $comment,
                'acted_at' => Carbon::now(),
            ]);

            $request->update([
                'status' => ApprovalStatus::Rejected,
            ]);

            return $request->fresh(['workflow.steps', 'actions']);
        });
    }

    /**
     * Determine who needs to approve next based on the current step.
     *
     * @return array{type: ApproverType, id: int|null}|null
     */
    public function getNextApprover(ApprovalRequest $request): ?array
    {
        if (! $request->isPending()) {
            return null;
        }

        $step = $request->workflow->steps
            ->where('step_order', $request->current_step)
            ->first();

        if (! $step) {
            return null;
        }

        return [
            'type' => $step->approver_type,
            'id' => $step->approver_id,
        ];
    }

    /**
     * Get all pending approvals for a user (by direct assignment or role).
     */
    public function listPending(int $userId): Collection
    {
        $user = User::findOrFail($userId);
        $roleNames = $user->getRoleNames()->toArray();

        // Get role IDs from Spatie
        $roleIds = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id')
            ->toArray();

        return ApprovalRequest::query()
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::InProgress])
            ->whereHas('workflow.steps', function ($q) use ($userId, $roleIds) {
                $q->whereColumn('approval_steps.step_order', 'approval_requests.current_step')
                    ->where(function ($q) use ($userId, $roleIds): void {
                        $q->where(function ($q) use ($userId): void {
                            $q->where('approver_type', ApproverType::User)
                                ->where('approver_id', $userId);
                        })->orWhere(function ($q) use ($roleIds): void {
                            $q->where('approver_type', ApproverType::Role)
                                ->whereIn('approver_id', $roleIds);
                        })->orWhere('approver_type', ApproverType::Manager);
                    });
            })
            ->with(['workflow', 'requester'])
            ->get();
    }

    /**
     * Get approval history for an entity.
     */
    public function listForEntity(string $type, int $id): Collection
    {
        return ApprovalRequest::query()
            ->where('entity_type', $type)
            ->where('entity_id', $id)
            ->with(['workflow', 'actions.actor', 'requester'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
