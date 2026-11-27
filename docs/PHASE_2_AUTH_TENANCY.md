# Phase 2 — authentication and company tenancy

This phase adds session-based sign-in/out, a per-email/IP login throttle, no public self-registration, a one-time CLI bootstrap command and company membership selection.

## First-time bootstrap
After migrations have run, execute:
`docker compose exec app php artisan anerp:install-admin`
The exact command name is `anerp:install-admin`. Create the first company and owner account interactively. Do not place credentials in source control or deployment environment files.

## Tenant rules
- Authenticated workspace requests pass EnsureCompanyAccess.
- Active company is selected only from the authenticated user's active memberships.
- Future business tables must carry company_id and authorize every read/write against the active company.
- Branch access must be checked independently for branch-scoped operations.
- Current role_code is a foundation only; granular permissions and maker-checker workflows are still required.

Not yet included: MFA, password reset, complete RBAC, full audit coverage, automated cross-tenant tests or a security certification. Complete these before production.
