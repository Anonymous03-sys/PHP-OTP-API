# PHP-OTP-API

This service supports two intentionally isolated API surfaces.

## Legacy OTP API

The existing endpoints remain backward compatible:

- `GET /index.php?email=...&school_id=...`
- `GET /verify.php?email=...&otp=...`

The Senior E-Services work must not change their contract or Codetology email
behavior.

## Senior E-Services recovery API

Versioned endpoints:

- `POST /v1/password-reset/request.php`
- `POST /v1/password-reset/verify.php`
- `POST /v1/password-reset/complete.php`

### OTP-4 state

The request endpoint now:

1. resolves the account on the trusted server,
2. creates a durable OTP challenge,
3. sends a newly-created real OTP only to the registered account email,
4. records delivery outcome on the challenge,
5. returns only generic recovery metadata to the client.

Unknown/ineligible requests still receive decoy challenges. They never send
mail.

OTP verification and password replacement are still inactive public endpoints
until OTP-5 and OTP-6.

### Email delivery

The new recovery flow reuses the repository's existing SendGrid dependency and
`SENDGRID_API_KEY`, but has a separate Senior E-Services mail template:

- subject: `Senior Citizen Information System - Password Recovery Code`
- six-digit OTP
- five-minute expiration notice
- warning not to share the code
- notice that ignoring the email leaves the current password unchanged

Both text/plain and text/html versions are sent.

The recovery sender is configured with:

- `SENIOR_RECOVERY_FROM_EMAIL`
- `SENIOR_RECOVERY_FROM_NAME`

If `SENIOR_RECOVERY_FROM_EMAIL` is not set, the recovery flow falls back to
the legacy verified sender address `codetology.adm1n@gmail.com`. This avoids
requiring a new SendGrid sender before deployment; a dedicated verified LGU
sender can be configured later without changing code.

The legacy `index.php` continues to use its original Codetology sender,
subject, and body unchanged.

### Challenge policy

- six-digit OTP generated with `random_int()`
- OTP lifetime: 5 minutes
- resend cooldown: 60 seconds
- maximum OTP guesses: 5
- resend supersedes older pending challenges after cooldown
- successful verification is single-use
- OTPs are stored only as an HMAC, never as raw codes
- account/source rate keys are HMACs
- durable collection: `password_reset_challenges`
- account limit: 5 challenge requests / 15 minutes
- source limit: 20 challenge requests / 15 minutes

### Fixed portal mapping

- `senior` -> `seniorcitizens.senior_id_number`
- `lgu` -> `seniorlgu.employee_no`
- `sysadmin` -> `seniorsysadusers.employee_no`

The client cannot choose a Firestore collection, password field, document ID,
or recovery email.

## Environment

Required for the versioned recovery API:

- `FIREBASE_SERVICE_ACCOUNT_JSON` or
  `FIREBASE_SERVICE_ACCOUNT_JSON_BASE64`
- `OTP_RECOVERY_PEPPER`
- `OTP_RECOVERY_ALLOWED_ORIGINS`
- `SENDGRID_API_KEY`

Recovery mail branding:

- `SENIOR_RECOVERY_FROM_EMAIL`
- `SENIOR_RECOVERY_FROM_NAME`

Server secrets must never be committed or shipped to Angular.
