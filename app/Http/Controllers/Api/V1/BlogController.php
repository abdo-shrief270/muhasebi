<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Blog\Services\BlogService;
use App\Domain\Cms\Models\SlugRedirect;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCategoryResource;
use App\Http\Resources\BlogPostResource;
use App\Http\Resources\BlogTagResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlogController extends Controller
{
    public function __construct(
        private readonly BlogService $blogService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BlogPostResource::collection(
            $this->blogService->listPublishedPosts(array_merge($request->only('category', 'tag', 'search', 'page'), ['per_page' => min((int) ($request->query('per_page', 15)), 100)]))
        );
    }

    public function show(string $slug): JsonResponse
    {
        $post = $this->blogService->getPublishedPost($slug);

        if (! $post) {
            $newSlug = SlugRedirect::resolve($slug, 'blog');
            if ($newSlug) {
                return response()->json(['redirect' => $newSlug], 301);
            }
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $related = $this->blogService->getRelatedPosts($post);

        return response()->json([
            'data' => new BlogPostResource($post),
            'related' => BlogPostResource::collection($related),
        ]);
    }

    public function search(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        return BlogPostResource::collection(
            $this->blogService->searchPosts($request->input('q'))
        );
    }

    public function featured(): AnonymousResourceCollection
    {
        return BlogPostResource::collection($this->blogService->getFeaturedPosts());
    }

    public function categories(): AnonymousResourceCollection
    {
        return BlogCategoryResource::collection($this->blogService->getCategories());
    }

    public function tags(): AnonymousResourceCollection
    {
        return BlogTagResource::collection($this->blogService->getPopularTags());
    }
}
