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

### OTP-5 state

The request endpoint resolves an eligible account, creates a durable challenge,
and sends the OTP to the server-resolved registered email.

The verification endpoint is now active.

Request:

```json
{
  "challengeId": "<opaque-challenge-id>",
  "otp": "123456"
}
```

Successful verification:

```json
{
  "success": true,
  "code": "OTP_VERIFIED",
  "message": "Verification successful. You can now create a new password.",
  "resetToken": "<one-purpose-random-token>",
  "expiresIn": 600
}
```

The raw reset token is returned once to the caller. Firestore stores only its
HMAC in the challenge document.

Reset-token policy:

- random 32-byte source value encoded as an opaque URL-safe token
- lifetime: 10 minutes
- bound to the already verified recovery challenge/account
- stored only as `reset_token_hash`
- OTP hash is cleared when verification succeeds
- OTP and reset-token issuance are persisted in the same Firestore update
- challenge state changes from `PENDING` to `VERIFIED`
- a second OTP verification attempt cannot issue another token
- token does not create an application session
- token does not grant normal portal access
- token authorizes only the later password-reset completion operation

The public `complete.php` endpoint is intentionally still inactive until
OTP-6. No password is changed in OTP-5.

### OTP challenge policy

- six-digit OTP generated with `random_int()`
- OTP lifetime: 5 minutes
- resend cooldown: 60 seconds
- maximum OTP guesses: 5
- resend supersedes prior pending challenge after cooldown
- OTP stored only as HMAC
- account/source throttling uses HMAC keys
- durable collection: `password_reset_challenges`

### Email delivery

The v1 recovery flow uses the existing SendGrid dependency and
`SENDGRID_API_KEY`, with separate Senior Citizen Information System branding.
Only fresh, eligible challenges send mail to the server-resolved account email.
Legacy Codetology mail remains unchanged.

### Fixed portal mapping

- `senior` -> `seniorcitizens.senior_id_number`
- `lgu` -> `seniorlgu.employee_no`
- `sysadmin` -> `seniorsysadusers.employee_no`

The client cannot choose Firestore collections, document IDs, password fields,
or recovery email addresses.

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
