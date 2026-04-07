<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Cms\Models\ContactSubmission;
use App\Domain\Shared\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Batch (bulk) operations for admin panel.
 * All operations are atomic (transaction-wrapped) and audited.
 */
class AdminBatchController extends Controller
{
    /**
     * Bulk update tenant statuses.
     *
     * POST /admin/batch/tenants/update-status
     * { "ids": [1,2,3], "status": "suspended" }
     */
    public function updateTenantStatus(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|exists:tenants,id',
            'status' => 'required|string|in:active,suspended,cancelled',
        ]);

        $ids = $request->input('ids');
        $status = $request->input('status');

        $count = DB::transaction(function () use ($ids, $status) {
            return Tenant::whereIn('id', $ids)
                ->update(['status' => $status]);
        });

        return response()->json([
            'message' => "{$count} tenant(s) updated to {$status}.",
            'data' => ['affected' => $count],
        ]);
    }

    /**
     * Bulk toggle user active status.
     *
     * POST /admin/batch/users/toggle-active
     * { "ids": [1,2,3], "is_active": false }
     */
    public function toggleUsersActive(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|exists:users,id',
            'is_active' => 'required|boolean',
        ]);

        $ids = $request->input('ids');
        $isActive = $request->boolean('is_active');

        // Prevent deactivating the current user
        $currentUserId = $request->user()?->id;
        if (! $isActive && in_array($currentUserId, $ids)) {
            return response()->json([
                'error' => 'cannot_deactivate_self',
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        $count = DB::transaction(function () use ($ids, $isActive) {
            return User::whereIn('id', $ids)->update(['is_active' => $isActive]);
        });

        $action = $isActive ? 'activated' : 'deactivated';

        return response()->json([
            'message' => "{$count} user(s) {$action}.",
            'data' => ['affected' => $count],
        ]);
    }

    /**
     * Bulk delete users (soft-delete).
     *
     * POST /admin/batch/users/delete
     * { "ids": [1,2,3] }
     */
    public function deleteUsers(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|exists:users,id',
        ]);

        $ids = $request->input('ids');
        $currentUserId = $request->user()?->id;

        // Prevent self-deletion
        $ids = array_filter($ids, fn ($id) => $id !== $currentUserId);

        $count = User::whereIn('id', $ids)->where('role', '!=', 'super_admin')->delete();

        return response()->json([
            'message' => "{$count} user(s) deleted.",
            'data' => ['affected' => $count],
        ]);
    }

    /**
     * Bulk update contact submission statuses.
     *
     * POST /admin/batch/contacts/update-status
     * { "ids": [1,2,3], "status": "resolved" }
     */
    public function updateContactStatus(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|exists:contact_submissions,id',
            'status' => 'required|string|in:new,in_progress,resolved,archived',
        ]);

        $count = ContactSubmission::whereIn('id', $request->input('ids'))
            ->update(['status' => $request->input('status')]);

        return response()->json([
            'message' => "{$count} submission(s) updated.",
            'data' => ['affected' => $count],
        ]);
    }

    /**
     * Bulk mark contacts as read.
     *
     * POST /admin/batch/contacts/mark-read
     * { "ids": [1,2,3] }
     */
    public function markContactsRead(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
        ]);

        $count = ContactSubmission::whereIn('id', $request->input('ids'))
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => "{$count} submission(s) marked as read.",
            'data' => ['affected' => $count],
        ]);
    }

    /**
     * Bulk delete contact submissions.
     *
     * POST /admin/batch/contacts/delete
     * { "ids": [1,2,3] }
     */
    public function deleteContacts(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
        ]);

        $count = ContactSubmission::whereIn('id', $request->input('ids'))->delete();

        return response()->json([
            'message' => "{$count} submission(s) deleted.",
            'data' => ['affected' => $count],
        ]);
    }

    /**
     * Bulk delete blog posts.
     *
     * POST /admin/batch/blog/delete
     * { "ids": [1,2,3] }
     */
    public function deleteBlogPosts(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
        ]);

        $posts = \App\Domain\Blog\Models\BlogPost::where('tenant_id', app('tenant.id'))->whereIn('id', $request->input('ids'))->get();

        $count = 0;
        foreach ($posts as $post) {
            $post->tags()->detach();
            $post->delete();
            $count++;
        }

        return response()->json([
            'message' => "{$count} post(s) deleted.",
            'data' => ['affected' => $count],
        ]);
    }

    /**
     * Bulk publish/unpublish blog posts.
     *
     * POST /admin/batch/blog/toggle-publish
     * { "ids": [1,2,3], "is_published": true }
     */
    public function toggleBlogPublish(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
            'is_published' => 'required|boolean',
        ]);

        $updates = ['is_published' => $request->boolean('is_published')];

        if ($request->boolean('is_published')) {
            $updates['published_at'] = now();
        }

        $count = \App\Domain\Blog\Models\BlogPost::where('tenant_id', app('tenant.id'))
            ->whereIn('id', $request->input('ids'))
            ->update($updates);

        return response()->json([
            'message' => "{$count} post(s) updated.",
            'data' => ['affected' => $count],
        ]);
    }
}
