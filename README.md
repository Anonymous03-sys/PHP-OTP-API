# PHP-OTP-API

This service supports two intentionally isolated API surfaces.

## Legacy OTP API

The existing endpoints remain backward compatible:

- `GET /index.php?email=...&school_id=...`
- `GET /verify.php?email=...&otp=...`

Their request/response contract and Codetology mail behavior remain unchanged.

## Senior E-Services recovery API

Versioned endpoints:

- `POST /v1/password-reset/request.php`
- `POST /v1/password-reset/verify.php`
- `POST /v1/password-reset/complete.php`

### Frozen v1 state (OTP-12)

The complete password-recovery backend flow is active and frozen for final deployment/live testing.

See `docs/OTP12_RECOVERY_API_FREEZE.md` for the authoritative API, security, deployment, and test contract.

`complete.php` accepts:

```json
{
  "resetToken": "<restricted-reset-token>",
  "newPassword": "<new-password>"
}
```

Before changing the password, the server:

1. HMACs the supplied reset token and resolves exactly one VERIFIED challenge.
2. Requires the token to be unused and within its 10-minute lifetime.
3. Loads the account bound to that challenge through the fixed portal mapping.
4. Re-checks current account eligibility so recovery cannot reactivate or
   bypass a newly disabled/inactive account.
5. Applies the same password policy currently used by that portal.

Portal password policies:

- Senior: minimum 8 characters, at least one letter and one number.
- LGU: minimum 8 characters, uppercase, lowercase, number, and one of
  `@$!%*?&_-#`; the complete password is restricted to the characters allowed
  by the current LGU profile validator.
- Super Admin: minimum 8 characters, uppercase, lowercase, number, and a
  special/non-word character.

The account password remains a plain Firestore string by explicit project
requirement.

### Atomic reset completion

One Firestore commit updates all core security state:

- existing password field -> new plain string
- `currentSessionId -> fresh server-generated recovery invalidation token`
- current challenge `state -> COMPLETED`
- current `otp_hash -> null`
- current `reset_token_hash -> null`
- current `reset_token_used -> true`
- completion timestamp
- all sibling PENDING/VERIFIED recovery challenges for the same account ->
  `SUPERSEDED`, with OTP/reset-token hashes cleared

Firestore update-time preconditions are included when available so concurrent
changes cause the commit to fail rather than silently overwriting newer state.

Existing signed-in sessions are invalidated through the same
`currentSessionId` mechanism already used by the three applications. The reset
writes a non-empty random token because the current app listeners log out when
the remote token is present and differs from their local session token.

After the atomic reset succeeds, a separate password-changed informational
email is sent to the account's registered email. Notification failure does not
roll back the completed password reset.

The reset endpoint never creates an application session and never
automatically logs the user in. The user must return to normal sign-in.

### Recovery stages now active

- request: trusted account resolution, durable challenge issuance, throttling,
  and OTP email delivery
- verify: OTP validation and 10-minute one-purpose reset token
- complete: password replacement, reset-token consumption, sibling challenge
  invalidation, and session invalidation

### Fixed portal mapping

- `senior` -> `seniorcitizens.senior_id_number / senior_password`
- `lgu` -> `seniorlgu.employee_no / password`
- `sysadmin` -> `seniorsysadusers.employee_no / password`

The client cannot select a collection, document ID, password field, or recovery
email.

## Environment

Required:

- `FIREBASE_SERVICE_ACCOUNT_JSON` or
  `FIREBASE_SERVICE_ACCOUNT_JSON_BASE64`
- `OTP_RECOVERY_PEPPER`
- `OTP_RECOVERY_ALLOWED_ORIGINS`
- `SENDGRID_API_KEY`

Optional recovery mail branding:

- `SENIOR_RECOVERY_FROM_EMAIL`
- `SENIOR_RECOVERY_FROM_NAME`

Server secrets must never be committed or shipped to Angular.

## OTP-10 hardening

The versioned recovery API adds failure-path and abuse protections without
changing the legacy OTP endpoints:

- JSON-only POST requests with an 8 KiB request-body cap
- generic public errors with provider/server details kept private
- per-account issuance serialization through `password_reset_locks`
- Firestore update-time preconditions for concurrent OTP verification
- atomic resend supersession so the old OTP becomes unusable before the new OTP
  becomes active
- terminal challenge states scrub OTP/reset-token hashes
- stale PENDING and VERIFIED challenges are expired and scrubbed when revisited
- new challenges include `cleanup_after_epoch` as a seven-day retention marker;
  it is an integer and is not directly usable as a Firestore TTL timestamp field
- exact rolling `Retry-After` calculation for account/source throttles
- the fifth failed OTP attempt locks the challenge and removes its OTP hash
- reset-token expiry/reuse remain terminal and one-way
- password completion uses Firestore preconditions and the same per-account
  issuance lock, then returns a retry response if account/challenge state changes
  concurrently
- request responses use a small randomized minimum delay to reduce simple
  account-enumeration timing differences
- optional proxy-aware source fingerprints are controlled by
  `OTP_RECOVERY_TRUST_PROXY_HEADERS`

Only enable trusted proxy headers when the hosting reverse proxy sanitizes or
overwrites `X-Forwarded-For`.
