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

### 2FA flow does not match docs (§1.1)
`POST /v1/login` does not return `requires_2fa`, and the issued token is full-access. Enforcement is downstream via `Enforce2fa` returning 403 `{code: "2fa_required"}`. This works for admins but:

- [ ] Pick a direction:
  - (a) Add `requires_2fa` to the login response and keep the downstream 403 as belt-and-suspenders — minimal change, just update the controller.
  - (b) Scope the login-issued token with `['2fa:pending']` ability and swap to an unrestricted token on `/2fa/verify` — more work, better security posture. Matches the docs literally.
- [ ] Update `docs/features/01-authentication.md` to whatever gets shipped.

### E-commerce webhook has no signature verification (§7.1)
`POST /webhooks/ecommerce/{platform}` accepts anything. `ECommerceService::handleIncoming()` logs the event but performs no auth.

- [ ] Add HMAC verification per platform:
  - Shopify: `X-Shopify-Hmac-Sha256` header, base64-encoded HMAC-SHA256 of raw body with webhook secret
  - WooCommerce: `X-WC-Webhook-Signature` header, base64 HMAC-SHA256 of raw body
  - Salla / Zid: platform docs for their schemes
- [ ] Store per-tenant webhook secrets in `ecommerce_channels` (or similar).

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
