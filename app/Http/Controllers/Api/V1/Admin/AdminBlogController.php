<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Blog\Models\BlogCategory;
use App\Domain\Blog\Models\BlogPost;
use App\Domain\Blog\Models\BlogTag;
use App\Domain\Blog\Services\BlogService;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCategoryResource;
use App\Http\Resources\BlogPostResource;
use App\Http\Resources\BlogTagResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AdminBlogController extends Controller
{
    public function __construct(
        private readonly BlogService $blogService,
    ) {}

    // ── Posts ──────────────────────────────────────────────────

    public function listPosts(Request $request): AnonymousResourceCollection
    {
        return BlogPostResource::collection(
            $this->blogService->listAdminPosts($request->only('search', 'is_published', 'category_id', 'per_page', 'page'))
        );
    }

    public function showPost(BlogPost $post): BlogPostResource
    {
        return new BlogPostResource($post->load(['category', 'tags']));
    }

    public function storePost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => 'nullable|string|max:255|unique:blog_posts,slug',
            'category_id' => 'nullable|exists:blog_categories,id',
            'title' => 'required|array', 'title.ar' => 'required|string|max:255', 'title.en' => 'required|string|max:255',
            'excerpt' => 'nullable|array', 'excerpt.ar' => 'nullable|string', 'excerpt.en' => 'nullable|string',
            'content' => 'required|array', 'content.ar' => 'required|string', 'content.en' => 'required|string',
            'cover_image' => 'nullable|string|max:500',
            'meta_description' => 'nullable|array', 'meta_description.ar' => 'nullable|string|max:255', 'meta_description.en' => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'reading_time' => 'nullable|integer|min:1',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:blog_tags,id',
        ]);

        $post = $this->blogService->createPost($data);

        return (new BlogPostResource($post))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function updatePost(Request $request, BlogPost $post): BlogPostResource
    {
        $data = $request->validate([
            'slug' => "nullable|string|max:255|unique:blog_posts,slug,{$post->id}",
            'category_id' => 'nullable|exists:blog_categories,id',
            'title' => 'nullable|array', 'title.ar' => 'nullable|string|max:255', 'title.en' => 'nullable|string|max:255',
            'excerpt' => 'nullable|array', 'excerpt.ar' => 'nullable|string', 'excerpt.en' => 'nullable|string',
            'content' => 'nullable|array', 'content.ar' => 'nullable|string', 'content.en' => 'nullable|string',
            'cover_image' => 'nullable|string|max:500',
            'meta_description' => 'nullable|array', 'meta_description.ar' => 'nullable|string|max:255', 'meta_description.en' => 'nullable|string|max:255',
            'author_name' => 'nullable|string|max:255',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'reading_time' => 'nullable|integer|min:1',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:blog_tags,id',
        ]);

        return new BlogPostResource($this->blogService->updatePost($post, $data));
    }

    public function destroyPost(BlogPost $post): JsonResponse
    {
        $post->tags()->detach();
        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    // ── Categories ────────────────────────────────────────────

    public function listCategories(): AnonymousResourceCollection
    {
        return BlogCategoryResource::collection($this->blogService->listAdminCategories());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => 'nullable|string|max:100|unique:blog_categories,slug',
            'name' => 'required|array', 'name.ar' => 'required|string|max:255', 'name.en' => 'required|string|max:255',
            'description' => 'nullable|array', 'description.ar' => 'nullable|string', 'description.en' => 'nullable|string',
            'sort_order' => 'integer|min:0',
        ]);

        return (new BlogCategoryResource($this->blogService->createCategory($data)))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateCategory(Request $request, BlogCategory $category): BlogCategoryResource
    {
        $data = $request->validate([
            'slug' => "nullable|string|max:100|unique:blog_categories,slug,{$category->id}",
            'name' => 'nullable|array', 'name.ar' => 'nullable|string|max:255', 'name.en' => 'nullable|string|max:255',
            'description' => 'nullable|array', 'description.ar' => 'nullable|string', 'description.en' => 'nullable|string',
            'sort_order' => 'integer|min:0',
        ]);

        return new BlogCategoryResource($this->blogService->updateCategory($category, $data));
    }

    public function destroyCategory(BlogCategory $category): JsonResponse
    {
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    // ── Tags ──────────────────────────────────────────────────

    public function listTags(): AnonymousResourceCollection
    {
        return BlogTagResource::collection($this->blogService->listAdminTags());
    }

    public function storeTag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => 'nullable|string|max:100|unique:blog_tags,slug',
            'name' => 'required|array', 'name.ar' => 'required|string|max:255', 'name.en' => 'required|string|max:255',
        ]);

        return (new BlogTagResource($this->blogService->createTag($data)))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyTag(BlogTag $tag): JsonResponse
    {
        $tag->posts()->detach();
        $tag->delete();

        return response()->json(['message' => 'Tag deleted.']);
    }
}
