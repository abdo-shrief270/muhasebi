<?php

declare(strict_types=1);

use App\Domain\Cms\Models\CmsPage;

describe('Public CMS Pages', function (): void {

    it('returns a published page by slug', function (): void {
        CmsPage::create([
            'slug' => 'terms',
            'title_ar' => 'الشروط',
            'title_en' => 'Terms',
            'content_ar' => '<p>شروط عربي</p>',
            'content_en' => '<p>English terms</p>',
            'is_published' => true,
        ]);

        $response = $this->getJson('/api/v1/pages/terms');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'terms')
            ->assertJsonPath('data.title.ar', 'الشروط')
            ->assertJsonPath('data.title.en', 'Terms')
            ->assertJsonPath('data.is_published', true);
    });

    it('returns 404 for unpublished page', function (): void {
        CmsPage::create([
            'slug' => 'draft-page',
            'title_ar' => 'مسودة',
            'title_en' => 'Draft',
            'is_published' => false,
        ]);

        $this->getJson('/api/v1/pages/draft-page')->assertNotFound();
    });

    it('returns 404 for non-existent page', function (): void {
        $this->getJson('/api/v1/pages/nonexistent')->assertNotFound();
    });

    it('returns 301 redirect when slug changed', function (): void {
        $page = CmsPage::create([
            'slug' => 'new-terms',
            'title_ar' => 'الشروط',
            'title_en' => 'Terms',
            'is_published' => true,
        ]);

        \App\Domain\Cms\Models\SlugRedirect::create([
            'old_slug' => 'old-terms',
            'new_slug' => 'new-terms',
            'type' => 'page',
        ]);

        $response = $this->getJson('/api/v1/pages/old-terms');
        $response->assertStatus(301)
            ->assertJsonPath('redirect', 'new-terms');
    });
});

describe('Admin CMS Pages', function (): void {

    it('lists all pages for super admin', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        CmsPage::create(['slug' => 'page-1', 'title_ar' => 'ص1', 'title_en' => 'P1', 'is_published' => true]);
        CmsPage::create(['slug' => 'page-2', 'title_ar' => 'ص2', 'title_en' => 'P2', 'is_published' => false]);

        $response = $this->getJson('/api/v1/admin/pages');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('creates a new page', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $response = $this->postJson('/api/v1/admin/pages', [
            'slug' => 'about-us',
            'title' => ['ar' => 'من نحن', 'en' => 'About Us'],
            'content' => ['ar' => '<p>نحن محاسبي</p>', 'en' => '<p>We are Muhasebi</p>'],
            'is_published' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'about-us');

        $this->assertDatabaseHas('cms_pages', ['slug' => 'about-us', 'is_published' => true]);
    });

    it('validates unique slug on create', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        CmsPage::create(['slug' => 'taken', 'title_ar' => 'ت', 'title_en' => 'T']);

        $response = $this->postJson('/api/v1/admin/pages', [
            'slug' => 'taken',
            'title' => ['ar' => 'جديد', 'en' => 'New'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });

    it('updates a page', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $page = CmsPage::create(['slug' => 'edit-me', 'title_ar' => 'قديم', 'title_en' => 'Old']);

        $response = $this->putJson("/api/v1/admin/pages/{$page->id}", [
            'title' => ['ar' => 'جديد', 'en' => 'New'],
            'is_published' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title.ar', 'جديد');
    });

    it('deletes a page', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $page = CmsPage::create(['slug' => 'delete-me', 'title_ar' => 'حذف', 'title_en' => 'Delete']);

        $this->deleteJson("/api/v1/admin/pages/{$page->id}")->assertOk();
        $this->assertDatabaseMissing('cms_pages', ['id' => $page->id]);
    });

    it('rejects non-super-admin access', function (): void {
        $user = createAdminUser();
        actingAsUser($user);

        $this->getJson('/api/v1/admin/pages')->assertForbidden();
    });
});
