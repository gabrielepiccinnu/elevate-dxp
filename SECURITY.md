# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x (development, `main`) | Yes |

Until 1.0.0 is released, fixes are made on `main` only.

## Reporting a vulnerability

Please report vulnerabilities **privately** through GitHub security advisories:

1. Open the repository on GitHub.
2. Go to **Security → Advisories → Report a vulnerability**.
3. Describe the issue.

Do not open a public issue, pull request or discussion for a security problem, and do not disclose it publicly before a fix is available.

Please include:

- affected version or commit, OpenDXP version and PHP version;
- the affected module and endpoint, command or admin resource;
- steps to reproduce or a proof of concept;
- the impact as you understand it (for example data exposure, privilege escalation, injection);
- whether the issue requires an authenticated admin user, and which permissions.

## What to expect

- Acknowledgement within 5 working days.
- An initial assessment, and questions if needed, within 10 working days.
- A fix, a GitHub security advisory and a CHANGELOG entry under "Security" once the fix is released. You will be credited unless you prefer otherwise.

This is a community project maintained on a best-effort basis; these are targets, not guarantees.

## Scope

In scope: code in this repository (`src/`, `config/`, `public/`, `templates/`).

Out of scope, please report upstream:

- OpenDXP core, `open-dxp/admin-bundle`, `open-dxp/personalization-bundle` and other OpenDXP bundles: see the [OpenDXP security policy](https://github.com/open-dxp/opendxp/blob/1.x/SECURITY.md).
- Third-party dependencies (Symfony, Guzzle, Twig, ...): their own security contacts.

Misconfiguration (for example committed secrets, `webhook.sink_enabled: true` in production, permissions granted to everyone) is not a vulnerability of this project, but tell us if the documentation is misleading.

## Hardening guidance

See [docs/security-and-privacy.md](docs/security-and-privacy.md).
