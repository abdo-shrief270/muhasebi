<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\ContactSubmission;
use App\Domain\Cms\Models\Faq;
use App\Domain\Cms\Models\FeatureShowcaseSection;
use App\Domain\Cms\Models\LandingSetting;
use App\Domain\Cms\Models\SlugRedirect;
use App\Domain\Cms\Models\Testimonial;
use App\Domain\Shared\Services\HtmlSanitizer;
use App\Mail\ContactAdminAlertMail;
use App\Mail\ContactAutoReplyMail;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class CmsService
{
    // ── Landing Settings ──────────────────────────────────────

    public function getLandingData(): array
    {
        // Cache ONLY the serialization-safe scalar settings. Live Eloquent
        // Collections (Testimonial + Faq) are NOT cached — caching them
        // across process boundaries causes `__PHP_Incomplete_Class` errors
        // whenever the class signature drifts between write and read (e.g.
        // after a composer update or OPcache reset on only one side), and
        // manifests as a recurring 500 on /landing until cache:clear.
        // Testimonials and FAQs are small tables and queried fresh each
        // request; the controller re-wraps them with Resources anyway.
        $settings = Cache::remember('landing_data.settings', 3600, function () {
            return LandingSetting::all()->pluck('data', 'section')->toArray();
        });

        return [
            'hero' => $settings['hero'] ?? null,
            'stats' => $settings['stats'] ?? null,
            'testimonials' => Testimonial::active()->ordered()->get(),
            'faqs' => Faq::active()->ordered()->get(),
        ];
    }

    /**
     * Bilingual feature-showcase tree: active sections, each with its
     * active items pre-loaded. Cached 10 minutes — content changes rarely
     * and the page is loaded by anonymous visitors so cache absorbs the
     * read load. Bust manually after a seeder rerun via cache:clear.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, FeatureShowcaseSection>
     */
    public function getFeatureShowcase(): \Illuminate\Database\Eloquent\Collection
    {
        // Cache the IDs (lightweight, serialization-safe) and re-hydrate
        // the relation tree from the DB on each hit. Caching the eager
        // collection across processes can hit `__PHP_Incomplete_Class`
        // after deploys, same trap LandingSetting already navigates.
        $ids = Cache::remember('feature_showcase.ids', 600, function () {
            return FeatureShowcaseSection::active()
                ->ordered()
                ->pluck('id')
                ->all();
        });

        if ($ids === []) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return FeatureShowcaseSection::query()
            ->with(['items' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function getAdminLandingData(): array
    {
        $settings = LandingSetting::all()->pluck('data', 'section')->toArray();

        return [
            'hero' => $settings['hero'] ?? [
                'badge' => ['ar' => '', 'en' => ''],
                'title1' => ['ar' => '', 'en' => ''],
                'title2' => ['ar' => '', 'en' => ''],
                'subtitle' => ['ar' => '', 'en' => ''],
            ],
            'stats' => $settings['stats'] ?? [
                'firms' => '+500',
                'invoices' => '+10K',
                'uptime' => '99.9%',
            ],
        ];
    }

    public function updateLandingData(array $data): array
    {
        foreach (['hero', 'stats'] as $section) {
            if (isset($data[$section])) {
                LandingSetting::updateOrCreate(
                    ['section' => $section],
                    ['data' => $data[$section]],
                );
            }
        }

        Cache::forget('landing_data.settings');
        Cache::forget('landing_data'); // legacy key from before 2026-04-23 refactor

        return $this->getAdminLandingData();
    }

    // ── CMS Pages ─────────────────────────────────────────────

    public function getPublishedPage(string $slug): ?CmsPage
    {
        return Cache::remember("cms_page_{$slug}", 3600, function () use ($slug) {
            return CmsPage::published()->bySlug($slug)->first();
        });
    }

    public function listPages(array $filters = []): LengthAwarePaginator
    {
        $query = CmsPage::query()->latest('updated_at');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title_ar', 'like', "%{$search}%")
                    ->orWhere('title_en', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function createPage(array $data): CmsPage
    {
        return CmsPage::create([
            'slug' => $data['slug'],
            'title_ar' => $data['title']['ar'] ?? '',
            'title_en' => $data['title']['en'] ?? '',
            'content_ar' => HtmlSanitizer::sanitize($data['content']['ar'] ?? ''),
            'content_en' => HtmlSanitizer::sanitize($data['content']['en'] ?? ''),
            'meta_description_ar' => $data['meta_description']['ar'] ?? '',
            'meta_description_en' => $data['meta_description']['en'] ?? '',
            'is_published' => $data['is_published'] ?? false,
        ]);
    }

    public function updatePage(CmsPage $page, array $data): CmsPage
    {
        $oldSlug = $page->slug;
        Cache::forget("cms_page_{$page->slug}");

        $page->update([
            'slug' => $data['slug'] ?? $page->slug,
            'title_ar' => $data['title']['ar'] ?? $page->title_ar,
            'title_en' => $data['title']['en'] ?? $page->title_en,
            'content_ar' => isset($data['content']['ar']) ? HtmlSanitizer::sanitize($data['content']['ar']) : $page->content_ar,
            'content_en' => isset($data['content']['en']) ? HtmlSanitizer::sanitize($data['content']['en']) : $page->content_en,
            'meta_description_ar' => $data['meta_description']['ar'] ?? $page->meta_description_ar,
            'meta_description_en' => $data['meta_description']['en'] ?? $page->meta_description_en,
            'is_published' => $data['is_published'] ?? $page->is_published,
        ]);

        // Track slug change for 301 redirects
        if ($page->slug !== $oldSlug) {
            SlugRedirect::track($oldSlug, $page->slug, 'page');
            Cache::forget("cms_page_{$oldSlug}");
        }

        return $page->fresh();
    }

    // ── Testimonials ──────────────────────────────────────────

    public function listTestimonials(array $filters = []): LengthAwarePaginator
    {
        return Testimonial::ordered()->paginate($filters['per_page'] ?? 50);
    }

    public function createTestimonial(array $data): Testimonial
    {
        Cache::forget('landing_data.settings');
        Cache::forget('landing_data'); // legacy key from before 2026-04-23 refactor

        return Testimonial::create([
            'name_ar' => $data['name']['ar'] ?? '',
            'name_en' => $data['name']['en'] ?? '',
            'role_ar' => $data['role']['ar'] ?? '',
            'role_en' => $data['role']['en'] ?? '',
            'quote_ar' => $data['quote']['ar'] ?? '',
            'quote_en' => $data['quote']['en'] ?? '',
            'rating' => $data['rating'] ?? 5,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateTestimonial(Testimonial $testimonial, array $data): Testimonial
    {
        Cache::forget('landing_data.settings');
        Cache::forget('landing_data'); // legacy key from before 2026-04-23 refactor

        $testimonial->update([
            'name_ar' => $data['name']['ar'] ?? $testimonial->name_ar,
            'name_en' => $data['name']['en'] ?? $testimonial->name_en,
            'role_ar' => $data['role']['ar'] ?? $testimonial->role_ar,
            'role_en' => $data['role']['en'] ?? $testimonial->role_en,
            'quote_ar' => $data['quote']['ar'] ?? $testimonial->quote_ar,
            'quote_en' => $data['quote']['en'] ?? $testimonial->quote_en,
            'rating' => $data['rating'] ?? $testimonial->rating,
            'is_active' => $data['is_active'] ?? $testimonial->is_active,
            'sort_order' => $data['sort_order'] ?? $testimonial->sort_order,
        ]);

        return $testimonial->fresh();
    }

    // ── FAQs ──────────────────────────────────────────────────

    public function listFaqs(array $filters = []): LengthAwarePaginator
    {
        return Faq::ordered()->paginate($filters['per_page'] ?? 50);
    }

    public function createFaq(array $data): Faq
    {
        Cache::forget('landing_data.settings');
        Cache::forget('landing_data'); // legacy key from before 2026-04-23 refactor

        return Faq::create([
            'question_ar' => $data['question']['ar'] ?? '',
            'question_en' => $data['question']['en'] ?? '',
            'answer_ar' => $data['answer']['ar'] ?? '',
            'answer_en' => $data['answer']['en'] ?? '',
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateFaq(Faq $faq, array $data): Faq
    {
        Cache::forget('landing_data.settings');
        Cache::forget('landing_data'); // legacy key from before 2026-04-23 refactor

        $faq->update([
            'question_ar' => $data['question']['ar'] ?? $faq->question_ar,
            'question_en' => $data['question']['en'] ?? $faq->question_en,
            'answer_ar' => $data['answer']['ar'] ?? $faq->answer_ar,
            'answer_en' => $data['answer']['en'] ?? $faq->answer_en,
            'is_active' => $data['is_active'] ?? $faq->is_active,
            'sort_order' => $data['sort_order'] ?? $faq->sort_order,
        ]);

        return $faq->fresh();
    }

    // ── Contact Submissions ───────────────────────────────────

    public function submitContact(array $data): ContactSubmission
    {
        return DB::transaction(function () use ($data) {
            $submission = ContactSubmission::create($data);

            // Auto-reply to the user
            Mail::to($submission->email)->send(new ContactAutoReplyMail($submission));

            // Alert all super admins
            $admins = User::where('role', 'super_admin')->where('is_active', true)->pluck('email');
            if ($admins->isNotEmpty()) {
                Mail::to($admins->first())
                    ->cc($admins->skip(1)->values()->all())
                    ->send(new ContactAdminAlertMail($submission));
            }

            return $submission;
        });
    }

    public function listContacts(array $filters = []): LengthAwarePaginator
    {
        $query = ContactSubmission::latest();

        if (isset($filters['is_read'])) {
            $query->where('is_read', $filters['is_read']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function markContactRead(ContactSubmission $submission): ContactSubmission
    {
        $submission->update(['is_read' => true]);

        return $submission;
    }
}
