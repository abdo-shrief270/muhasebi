<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cms\Models\SlugRedirect;
use App\Domain\Cms\Services\CmsService;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\CmsPageResource;
use App\Http\Resources\FaqResource;
use App\Http\Resources\TestimonialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function __construct(
        private readonly CmsService $cmsService,
    ) {}

    /**
     * GET /v1/landing — public landing page data.
     */
    public function index(): JsonResponse
    {
        $data = $this->cmsService->getLandingData();

        return response()->json([
            'data' => [
                'hero' => $data['hero'],
                'stats' => $data['stats'],
                'testimonials' => TestimonialResource::collection($data['testimonials']),
                'faqs' => FaqResource::collection($data['faqs']),
            ],
        ]);
    }

    /**
     * GET /v1/pages/{slug} — public page by slug.
     */
    public function showPage(string $slug): JsonResponse
    {
        $page = $this->cmsService->getPublishedPage($slug);

        if (! $page) {
            // Check for slug redirect (301)
            $newSlug = SlugRedirect::resolve($slug, 'page');
            if ($newSlug) {
                return response()->json([
                    'redirect' => $newSlug,
                ], 301);
            }

            return ApiResponse::notFound(__('messages.error.not_found'));
        }

        return response()->json([
            'data' => new CmsPageResource($page),
        ]);
    }

    /**
     * POST /v1/contact — public contact form submission.
     */
    public function submitContact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            // Honeypot field — checked after validation (bots fill it in)
            'website' => 'nullable|string|max:500',
            // Timing check — form must take at least 2 seconds to fill
            '_timestamp' => 'nullable|integer',
        ]);

        // Honeypot: if 'website' field has content, it's a bot
        if (! empty($validated['website'])) {
            // Silently accept but don't process (so bots think it worked)
            return response()->json(['message' => 'Your message has been sent successfully.'], 201);
        }

        // Timing: if form was submitted in under 2 seconds, likely a bot
        if (isset($validated['_timestamp'])) {
            $elapsed = time() - (int) $validated['_timestamp'];
            if ($elapsed < 2) {
                return response()->json(['message' => 'Your message has been sent successfully.'], 201);
            }
        }

        // Remove honeypot fields before saving
        unset($validated['website'], $validated['_timestamp']);

        $this->cmsService->submitContact($validated);

        return response()->json([
            'message' => 'Your message has been sent successfully.',
        ], 201);
    }
}
