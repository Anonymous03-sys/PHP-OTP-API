# PHP-OTP-API

This service supports two intentionally isolated API surfaces.

## Legacy OTP API

The existing endpoints remain backward compatible:

- `GET /index.php?email=...&school_id=...`
- `GET /verify.php?email=...&otp=...`

The Senior E-Services work must not change their contract.

## Senior E-Services recovery API

Versioned endpoints:

- `POST /v1/password-reset/request.php`
- `POST /v1/password-reset/verify.php`
- `POST /v1/password-reset/complete.php`

### OTP-3 state

The request endpoint now resolves the account and creates a durable Firestore
challenge. Email delivery is deliberately not active until OTP-4.

Request:

```json
{
  "portal": "senior",
  "identifier": "SC-2026-000123"
}
```

Successful accepted response:

```json
{
  "success": true,
  "code": "RECOVERY_REQUEST_ACCEPTED",
  "message": "If an eligible account is registered and has a recovery email, a verification code will be sent.",
  "challengeId": "<opaque-id>",
  "expiresIn": 300,
  "resendAfter": 60
}
```

An opaque challenge is returned for both eligible and non-eligible identifiers.
Ineligible/unknown requests use decoy challenges, so the response shape does not
directly disclose whether an account exists.

### Challenge policy

- six-digit OTP generated with `random_int()`
- OTP lifetime: 5 minutes
- resend cooldown: 60 seconds
- maximum OTP guesses: 5
- resend supersedes older pending challenges after cooldown
- successful verification is single-use
- OTPs are stored only as an HMAC, never as raw codes
- account/source rate keys are HMACs, not raw identifiers/IP addresses
- durable challenge collection: `password_reset_challenges`
- account limit: 5 challenge requests / 15 minutes
- source limit: 20 challenge requests / 15 minutes

The challenge engine already contains verification/attempt state transitions,
but the public v1 `verify.php` remains inactive until OTP-5, where successful
OTP verification will create the restricted reset token.

### Fixed server-side portal mapping

- `senior` -> `seniorcitizens.senior_id_number`
- `lgu` -> `seniorlgu.employee_no`
- `sysadmin` -> `seniorsysadusers.employee_no`

The client cannot select a collection, password field, Firestore document ID, or
recovery email.

## Environment

Required for the versioned recovery API:

- `FIREBASE_SERVICE_ACCOUNT_JSON`
  - complete Firebase service-account JSON, or
- `FIREBASE_SERVICE_ACCOUNT_JSON_BASE64`
  - base64 alternative
- `OTP_RECOVERY_PEPPER`
  - at least 32 unpredictable characters used for HMACs
- `OTP_RECOVERY_ALLOWED_ORIGINS`
  - comma-separated approved browser origins

The Firebase service-account credential and recovery pepper are server secrets
and must never be committed or shipped to Angular.

The legacy endpoints keep their existing CORS, SendGrid, storage and request
behavior unchanged.
