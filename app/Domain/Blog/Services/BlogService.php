<?php

namespace App\Domain\Blog\Services;

use App\Domain\Blog\Models\BlogCategory;
use App\Domain\Blog\Models\BlogPost;
use App\Domain\Blog\Models\BlogTag;
use App\Domain\Cms\Models\SlugRedirect;
use App\Domain\Shared\Services\HtmlSanitizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BlogService
{
    // ── Public ─────────────────────────────────────────────────

    public function listPublishedPosts(array $filters = []): LengthAwarePaginator
    {
        $query = BlogPost::published()->with(['category', 'tags'])->latest('published_at');

        if (! empty($filters['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $filters['category']));
        }

        if (! empty($filters['tag'])) {
            $query->whereHas('tags', fn ($q) => $q->where('slug', $filters['tag']));
        }

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('title_ar', 'like', "%{$s}%")
                ->orWhere('title_en', 'like', "%{$s}%"));
        }

        return $query->paginate($filters['per_page'] ?? 9);
    }

    public function getPublishedPost(string $slug): ?BlogPost
    {
        $post = BlogPost::published()->with(['category', 'tags'])->where('slug', $slug)->first();

        if ($post) {
            $post->incrementViews();
        }

        return $post;
    }

    public function getFeaturedPosts(int $limit = 3)
    {
        return BlogPost::published()->featured()->with(['category'])->latest('published_at')->limit($limit)->get();
    }

    /**
     * Get related posts by shared category and tags.
     */
    public function getRelatedPosts(BlogPost $post, int $limit = 3)
    {
        $tagIds = $post->tags->pluck('id');

        return Cache::remember("blog_related_{$post->id}", 3600, function () use ($post, $tagIds, $limit) {
            return BlogPost::published()
                ->where('id', '!=', $post->id)
                ->where(function ($query) use ($post, $tagIds) {
                    // Same category
                    if ($post->category_id) {
                        $query->where('category_id', $post->category_id);
                    }
                    // Or shared tags
                    if ($tagIds->isNotEmpty()) {
                        $query->orWhereHas('tags', fn ($q) => $q->whereIn('blog_tags.id', $tagIds));
                    }
                })
                ->with('category')
                ->latest('published_at')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Full-text search across blog posts (title + content in both languages).
     */
    public function searchPosts(string $query, int $perPage = 9): LengthAwarePaginator
    {
        $terms = '%' . $query . '%';

        return BlogPost::published()
            ->with(['category', 'tags'])
            ->where(function ($q) use ($terms) {
                $q->where('title_ar', 'like', $terms)
                  ->orWhere('title_en', 'like', $terms)
                  ->orWhere('content_ar', 'like', $terms)
                  ->orWhere('content_en', 'like', $terms)
                  ->orWhere('excerpt_ar', 'like', $terms)
                  ->orWhere('excerpt_en', 'like', $terms);
            })
            ->latest('published_at')
            ->paginate($perPage);
    }

    public function getCategories()
    {
        return BlogCategory::ordered()->withCount(['posts' => fn ($q) => $q->published()])->get();
    }

    public function getPopularTags(int $limit = 20)
    {
        return BlogTag::withCount('posts')->orderByDesc('posts_count')->limit($limit)->get();
    }

    // ── Admin ─────────────────────────────────────────────────

    public function listAdminPosts(array $filters = []): LengthAwarePaginator
    {
        $query = BlogPost::with(['category', 'tags'])->latest('updated_at');

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('title_ar', 'like', "%{$s}%")
                ->orWhere('title_en', 'like', "%{$s}%"));
        }

        if (isset($filters['is_published'])) {
            $query->where('is_published', $filters['is_published']);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function createPost(array $data): BlogPost
    {
        $post = BlogPost::create([
            'slug' => $data['slug'] ?? Str::slug($data['title']['en'] ?? $data['title']['ar'] ?? ''),
            'category_id' => $data['category_id'] ?? null,
            'title_ar' => $data['title']['ar'] ?? '',
            'title_en' => $data['title']['en'] ?? '',
            'excerpt_ar' => $data['excerpt']['ar'] ?? null,
            'excerpt_en' => $data['excerpt']['en'] ?? null,
            'content_ar' => HtmlSanitizer::sanitize($data['content']['ar'] ?? ''),
            'content_en' => HtmlSanitizer::sanitize($data['content']['en'] ?? ''),
            'cover_image' => $data['cover_image'] ?? null,
            'meta_description_ar' => $data['meta_description']['ar'] ?? null,
            'meta_description_en' => $data['meta_description']['en'] ?? null,
            'author_name' => $data['author_name'] ?? null,
            'is_published' => $data['is_published'] ?? false,
            'is_featured' => $data['is_featured'] ?? false,
            'published_at' => ($data['is_published'] ?? false) ? now() : null,
            'reading_time' => $data['reading_time'] ?? $this->estimateReadingTime($data['content']['en'] ?? $data['content']['ar'] ?? ''),
        ]);

        if (! empty($data['tag_ids'])) {
            $post->tags()->sync($data['tag_ids']);
        }

        return $post->load(['category', 'tags']);
    }

    public function updatePost(BlogPost $post, array $data): BlogPost
    {
        $oldSlug = $post->slug;
        $wasPublished = $post->is_published;

        $post->update(array_filter([
            'slug' => $data['slug'] ?? $post->slug,
            'category_id' => array_key_exists('category_id', $data) ? $data['category_id'] : $post->category_id,
            'title_ar' => $data['title']['ar'] ?? $post->title_ar,
            'title_en' => $data['title']['en'] ?? $post->title_en,
            'excerpt_ar' => $data['excerpt']['ar'] ?? $post->excerpt_ar,
            'excerpt_en' => $data['excerpt']['en'] ?? $post->excerpt_en,
            'content_ar' => isset($data['content']['ar']) ? HtmlSanitizer::sanitize($data['content']['ar']) : $post->content_ar,
            'content_en' => isset($data['content']['en']) ? HtmlSanitizer::sanitize($data['content']['en']) : $post->content_en,
            'cover_image' => $data['cover_image'] ?? $post->cover_image,
            'meta_description_ar' => $data['meta_description']['ar'] ?? $post->meta_description_ar,
            'meta_description_en' => $data['meta_description']['en'] ?? $post->meta_description_en,
            'author_name' => $data['author_name'] ?? $post->author_name,
            'is_published' => $data['is_published'] ?? $post->is_published,
            'is_featured' => $data['is_featured'] ?? $post->is_featured,
            'reading_time' => $data['reading_time'] ?? $post->reading_time,
        ], fn ($v) => $v !== null));

        // Set published_at on first publish
        if (! $wasPublished && $post->is_published && ! $post->published_at) {
            $post->update(['published_at' => now()]);
        }

        // Track slug change for 301 redirects
        if ($post->slug !== $oldSlug) {
            SlugRedirect::track($oldSlug, $post->slug, 'blog');
            Cache::forget("blog_related_{$post->id}");
        }

        if (isset($data['tag_ids'])) {
            $post->tags()->sync($data['tag_ids']);
        }

        return $post->fresh(['category', 'tags']);
    }

    // ── Categories ────────────────────────────────────────────

    public function listAdminCategories(): LengthAwarePaginator
    {
        return BlogCategory::ordered()->withCount('posts')->paginate(50);
    }

    public function createCategory(array $data): BlogCategory
    {
        return BlogCategory::create([
            'slug' => $data['slug'] ?? Str::slug($data['name']['en'] ?? $data['name']['ar'] ?? ''),
            'name_ar' => $data['name']['ar'] ?? '',
            'name_en' => $data['name']['en'] ?? '',
            'description_ar' => $data['description']['ar'] ?? null,
            'description_en' => $data['description']['en'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateCategory(BlogCategory $category, array $data): BlogCategory
    {
        $category->update(array_filter([
            'slug' => $data['slug'] ?? $category->slug,
            'name_ar' => $data['name']['ar'] ?? $category->name_ar,
            'name_en' => $data['name']['en'] ?? $category->name_en,
            'description_ar' => $data['description']['ar'] ?? $category->description_ar,
            'description_en' => $data['description']['en'] ?? $category->description_en,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
        ], fn ($v) => $v !== null));

        return $category->fresh();
    }

    // ── Tags ──────────────────────────────────────────────────

    public function listAdminTags(): LengthAwarePaginator
    {
        return BlogTag::withCount('posts')->latest()->paginate(50);
    }

    public function createTag(array $data): BlogTag
    {
        return BlogTag::create([
            'slug' => $data['slug'] ?? Str::slug($data['name']['en'] ?? $data['name']['ar'] ?? ''),
            'name_ar' => $data['name']['ar'] ?? '',
            'name_en' => $data['name']['en'] ?? '',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────

    private function estimateReadingTime(string $content): int
    {
        $wordCount = str_word_count(strip_tags($content));

        return max(1, (int) ceil($wordCount / 200));
    }
}
