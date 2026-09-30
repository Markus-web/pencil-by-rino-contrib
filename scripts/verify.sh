#!/usr/bin/env bash
# Reusable local/CI source gate for the public Pencil contribution fork.
# Usage: ./scripts/verify.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo '== PHP syntax =='
while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null
	printf 'OK %s\n' "$file"
done < <(find . -type f -name '*.php' -not -path './.git/*' -print0 | sort -z)

echo '== plugin metadata =='
grep -qF 'Requires PHP: 7.4' pencil-by-rino.php
grep -qF 'License: GPLv2 or later' pencil-by-rino.php
header_version="$(sed -n 's/^ \* Version: //p' pencil-by-rino.php | head -1)"
constant_version="$(sed -n "s/^define( 'PENCIL_VERSION', '\([^']*\)' );$/\1/p" pencil-by-rino.php | head -1)"
[[ -n "$header_version" && "$header_version" == "$constant_version" ]]
printf 'OK version %s\n' "$header_version"

echo '== CSS structure =='
python3 - <<'PY'
from pathlib import Path

for path in (Path("admin/css/admin.css"), Path("assets/css/editor.css")):
    text = path.read_text(encoding="utf-8")
    assert text.count("{") == text.count("}"), f"unbalanced braces: {path}"
    assert text.count("(") == text.count(")"), f"unbalanced parentheses: {path}"
    print(f"OK {path}")
PY

echo '== translation template =='
if [[ -f languages/pencil-by-rino.pot ]]; then
	if command -v msgfmt >/dev/null 2>&1; then
		# POT templates intentionally retain empty translator/language headers.
		msgfmt --check-format -o /dev/null languages/pencil-by-rino.pot
		echo 'OK languages/pencil-by-rino.pot'
	else
		echo 'SKIP msgfmt unavailable'
	fi
else
	echo 'SKIP no POT on this branch'
fi

echo '== repository hygiene =='
private_scan_paths=(pencil-by-rino.php includes assets admin docs/agent-instructions.md)
[[ -d languages ]] && private_scan_paths+=(languages)
if rg -n -i 'kotisivu|markus media|markusmedia' \
	"${private_scan_paths[@]}"; then
	echo 'Private customer/brand reference found in contribution source.' >&2
	exit 1
fi
if rg -n '^(<<<<<<<|=======|>>>>>>>)' --glob '!LICENSE' .; then
	echo 'Merge conflict marker found.' >&2
	exit 1
fi
git diff --check
echo 'VERIFY_OK'
