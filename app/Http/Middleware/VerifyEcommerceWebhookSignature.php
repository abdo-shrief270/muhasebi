<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\ECommerce\Enums\ECommercePlatform;
use App\Domain\ECommerce\Models\ECommerceChannel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies inbound e-commerce webhook signatures per platform.
 *
 * Route shape: POST /webhooks/ecommerce/{platform}/{channel}
 *   - {platform}: shopify|woocommerce|salla|zid|custom (must match channel.platform)
 *   - {channel}:  ECommerceChannel id, owns the shared secret
 *
 * The middleware loads the channel (outside tenant scope since the request
 * is unauthenticated), verifies the HMAC against the raw body, and binds
 * `tenant.id` so downstream code runs in the correct tenant context.
 *
 * Custom platform is intentionally rejected — a self-hosted integration
 * should hit an authenticated endpoint instead.
 */
class VerifyEcommerceWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $platform = (string) $request->route('platform');
        $channelId = (int) $request->route('channel');

        $channel = ECommerceChannel::query()
            ->withoutGlobalScope('tenant')
            ->find($channelId);

        if (! $channel || ! $channel->is_active || $channel->platform->value !== $platform) {
            Log::warning('E-commerce webhook rejected: unknown or inactive channel', [
                'platform' => $platform,
                'channel_id' => $channelId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_channel'], 401);
        }

        $secret = $channel->webhook_secret;
        if (! $secret) {
            Log::warning('E-commerce webhook rejected: channel has no webhook_secret', [
                'channel_id' => $channel->id,
                'platform' => $platform,
            ]);

            return response()->json(['error' => 'webhook_secret_not_configured'], 401);
        }

        $raw = $request->getContent();
        $valid = match ($channel->platform) {
            ECommercePlatform::Shopify => $this->verifyBase64Hmac($request, $raw, $secret, 'X-Shopify-Hmac-Sha256'),
            ECommercePlatform::WooCommerce => $this->verifyBase64Hmac($request, $raw, $secret, 'X-WC-Webhook-Signature'),
            ECommercePlatform::Salla => $this->verifyHexHmac($request, $raw, $secret, 'X-Salla-Signature'),
            ECommercePlatform::Zid => $this->verifyHexHmac($request, $raw, $secret, 'X-Zid-Signature'),
            ECommercePlatform::Custom => false,
        };

        if (! $valid) {
            Log::warning('E-commerce webhook rejected: invalid signature', [
                'channel_id' => $channel->id,
                'platform' => $platform,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $request->attributes->set('ecommerce_channel', $channel);
        app()->instance('tenant.id', $channel->tenant_id);

        return $next($request);
    }

    private function verifyBase64Hmac(Request $request, string $raw, string $secret, string $header): bool
    {
        $provided = $request->header($header);
        if (! $provided) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return hash_equals($expected, $provided);
    }

    private function verifyHexHmac(Request $request, string $raw, string $secret, string $header): bool
    {
        $provided = $request->header($header);
        if (! $provided) {
            return false;
        }

        $expected = hash_hmac('sha256', $raw, $secret);

        return hash_equals($expected, $provided);
    }
}
