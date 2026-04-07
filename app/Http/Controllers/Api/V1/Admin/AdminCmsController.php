<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Blog\Models\BlogPost;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\ContactSubmission;
use App\Domain\Cms\Models\Faq;
use App\Domain\Cms\Models\Testimonial;
use App\Domain\Cms\Services\CmsService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CmsPageResource;
use App\Http\Resources\ContactSubmissionResource;
use App\Http\Resources\FaqResource;
use App\Http\Resources\TestimonialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AdminCmsController extends Controller
{
    public function __construct(
        private readonly CmsService $cmsService,
    ) {}

    // ── CMS Analytics ─────────────────────────────────────────

    public function analytics(): JsonResponse
    {
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        return response()->json([
            'data' => [
                'blog' => [
                    'total_posts' => BlogPost::count(),
                    'published_posts' => BlogPost::where('is_published', true)->count(),
                    'draft_posts' => BlogPost::where('is_published', false)->count(),
                    'total_views' => BlogPost::sum('views_count'),
                    'views_30d' => BlogPost::where('updated_at', '>=', $thirtyDaysAgo)->sum('views_count'),
                    'top_posts' => BlogPost::published()
                        ->select('id', 'slug', 'title_en', 'title_ar', 'views_count', 'published_at')
                        ->orderByDesc('views_count')
                        ->limit(5)
                        ->get(),
                ],
                'pages' => [
                    'total' => CmsPage::count(),
                    'published' => CmsPage::where('is_published', true)->count(),
                ],
                'contacts' => [
                    'total' => ContactSubmission::count(),
                    'unread' => ContactSubmission::where('is_read', false)->count(),
                    'new' => ContactSubmission::where('status', 'new')->count(),
                    'in_progress' => ContactSubmission::where('status', 'in_progress')->count(),
                    'resolved' => ContactSubmission::where('status', 'resolved')->count(),
                    'last_30_days' => ContactSubmission::where('created_at', '>=', $thirtyDaysAgo)->count(),
                ],
                'testimonials' => [
                    'total' => Testimonial::count(),
                    'active' => Testimonial::where('is_active', true)->count(),
                ],
                'faqs' => [
                    'total' => Faq::count(),
                    'active' => Faq::where('is_active', true)->count(),
                ],
            ],
        ]);
    }

    // ── Landing Settings ──────────────────────────────────────

    public function getLanding(): JsonResponse
    {
        return response()->json([
            'data' => $this->cmsService->getAdminLandingData(),
        ]);
    }

    public function updateLanding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hero' => 'nullable|array',
            'hero.badge' => 'nullable|array',
            'hero.title1' => 'nullable|array',
            'hero.title2' => 'nullable|array',
            'hero.subtitle' => 'nullable|array',
            'stats' => 'nullable|array',
            'stats.firms' => 'nullable|string',
            'stats.invoices' => 'nullable|string',
            'stats.uptime' => 'nullable|string',
        ]);

        return response()->json([
            'data' => $this->cmsService->updateLandingData($data),
        ]);
    }

    // ── CMS Pages ─────────────────────────────────────────────

    public function listPages(Request $request): AnonymousResourceCollection
    {
        $pages = $this->cmsService->listPages($request->only('search', 'per_page', 'page'));

        return CmsPageResource::collection($pages);
    }

    public function showPage(CmsPage $page): CmsPageResource
    {
        return new CmsPageResource($page);
    }

    public function storePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => 'required|string|max:100|unique:cms_pages,slug',
            'title' => 'required|array',
            'title.ar' => 'required|string|max:255',
            'title.en' => 'required|string|max:255',
            'content' => 'nullable|array',
            'content.ar' => 'nullable|string',
            'content.en' => 'nullable|string',
            'meta_description' => 'nullable|array',
            'meta_description.ar' => 'nullable|string|max:255',
            'meta_description.en' => 'nullable|string|max:255',
            'is_published' => 'boolean',
        ]);

        $page = $this->cmsService->createPage($data);

        return (new CmsPageResource($page))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updatePage(Request $request, CmsPage $page): CmsPageResource
    {
        $data = $request->validate([
            'slug' => "nullable|string|max:100|unique:cms_pages,slug,{$page->id}",
            'title' => 'nullable|array',
            'title.ar' => 'nullable|string|max:255',
            'title.en' => 'nullable|string|max:255',
            'content' => 'nullable|array',
            'content.ar' => 'nullable|string',
            'content.en' => 'nullable|string',
            'meta_description' => 'nullable|array',
            'meta_description.ar' => 'nullable|string|max:255',
            'meta_description.en' => 'nullable|string|max:255',
            'is_published' => 'boolean',
        ]);

        $page = $this->cmsService->updatePage($page, $data);

        return new CmsPageResource($page);
    }

    public function destroyPage(CmsPage $page): JsonResponse
    {
        $page->delete();

        return response()->json(['message' => 'Page deleted.']);
    }

    // ── Testimonials ──────────────────────────────────────────

    public function listTestimonials(Request $request): AnonymousResourceCollection
    {
        return TestimonialResource::collection(
            $this->cmsService->listTestimonials($request->only('per_page', 'page'))
        );
    }

    public function storeTestimonial(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|array', 'name.ar' => 'required|string', 'name.en' => 'required|string',
            'role' => 'required|array', 'role.ar' => 'required|string', 'role.en' => 'required|string',
            'quote' => 'required|array', 'quote.ar' => 'required|string', 'quote.en' => 'required|string',
            'rating' => 'integer|min:1|max:5',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $testimonial = $this->cmsService->createTestimonial($data);

        return (new TestimonialResource($testimonial))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateTestimonial(Request $request, Testimonial $testimonial): TestimonialResource
    {
        $data = $request->validate([
            'name' => 'nullable|array', 'name.ar' => 'nullable|string', 'name.en' => 'nullable|string',
            'role' => 'nullable|array', 'role.ar' => 'nullable|string', 'role.en' => 'nullable|string',
            'quote' => 'nullable|array', 'quote.ar' => 'nullable|string', 'quote.en' => 'nullable|string',
            'rating' => 'integer|min:1|max:5',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        return new TestimonialResource(
            $this->cmsService->updateTestimonial($testimonial, $data)
        );
    }

    public function destroyTestimonial(Testimonial $testimonial): JsonResponse
    {
        $testimonial->delete();

        return response()->json(['message' => 'Testimonial deleted.']);
    }

    // ── FAQs ──────────────────────────────────────────────────

    public function listFaqs(Request $request): AnonymousResourceCollection
    {
        return FaqResource::collection(
            $this->cmsService->listFaqs($request->only('per_page', 'page'))
        );
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|array', 'question.ar' => 'required|string', 'question.en' => 'required|string',
            'answer' => 'required|array', 'answer.ar' => 'required|string', 'answer.en' => 'required|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $faq = $this->cmsService->createFaq($data);

        return (new FaqResource($faq))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateFaq(Request $request, Faq $faq): FaqResource
    {
        $data = $request->validate([
            'question' => 'nullable|array', 'question.ar' => 'nullable|string', 'question.en' => 'nullable|string',
            'answer' => 'nullable|array', 'answer.ar' => 'nullable|string', 'answer.en' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        return new FaqResource($this->cmsService->updateFaq($faq, $data));
    }

    public function destroyFaq(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(['message' => 'FAQ deleted.']);
    }

    // ── Contact Submissions ───────────────────────────────────

    public function listContacts(Request $request): AnonymousResourceCollection
    {
        return ContactSubmissionResource::collection(
            $this->cmsService->listContacts($request->only('search', 'is_read', 'per_page', 'page'))
        );
    }

    public function showContact(ContactSubmission $contactSubmission): ContactSubmissionResource
    {
        $this->cmsService->markContactRead($contactSubmission);

        return new ContactSubmissionResource($contactSubmission);
    }

    public function updateContact(Request $request, ContactSubmission $contactSubmission): ContactSubmissionResource
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:new,in_progress,resolved,archived',
            'assigned_to' => 'nullable|string|max:255',
            'admin_notes' => 'nullable|string|max:5000',
        ]);

        $contactSubmission->update($data);

        return new ContactSubmissionResource($contactSubmission->fresh());
    }

    public function destroyContact(ContactSubmission $contactSubmission): JsonResponse
    {
        $contactSubmission->delete();

        return response()->json(['message' => 'Submission deleted.']);
    }
}
