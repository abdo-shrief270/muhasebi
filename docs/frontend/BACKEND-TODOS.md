# Backend TODOs — surfaced from frontend integration questions

Follow-ups surfaced during the review in `OPEN-QUESTIONS.md`. Solo-dev punch list; prioritised by risk.

---

## P0 — Correctness / money-safety

### ~~Idempotency coverage is incomplete~~ ✅ Done
Extended `idempotent` middleware to all financial-write endpoints. Done-by commit for this item noted inline:

- [x] `POST /invoices` (apiResource + cancel/post-to-gl/credit-note)
- [x] `POST /invoices/{id}/send`
- [x] `POST /payments`
- [x] `POST /bills` (apiResource + approve/cancel)
- [x] `POST /bills/{id}/payments`
- [x] `POST /payroll/{id}/calculate|approve|mark-paid`
- [x] `POST /eta/documents/{invoice}/submit|cancel`
- [x] `POST /subscription/change-plan`
- [x] `POST /import`, `POST /import/clients`, `POST /import/accounts`, `POST /import/opening-balances`
- [x] Also extended to `journal-entries` (apiResource + reverse + post) since misposting is expensive to undo
- [x] Regression test added at `tests/Feature/IdempotencyMiddlewareTest.php` (5 assertions: no-key pass-through, replay preserves status, bad-UUID 422, GET skipped, failed responses not cached)

### ~~2FA flow does not match docs~~ ✅ Done (option a)
Login response now includes `requires_2fa: bool`. True when user is admin/super-admin without 2FA enabled — exactly matches `Enforce2fa` middleware's gating condition, so the login flag and the downstream 403 stay in sync. Token remains full-access; non-admins bypass the flag entirely.

- [x] `AuthController::login()` returns `data.requires_2fa`
- [x] `docs/features/01-authentication.md` rewritten to describe actual behavior (flag semantics, no token scoping)
- [x] 3 regression tests in `AuthTest.php` (admin w/o 2FA → true; admin w/ 2FA → false; non-admin → false)

Option (b) — token scoping + per-session verification — deferred. Current model is "once 2FA enabled, always allowed" which is weaker than per-session verification but matches what the `Enforce2fa` middleware actually enforces. If per-session verification becomes a requirement (e.g. for SOC2), revisit this with a new ticket.

### ~~E-commerce webhook has no signature verification~~ ✅ Done
Route changed to `POST /webhooks/ecommerce/{platform}/{channel}` — channel id identifies tenant + webhook_secret. New `VerifyEcommerceWebhookSignature` middleware rejects unsigned / wrong-signature / inactive-channel requests with 401.

- [x] Middleware at `app/Http/Middleware/VerifyEcommerceWebhookSignature.php` registered as `ecommerce.verify`
- [x] Platform schemes wired:
  - Shopify: `X-Shopify-Hmac-Sha256` base64 HMAC-SHA256
  - WooCommerce: `X-WC-Webhook-Signature` base64 HMAC-SHA256
  - Salla: `X-Salla-Signature` hex HMAC-SHA256
  - Zid: `X-Zid-Signature` hex HMAC-SHA256
  - Custom: always rejected (use an authenticated endpoint for self-hosted integrations)
- [x] Webhook secret stored encrypted on `ECommerceChannel.webhook_secret` (was already there)
- [x] 11 regression tests cover: valid-per-platform, wrong-sig, missing-sig, missing-secret, unknown-channel, platform-mismatch, inactive-channel, custom rejection
- [x] Middleware binds `tenant.id` and exposes verified channel via `$request->attributes->get('ecommerce_channel')` for downstream handlers

Known gap: the actual event-processing logic in `ECommerceService::webhookHandler` is still placeholders — signatures are now verified, but order records aren't yet created from webhook payloads. Separate ticket when real sync is implemented.

---

## P1 — Gaps blocking clean frontend integration

### `/v1/me` missing `tenant.features` (§9.1)
The machinery (`FeatureFlagService::getAllForTenant`) exists but isn't wired into the `/me` response. Frontend has to fetch `/v1/subscription` and misses per-tenant overrides entirely.

- [ ] In `AuthController::me()` (app/Domain/Auth/Controllers/AuthController.php:78-112), add to the `tenant` payload:
  ```php
  'features' => $tenant ? FeatureFlagService::getAllForTenant($tenant->id, $tenant->plan_id ?? null) : [],
  ```
- [ ] Verify it doesn't cause N+1 (cache the result per tenant, 60s).

### Permissions missing from backend (§10.1)
Frontend references these; backend doesn't define them. Decide per slug:

- [ ] `manage_engagements` — module exists, permission missing. **Add to `config/permissions.php`.**
- [ ] `manage_approvals` — add for fine-grained approval RBAC (today it's role-based).
- [ ] `manage_alerts` — add for alert management.
- [ ] `manage_reports` — currently redundant with `view_reports`. Only add if report templates/schedules become user-editable. Otherwise tell frontend to drop.

Then seed into role presets (at least `tenant_admin` gets all of them).

### Role-to-permission preset endpoint (§10.2)
Frontend would like to show "Apply Accountant preset" buttons in team management.

- [ ] Add `GET /v1/rbac/role-presets` returning:
  ```json
  {
    "data": [
      { "role": "admin", "label": "Administrator", "permissions": [...] },
      { "role": "accountant", "label": "Accountant", "permissions": [...] },
      { "role": "auditor", "label": "Auditor", "permissions": [...] },
      { "role": "limited", "label": "Limited Access", "permissions": [...] }
    ]
  }
  ```
  Source: `config('permissions.{role}')` arrays already in the codebase.

---

## P2 — Consistency / polish

### Canonical bulk-response pattern (§4.1)
Two patterns coexist:
- Documents bulk: 201 / 206 + per-file results
- E-commerce bulk-convert: 200 + `{ converted, errors }` counts

- [ ] Align e-commerce bulk-convert on the 201/206 pattern for consistency. Non-breaking if frontend already handles both.

### Magic-link invite for portal users (§2.1)
Today portal users are created with `Hash::make(Str::random(16))` and must use the password-reset flow to sign in — poor UX for an invited client.

- [ ] Add a signed, single-use invite-link token (e.g. `POST /v1/portal/accept-invite` that accepts the token and lets the user set their password in one step).
- [ ] Include the signed URL in the welcome email from `ClientInvitationService::sendWelcome()`.

### Messaging `throttle:10,1` may be too low (§5.2)
10 sends/min per user is tight for an accounting firm with heavy reminder workflows.

- [ ] Consider raising to 30–60/min OR defining a named `messaging` limiter with per-tenant quota (e.g. 200/min/tenant). Only if user feedback confirms — don't pre-optimize.

---

## Done-when-confirmed

Delete sections as they land. Reference commit SHAs here when closing items so the audit trail stays readable.
