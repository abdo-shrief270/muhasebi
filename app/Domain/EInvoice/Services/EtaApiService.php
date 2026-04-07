<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Models\EtaSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EtaApiService
{
    public function __construct(
        private readonly EtaSettingsService $settingsService,
    ) {}

    /**
     * Submit documents to ETA.
     *
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function submitDocuments(array $documents): array
    {
        $settings = $this->settingsService->ensureEnabled();
        $token = $this->getAccessToken($settings);

        $response = $this->makeRequest('POST', $settings->getBaseUrl() . '/documentsubmissions', [
            'documents' => $documents,
        ], $token);

        if (! $response->successful()) {
            Log::error('ETA document submission failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'document_count' => count($documents),
            ]);

            throw ValidationException::withMessages([
                'eta' => [
                    'Failed to submit documents to ETA. Status: ' . $response->status(),
                    'فشل إرسال المستندات إلى مصلحة الضرائب. الحالة: ' . $response->status(),
                ],
            ]);
        }

        return $response->json();
    }

    /**
     * Get the status of a specific document from ETA.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function getDocumentStatus(string $etaUuid): array
    {
        $settings = $this->settingsService->ensureEnabled();
        $token = $this->getAccessToken($settings);

        $response = $this->makeRequest('GET', $settings->getBaseUrl() . "/documents/{$etaUuid}/raw", token: $token);

        if (! $response->successful()) {
            Log::error('ETA document status check failed.', [
                'status' => $response->status(),
                'eta_uuid' => $etaUuid,
            ]);

            throw ValidationException::withMessages([
                'eta' => [
                    "Failed to get document status from ETA. UUID: {$etaUuid}",
                    "فشل في الحصول على حالة المستند من مصلحة الضرائب. UUID: {$etaUuid}",
                ],
            ]);
        }

        return $response->json();
    }

    /**
     * Get the status of a submission batch from ETA.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function getSubmissionStatus(string $submissionUuid): array
    {
        $settings = $this->settingsService->ensureEnabled();
        $token = $this->getAccessToken($settings);

        $response = $this->makeRequest('GET', $settings->getBaseUrl() . "/documentsubmissions/{$submissionUuid}", token: $token);

        if (! $response->successful()) {
            Log::error('ETA submission status check failed.', [
                'status' => $response->status(),
                'submission_uuid' => $submissionUuid,
            ]);

            throw ValidationException::withMessages([
                'eta' => [
                    'Failed to get submission status from ETA.',
                    'فشل في الحصول على حالة الإرسال من مصلحة الضرائب.',
                ],
            ]);
        }

        return $response->json();
    }

    /**
     * Cancel a document at ETA.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function cancelDocument(string $etaUuid, string $reason): array
    {
        $settings = $this->settingsService->ensureEnabled();
        $token = $this->getAccessToken($settings);

        $response = $this->makeRequest('PUT', $settings->getBaseUrl() . "/documents/state/{$etaUuid}/state", [
            'status' => 'cancelled',
            'reason' => $reason,
        ], $token);

        if (! $response->successful()) {
            Log::error('ETA document cancellation failed.', [
                'status' => $response->status(),
                'eta_uuid' => $etaUuid,
                'reason' => $reason,
            ]);

            throw ValidationException::withMessages([
                'eta' => [
                    'Failed to cancel document at ETA.',
                    'فشل إلغاء المستند في مصلحة الضرائب.',
                ],
            ]);
        }

        return $response->json();
    }

    /**
     * Get recent documents from ETA for reconciliation.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function getRecentDocuments(int $page = 1, int $pageSize = 50): array
    {
        $settings = $this->settingsService->ensureEnabled();
        $token = $this->getAccessToken($settings);

        $response = $this->makeRequest(
            'GET',
            $settings->getBaseUrl() . "/documents/recent?PageSize={$pageSize}&PageNo={$page}",
            token: $token,
        );

        if (! $response->successful()) {
            Log::error('ETA recent documents fetch failed.', [
                'status' => $response->status(),
            ]);

            throw ValidationException::withMessages([
                'eta' => [
                    'Failed to fetch recent documents from ETA.',
                    'فشل في جلب المستندات الأخيرة من مصلحة الضرائب.',
                ],
            ]);
        }

        return $response->json();
    }

    /**
     * Obtain an OAuth2 access token, using cached token when valid.
     *
     * @throws ValidationException
     */
    private function getAccessToken(EtaSettings $settings): string
    {
        if ($settings->isTokenValid()) {
            return $settings->access_token;
        }

        $response = Http::asForm()
            ->timeout(30)
            ->retry(2, 1000)
            ->post($settings->getTokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => $settings->client_id,
                'client_secret' => $settings->client_secret,
                'scope' => 'InvoicingAPI',
            ]);

        if (! $response->successful()) {
            Log::error('ETA OAuth authentication failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
                'environment' => $settings->environment,
            ]);

            throw ValidationException::withMessages([
                'eta' => [
                    'ETA authentication failed. Please check your API credentials.',
                    'فشلت المصادقة مع مصلحة الضرائب. يرجى التحقق من بيانات الاعتماد.',
                ],
            ]);
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in', 3600);

        // Cache token on the settings row (subtract 60s buffer)
        $settings->update([
            'access_token' => $token,
            'token_expires_at' => now()->addSeconds($expiresIn - 60),
        ]);

        return $token;
    }

    /**
     * Make an authenticated HTTP request to the ETA API.
     */
    private function makeRequest(string $method, string $url, array $data = [], string $token = ''): Response
    {
        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->retry(2, 2000, fn ($exception, $request) => $exception->response?->status() === 429);

        return match (strtoupper($method)) {
            'GET' => $request->get($url),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => $request->get($url),
        };
    }
}
