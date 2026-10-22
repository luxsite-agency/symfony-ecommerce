#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
"""Стан теми паку проти бази рушія: bin/store theme status|update|prune|merge (E14 PR-4, критик консиліуму пп. 1–2).

Викликається з bin/store.d/theme.sh, deploy.sh і doctor.sh:
    _theme.py status <engine> <pack> [--summary] [--json]
    _theme.py update <engine> <pack> [--dry-run] [--ack-extends]
    _theme.py prune  <engine> <pack> [--dry-run]
    _theme.py merge  <engine> <pack> <файл>            3-way git merge-file -p (копія, база@engine, база@HEAD) у stdout

Три версії кожного файлу: B0 — база рушія на момент копії (git show <engine>:templates/<шлях>; якщо ref маніфесту не
знає такого файлу або хеш не збігається — пошук по історії файлу за sha256 з маніфесту), B1 — поточна база рушія
(робоча копія templates/), C — копія в паку (без службового заголовка {# Тема … #}, який пише theme new).
Класи (status):
    unchanged   база без змін (B1 == B0)
    auto        база змінилась, копія не чіпалась (C == B0) → theme update перепише копію на B1
    conflict    змінились обидві → ручне злиття: theme diff <пак> <файл> (3-way)
    extends     заготовка {% extends '@engine/…' %}: зміни бази підхоплюються самі; після зміни бази — theme diff (блоки);
                update не знімає це нагадування, поки не перевірено: theme update --ack-extends
    prunable    копія ідентична поточній базі → theme prune прибере, fallback рушія її підхопить
    removed     файл прибрано з бази (застаріло): у рушії такого шаблону більше нема
    added       новий шаблон у базі з версії engine (додати? theme new <пак> --copy <шлях>)
    untracked   файл є в паку й у рушії, але не в маніфесті (не через theme new): лише prune, якщо ідентичний базі
    own         власний partial паку, у рушії такого нема
    missing     у маніфесті є, у паку файлу нема → theme update прибере запис
Маніфест без поля або без маніфесту — попередження, не збій. Пак без themes/<key>/templates/ — «теми нема», код 0.
"""
import datetime
import hashlib
import json
import os
import re
import subprocess
import sys

import yaml

HEADER_RE = re.compile(r"\A\{#\s*Тема .*?#\}\r?\n", re.S)
TEMPLATE_ALLOWED = ("site/",)
TEMPLATE_FORBIDDEN = ("site/account/", "site/partials/_schema", "site/sitemap")
CLASS_LABEL = {
    "unchanged": "без змін у базі",
    "auto": "ОНОВИТИ автоматично (theme update)",
    "conflict": "РУЧНЕ ЗЛИТТЯ (theme diff <пак> <файл>)",
    "extends": "заготовка extends @engine: зміни бази підхоплюються",
    "prunable": "ідентична базі: можна прибрати (theme prune)",
    "removed": "ЗАСТАРІЛО: у рушії шаблону більше нема",
    "added": "новий у базі: додати? (theme new <пак> --copy <шлях>)",
    "untracked": "не в маніфесті (не через theme new)",
    "own": "власний partial паку",
    "missing": "у маніфесті є, файлу нема",
}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read(path: str) -> bytes:
    with open(path, "rb") as fh:
        return fh.read()


def strip_header(data: bytes) -> bytes:
    """Службовий заголовок копії/заготовки (theme new) не частина бази: копія без змін = база + заголовок."""
    text = data.decode("utf-8", errors="replace")
    m = HEADER_RE.match(text)
    return data if m is None else text[m.end():].encode("utf-8")


def header(data: bytes) -> str:
    m = HEADER_RE.match(data.decode("utf-8", errors="replace"))
    return "" if m is None else m.group(0)


def is_extends(data: bytes, rel: str) -> bool:
    body = re.sub(r"\{#.*?#\}", "", data.decode("utf-8", errors="replace"), flags=re.S)
    return re.search(r"""\{%-?\s*extends\s+['"]@engine/""" + re.escape(rel) + r"""['"]""", body) is not None


def git(engine: str, *args: str, check: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(["git", "-C", engine, *args], capture_output=True, check=check)


def resolve_ref(engine: str, ref: str):
    for candidate in (ref, "origin/" + ref):
        r = git(engine, "rev-parse", "--verify", "--quiet", candidate + "^{commit}", check=False)
        if r.returncode == 0:
            return r.stdout.decode().strip()
    return None


def show(engine: str, commit: str, rel: str):
    r = git(engine, "show", f"{commit}:templates/{rel}", check=False)
    return r.stdout if r.returncode == 0 else None


def base_at_copy(engine: str, base_commit, rel: str, want_hash):
    """B0: з коміту engine маніфесту; якщо хеш не той (маніфест оновлювали окремо) — та сама версія файлу з історії."""
    if base_commit is not None:
        data = show(engine, base_commit, rel)
        if data is not None and (want_hash is None or sha256(data) == want_hash):
            return data
    if want_hash is None:
        return None
    r = git(engine, "log", "--format=%H", "-n", "200", "--", f"templates/{rel}", check=False)
    for commit in r.stdout.decode().split():
        data = show(engine, commit, rel)
        if data is not None and sha256(data) == want_hash:
            return data
    return None


def engine_version(engine: str) -> str:
    r = git(engine, "describe", "--tags", "--exact-match", check=False)
    if r.returncode == 0:
        return r.stdout.decode().strip()
    return git(engine, "rev-parse", "--short=12", "HEAD").stdout.decode().strip()


class PackError(Exception):
    """store.yaml паку не читається — далі йти нема з чим."""


def load_store_yaml(pack: str) -> dict:
    path = os.path.join(pack, "store.yaml")
    try:
        with open(path, encoding="utf-8") as fh:
            meta = yaml.safe_load(fh) or {}
    except OSError as e:
        raise PackError(f"{path}: не читається ({e.strerror})") from e
    except yaml.YAMLError as e:
        raise PackError(f"{path}: зіпсований YAML ({str(e).splitlines()[0]})") from e
    return meta if isinstance(meta, dict) else {}


def pack_key(pack: str) -> str:
    return str(load_store_yaml(pack).get("key", ""))


def pack_engine(pack: str):
    return load_store_yaml(pack).get("engine")


def overridable(rel: str) -> bool:
    return rel.endswith(".twig") and rel.startswith(TEMPLATE_ALLOWED) and not rel.startswith(TEMPLATE_FORBIDDEN)


class Theme:
    def __init__(self, engine: str, pack: str):
        self.engine = os.path.abspath(engine)
        self.pack = os.path.abspath(pack)
        self.key = pack_key(self.pack)
        self.dir = os.path.join(self.pack, "themes", self.key)
        self.tpl = os.path.join(self.dir, "templates")
        self.manifest_path = os.path.join(self.dir, "theme.yaml")
        self.warnings = []
        self.manifest = {}
        self.files = {}
        self.base_commit = None
        self.ref = None

    def exists(self) -> bool:
        return bool(self.key) and os.path.isdir(self.tpl)

    def load_manifest(self):
        if os.path.isfile(self.manifest_path):
            try:
                with open(self.manifest_path, encoding="utf-8") as fh:
                    data = yaml.safe_load(fh)
            except yaml.YAMLError as e:
                data = None
                self.warnings.append(f"themes/{self.key}/theme.yaml: зіпсований YAML ({str(e).splitlines()[0]}) — працюємо як без маніфесту; update/prune перепишуть його")
            if isinstance(data, dict):
                self.manifest = data
            elif data is not None:
                self.warnings.append(f"themes/{self.key}/theme.yaml: не мапа — ігнорується")
        else:
            self.warnings.append(f"themes/{self.key}/theme.yaml: маніфесту нема (bin/store theme new) — база на момент копії невідома")
        files = self.manifest.get("files")
        if not isinstance(files, dict):
            if self.manifest:
                self.warnings.append(f"themes/{self.key}/theme.yaml: нема поля files — хеші бази невідомі")
            files = {}
        self.files = {str(k): (str(v) if isinstance(v, str) and re.match(r"^[0-9a-f]{64}$", v) else None) for k, v in files.items()}
        self.ref = self.manifest.get("engine") or pack_engine(self.pack)
        if not self.ref:
            self.warnings.append(f"themes/{self.key}/theme.yaml: нема поля engine (і в store.yaml теж) — база на момент копії лише за хешами")
        else:
            self.base_commit = resolve_ref(self.engine, str(self.ref))
            if self.base_commit is None:
                self.warnings.append(f"рушій не знає '{self.ref}' (git fetch --tags) — база на момент копії лише за хешами з маніфесту")

    def copies(self):
        out = []
        for dp, _dirs, names in os.walk(self.tpl):
            for f in sorted(names):
                p = os.path.join(dp, f)
                rel = os.path.relpath(p, self.tpl).replace(os.sep, "/")
                if rel in ("README.md", ".gitkeep") or os.path.islink(p):
                    continue
                out.append(rel)
        return sorted(out)

    def added_in_base(self):
        """Шаблони, що зʼявились у базі після версії engine маніфесту (лише ті, що можна перекрити)."""
        if self.base_commit is None:
            return []
        r = git(self.engine, "diff", "--name-status", "--diff-filter=A", self.base_commit, "HEAD", "--", "templates/site", check=False)
        added = []
        for line in r.stdout.decode().splitlines():
            parts = line.split("\t")
            if len(parts) >= 2 and parts[1].startswith("templates/"):
                rel = parts[1][len("templates/"):]
                if overridable(rel):
                    added.append(rel)
        return added

    def status(self):
        """list of dict(file, cls, base_now, base_then, copy, note)."""
        rows = []
        copies = self.copies()
        for rel in copies:
            cpath = os.path.join(self.tpl, rel)
            epath = os.path.join(self.engine, "templates", rel)
            c_raw = read(cpath)
            c = strip_header(c_raw)
            b1 = read(epath) if os.path.isfile(epath) else None
            h0 = self.files.get(rel) if rel in self.files else "absent"
            row = {"file": rel, "copy": c_raw, "base_now": b1, "base_then": None, "note": ""}
            if b1 is None:
                row["cls"] = "removed" if rel in self.files else "own"
                rows.append(row)
                continue
            if is_extends(c_raw, rel):
                row["cls"] = "extends"
                if h0 not in ("absent", None) and sha256(b1) != h0:
                    row["note"] = "база змінилась: перевірте блоки (theme diff)"
                rows.append(row)
                continue
            if h0 == "absent":
                row["cls"] = "prunable" if c == b1 else "untracked"
                rows.append(row)
                continue
            b0 = base_at_copy(self.engine, self.base_commit, rel, h0)
            row["base_then"] = b0
            if c == b1:
                row["cls"] = "prunable"
            elif h0 is not None and sha256(b1) == h0:
                row["cls"] = "unchanged"
            elif b0 is None:
                row["cls"] = "conflict"
                row["note"] = "базу на момент копії не відтворено (нема в історії рушія) — порівнюйте з поточною базою"
            elif c == b0:
                row["cls"] = "auto"
            else:
                row["cls"] = "conflict"
            rows.append(row)
        for rel in sorted(set(self.files) - set(copies)):
            rows.append({"file": rel, "cls": "missing", "copy": None, "base_now": None, "base_then": None, "note": ""})
        for rel in self.added_in_base():
            if rel not in copies:
                rows.append({"file": rel, "cls": "added", "copy": None, "base_now": None, "base_then": None, "note": ""})
        return rows

    def write_manifest(self, files: dict, bump_engine: bool):
        data = dict(self.manifest)
        data.update({"format": 1, "key": self.key, "from": data.get("from") or "engine",
                     "engine": engine_version(self.engine) if bump_engine else (data.get("engine") or engine_version(self.engine)),
                     "contract": data.get("contract") or 1, "created": data.get("created") or datetime.date.today().isoformat(),
                     "files": dict(sorted(files.items()))})
        ordered = {k: data[k] for k in ("format", "key", "from", "engine", "contract", "created", "files")}
        for k, v in data.items():
            ordered.setdefault(k, v)
        with open(self.manifest_path, "w", encoding="utf-8") as fh:
            fh.write("# Маніфест теми паку (bin/store theme): from/engine — звідки й з якої версії рушія зроблено заготовки й копії,\n"
                     "# contract — версія контракту шаблонів (docs/themes.md), files — sha256 базових файлів рушія на момент копії.\n"
                     "# Пише bin/store theme new/update/prune, читає theme status/diff і bin/store validate. Руками не правити.\n")
            yaml.safe_dump(ordered, fh, allow_unicode=True, sort_keys=False)


def summary_counts(rows):
    counts = {}
    for r in rows:
        counts[r["cls"]] = counts.get(r["cls"], 0) + 1
    return counts


def print_status(theme: Theme, rows, as_json: bool):
    if as_json:
        print(json.dumps({"key": theme.key, "engine": theme.ref, "warnings": theme.warnings,
                          "files": [{"file": r["file"], "class": r["cls"], "note": r["note"]} for r in rows]}, ensure_ascii=False, indent=1))
        return
    head = git(theme.engine, "rev-parse", "--short=12", "HEAD").stdout.decode().strip()
    base = f"{theme.ref} ({theme.base_commit[:12]})" if theme.base_commit else f"{theme.ref or '?'} (невідомий рушію)"
    print(f"тема {theme.key}: база на момент копії {base} → рушій HEAD {head}")
    for w in theme.warnings:
        print(f"  !! {w}")
    width = max([len(r["file"]) for r in rows] + [20])
    for r in rows:
        line = f"  {r['file']:<{width}}  {r['cls']:<10} {CLASS_LABEL[r['cls']]}"
        if r["note"]:
            line += f" — {r['note']}"
        print(line)
    c = summary_counts(rows)
    print("разом: " + ", ".join(f"{k} {v}" for k, v in sorted(c.items())) if rows else "разом: файлів нема")


def cmd_status(theme: Theme, args):
    theme.load_manifest()
    rows = theme.status()
    if "--summary" in args:
        c = summary_counts(rows)
        behind = c.get("auto", 0) + c.get("conflict", 0) + c.get("removed", 0)
        if behind or c.get("conflict", 0):
            print(f"тема {theme.key} відстала від рушія на {behind} файлів, {c.get('conflict', 0)} конфліктів "
                  f"(auto {c.get('auto', 0)}, removed {c.get('removed', 0)}; bin/store theme status <пак>)")
            return 2
        print(f"тема {theme.key}: копії узгоджені з рушієм ({len(rows)} файлів)")
        return 0
    print_status(theme, rows, "--json" in args)
    return 0


def cmd_update(theme: Theme, args):
    dry = "--dry-run" in args
    theme.load_manifest()
    rows = theme.status()
    files = dict(theme.files)
    ack_extends = "--ack-extends" in args
    updated, conflicts, dropped, check_blocks = [], [], [], []
    version = engine_version(theme.engine)
    for r in rows:
        rel, cls = r["file"], r["cls"]
        if cls == "auto":
            new = r["base_now"]
            hdr = header(r["copy"])
            if hdr:
                hdr = re.sub(r"\(рушій [^)]*\)", f"(рушій {version})", hdr)
            if not dry:
                with open(os.path.join(theme.tpl, rel), "wb") as fh:
                    fh.write(hdr.encode("utf-8") + new)
            files[rel] = sha256(new)
            updated.append(rel)
        elif cls == "extends" and r["note"] and not ack_extends:
            check_blocks.append(rel)  # база заготовки змінилась: хеш лишається, нагадування «перевірте блоки» не знімається
        elif cls in ("unchanged", "extends", "prunable", "untracked"):
            if r["base_now"] is not None and (cls != "untracked" or rel in files):
                files[rel] = sha256(r["base_now"])
        elif cls == "conflict":
            conflicts.append(rel)  # хеш бази на момент копії лишається: 3-way потребує старої бази
        elif cls == "missing":
            files.pop(rel, None)
            dropped.append(rel)
        elif cls == "removed":
            pass  # копія лишається (власний шаблон тепер), запис у маніфесті теж — status покаже «застаріло»
    prefix = "[dry-run] " if dry else ""
    for rel in updated:
        print(f"{prefix}оновлено з бази: {rel}")
    for rel in dropped:
        print(f"{prefix}прибрано з маніфесту (файлу нема): {rel}")
    for rel in conflicts:
        print(f"{prefix}КОНФЛІКТ, лишено як є: {rel} → bin/store theme diff {theme.pack} {rel}")
    for rel in check_blocks:
        print(f"{prefix}ПЕРЕВІРТЕ БЛОКИ заготовки (база змінилась): {rel} → bin/store theme diff {theme.pack}; потім theme update --ack-extends")
    if not dry:
        theme.write_manifest(files, bump_engine=not conflicts and not check_blocks)
    note = "" if not conflicts and not check_blocks else " (engine у маніфесті не піднято: конфліктні файли й неперевірені заготовки ще проти старої бази)"
    print(f"{prefix}оновлено {len(updated)}, конфліктів {len(conflicts)}, перевірити блоки {len(check_blocks)}, маніфест {'оновлено' if not dry else 'буде оновлено'}{note}")
    return 0


def cmd_prune(theme: Theme, args):
    dry = "--dry-run" in args
    theme.load_manifest()
    rows = theme.status()
    files = dict(theme.files)
    pruned = []
    for r in rows:
        if r["cls"] != "prunable":
            continue
        rel = r["file"]
        if not dry:
            os.remove(os.path.join(theme.tpl, rel))
            d = os.path.dirname(os.path.join(theme.tpl, rel))
            while d != theme.tpl and os.path.isdir(d) and not os.listdir(d):
                os.rmdir(d)
                d = os.path.dirname(d)
        files.pop(rel, None)
        pruned.append(rel)
    prefix = "[dry-run] " if dry else ""
    for rel in pruned:
        print(f"{prefix}прибрано (ідентична базі, fallback рушія): {rel}")
    if pruned and not dry:
        theme.write_manifest(files, bump_engine=False)
    print(f"{prefix}прибрано {len(pruned)} з {len([r for r in rows if r['cls'] not in ('added', 'missing')])}")
    return 0


def cmd_merge(theme: Theme, args):
    rel = next((a for a in args if not a.startswith("--")), None)
    if not rel:
        print("theme merge: вкажіть файл (шлях відносно templates/)", file=sys.stderr)
        return 1
    rel = rel.removeprefix("templates/")
    theme.load_manifest()
    row = next((r for r in theme.status() if r["file"] == rel), None)
    if row is None:
        print(f"{rel}: у темі такого файлу нема", file=sys.stderr)
        return 1
    if row["cls"] != "conflict" or row["base_then"] is None:
        print(f"{rel}: {row['cls']} — {CLASS_LABEL[row['cls']]}; 3-way лише для conflict з відомою базою", file=sys.stderr)
        return 0 if row["cls"] != "conflict" else 1
    import tempfile
    with tempfile.TemporaryDirectory() as tmp:
        paths = {}
        for name, data in (("copy", strip_header(row["copy"])), ("base0", row["base_then"]), ("base1", row["base_now"])):
            paths[name] = os.path.join(tmp, name)
            with open(paths[name], "wb") as fh:
                fh.write(data)
        r = subprocess.run(["git", "merge-file", "-p", "-L", f"копія паку: {rel}", "-L", f"база рушія @{theme.ref}", "-L", "база рушія @HEAD",
                            paths["copy"], paths["base0"], paths["base1"]], capture_output=True)
    sys.stdout.write(r.stdout.decode("utf-8", errors="replace"))
    sys.stdout.flush()
    if r.returncode > 0:
        print(f"# конфліктів: {r.returncode} — маркери <<<<<<< вище; нічого не записано, злийте руками в themes/{theme.key}/templates/{rel}", file=sys.stderr)
        return min(r.returncode, 127)  # код ≠ 0: `theme diff … > файл && theme update` при конфлікті не піде далі
    print(f"# злилось чисто (код 0): збережіть вивід у themes/{theme.key}/templates/{rel} і запустіть theme update", file=sys.stderr)
    return 0


def main(argv):
    # Windows git bash: stdout у pipe ($(…) у deploy/doctor) бере кодування локалі (cp1251) — кирилиця падала б з UnicodeEncodeError
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(encoding="utf-8", errors="replace")
    if len(argv) < 3:
        print(__doc__, file=sys.stderr)
        return 1
    mode, engine, pack, *args = argv
    try:
        theme = Theme(engine, pack)
    except PackError as e:
        print(f"theme: {e}", file=sys.stderr)
        return 1
    if not theme.key:
        print(f"theme: у {pack}/store.yaml нема key", file=sys.stderr)
        return 1
    if not theme.exists():
        if "--summary" in args:
            return 0
        print(f"{theme.key}: у паку нема themes/{theme.key}/templates/ — своєї теми нема, порівнювати нічого")
        return 0
    if mode not in ("status", "update", "prune", "merge"):
        print(f"theme: невідомий режим {mode}", file=sys.stderr)
        return 1
    return {"status": cmd_status, "update": cmd_update, "prune": cmd_prune, "merge": cmd_merge}[mode](theme, args)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
