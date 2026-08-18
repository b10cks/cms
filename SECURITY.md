# Security Policy

b10cks CMS is a multi-tenant headless CMS. It manages content, assets, and user access across isolated tenant spaces. We treat authentication, authorization, tenant isolation, data integrity, and release integrity as security-sensitive areas.

## Supported Versions

Security fixes are guaranteed for the latest CalVer release published on the `main` branch. Older releases do not receive backported security patches — users are encouraged to stay on the latest release.

| Release     | Status          |
|-------------|-----------------|
| Latest      | Supported       |
| Older       | Not supported   |

## Reporting a Vulnerability

**Do not open a public GitHub issue for an unpatched vulnerability.**

Report suspected security issues through one of the following channels (in order of preference):

1. **GitHub Security Advisories** — use the private reporting feature:
   [Submit a security advisory](../../security/advisories/new)

2. **Email** — `security@b10cks.com`
   Subject line: `b10cks security report`

Please include as much of the following as possible:

- b10cks version (CalVer release tag, e.g. `v2026.3.24-abc1234`)
- Environment: self-hosted / managed, PHP version, database engine
- Affected component (API, auth, tenant isolation, asset handling, webhooks, etc.)
- Reproduction steps or proof of concept
- Impact summary: what data or access is at risk and for whom
- Your estimated CVSS 3.1 score (optional — we may adjust)

We do not require a CVE request from reporters; we handle that as part of the advisory process.

## Response Expectations

- Acknowledgement within **72 hours**.
- Status update within **14 calendar days** if a fix is not yet available.
- We coordinate public disclosure after a fix or mitigation is ready.
- Credit is given to reporters in the advisory and release notes (with your consent).

## Disclosure Policy

Once a fix is prepared:

1. A patch release is issued (tagged with the current CalVer).
2. Release notes include a high-level warning that a security issue was addressed.
3. Detailed disclosure is delayed **2–8 weeks** depending on severity, giving operators time to upgrade.
4. The GitHub Security Advisory and CVE (if applicable) are published after the waiting period.

Please contact us before any public disclosure to avoid releasing information before operators can patch.

## What We Consider a Security Issue

Examples of security-relevant issues:

- Authentication or session bypass
- Tenant isolation bypass (accessing another tenant's data or spaces)
- Authorization flaws (privilege escalation, missing permission checks)
- Injection vulnerabilities (SQL, command, template, SSRF)
- Insecure direct object references
- Path traversal or unsafe file handling in asset management
- Webhook signature bypass or replay attacks
- Unsafe deserialization or mass assignment
- Sensitive data exposure (API keys, tokens, user PII)
- Release or package integrity issues (tampered artifacts, supply chain)

## What Usually Does Not Qualify

The following are typically normal bugs or feature requests rather than security issues:

- Cosmetic UI defects
- Performance issues without a plausible security impact
- Space Data API delivery tokens stored and listed in plaintext (operators copy them from Settings after creation; documented product behavior)
- Misconfiguration by an authorized administrator that is documented behavior
- Dependency version bumps (unless they introduce a new vulnerability surface in b10cks)
- End-of-life environment compatibility (unsupported PHP/DB versions)

If you are unsure whether something qualifies, report it privately first — we would rather triage a non-issue than miss a real one.

## Security-Focused Areas

The project pays particular attention to:

- **Tenant isolation**: per-space database connections and scoped queries
- **Authentication & authorization**: API token validation, role checks, space membership
- **Webhooks**: signature verification (e.g. LemonSqueezy webhook validation)
- **Asset handling**: upload validation, path traversal prevention
- **Job/queue security**: data passed to background jobs, progress reporting
- **Release integrity**: CalVer tagged releases, signed commits

## Other Platforms

We do not monitor third-party vulnerability disclosure platforms. All reports must come through GitHub Security Advisories or `security@b10cks.com`. Direct messages to maintainers on other channels (especially social media) are not a supported reporting path.
