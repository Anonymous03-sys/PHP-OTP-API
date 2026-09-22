# OTP-12 Password Recovery API Freeze

Status: **FROZEN FOR FINAL DEPLOYMENT / LIVE TESTING**

This document is the authoritative OTP-12 backend contract for the Senior
E-Services password-recovery work.

Validated implementation baseline before this documentation freeze:

- PHP-OTP-API `dev-ai`: `c656ee77f536d6643a35c5fee25b8c763dc76640`
- OTP-11 PHP validation: PASS

The OTP-12 documentation commit may move `dev-ai` beyond that SHA without
changing the frozen runtime behavior.

## 1. Compatibility boundary

The repository contains two intentionally separate API surfaces.

Legacy API, preserved for the existing external consumer:

- `GET /index.php?email=...&school_id=...`
- `GET /verify.php?email=...&otp=...`

Senior E-Services recovery API:

- `POST /v1/password-reset/request.php`
- `POST /v1/password-reset/verify.php`
- `POST /v1/password-reset/complete.php`
- `GET /health.php`

OTP-11 CI verifies that these protected legacy files remain unchanged from
`main`:

- `index.php`
- `verify.php`
- `Dockerfile`
- `composer.json`
- `composer.lock`

The legacy Codetology mail behavior and legacy request/response contracts are
outside the Senior E-Services recovery implementation and must remain
compatible.

## 2. Fixed server-side account mapping

The caller may provide only a portal identifier and an account identifier.
The caller cannot select a Firestore collection, Firestore document ID,
password field, or destination email.

| Portal | Collection | Identifier | Recovery email | Password field |
| --- | --- | --- | --- | --- |
| `senior` | `seniorcitizens` | `senior_id_number` | `email_address` | `senior_password` |
| `lgu` | `seniorlgu` | `employee_no` | `email_address` | `password` |
| `sysadmin` | `seniorsysadusers` | `employee_no` | `email_address` | `password` |

Senior identifiers are uppercased before lookup. LGU and Super Admin Employee
Numbers are trimmed.

## 3. Eligibility rules

Recovery does not approve, reactivate, or alter account status.

Senior accounts are not eligible when:

- `status` is `PENDING`
- `status` is `REJECTED`
- `status` is `DISABLED`
- `vital_status` is `DECEASED`

LGU recovery follows the current LGU login compatibility rules:

- explicit `account_status = INACTIVE` is ineligible
- legacy `is_employed` false/inactive forms are ineligible
- recovery never changes either field

Super Admin currently has no additional login-time account-status gate, so the
recovery backend does not invent one.

Eligibility is checked again immediately before password replacement.

## 4. Request endpoint

### Request

```json
{
  "portal": "senior",
  "identifier": "SC-2026-000123"
}
```

Only JSON POST requests are accepted. Request bodies are capped at 8 KiB.

### Accepted response

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

Unknown, ineligible, and missing-email accounts use the same public response
shape. Decoy challenges are created when appropriate so the endpoint does not
become an account directory.

Rate-limited requests return HTTP 429 and a calculated `Retry-After` header.

## 5. OTP policy

Frozen OTP policy:

- six numeric digits
- generated with `random_int()`
- lifetime: 300 seconds
- resend cooldown: 60 seconds
- maximum failed guesses: 5
- OTP stored only as HMAC, never raw
- successful OTP verification is single-use
- fifth failed attempt locks the challenge and removes the OTP hash
- resend after cooldown supersedes the old pending challenge atomically

The server uses `OTP_RECOVERY_PEPPER` for HMAC operations. The pepper must be
at least 32 unpredictable characters.

## 6. Verification endpoint

### Request

```json
{
  "challengeId": "<opaque-id>",
  "otp": "123456"
}
```

### Success

```json
{
  "success": true,
  "code": "OTP_VERIFIED",
  "message": "Verification successful. You can now create a new password.",
  "resetToken": "<one-purpose-token>",
  "expiresIn": 600
}
```

The reset token:

- is generated from 32 random bytes
- is valid for 600 seconds
- is bound to the already verified challenge/account
- is not an application login/session token
- is returned raw only once
- is stored in Firestore only as an HMAC
- cannot be reused after completion or expiry

## 7. Completion endpoint

### Request

```json
{
  "resetToken": "<one-purpose-token>",
  "newPassword": "<new-password>"
}
```

Password policy is portal-specific and mirrors the existing application policy.

Senior:

- minimum 8 characters
- at least one letter
- at least one number

LGU:

- minimum 8 characters
- uppercase
- lowercase
- number
- at least one of `@$!%*?&_-#`
- entire password limited to the current LGU allowed-character set

Super Admin:

- minimum 8 characters
- uppercase
- lowercase
- number
- at least one special/non-word character

By explicit project requirement, the account password remains a plain Firestore
string in the existing password field. This recovery implementation does not
introduce Firebase Auth or password hashing/migration.

### Atomic completion

A successful reset atomically updates the security-critical state:

- existing portal password field -> new plain string
- `currentSessionId` -> fresh non-empty `recovery-reset-...` token
- current challenge -> `COMPLETED`
- OTP hash -> cleared
- reset-token hash -> cleared
- reset token -> marked used
- sibling `PENDING`/`VERIFIED` challenges -> `SUPERSEDED`
- sibling OTP/reset-token hashes -> cleared
- per-account issuance lock -> released

The non-empty `currentSessionId` token is intentional. All three applications
sign out an existing session when the remotely observed session ID is present
and differs from the local session ID.

The endpoint does not auto-login the user. The user returns to normal sign-in.

## 8. Challenge state machine

Primary states:

```text
PENDING
  |-- correct eligible OTP --> VERIFIED
  |-- expiry ---------------> EXPIRED
  |-- 5 failed OTPs --------> LOCKED
  |-- newer challenge ------> SUPERSEDED
  |-- valid decoy OTP ------> CONSUMED

VERIFIED
  |-- password reset -------> COMPLETED
  |-- reset-token expiry ---> EXPIRED
  |-- sibling invalidation -> SUPERSEDED
```

Terminal states scrub temporary OTP/reset-token hashes.

Delivery metadata may use:

- `PENDING`
- `SENT`
- `FAILED`
- `NOT_APPLICABLE`

## 9. Durable Firestore state

Recovery challenge collection:

- `password_reset_challenges`

Per-account issuance serialization collection:

- `password_reset_locks`

Challenge records contain security state such as account/source HMAC keys,
expiry times, attempt counts, delivery state, and token state.

`cleanup_after_epoch` is an integer retention marker currently set seven days
after challenge creation. The implementation also performs on-access cleanup of
stale challenge secrets.

Important: `cleanup_after_epoch` is **not** a Firestore TTL-policy timestamp
field. Do not configure Firestore TTL directly against that integer field.
If automatic Firestore TTL deletion is desired later, add a dedicated timestamp
field in a separate migration/hardening change.

Client Firestore rules should deny direct browser/app access to the recovery
challenge and lock collections. The trusted PHP service uses its server
credential to access them.

## 10. Rate limiting and concurrency

Frozen request limits:

- account: 5 challenge requests per 900 seconds
- source: 20 challenge requests per 900 seconds

The backend uses HMAC identifiers for account/source rate keys.

Per-account challenge issuance is serialized through
`password_reset_locks`. Firestore update-time/create preconditions protect
concurrent issuance, verification, and completion from stale writes.

If password completion loses a concurrent state race, the API returns
`RECOVERY_RETRY_REQUIRED` rather than silently overwriting newer state.

## 11. Email behavior

OTP email:

- SendGrid delivery
- server-resolved registered account email only
- subject: `Senior Citizen Information System - Password Recovery Code`
- plain-text and HTML bodies
- includes expiration and non-sharing warning
- never includes the account password

Password-changed notice:

- sent after the atomic password reset succeeds
- does not contain the password
- notification failure does not roll back a completed password reset

Unknown/ineligible/decoy challenges never send recovery email.

## 12. Required environment variables

Required for Senior E-Services recovery:

- `OTP_RECOVERY_ALLOWED_ORIGINS`
- `FIREBASE_PROJECT_ID=ojt-app-3cebb-3ac5a`
- `FIREBASE_SERVICE_ACCOUNT_JSON` **or**
  `FIREBASE_SERVICE_ACCOUNT_JSON_BASE64`
- `OTP_RECOVERY_PEPPER`
- `SENDGRID_API_KEY`

The Firestore target project is explicitly configured and does not come from
the service-account JSON. This prevents an old or unrelated credential from
silently redirecting recovery queries to the wrong Firebase project.

The service account should normally be created in
`ojt-app-3cebb-3ac5a`. If a credential from another Google Cloud project is
used instead, it must have IAM permission to access Firestore in
`ojt-app-3cebb-3ac5a`.

Optional branding:

- `SENIOR_RECOVERY_FROM_EMAIL`
- `SENIOR_RECOVERY_FROM_NAME`

Proxy source handling:

- `OTP_RECOVERY_TRUST_PROXY_HEADERS=false` by default
- set true only when the deployment reverse proxy is trusted to sanitize or
  overwrite `X-Forwarded-For`

Never commit Firebase service-account JSON, SendGrid keys, or the recovery
pepper.

## 13. CORS and public errors

The new v1 endpoints use their own allowed-origin list. They do not inherit the
legacy wildcard CORS policy.

For local Ionic/Capacitor testing, the backend explicitly allows:

- `http://localhost`
- `http://localhost:8100`
- `http://localhost:8101`
- `http://localhost:8102`
- `http://127.0.0.1:8100`
- `http://127.0.0.1:8101`
- `http://127.0.0.1:8102`
- `capacitor://localhost`

Deployed frontend origins must still be configured through
`OTP_RECOVERY_ALLOWED_ORIGINS`.

Public recovery errors are deliberately generic. Provider response bodies,
Firebase internals, service-account data, stack traces, passwords, OTP hashes,
and reset-token hashes must not be returned to clients.

## 14. No-email/manual recovery path

Some Senior accounts can legitimately have no recovery email because the
existing registration flow allows email to be blank.

The recovery API must not accept a replacement destination email from the
caller.

When no registered recovery email is available, self-service recovery cannot
continue. The Senior UI directs the person to the authorized LGU Senior Citizen
Office for identity verification/manual assistance.

LGU and Super Admin interfaces similarly direct users without access to the
registered email to the appropriate authorized administrator.

## 15. Deployment checklist

Before live recovery testing:

1. Deploy the frozen `dev-ai` backend build to the intended Render/service
   environment or deliberately merge it through the project's release process.
2. Configure all required server environment variables.
3. Set `OTP_RECOVERY_ALLOWED_ORIGINS` to the exact deployed origins of the
   Senior, LGU, and Super Admin frontends. Do not use `*` for the v1 API.
4. Confirm the Firebase service account can read the three account collections
   and create/update the two recovery collections.
5. Confirm SendGrid sender identity is verified.
6. Keep `OTP_RECOVERY_TRUST_PROXY_HEADERS=false` unless the proxy forwarding
   model has been verified.
7. Confirm client Firestore rules do not expose recovery challenge/lock records.
8. Confirm `GET /health.php` reports the recovery service as available.
9. Do not modify the legacy `index.php` or `verify.php` during deployment.

## 16. Final live test checklist

Use disposable/test accounts for each portal.

For Senior:

1. Sign in with the old password.
2. Keep one existing session open.
3. Request recovery with Senior Citizen ID.
4. Confirm OTP arrives only at the registered email.
5. Confirm wrong OTP decrements remaining attempts.
6. Confirm the fifth wrong OTP locks that challenge.
7. Request a fresh challenge.
8. Confirm old/superseded OTP cannot verify.
9. Verify the fresh OTP.
10. Reset to a valid Senior password.
11. Confirm the existing signed-in session is revoked.
12. Confirm the old password fails.
13. Confirm the new password succeeds.
14. Confirm status/vital status did not change.

Repeat equivalent tests for LGU and Super Admin, including their stronger
password rules.

Also test:

- unknown identifier
- missing recovery email
- inactive LGU account
- disabled/pending/rejected/deceased Senior account
- malformed JSON
- non-JSON content type
- expired OTP
- expired reset token
- reset-token reuse
- resend before 60 seconds
- resend after 60 seconds
- account/source rate limits
- SendGrid delivery failure
- concurrent recovery requests
- concurrent completion request
- legacy external consumer request/verify flow

## 17. Known deferred items

The following are intentionally outside the frozen OTP-1 through OTP-12 scope:

- password hashing or Firebase Auth migration
- changing account collection schemas
- making Senior registration email mandatory
- changing the legacy Codetology OTP API
- migrating the external React Native consumer
- removing committed `vendor/`
- removing/restricting legacy `test.php` without a separate compatibility
  review
- adding a Firestore-native TTL timestamp field for automatic deletion

Any future change to these items should be handled as a new bounded stage,
not folded into the frozen recovery implementation.
