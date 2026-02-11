#!/usr/bin/env python3
"""Import a topic-model CSV into the `topics` table.

Expected CSV columns:
    topic_id, label, keywords (pipe-delimited), coherence_score

Usage:
    python scripts/import_topic_model.py [--file path/to/model.csv]
"""

from __future__ import annotations

import argparse
import csv
import os
import sys
from pathlib import Path

import psycopg2
from dotenv import load_dotenv

load_dotenv()

# ── Postgres connection ───────────────────────────────────────────────────

DATABASE_URL: str = os.getenv("DATABASE_URL", "")

INSERT_SQL = """
INSERT INTO topics (label, keywords, coherence_score, source_file)
VALUES (%s, %s, %s, %s)
ON CONFLICT (label, source_file) DO UPDATE
    SET keywords        = EXCLUDED.keywords,
        coherence_score = EXCLUDED.coherence_score;
"""


def parse_keywords(raw: str) -> list[str]:
    """Split a pipe-delimited keyword string into a Python list."""
    if not isinstance(raw, str) or not raw.strip():
        return []
    return [kw.strip() for kw in raw.split("|") if kw.strip()]


def _parse_coherence(value: str) -> float | None:
    """Return a float or None for empty / non-numeric values."""
    if not value or not value.strip():
        return None
    try:
        return float(value)
    except ValueError:
        return None


def import_csv(csv_path: Path) -> int:
    """Read *csv_path*, insert rows into ``topics``, return count."""
    with open(csv_path, newline="", encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        fieldnames: list[str] = reader.fieldnames or []

        required_cols = {"label", "keywords", "coherence_score"}
        missing = required_cols - set(fieldnames)
        if missing:
            print(f"ERROR: CSV is missing columns: {missing}", file=sys.stderr)
            sys.exit(1)

        rows = list(reader)

    conn = psycopg2.connect(DATABASE_URL)
    cur = conn.cursor()
    inserted = 0

    try:
        for row in rows:
            keywords: list[str] = parse_keywords(row["keywords"])
            cur.execute(
                INSERT_SQL,
                (
                    row["label"],
                    keywords,
                    _parse_coherence(row["coherence_score"]),
                    str(csv_path.name),
                ),
            )
            inserted += cur.rowcount
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        cur.close()
        conn.close()

    return inserted


def main() -> None:
    parser = argparse.ArgumentParser(description="Import topic model CSV into Postgres.")
    parser.add_argument(
        "--file",
        type=Path,
        default=Path(os.getenv("TOPIC_MODEL_CSV", "./data/topic_model.csv")),
        help="Path to the topic-model CSV file.",
    )
    args = parser.parse_args()

    if not DATABASE_URL:
        print("ERROR: DATABASE_URL must be set in .env", file=sys.stderr)
        sys.exit(1)

    if not args.file.exists():
        print(f"ERROR: File not found: {args.file}", file=sys.stderr)
        sys.exit(1)

    count = import_csv(args.file)
    print(f"Imported {count} topic(s) from {args.file}")


if __name__ == "__main__":
    main()
