# Contributing from this fork

This fork prepares narrowly scoped pull requests for the upstream Pencil by
Rino project. Upstream ownership and product decisions remain with Rino de Boer.

## Workflow

```bash
git remote add upstream https://github.com/rinothecoder/pencil-by-rino.git  # once
git fetch --all --prune
git switch -c pr/short-topic upstream/main
# make one cohesive change
./scripts/verify.sh
git push -u origin pr/short-topic
gh pr create --repo rinothecoder/pencil-by-rino --base main
```

Keep each branch to one concern. Explain the observed problem, why the chosen
layer owns the fix, compatibility impact, and how the result was verified.
Avoid references to private customer infrastructure in source or examples.

## Required checks

`./scripts/verify.sh` is the local and CI source of truth. It requires PHP and
uses `msgfmt` when gettext is installed. GitHub Actions runs it on PHP 7.4 (the
declared minimum), 8.2, and 8.4.

Changes to persistence, REST authorization, or activity history also need a
regression scenario covering:

- the least-privileged supported editor role;
- multisite/current-blog isolation where applicable;
- concurrent or repeated writes when a whole option is updated;
- both accepted and rejected payload boundaries;
- direct URL/API access, not only hidden navigation.

## Pull-request hygiene

- Keep the author identity on the GitHub noreply address.
- Do not commit generated build directories, local WordPress state, credentials,
  scanner workspaces, or customer-specific patches.
- Use Conventional Commit subjects.
- Do not mark a static-only security observation as exploited or proven.
- Coordinate confirmed upstream vulnerabilities privately as described in
  [`SECURITY.md`](SECURITY.md).
