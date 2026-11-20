#!/usr/bin/env python3
"""
Compare two dumps from tests/dump_api.php: PostgreSQL against MySQL.

Numbers are compared with a stated tolerance rather than for exact equality,
because exact equality is the wrong test for a floating-point sum. Adding the
same 870,000 doubles in a different order gives a different last bit, and both
engines are free to choose the order. The tolerance below is 1e-12 relative,
which is four orders of magnitude tighter than the smallest fault worth
catching: a single lost row moves a hectare total by about 1e-6 relative, and
one lost row out of alerts_weekly moves it by far more.

Values inside the tolerance are counted and reported separately from exact
matches, with the worst difference seen, so nothing is quietly waved through.

Lists are compared in order first, which is how the ORDER BY clauses get
checked. Only if that fails are they compared as sets, which distinguishes a
row that moved from a row that changed. The two mean different things.

  python compare_backends.py /tmp/pg.json /tmp/my.json
"""

import json
import sys

REL_TOL = 1e-12
ABS_TOL = 1e-9


def canon(value) -> str:
    return json.dumps(value, sort_keys=True, ensure_ascii=False)


def compare(a, b, path, bad, soft) -> None:
    """Walk two values together, filing each leaf difference under bad or soft."""
    # Booleans before numbers: in Python True == 1, and a flagged column that
    # came back as a boolean on one side and as 1 on the other is a difference
    # worth seeing rather than hiding.
    if isinstance(a, bool) or isinstance(b, bool):
        if a is not b:
            bad.append((path, a, b, None))
        return

    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        if a == b:
            return
        scale = max(abs(a), abs(b))
        rel = abs(a - b) / scale if scale else 0.0
        if abs(a - b) <= ABS_TOL or rel <= REL_TOL:
            soft.append((path, a, b, rel))
        else:
            bad.append((path, a, b, rel))
        return

    if isinstance(a, dict) and isinstance(b, dict):
        for key in sorted(set(a) | set(b)):
            if key not in a or key not in b:
                bad.append((f"{path}.{key}", a.get(key, "<falta>"), b.get(key, "<falta>"), None))
            else:
                compare(a[key], b[key], f"{path}.{key}", bad, soft)
        return

    if isinstance(a, list) and isinstance(b, list):
        if len(a) != len(b):
            bad.append((f"{path} (cantidad)", len(a), len(b), None))
            return
        for i, (x, y) in enumerate(zip(a, b)):
            compare(x, y, f"{path}[{i}]", bad, soft)
        return

    if a != b:
        bad.append((path, a, b, None))


def main() -> int:
    if len(sys.argv) != 3:
        print("uso: python compare_backends.py <pg.json> <my.json>")
        return 1

    with open(sys.argv[1], encoding="utf-8") as f:
        pg = json.load(f)
    with open(sys.argv[2], encoding="utf-8") as f:
        my = json.load(f)

    missing = [f"  SOLO EN PG   {k}" for k in sorted(set(pg) - set(my))]
    missing += [f"  SOLO EN MY   {k}" for k in sorted(set(my) - set(pg))]
    for line in missing:
        print(line)

    exact = 0
    tolerated = []
    reordered = []
    different = []
    worst_rel = 0.0

    for key in pg:
        if key not in my:
            continue

        bad, soft = [], []
        compare(pg[key], my[key], key, bad, soft)

        if not bad:
            if soft:
                tolerated.append((key, soft))
                worst_rel = max(worst_rel, max(s[3] for s in soft))
            else:
                exact += 1
            continue

        # Same rows in another place, or genuinely other rows?
        if isinstance(pg[key], list) and isinstance(my[key], list) \
                and len(pg[key]) == len(my[key]):
            sa = sorted(pg[key], key=canon)
            sb = sorted(my[key], key=canon)
            bad2, soft2 = [], []
            compare(sa, sb, key, bad2, soft2)
            if not bad2:
                reordered.append((key, len(pg[key])))
                if soft2:
                    worst_rel = max(worst_rel, max(s[3] for s in soft2))
                continue

        different.append((key, bad))

    print(f"\n  identicos                     {exact}")
    print(f"  iguales dentro de tolerancia  {len(tolerated)}")
    print(f"  mismas filas, otro orden      {len(reordered)}")
    print(f"  distintos                     {len(different)}")
    if worst_rel:
        print(f"\n  peor diferencia relativa: {worst_rel:.3e}  "
              f"(tolerancia {REL_TOL:.0e})")

    for key, soft in tolerated:
        leaf, a, b, rel = max(soft, key=lambda s: s[3])
        print(f"\n  TOLERADO  {key}  ({len(soft)} valores)")
        print(f"    peor: {leaf}")
        print(f"          pg {a!r}")
        print(f"          my {b!r}   rel {rel:.3e}")

    for key, n in reordered:
        print(f"\n  ORDEN  {key}  ({n} filas)")
        print("    las mismas filas en otro orden. Revisar el ORDER BY.")

    for key, bad in different:
        print(f"\n  DISTINTO  {key}  ({len(bad)} diferencias)")
        for leaf, a, b, rel in bad[:5]:
            extra = f"   rel {rel:.3e}" if rel is not None else ""
            print(f"    {leaf}")
            print(f"      pg {canon(a)[:200]}")
            print(f"      my {canon(b)[:200]}{extra}")

    print()
    if missing or different:
        print("Las dos versiones NO dan lo mismo.")
        return 1
    if reordered:
        print("Mismos datos, pero algun orden quedo sin definir.")
        return 1
    print("Las dos versiones dan lo mismo.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
