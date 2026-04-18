<?php

declare(strict_types=1);

use App\Domain\Blog\Models\BlogCategory;
use App\Domain\Blog\Models\BlogPost;
use App\Domain\Blog\Models\BlogTag;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\Faq;
use App\Domain\Cms\Models\LandingSetting;
use App\Domain\Cms\Models\SlugRedirect;
use App\Domain\Cms\Models\Testimonial;

beforeEach(function (): void {
    $this->superAdmin = createSuperAdmin();
});

describe('Content resource index pages load for SuperAdmin', function (): void {

    it('loads /admin/cms-pages', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/cms-pages')->assertOk();
    });

    it('loads /admin/blog-categories', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/blog-categories')->assertOk();
    });

    it('loads /admin/blog-tags', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/blog-tags')->assertOk();
    });

    it('loads /admin/blog-posts', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/blog-posts')->assertOk();
    });

    it('loads /admin/faqs', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/faqs')->assertOk();
    });

    it('loads /admin/testimonials', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/testimonials')->assertOk();
    });

    it('loads /admin/landing-settings', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/landing-settings')->assertOk();
    });

    it('loads /admin/slug-redirects', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/slug-redirects')->assertOk();
    });

    it('denies non-SuperAdmin access to blog posts', function (): void {
        $tenant = createTenant();
        $this->actingAs(createAdminUser($tenant));

        $this->get('/admin/blog-posts')->assertForbidden();
    });
});

describe('Content model smoke tests', function (): void {

    it('persists a BlogPost with category and tags', function (): void {
        $cat = BlogCategory::create([
            'slug' => 'news', 'name_en' => 'News', 'name_ar' => 'أخبار', 'sort_order' => 1,
        ]);
        $tag = BlogTag::create([
            'slug' => 'product', 'name_en' => 'Product', 'name_ar' => 'منتج',
        ]);

        $post = BlogPost::create([
            'slug' => 'hello',
            'category_id' => $cat->id,
            'title_en' => 'Hello',
            'title_ar' => 'مرحبا',
            'content_en' => 'Content',
            'content_ar' => 'محتوى',
            'author_name' => 'Tester',
            'is_published' => true,
        ]);
        $post->tags()->attach($tag->id);

        expect($post->fresh()->category->slug)->toBe('news')
            ->and($post->fresh()->tags->pluck('slug')->all())->toBe(['product']);
    });

    it('persists a CmsPage with bilingual content', function (): void {
        $page = CmsPage::create([
            'slug' => 'about',
            'title_en' => 'About',
            'title_ar' => 'حول',
            'content_en' => 'en',
            'content_ar' => 'ar',
            'is_published' => true,
        ]);

        expect($page->fresh()->slug)->toBe('about');
    });

    it('persists a Faq, Testimonial, SlugRedirect and LandingSetting', function (): void {
        Faq::create([
            'question_en' => 'q?', 'question_ar' => 'س؟',
            'answer_en' => 'a', 'answer_ar' => 'ج', 'sort_order' => 1, 'is_active' => true,
        ]);
        Testimonial::create([
            'name_en' => 'Ali', 'name_ar' => 'علي', 'role_en' => 'CEO', 'role_ar' => 'مدير',
            'quote_en' => 'great', 'quote_ar' => 'رائع', 'rating' => 5, 'is_active' => true, 'sort_order' => 1,
        ]);
        SlugRedirect::create(['old_slug' => 'a', 'new_slug' => 'b', 'type' => 'page']);
        LandingSetting::create([
            'section' => 'hero',
            'data' => ['headline' => 'Welcome', 'cta_label' => 'Start'],
        ]);

        expect(Faq::count())->toBe(1)
            ->and(Testimonial::count())->toBe(1)
            ->and(SlugRedirect::first()?->new_slug)->toBe('b')
            ->and(LandingSetting::first()?->data['headline'] ?? null)->toBe('Welcome');
    });
});
