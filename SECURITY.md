# Security policy for the contribution fork

This repository is a public contribution fork. The canonical product and its
release policy belong to [`rinothecoder/pencil-by-rino`](https://github.com/rinothecoder/pencil-by-rino).

## Reporting

For a suspected vulnerability:

1. Do not open a public issue or publish a working exploit while upstream is
   affected.
2. Contact the upstream maintainer through GitHub's private vulnerability
   reporting channel when available, or another private channel they publish.
3. Provide affected versions, prerequisites, impact, minimal reproduction,
   proposed remediation, and a regression test.
4. Open a public PR only after the maintainer agrees disclosure is appropriate.

Do not include Kotisivu.org customer data, credentials, hostnames, or private
deployment details in a report or patch.

## Supported code

The fork's `main` follows upstream and is not an independently supported
release channel. Security review covers the current `main` plus active
`pr/*` contribution branches. A clean static scan is not a guarantee of
security; authorization and persistence changes require behavior-level proof.

The latest retained assessment is
[`docs/audit-2026-09-30.md`](docs/audit-2026-09-30.md).
