#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
"""Шапка SPDX у файлах рушія: PHP у src/ і bin/ (після <?php), shell/python у bin/ (після shebang або директиви shellcheck).

    python3 tools/spdx-headers.py           # дописати шапку туди, де її нема
    python3 tools/spdx-headers.py --check   # лише перелічити файли без шапки; код 1, якщо такі є

Файли з рецептів Symfony (bin/console, bin/phpunit) не чіпаємо: це не наш код. Береться лише те, що відстежує git.
"""
import subprocess
import sys

SPDX = 'SPDX-License-Identifier: Apache-2.0'
COPYRIGHT = 'Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.'
SKIP = {'bin/console', 'bin/phpunit'}


def files() -> list[str]:
    out = subprocess.run(['git', 'ls-files', '-z', 'src', 'bin'], check=True, capture_output=True).stdout
    return [p for p in out.decode().split('\0') if p and p not in SKIP]


def kind(path: str, text: str) -> str | None:
    first = text.split('\n', 1)[0]
    if path.endswith('.php') or text.startswith('<?php'):
        return 'php' if text.startswith('<?php') else None
    if path.endswith(('.sh', '.py')) or first.startswith('#!') or first.startswith('# shellcheck shell='):
        return 'hash'
    return None


def with_header(k: str, text: str) -> str:
    if k == 'php':
        rest = text[len('<?php'):].lstrip('\n')
        return f'<?php\n\n// {SPDX}\n// {COPYRIGHT}\n\n{rest}'
    lines = text.split('\n')
    at = 1 if lines and (lines[0].startswith('#!') or lines[0].startswith('# shellcheck shell=')) else 0
    return '\n'.join(lines[:at] + [f'# {SPDX}', f'# {COPYRIGHT}'] + lines[at:])


def main() -> int:
    check = '--check' in sys.argv[1:]
    missing = []
    for path in files():
        with open(path, encoding='utf-8') as f:
            text = f.read()
        k = kind(path, text)
        if k is None or SPDX in text[:500]:
            continue
        missing.append(path)
        if not check:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(with_header(k, text))
    print(('без шапки' if check else 'дописано') + f': {len(missing)}')
    for path in missing if check else []:
        print('  ' + path)
    return 1 if check and missing else 0


if __name__ == '__main__':
    sys.exit(main())
