#!/usr/bin/env python3
"""
Add the two missing ORDER BY clauses to a copy of the PostgreSQL data layer.

The port gave dm_losing a tiebreaker and dm_map_points an order it never had.
Those are not dialect changes, so the PostgreSQL version needs them too before
the two can be compared: otherwise the comparison reports a difference that is
the fix itself rather than a fault.

This is for a throwaway copy used by tests/dump_api.php, not for the backup.
The clauses are identical in both dialects.

  cp web/data.php.bak /tmp/data_pg_ordered.php
  python add_order_by.py /tmp/data_pg_ordered.php
"""

import pathlib
import sys

PATCHES = [
    (
        "        WHERE r.area_ha > 0' . $clause . '\n"
        "        ORDER BY r.area_ha DESC",

        "        WHERE r.area_ha > 0' . $clause . '\n"
        "        ORDER BY r.area_ha DESC, r.territory_id ASC",
    ),
    (
        "          ' . ($flagged_only ? 'AND r.flagged' : '') . '\n"
        "    ', $params);",

        "          ' . ($flagged_only ? 'AND r.flagged' : '') . '\n"
        "        ORDER BY t.territory_id ASC\n"
        "    ', $params);",
    ),
]


def main() -> int:
    if len(sys.argv) != 2:
        print("uso: python add_order_by.py <archivo>")
        return 1

    path = pathlib.Path(sys.argv[1])
    if not path.is_file():
        print(f"no existe: {path}")
        return 1

    text = path.read_text(encoding="utf-8")

    for i, (old, new) in enumerate(PATCHES, 1):
        found = text.count(old)
        if found != 1:
            print(f"patch {i}: {found} coincidencias, esperaba 1. No escribi nada.")
            return 1
        text = text.replace(old, new, 1)

    path.write_text(text, encoding="utf-8")
    print(f"{path}: 2 ORDER BY agregados")
    return 0


if __name__ == "__main__":
    sys.exit(main())
