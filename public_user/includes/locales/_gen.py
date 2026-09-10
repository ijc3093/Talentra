#!/usr/bin/env python3
"""Generate Settings locale PHP files from the English keys in tr.php."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def load_keys() -> list[str]:
    keys: list[str] = []
    for line in (ROOT / "tr.php").read_text(encoding="utf-8").splitlines():
        m = re.match(r"\s*'((?:\\'|[^'])*)'\s*=>", line)
        if m:
            keys.append(m.group(1).replace("\\'", "'"))
    return keys


def php_escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace("'", "\\'")


def write_locale(code: str, mapping: dict[str, str], keys: list[str]) -> None:
    missing = [k for k in keys if k not in mapping]
    extra = [k for k in mapping if k not in keys]
    if missing or extra:
        raise SystemExit(f"{code}: missing {len(missing)} extra {len(extra)} first_missing={missing[:3]}")
    lines = ["<?php", "declare(strict_types=1);", "", "return ["]
    for k in keys:
        lines.append(f"    '{php_escape(k)}' => '{php_escape(mapping[k])}',")
    lines.append("];")
    lines.append("")
    (ROOT / f"{code}.php").write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    keys = load_keys()
    from _translations import LOCALES

    for code, mapping in sorted(LOCALES.items()):
        write_locale(code, mapping, keys)
        print(f"wrote {code}.php")
    print(f"done {len(LOCALES)} locales, {len(keys)} strings")


if __name__ == "__main__":
    main()
