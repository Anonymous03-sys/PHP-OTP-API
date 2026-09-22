# PHP-OTP-API

This service supports two intentionally isolated API surfaces.

## Legacy OTP API

The existing endpoints are retained for backward compatibility with the system
that already consumes them:

- `GET /index.php?email=...&school_id=...`
- `GET /verify.php?email=...&otp=...`

Their existing request/response behavior must not be changed as part of the
Senior E-Services password-recovery work.

## Senior E-Services recovery API

A separate versioned API is being introduced under:

- `POST /v1/password-reset/request.php`
- `POST /v1/password-reset/verify.php`
- `POST /v1/password-reset/complete.php`

### OTP-2 state

`request.php` now performs trusted server-side account resolution only.
OTP generation and delivery are intentionally deferred to the next stages.

The caller submits only:

```json
{
  "portal": "senior",
  "identifier": "SC-2026-000123"
}
```

Allowed portal mappings are fixed on the server:

- `senior` -> `seniorcitizens.senior_id_number`
- `lgu` -> `seniorlgu.employee_no`
- `sysadmin` -> `seniorsysadusers.employee_no`

The browser/app cannot select a Firestore collection, password field, document
ID, or destination email. The registered recovery email is read from the
resolved account record.

The public response is deliberately generic for known, unknown, ineligible, and
missing-email accounts so the endpoint does not expose an account directory.

`verify.php` and `complete.php` remain inactive scaffolds until their later
OTP stages.

`GET /health.php` provides a non-secret service health response.

## Environment

The new versioned recovery API uses a Firebase service account supplied only by
the server environment.

Required for OTP-2 account resolution:

- `FIREBASE_SERVICE_ACCOUNT_JSON`
  - complete Firebase service-account JSON, or
- `FIREBASE_SERVICE_ACCOUNT_JSON_BASE64`
  - base64-encoded service-account JSON as an alternative

Recovery CORS is configured separately with:

- `OTP_RECOVERY_ALLOWED_ORIGINS`
  - comma-separated list of approved origins

No Firebase service-account credential belongs in the repository or any Angular
application.

The legacy endpoints keep their existing CORS, SendGrid, and request behavior
unchanged.
