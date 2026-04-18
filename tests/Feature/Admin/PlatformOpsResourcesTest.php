<?php

declare(strict_types=1);

use App\Domain\Cms\Models\ContactSubmission;
use App\Domain\Communication\Models\EmailTemplate;
use App\Domain\Currency\Models\Currency;
use App\Domain\Currency\Models\ExchangeRate;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Filament\Admin\Resources\ContactSubmissionResource;

beforeEach(function (): void {
    $this->superAdmin = createSuperAdmin();
});

describe('Platform Ops resource pages', function (): void {

    it('loads the webhook endpoints index', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/webhook-endpoints')->assertOk();
    });

    it('loads the email templates index', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/email-templates')->assertOk();
    });

    it('loads the currencies index', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/currencies')->assertOk();
    });

    it('loads the exchange rates index', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/exchange-rates')->assertOk();
    });

    it('loads the contact submissions index', function (): void {
        $this->actingAs($this->superAdmin);
        $this->get('/admin/contact-submissions')->assertOk();
    });

    it('denies non-SuperAdmin access to webhook endpoints', function (): void {
        $tenant = createTenant();
        $this->actingAs(createAdminUser($tenant));

        $this->get('/admin/webhook-endpoints')->assertForbidden();
    });
});

describe('ContactSubmissionResource navigation badge', function (): void {

    it('counts unread submissions', function (): void {
        ContactSubmission::create([
            'name' => 'Unread', 'email' => 'a@b.co', 'subject' => 's', 'message' => 'm', 'is_read' => false,
        ]);
        ContactSubmission::create([
            'name' => 'Read', 'email' => 'c@d.co', 'subject' => 's', 'message' => 'm', 'is_read' => true,
        ]);

        expect(ContactSubmissionResource::getNavigationBadge())->toBe('1');
    });
});

describe('Platform Ops model smoke tests', function (): void {

    it('persists a WebhookEndpoint and a WebhookDelivery', function (): void {
        $tenant = createTenant();
        $endpoint = WebhookEndpoint::create([
            'tenant_id' => $tenant->id,
            'url' => 'https://example.com/hook',
            'secret' => 'shh',
            'events' => ['invoice.created'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::create([
            'endpoint_id' => $endpoint->id,
            'event' => 'invoice.created',
            'payload' => ['id' => 1],
            'status' => 'delivered',
            'status_code' => 200,
            'duration_ms' => 120,
            'attempt' => 1,
        ]);

        expect($endpoint->deliveries()->count())->toBe(1)
            ->and($delivery->fresh()->payload)->toBe(['id' => 1]);
    });

    it('persists Currency + ExchangeRate', function (): void {
        Currency::create([
            'code' => 'EGP', 'name_en' => 'Egyptian Pound', 'name_ar' => 'جنيه مصري',
            'symbol' => 'E£', 'decimal_places' => 2, 'is_active' => true,
        ]);
        Currency::create([
            'code' => 'USD', 'name_en' => 'US Dollar', 'name_ar' => 'دولار أمريكي',
            'symbol' => '$', 'decimal_places' => 2, 'is_active' => true,
        ]);

        ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'EGP',
            'rate' => 48.75,
            'effective_date' => now()->toDateString(),
            'source' => 'manual',
        ]);

        expect(ExchangeRate::getRate('USD', 'EGP'))->toBe(48.75);
    });

    it('persists an EmailTemplate with ar/en variants', function (): void {
        $t = EmailTemplate::create([
            'key' => 'test.welcome',
            'name' => 'Welcome',
            'subject_en' => 'Welcome',
            'subject_ar' => 'أهلا',
            'body_en' => 'Hello {name}',
            'body_ar' => 'مرحبا {name}',
            'variables' => ['name'],
            'is_active' => true,
        ]);

        expect(EmailTemplate::findByKey('test.welcome')?->id)->toBe($t->id);
    });
});
