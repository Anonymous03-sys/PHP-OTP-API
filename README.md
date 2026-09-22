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

### OTP-6 state

The complete password-recovery backend flow is active.

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
- `currentSessionId -> null`
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
`currentSessionId` mechanism already used by the three applications.

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
