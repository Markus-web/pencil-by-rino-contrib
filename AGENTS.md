# AGENTS.md - Pencil contribution fork

This public fork exists to prepare small, reviewable contributions for
[`rinothecoder/pencil-by-rino`](https://github.com/rinothecoder/pencil-by-rino).
It is not the Kotisivu.org production fork and must never contain customer
configuration, credentials, private branding, or deployment code.

## Repository map

| Path | Purpose |
|---|---|
| `pencil-by-rino.php` | Plugin bootstrap, metadata, constants, includes |
| `includes/` | PHP field storage, REST route, activity log, admin UI, helpers |
| `assets/` | Frontend Pencil editor assets |
| `admin/` | WordPress admin assets |
| `languages/` | Translation template and translations when present |
| `docs/` | Public plugin and audit documentation |
| `scripts/verify.sh` | Reusable local/CI quality gate |
| `scripts/check-workflow-pins.py` | Semantic YAML gate for immutable Actions dependencies |
| `scripts/fixtures/` | Tracked parser regressions used by the reusable gate |
| `.github/workflows/quality.yml` | PHP-version matrix running the same gate |

## Remotes and branches

- `origin`: `Markus-web/pencil-by-rino-contrib`
- Add upstream locally when needed:
  `git remote add upstream https://github.com/rinothecoder/pencil-by-rino.git`
- Keep `main` aligned conceptually with upstream plus fork-maintenance files.
- One upstream contribution per `pr/<topic>` branch, rebased from the current
  upstream `main`. Do not combine unrelated fixes into an existing PR.
- Never force-push upstream branches after review starts unless rebasing is
  necessary and the reason is recorded in the PR.

## Before committing or opening a PR

```bash
./scripts/verify.sh
git diff --check
git status --short
```

The gate checks PHP syntax, CSS structure, POT validity when `msgfmt` is
available, plugin metadata, accidental private branding, merge markers, and
immutable GitHub Action revisions. It requires Python 3 + PyYAML in addition
to PHP; CI installs the distro package explicitly.
For behavior changes, add focused WordPress regression tests when a test
harness exists; until then, document exact manual reproduction and proof in
the PR body.

## Security-sensitive changes

Follow [`SECURITY.md`](SECURITY.md). Do not publish exploit details for an
unfixed upstream issue. Keep security fixes isolated from cosmetic or feature
work and give the upstream maintainer a private review path first.

Current audit and PR assessment:
[`docs/audit-2026-09-30.md`](docs/audit-2026-09-30.md).

## Documentation upkeep

- Update `README.md` when installation, supported behavior, or public API changes.
- Update `CONTRIBUTING.md` when the fork workflow or required checks change.
- Update this file when paths, invariants, or agent commands change.
- Keep audit reports immutable except for a clearly dated remediation section.
- Do not claim a scanner completed when it stopped on a budget or coverage cap.
