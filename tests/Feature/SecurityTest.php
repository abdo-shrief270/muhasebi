<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PasswordPolicyService;
use App\Domain\Cms\Models\SlugRedirect;
use App\Domain\Shared\Services\HtmlSanitizer;

describe('Password Policy', function (): void {

    it('rejects short passwords', function (): void {
        $errors = PasswordPolicyService::validate('Ab1');

        expect($errors)->not->toBeEmpty()
            ->and($errors[0])->toContain('at least');
    });

    it('requires uppercase letter', function (): void {
        $errors = PasswordPolicyService::validate('lowercase123');

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('uppercase');
    });

    it('requires lowercase letter', function (): void {
        $errors = PasswordPolicyService::validate('UPPERCASE123');

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('lowercase');
    });

    it('requires a number', function (): void {
        $errors = PasswordPolicyService::validate('NoNumbersHere');

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('number');
    });

    it('accepts a valid password', function (): void {
        // Disable breach check for this test (network call)
        config(['auth.password_policy.check_breach' => false]);

        $errors = PasswordPolicyService::validate('SecurePass123!');

        expect($errors)->toBeEmpty();
    });
});

describe('HTML Sanitizer', function (): void {

    it('allows safe HTML tags', function (): void {
        $html = '<h2>Title</h2><p>Paragraph with <strong>bold</strong> and <em>italic</em>.</p>';
        $result = HtmlSanitizer::sanitize($html);

        expect($result)->toContain('<h2>')
            ->and($result)->toContain('<strong>')
            ->and($result)->toContain('<em>');
    });

    it('strips script tags', function (): void {
        $html = '<p>Safe</p><script>alert("xss")</script>';
        $result = HtmlSanitizer::sanitize($html);

        expect($result)->toContain('<p>Safe</p>')
            ->and($result)->not->toContain('<script>')
            ->and($result)->not->toContain('alert');
    });

    it('strips event handlers', function (): void {
        $html = '<div onclick="alert(1)">Click</div>';
        $result = HtmlSanitizer::sanitize($html);

        expect($result)->not->toContain('onclick');
    });

    it('strips javascript: URLs', function (): void {
        $html = '<a href="javascript:alert(1)">Link</a>';
        $result = HtmlSanitizer::sanitize($html);

        expect($result)->not->toContain('javascript:');
    });

    it('allows safe links', function (): void {
        $html = '<a href="https://example.com">Link</a>';
        $result = HtmlSanitizer::sanitize($html);

        expect($result)->toContain('https://example.com');
    });

    it('handles null and empty input', function (): void {
        expect(HtmlSanitizer::sanitize(null))->toBe('')
            ->and(HtmlSanitizer::sanitize(''))->toBe('');
    });

    it('allows images with safe src', function (): void {
        $html = '<img src="https://example.com/image.jpg" alt="Photo">';
        $result = HtmlSanitizer::sanitize($html);

        expect($result)->toContain('https://example.com/image.jpg');
    });

    it('strips iframe tags', function (): void {
        $html = '<iframe src="https://evil.com"></iframe>';
        $result = HtmlSanitizer::sanitize($html);

        expect($result)->not->toContain('<iframe>');
    });
});

describe('Health Check', function (): void {

    it('returns healthy status', function (): void {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonStructure(['status', 'checks', 'version', 'timestamp']);
    });
});

describe('API Response Standardization', function (): void {

    it('returns standardized validation errors', function (): void {
        $response = $this->postJson('/api/v1/contact', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'errors']);

        expect($response->json('error'))->toBe('validation_error');
    });

    it('returns standardized 404 errors', function (): void {
        $response = $this->getJson('/api/v1/pages/nonexistent-slug');

        $response->assertNotFound()
            ->assertJsonStructure(['error', 'message']);
    });

    it('returns standardized 401 for unauthenticated requests', function (): void {
        $response = $this->getJson('/api/v1/me');

        $response->assertUnauthorized()
            ->assertJsonStructure(['error', 'message'])
            ->assertJsonPath('error', 'unauthorized');
    });

    it('returns localized errors in Arabic', function (): void {
        $response = $this->postJson('/api/v1/contact', [], [
            'Accept-Language' => 'ar',
        ]);

        $response->assertStatus(422);
        // Arabic validation messages should be returned
        $errors = $response->json('errors');
        expect($errors)->not->toBeEmpty();
    });

    it('returns localized errors in English', function (): void {
        $response = $this->postJson('/api/v1/contact', [], [
            'Accept-Language' => 'en',
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        expect($errors)->not->toBeEmpty();
    });

    it('returns X-API-Version header', function (): void {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-API-Version');
        $response->assertHeader('X-Request-Id');
    });
});

describe('Slug Redirects', function (): void {

    it('tracks slug changes for blog posts', function (): void {
        SlugRedirect::track('old-post', 'new-post', 'blog');

        $redirect = SlugRedirect::resolve('old-post', 'blog');

        expect($redirect)->toBe('new-post');
    });

    it('chain-updates existing redirects', function (): void {
        SlugRedirect::track('version-1', 'version-2', 'blog');
        SlugRedirect::track('version-2', 'version-3', 'blog');

        // version-1 should now point to version-3
        $redirect = SlugRedirect::resolve('version-1', 'blog');

        expect($redirect)->toBe('version-3');
    });

    it('ignores same slug', function (): void {
        SlugRedirect::track('same', 'same', 'page');

        $this->assertDatabaseCount('slug_redirects', 0);
    });
});
