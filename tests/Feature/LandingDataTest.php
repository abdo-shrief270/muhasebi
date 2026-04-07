<?php

declare(strict_types=1);

use App\Domain\Cms\Models\Faq;
use App\Domain\Cms\Models\LandingSetting;
use App\Domain\Cms\Models\Testimonial;

describe('Public Landing Data', function (): void {

    it('returns landing data with testimonials and faqs', function (): void {
        LandingSetting::create(['section' => 'hero', 'data' => ['title1' => ['ar' => 'عنوان', 'en' => 'Title']]]);
        LandingSetting::create(['section' => 'stats', 'data' => ['firms' => '+100']]);

        Testimonial::create([
            'name_ar' => 'أحمد', 'name_en' => 'Ahmed',
            'role_ar' => 'محاسب', 'role_en' => 'Accountant',
            'quote_ar' => 'ممتاز', 'quote_en' => 'Excellent',
            'is_active' => true, 'sort_order' => 1,
        ]);

        Faq::create([
            'question_ar' => 'سؤال؟', 'question_en' => 'Question?',
            'answer_ar' => 'إجابة', 'answer_en' => 'Answer',
            'is_active' => true, 'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/v1/landing');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['hero', 'stats', 'testimonials', 'faqs']])
            ->assertJsonCount(1, 'data.testimonials')
            ->assertJsonCount(1, 'data.faqs');
    });

    it('excludes inactive testimonials', function (): void {
        Testimonial::create([
            'name_ar' => 'نشط', 'name_en' => 'Active',
            'role_ar' => 'ر', 'role_en' => 'R',
            'quote_ar' => 'ق', 'quote_en' => 'Q',
            'is_active' => true, 'sort_order' => 1,
        ]);
        Testimonial::create([
            'name_ar' => 'معطل', 'name_en' => 'Inactive',
            'role_ar' => 'ر', 'role_en' => 'R',
            'quote_ar' => 'ق', 'quote_en' => 'Q',
            'is_active' => false, 'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/v1/landing');

        $response->assertJsonCount(1, 'data.testimonials');
    });
});

describe('Admin Landing Settings', function (): void {

    it('updates hero section', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $response = $this->putJson('/api/v1/admin/landing', [
            'hero' => [
                'badge' => ['ar' => 'شارة', 'en' => 'Badge'],
                'title1' => ['ar' => 'محاسبة', 'en' => 'Accounting'],
            ],
            'stats' => ['firms' => '+200'],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('landing_settings', ['section' => 'hero']);
        $this->assertDatabaseHas('landing_settings', ['section' => 'stats']);
    });
});

describe('Admin Testimonials CRUD', function (): void {

    it('creates a testimonial', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $response = $this->postJson('/api/v1/admin/testimonials', [
            'name' => ['ar' => 'سارة', 'en' => 'Sarah'],
            'role' => ['ar' => 'محاسبة', 'en' => 'CPA'],
            'quote' => ['ar' => 'نظام رائع', 'en' => 'Great system'],
            'rating' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name.en', 'Sarah');
    });

    it('validates rating range', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $response = $this->postJson('/api/v1/admin/testimonials', [
            'name' => ['ar' => 'ت', 'en' => 'T'],
            'role' => ['ar' => 'ر', 'en' => 'R'],
            'quote' => ['ar' => 'ق', 'en' => 'Q'],
            'rating' => 10,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['rating']);
    });
});

describe('Admin FAQs CRUD', function (): void {

    it('creates a FAQ', function (): void {
        $admin = createSuperAdmin();
        actingAsUser($admin);

        $response = $this->postJson('/api/v1/admin/faqs', [
            'question' => ['ar' => 'هل يدعم العربية؟', 'en' => 'Does it support Arabic?'],
            'answer' => ['ar' => 'نعم', 'en' => 'Yes'],
            'sort_order' => 1,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('faqs', ['question_en' => 'Does it support Arabic?']);
    });
});

describe('Contact Form', function (): void {

    it('accepts valid contact submission', function (): void {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'subject' => 'General Inquiry',
            'message' => 'This is a test message with enough content.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Your message has been sent successfully.');

        $this->assertDatabaseHas('contact_submissions', [
            'email' => 'test@example.com',
            'subject' => 'General Inquiry',
        ]);
    });

    it('validates required fields', function (): void {
        $response = $this->postJson('/api/v1/contact', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'subject', 'message']);
    });

    it('validates email format', function (): void {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Test',
            'email' => 'not-an-email',
            'subject' => 'Test',
            'message' => 'Test message',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('silently rejects honeypot submissions', function (): void {
        $response = $this->postJson('/api/v1/contact', [
            'name' => 'Bot',
            'email' => 'bot@spam.com',
            'subject' => 'Spam',
            'message' => 'Buy stuff',
            'website' => 'http://spam.com', // honeypot filled = bot
        ]);

        // Returns 201 (bot thinks it worked) but nothing saved
        $response->assertCreated();
        $this->assertDatabaseMissing('contact_submissions', ['email' => 'bot@spam.com']);
    });
});
