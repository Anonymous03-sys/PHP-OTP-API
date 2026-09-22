# PHP-OTP-API

This service currently supports two isolated API surfaces.

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

At OTP-1 these endpoints are scaffolds only and intentionally return
`RECOVERY_NOT_IMPLEMENTED`. No Senior E-Services application should depend on
them until the later OTP stages are completed and verified.

`GET /health.php` provides a non-secret service health response.

### Recovery CORS

The new versioned endpoints read approved browser origins from the optional
`OTP_RECOVERY_ALLOWED_ORIGINS` environment variable as a comma-separated list.
The legacy endpoints keep their existing behavior unchanged.
