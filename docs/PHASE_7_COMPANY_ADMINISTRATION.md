# Phase 7 — Company and User Administration

## Scope
- Company-scoped member directory and access administration.
- Email invitations use single-use random tokens; only SHA-256 token hashes are stored.
- Seven-day expiry, revocation, and one-time acceptance.
- New invitees create accounts with a 12-character minimum password; existing users authenticate as the invited email before adding the tenant.
- Tenant-specific role and branch assignment, membership suspension/reactivation, and company-scoped audit history.
- Only a company owner may grant owner or administrator membership.
- A company must retain at least one active owner; users cannot suspend their own membership.
- Branch-restricted users cannot administer company-wide memberships.

## Security notes
- Invitation URLs are bearer credentials. Share privately.
- Tokens are random, stored as hashes, expire after seven days, and are invalidated after acceptance or revocation.
- Existing users must sign in with the invited email. Invitations do not grant platform-wide access.
- SMTP is required for email delivery. If transport fails, the administrator sees the one-time URL once to send securely by another channel.
- Audit history is restricted to the active company and roles with audit.view.
- PostgreSQL triggers block audit-event updates/deletes; this is not an external WORM service.

## Validation
- Feature tests cover branch role assignment, tenant boundaries, privilege-escalation denial, and last-owner protection.
- Verify SMTP, invitation expiry/revocation, existing-account acceptance, and MFA enforcement in the full PostgreSQL CI suite before production.
