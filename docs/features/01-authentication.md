# Authentication & Account Management

> Sign up, sign in, manage profile, set up 2FA, and configure per-user preferences. All session tokens are issued via Laravel Sanctum.

## Access & Auth
- **Authentication:** public for `/register` and `/login`; Bearer token (Sanctum) for everything else.
- **Tenant scope:** no (account-level, not tenant-scoped).
- **Permissions required:** none (self-service account management).
- **Feature flags:** none.
- **Rate limits:** `throttle:5,1` on register/login/2fa verify; `throttle:3,1` on change-password and 2fa disable.

## Endpoints

### `POST /v1/register`
**Purpose:** create a new tenant and its admin user in a single call.
**Middleware:** `throttle:5,1`
**Request body:**
```json
{
  "company_name": "string (required) — tenant display name",
  "first_name": "string (required)",
  "last_name": "string (required)",
  "email": "string (required, unique, email)",
  "password": "string (required, min 8, confirmed)",
  "password_confirmation": "string (required)",
  "phone": "string (optional)",
  "city": "string (optional)"
}
```
**Response 201:**
```json
{ "data": { "user": { ... }, "tenant": { ... }, "token": "sanctum-token" } }
```
**Notes:** creates tenant, assigns starter plan, sends welcome email, returns token.

### `POST /v1/login`
**Purpose:** exchange email+password for a Sanctum bearer token.
**Middleware:** `throttle:5,1`
**Request body:**
```json
{
  "email": "string (required)",
  "password": "string (required)",
  "device_name": "string (optional) — label the token"
}
```
**Response 200:**
```json
{
  "data": {
    "user": { "id": 1, "name": "...", "email": "...", "role": "tenant_admin" },
    "tenant": { "id": 1, "name": "...", "plan": "...", "features": ["..."] },
    "token": "sanctum-token",
    "requires_2fa": false
  }
}
```
**Notes:** if user has 2FA enabled, `requires_2fa:true` is returned and token is scoped — call `/v1/2fa/verify` to fully authenticate.

### `POST /v1/logout`
**Purpose:** revoke the current access token.
**Middleware:** `auth:sanctum`
**Response 200:** `{ "message": "Logged out" }`

### `GET /v1/me`
**Purpose:** fetch the authenticated user, tenant, roles, permissions, and enabled features.
**Middleware:** `auth:sanctum`
**Response 200:**
```json
{
  "data": {
    "user": { "id": 1, "name": "...", "email": "...", "locale": "ar", "timezone": "Africa/Cairo" },
    "tenant": { "id": 1, "plan": "...", "features": ["invoicing","payroll","..."] },
    "permissions": ["manage_invoices","view_reports"],
    "two_factor_enabled": false
  }
}
```

### `PUT /v1/profile`
**Purpose:** update the user's profile.
**Middleware:** `auth:sanctum`
**Request body:**
```json
{
  "name": "string (optional)",
  "phone": "string (optional)",
  "locale": "ar | en (optional)",
  "timezone": "string (optional, IANA)"
}
```
**Response 200:** updated `UserResource`.

### `POST /v1/change-password`
**Purpose:** change the user's password.
**Middleware:** `auth:sanctum`, `throttle:3,1`
**Request body:**
```json
{
  "current_password": "string (required)",
  "new_password": "string (required, min 8, confirmed)",
  "new_password_confirmation": "string (required)"
}
```
**Response 200:** `{ "message": "Password changed" }`
**Notes:** all existing tokens other than the current one are revoked.

---

## Two-Factor Authentication (TOTP)

### `GET /v1/2fa/status`
Returns `{ "data": { "enabled": bool, "pending": bool, "recovery_codes_remaining": int } }`.

### `POST /v1/2fa/enable`
Begin TOTP enrollment. Response:
```json
{
  "data": {
    "secret": "BASE32...",
    "qr_code_url": "data:image/png;base64,...",
    "recovery_codes": ["abc-def", "..."]
  }
}
```
Enrollment is finalized by calling `/v1/2fa/verify` with the first valid code.

### `POST /v1/2fa/verify`
**Middleware:** `throttle:5,1`
```json
{ "code": "string (required, 6 digits or recovery code)" }
```
On success the current Sanctum token is upgraded (2fa-verified) and any sensitive endpoints become accessible.

### `POST /v1/2fa/disable`
**Middleware:** `throttle:3,1`
```json
{ "code": "string (required) — current TOTP", "password": "string (required)" }
```

---

## Notification Preferences (per-user)

### `GET /v1/notification-preferences`
Returns the user's channel×event matrix.

### `PUT /v1/notification-preferences`
```json
{
  "channels": {
    "email": true,
    "sms": false,
    "push": true
  },
  "events": {
    "invoice.created": ["email"],
    "payment.received": ["email","push"],
    "approval.requested": ["email","sms"]
  }
}
```

---

## User Preferences (UI state)

### `GET /v1/preferences`
Returns `{ theme, language, sidebar_collapsed, shortcuts_enabled, density, date_format, number_format }`.

### `PUT /v1/preferences`
```json
{
  "theme": "light | dark | system",
  "language": "ar | en",
  "sidebar_collapsed": true,
  "shortcuts_enabled": true,
  "density": "compact | comfortable"
}
```

### `POST /v1/preferences/reset`
Resets to defaults.

### `GET /v1/preferences/shortcuts`
Returns the keyboard shortcut map the UI should bind. Shape: `{ "goto_invoices": "g i", ... }`.

---

## Device Tokens (Push Notifications)

### `GET /v1/device-tokens`
List of registered FCM / APNs tokens for the user.

### `POST /v1/device-tokens`
```json
{
  "token": "string (required) — FCM or APNs token",
  "device_type": "android | ios | web (required)",
  "device_name": "string (optional)"
}
```

### `DELETE /v1/device-tokens`
```json
{ "token": "string (required)" }
```
Unregisters a token (typically on logout from a device).

---

## Notes
- All endpoints return Laravel's standard validation error shape on 422:
  ```json
  { "message": "...", "errors": { "field": ["message"] } }
  ```
- Sanctum token should be sent as `Authorization: Bearer <token>`.
- The response envelope is `{ "data": ..., "meta": ... }` or `{ "data": [...] , "meta": { pagination } }` for collections.
- Tenant context is inferred from the authenticated user — there is no explicit `X-Tenant-ID` header for app users.
