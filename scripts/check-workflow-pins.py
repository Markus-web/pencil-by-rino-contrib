#!/usr/bin/env python3
"""Require immutable dependencies in GitHub Actions workflows.

Remote repository actions and reusable workflows require a 40-character Git
commit. Local actions are repository-owned. Docker actions require a SHA-256
image digest. YAML is parsed semantically so alternate valid key spellings and
flow mappings cannot bypass the check.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path
from typing import Any

try:
    import yaml
    from yaml.constructor import ConstructorError
except ModuleNotFoundError:
    print(
        "PyYAML is required (Debian/Ubuntu: apt install python3-yaml).",
        file=sys.stderr,
    )
    raise SystemExit(2)


REMOTE_PIN = re.compile(r"^.+@[0-9a-f]{40}$")
DOCKER_PIN = re.compile(r"^docker://.+@sha256:[0-9a-f]{64}$")


class UniqueKeyLoader(yaml.SafeLoader):
    """SafeLoader that rejects ambiguous duplicate mapping keys."""


def construct_unique_mapping(
    loader: UniqueKeyLoader, node: yaml.MappingNode, deep: bool = False
) -> dict[Any, Any]:
    loader.flatten_mapping(node)
    mapping: dict[Any, Any] = {}
    for key_node, value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        try:
            duplicate = key in mapping
        except TypeError as error:
            raise ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                "found an unhashable key",
                key_node.start_mark,
            ) from error
        if duplicate:
            raise ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                f"found duplicate key {key!r}",
                key_node.start_mark,
            )
        mapping[key] = loader.construct_object(value_node, deep=deep)
    return mapping


UniqueKeyLoader.add_constructor(
    yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG,
    construct_unique_mapping,
)


def find_uses(node: Any, location: str = "$") -> list[tuple[str, Any]]:
    found: list[tuple[str, Any]] = []
    if isinstance(node, dict):
        for key, value in node.items():
            child = f"{location}.{key}"
            if key == "uses":
                found.append((child, value))
            found.extend(find_uses(value, child))
    elif isinstance(node, list):
        for index, value in enumerate(node):
            found.extend(find_uses(value, f"{location}[{index}]"))
    return found


def validate_ref(ref: Any) -> str | None:
    if not isinstance(ref, str) or not ref:
        return "uses value must be a non-empty string"
    if ref.startswith("./"):
        return None
    if ref.startswith("docker://"):
        return None if DOCKER_PIN.fullmatch(ref) else "Docker action must use @sha256:<64 hex>"
    if REMOTE_PIN.fullmatch(ref):
        return None
    return "remote action/workflow must use an exact 40-character Git commit"


def check_file(path: Path) -> tuple[list[str], list[str]]:
    try:
        data = yaml.load(path.read_text(encoding="utf-8"), Loader=UniqueKeyLoader)
    except (OSError, yaml.YAMLError) as error:
        return [], [f"{path}: invalid YAML: {error}"]
    refs: list[str] = []
    errors: list[str] = []
    for location, ref in find_uses(data):
        rendered = ref if isinstance(ref, str) else repr(ref)
        refs.append(rendered)
        problem = validate_ref(ref)
        if problem:
            errors.append(f"{path}:{location}: {problem}: {rendered}")
    return refs, errors


def workflow_files(inputs: list[Path]) -> list[Path]:
    files: set[Path] = set()
    for item in inputs:
        if item.is_dir():
            files.update(item.rglob("*.yml"))
            files.update(item.rglob("*.yaml"))
        elif item.suffix in {".yml", ".yaml"}:
            files.add(item)
    return sorted(files)


def self_test(root: Path) -> list[str]:
    valid = root / "workflow-uses-valid.yml"
    bypass = root / "workflow-uses-bypass.yaml"
    valid_refs, valid_errors = check_file(valid)
    bypass_refs, bypass_errors = check_file(bypass)
    expected_valid = {
        "owner/pinned@0123456789abcdef0123456789abcdef01234567",
        "./.github/actions/local",
        "docker://example/image@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
    }
    expected_bypass = {
        "owner/spaced@v1",
        "owner/quoted@v2",
        "owner/flow@main",
        "owner/reusable/.github/workflows/check.yaml@main",
    }
    errors: list[str] = []
    if valid_errors or set(valid_refs) != expected_valid:
        errors.append(f"valid workflow fixture mismatch: refs={valid_refs}, errors={valid_errors}")
    if set(bypass_refs) != expected_bypass or len(bypass_errors) != len(expected_bypass):
        errors.append(f"bypass workflow fixture mismatch: refs={bypass_refs}, errors={bypass_errors}")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("paths", nargs="+", type=Path)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()

    errors: list[str] = []
    if args.self_test:
        errors.extend(self_test(Path(__file__).parent / "fixtures"))

    files = workflow_files(args.paths)
    if not files:
        errors.append("no .yml/.yaml workflows found")
    refs_found = 0
    for path in files:
        refs, file_errors = check_file(path)
        refs_found += len(refs)
        errors.extend(file_errors)
        if not file_errors:
            for ref in refs:
                print(f"OK {ref}")
    if refs_found == 0:
        errors.append("no uses keys found in workflows")

    if errors:
        for error in errors:
            print(f"FAIL {error}", file=sys.stderr)
        return 1
    if args.self_test:
        print("OK semantic YAML bypass fixtures")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
