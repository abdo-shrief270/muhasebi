<?php

declare(strict_types=1);

use App\Domain\Blog\Models\BlogCategory;
use App\Domain\Blog\Models\BlogPost;
use App\Domain\Blog\Models\BlogTag;

describe('Public Blog API', function (): void {

    it('lists published blog posts', function (): void {
        BlogPost::create([
            'slug' => 'published-post',
            'title_ar' => 'منشور', 'title_en' => 'Published',
            'content_ar' => 'محتوى', 'content_en' => 'Content',
            'is_published' => true, 'published_at' => now()->subDay(),
        ]);
        BlogPost::create([
            'slug' => 'draft-post',
            'title_ar' => 'مسودة', 'title_en' => 'Draft',
            'content_ar' => 'محتوى', 'content_en' => 'Content',
            'is_published' => false,
        ]);

        $response = $this->getJson('/api/v1/blog');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('filters posts by category', function (): void {
        $cat = BlogCategory::create(['slug' => 'tax', 'name_ar' => 'ضرائب', 'name_en' => 'Tax']);
        BlogPost::create([
            'slug' => 'tax-post', 'category_id' => $cat->id,
            'title_ar' => 'ض', 'title_en' => 'T', 'content_ar' => 'م', 'content_en' => 'C',
            'is_published' => true, 'published_at' => now(),
        ]);
        BlogPost::create([
            'slug' => 'other-post',
            'title_ar' => 'أ', 'title_en' => 'O', 'content_ar' => 'م', 'content_en' => 'C',
            'is_published' => true, 'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/blog?category=tax');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'tax-post');
    });

    it('returns a single post by slug with related posts', function (): void {
        $post = BlogPost::create([
            'slug' => 'my-post',
            'title_ar' => 'مقالي', 'title_en' => 'My Post',
            'content_ar' => '<p>محتوى</p>', 'content_en' => '<p>Content</p>',
            'is_published' => true, 'published_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/v1/blog/my-post');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'my-post')
            ->assertJsonStructure(['data', 'related']);
    });

    it('returns 404 for unpublished post', function (): void {
        BlogPost::create([
            'slug' => 'draft',
            'title_ar' => 'م', 'title_en' => 'D', 'content_ar' => 'م', 'content_en' => 'C',
            'is_published' => false,
        ]);

        $this->getJson('/api/v1/blog/draft')->assertNotFound();
    });

    it('increments view count on show', function (): void {
        $post = BlogPost::create([
            'slug' => 'view-me',
            'title_ar' => 'م', 'title_en' => 'V', 'content_ar' => 'م', 'content_en' => 'C',
            'is_published' => true, 'published_at' => now(), 'views_count' => 5,
        ]);

        $this->getJson('/api/v1/blog/view-me');

        expect($post->fresh()->views_count)->toBe(6);
    });

    it('searches blog posts', function (): void {
        BlogPost::create([
            'slug' => 'eta-guide',
            'title_ar' => 'دليل الفاتورة الإلكترونية', 'title_en' => 'E-Invoice Guide',
            'content_ar' => 'محتوى', 'content_en' => 'Content about ETA',
            'is_published' => true, 'published_at' => now(),
        ]);
        BlogPost::create([
            'slug' => 'payroll-tips',
            'title_ar' => 'نصائح الرواتب', 'title_en' => 'Payroll Tips',
            'content_ar' => 'محتوى', 'content_en' => 'Content about payroll',
            'is_published' => true, 'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/blog/search?q=Invoice');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('returns categories with post counts', function (): void {
        $cat = BlogCategory::create(['slug' => 'accounting', 'name_ar' => 'محاسبة', 'name_en' => 'Accounting']);
        BlogPost::create([
            'slug' => 'p1', 'category_id' => $cat->id,
            'title_ar' => 'م', 'title_en' => 'P', 'content_ar' => 'م', 'content_en' => 'C',
            'is_published' => true, 'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/blog/categories');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'accounting');
    });

    it('returns RSS feed', function (): void {
        BlogPost::create([
            'slug' => 'rss-post',
            'title_ar' => 'RSS', 'title_en' => 'RSS Post',
            'content_ar' => 'م', 'content_en' => 'C',
            'is_published' => true, 'published_at' => now(),
        ]);

        $response = $this->get('/api/v1/blog/rss');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=utf-8');

        expect($response->getContent())->toContain('<rss version="2.0"')
            ->and($response->getContent())->toContain('RSS Post');
    });
});

describe('Admin Blog Posts', function (): void {

    it('creates a blog post', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $response = $this->postJson('/api/v1/admin/blog/posts', [
            'title' => ['ar' => 'مقال جديد', 'en' => 'New Post'],
            'content' => ['ar' => '<p>محتوى</p>', 'en' => '<p>Content</p>'],
            'is_published' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title.en', 'New Post')
            ->assertJsonPath('data.is_published', true);
    });

    it('auto-generates slug from title', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $response = $this->postJson('/api/v1/admin/blog/posts', [
            'title' => ['ar' => 'مقال', 'en' => 'My Great Post'],
            'content' => ['ar' => 'م', 'en' => 'C'],
        ]);

        $response->assertCreated();
        expect($response->json('data.slug'))->toBe('my-great-post');
    });

    it('attaches tags to a post', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $tag1 = BlogTag::create(['slug' => 'eta', 'name_ar' => 'فاتورة', 'name_en' => 'ETA']);
        $tag2 = BlogTag::create(['slug' => 'vat', 'name_ar' => 'ضريبة', 'name_en' => 'VAT']);

        $response = $this->postJson('/api/v1/admin/blog/posts', [
            'title' => ['ar' => 'مقال', 'en' => 'Tagged Post'],
            'content' => ['ar' => 'م', 'en' => 'C'],
            'tag_ids' => [$tag1->id, $tag2->id],
        ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'data.tags');
    });

    it('updates a blog post', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $post = BlogPost::create([
            'slug' => 'edit-post', 'title_ar' => 'قديم', 'title_en' => 'Old',
            'content_ar' => 'م', 'content_en' => 'C',
        ]);

        $response = $this->putJson("/api/v1/admin/blog/posts/{$post->id}", [
            'title' => ['en' => 'Updated Title'],
            'is_published' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title.en', 'Updated Title')
            ->assertJsonPath('data.is_published', true);
    });

    it('deletes a blog post', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $post = BlogPost::create([
            'slug' => 'delete-post', 'title_ar' => 'حذف', 'title_en' => 'Delete',
            'content_ar' => 'م', 'content_en' => 'C',
        ]);

        $this->deleteJson("/api/v1/admin/blog/posts/{$post->id}")->assertOk();
        $this->assertDatabaseMissing('blog_posts', ['id' => $post->id]);
    });
});

describe('Admin Blog Categories', function (): void {

    it('creates and lists categories', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $this->postJson('/api/v1/admin/blog/categories', [
            'name' => ['ar' => 'ضرائب', 'en' => 'Tax'],
        ])->assertCreated();

        $response = $this->getJson('/api/v1/admin/blog/categories');
        $response->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('Admin Blog Tags', function (): void {

    it('creates and lists tags', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $this->postJson('/api/v1/admin/blog/tags', [
            'name' => ['ar' => 'سحابي', 'en' => 'Cloud'],
        ])->assertCreated();

        $response = $this->getJson('/api/v1/admin/blog/tags');
        $response->assertOk()->assertJsonCount(1, 'data');
    });
});
