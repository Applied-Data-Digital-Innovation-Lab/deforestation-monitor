#!/usr/bin/env python3
"""
Move the web layer into the layout the delivery guide asks for.

    web/              -> app/
    web/data.php      -> app/includes/data.php
    web/partials.php  -> app/includes/partials.php
    config/           -> app/config/

config has to end up inside app/, or the delivery ZIP carries no template and
the install has nothing to copy.

Pages stay at the root of app/ rather than moving into pages/, and assets are
not split into css/js/images. Both are subdivisions the guide draws but does
not require, and both would change public URLs or asset paths for no gain.

Everything moves with git mv, so the history follows. Nothing is written
unless every patch below applies, and the checks at the end confirm that every
require and every asset still resolves.

    python tools/restructure.py
"""

import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path.cwd()

# Applied after the moves. (file, old, new)
PATCHES = [
    # The pages are one level above the includes now.
    ("app/api.php", "require __DIR__ . '/data.php';",
     "require __DIR__ . '/includes/data.php';"),
    ("app/report.php", "require __DIR__ . '/data.php';",
     "require __DIR__ . '/includes/data.php';"),
    ("app/index.php", "require __DIR__ . '/partials.php';",
     "require __DIR__ . '/includes/partials.php';"),
    ("app/map.php", "require __DIR__ . '/partials.php';",
     "require __DIR__ . '/includes/partials.php';"),
    ("app/history.php", "require __DIR__ . '/partials.php';",
     "require __DIR__ . '/includes/partials.php';"),
    ("app/territory.php", "require __DIR__ . '/partials.php';",
     "require __DIR__ . '/includes/partials.php';"),

    # dm_asset emits a URL relative to the page, which stays right, but reads
    # the file itself to stamp it. From includes/ that read would miss and the
    # stamp would silently become 0, which is the cache busting switched off.
    #
    # Matched without its leading spaces, so the indentation does not have to
    # be guessed: the replacement's first line inherits it.
    ("app/includes/partials.php",
     "$full = __DIR__ . '/' . $path;",
     "// dirname, because this file sits in includes/ and the assets are\n"
     "    // one level up beside the pages.\n"
     "    $full = dirname(__DIR__) . '/' . $path;"),

    # The badge still named the environment variable the port removed.
    ("app/includes/partials.php",
     "without NEON_URL the pages render",
     "without config/config.php the pages render"),
    ("app/includes/partials.php",
     'title="NEON_URL is not set, so this site has no data source. Every figure on the page will fail to load until it is set in the environment."',
     'title="config/config.php is missing, so this site has no data source. Every figure on the page will fail to load until it is created on the server."'),

    # Outside app/, two files pointed at the old places.
    ("tests/smoke_queries.php",
     "require dirname(__DIR__) . '/web/data.php';",
     "require dirname(__DIR__) . '/app/includes/data.php';"),
    ("database/import.php",
     "$path = dirname(__DIR__) . '/config/config.php';",
     "$path = dirname(__DIR__) . '/app/config/config.php';"),
]


def git(*args: str) -> None:
    subprocess.run(["git", *args], check=True, cwd=ROOT)


def main() -> int:
    if not (ROOT / "web").is_dir():
        print("No hay web/. Ya esta reestructurado o estas en otro directorio.")
        return 1

    git("mv", "web", "app")

    (ROOT / "app" / "includes").mkdir(exist_ok=True)
    for name in ("data.php", "partials.php"):
        git("mv", f"app/{name}", f"app/includes/{name}")

    (ROOT / "app" / "config").mkdir(exist_ok=True)
    git("mv", "config/config.example.php", "app/config/config.example.php")

    # The real config is untracked, so git does not know about it.
    real = ROOT / "config" / "config.php"
    if real.is_file():
        real.rename(ROOT / "app" / "config" / "config.php")
    old_config = ROOT / "config"
    if old_config.is_dir() and not any(old_config.iterdir()):
        old_config.rmdir()

    failed = []
    for name, old, new in PATCHES:
        path = ROOT / name
        if not path.is_file():
            failed.append(f"  {name}: no existe")
            continue
        text = path.read_text(encoding="utf-8")
        if text.count(old) != 1:
            failed.append(f"  {name}: {text.count(old)} coincidencias de "
                          f"{old[:50]!r}, esperaba 1")
            continue
        path.write_text(text.replace(old, new, 1), encoding="utf-8")

    if failed:
        print("Los archivos se movieron pero estos patches no aplicaron:\n")
        print("\n".join(failed))
        print("\nRevisalos a mano antes de seguir.")
        return 1

    return check()


def check() -> int:
    """Every require and every asset has to resolve where it now sits."""
    problems = []
    app = ROOT / "app"

    for php in sorted(app.rglob("*.php")):
        text = php.read_text(encoding="utf-8")

        for match in re.finditer(r"require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'", text):
            target = (php.parent / match.group(1).lstrip("/")).resolve()
            if not target.is_file():
                problems.append(f"  {php.relative_to(ROOT)}: require a "
                                f"{match.group(1)} que no existe")

        for match in re.finditer(r"dm_asset\('([^']+)'\)", text):
            # dm_asset resolves against app/, wherever the caller lives.
            target = app / match.group(1)
            if not target.is_file():
                problems.append(f"  {php.relative_to(ROOT)}: asset "
                                f"{match.group(1)} que no existe")

    # The same thing dm_asset does, to prove the stamp is a real time.
    partials = app / "includes" / "partials.php"
    if partials.is_file():
        if "dirname(__DIR__)" not in partials.read_text(encoding="utf-8"):
            problems.append("  partials.php: dm_asset sigue leyendo desde __DIR__")

    if problems:
        print("La estructura quedo pero hay referencias rotas:\n")
        print("\n".join(problems))
        return 1

    phps = len(list(app.rglob("*.php")))
    assets = len(list((app / "assets").iterdir())) if (app / "assets").is_dir() else 0
    print(f"Reestructurado. {phps} archivos PHP y {assets} assets, "
          "todas las referencias resuelven.")
    print("\nRevisar con:  git status --short")
    return 0


if __name__ == "__main__":
    sys.exit(main())
