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
- The original role_code foundation has since been extended with a deny-by-default permission matrix, company invitations and membership administration, branch-scoped access, TOTP MFA for privileged roles, append-only audit events and maker-checker controls across finance, payroll and procurement.

## Remaining acceptance work\n- Password reset and account recovery workflows are not implemented.\n- Complete route-by-route authorization coverage and automated tests for every sensitive object, export and mutation.\n- Verify session revocation, MFA recovery-code lifecycle, invitation expiry/replay protection and account suspension behavior.\n- Run the acceptance checklist on the exact integrated release commit.\n- Independent penetration testing and security certification are not provided by CI.
