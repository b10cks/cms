# CLAUDE.md

Laravel 12 + Vue 3 CMS. Agent conventions for this repo.

## Delivery tokens stay plaintext

Space Data API tokens (`blx_…` in `tokens.token`) are stored and listed in plaintext so operators can copy an existing token from **Settings → Access tokens** after creation. `TokenResource` returns `token` on list/show; `findValidToken()` looks up the raw value.

This is a product choice, not an oversight. Personal access tokens (Sanctum / Management API) are hashed and shown once. Delivery tokens are not.

A leaked delivery token is read access to that space's published content, and to drafts if it carries `preview`. Rotation and revoke live in Settings. Hashing would break copy-later and the `?token=` CDN cache key.

Reviews leave this as accepted. Do not hash delivery tokens, hide them after create, or file "plaintext token" findings.

## Other pointers

- Frontend: Vue 3, TypeScript, TanStack Query, Tailwind, i18n (`en`/`de`).
- Backend: actions + FormRequests + policies + resources. Space membership is fail-closed.
- Tests: targeted over suite-wide. PHPUnit for PHP, Vitest for `tests/js`.
