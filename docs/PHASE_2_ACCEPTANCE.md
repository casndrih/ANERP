# Phase 2 acceptance checklist

- [ ] CI Compose configuration passes.
- [ ] Docker image builds successfully from a clean checkout.
- [ ] PostgreSQL migrations run from an empty database.
- [ ] First owner bootstrap creates one company and owner membership.
- [ ] Login succeeds with valid credentials and regenerates the session.
- [ ] Invalid login attempts are throttled.
- [ ] Unauthenticated requests are redirected to sign-in.
- [ ] A user cannot select a company without an active membership.
- [ ] Product list/create operations are restricted to the active company.
- [ ] Nginx can read the Laravel public directory through the shared app-code volume.
- [ ] Backup restore, HTTPS, MFA and security review are completed before production.
