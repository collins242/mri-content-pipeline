#!/usr/bin/env python3
"""Publish articles with status='ready' to WordPress via the MRI Content Sync plugin.

For each qualifying article the script:
    1. POSTs to /wp-json/mri-content/v1/publish  (new)
       or      /wp-json/mri-content/v1/update    (existing wp_post_id)
    2. Stores the returned wp_post_id in the articles table
    3. Flips the article status to 'published'

Auth: HMAC-SHA256  (X-MRI-Timestamp + X-MRI-Signature)

Usage:
    python scripts/publish_to_wp.py [--dry-run] [--limit N]
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import sys
import time
from datetime import datetime, timezone

import psycopg2
import psycopg2.extras
import requests
from dotenv import load_dotenv

load_dotenv()

# ── Configuration ─────────────────────────────────────────────────────────

DATABASE_URL: str = os.getenv("DATABASE_URL", "")

WP_BASE_URL: str = os.getenv("WP_BASE_URL", "").rstrip("/")
WP_MRI_CONTENT_SECRET: str = os.getenv("WP_MRI_CONTENT_SECRET", "")

DEFAULT_POST_TYPE: str = os.getenv("DEFAULT_POST_TYPE", "post")
DEFAULT_POST_STATUS: str = os.getenv("DEFAULT_POST_STATUS", "draft")
PUBLISH_LIMIT: int = int(os.getenv("PUBLISH_LIMIT", "50"))

PUBLISH_ENDPOINT = f"{WP_BASE_URL}/wp-json/mri-content/v1/publish"
UPDATE_ENDPOINT = f"{WP_BASE_URL}/wp-json/mri-content/v1/update"

SELECT_READY = """
SELECT article_id, title, slug, body_html, meta_description, focus_keyphrase,
       wp_post_id
  FROM articles
 WHERE status = 'ready'
 ORDER BY article_id
 LIMIT %s;
"""

UPDATE_PUBLISHED = """
UPDATE articles
   SET status       = 'published',
       wp_post_id   = %s,
       published_at = %s
 WHERE article_id   = %s;
"""


# ── HMAC signing ──────────────────────────────────────────────────────────

def _sign(body: str) -> dict[str, str]:
    """Build HMAC-SHA256 auth headers expected by the WP plugin.

    Signature = hex(hmac_sha256(secret, "<timestamp>.<body>"))
    """
    ts = str(int(time.time()))
    msg = f"{ts}.{body}"
    sig = hmac.new(
        WP_MRI_CONTENT_SECRET.encode(),
        msg.encode(),
        hashlib.sha256,
    ).hexdigest()
    return {
        "Content-Type": "application/json",
        "X-MRI-Timestamp": ts,
        "X-MRI-Signature": sig,
    }


# ── Publish / update helpers ──────────────────────────────────────────────

def _build_payload(row: dict) -> dict:
    """Map DB columns → plugin expected field names."""
    return {
        "recommended_title": row["title"],
        "suggested_url_slug": row["slug"],
        "body_html": row["body_html"],
        "post_type": DEFAULT_POST_TYPE,
        "post_status": DEFAULT_POST_STATUS,
        "seo_title": row["title"],
        "seo_description": row["meta_description"] or "",
        "focus_keyword": row["focus_keyphrase"] or "",
    }


def publish_article(row: dict) -> tuple[int, str]:
    """Push a single article to WordPress; return (wp_post_id, url)."""
    existing_wp_id: int | None = row.get("wp_post_id")

    payload = _build_payload(row)

    if existing_wp_id:
        payload["wp_post_id"] = existing_wp_id
        endpoint = UPDATE_ENDPOINT
    else:
        endpoint = PUBLISH_ENDPOINT

    body = json.dumps(payload)
    resp = requests.post(endpoint, data=body, headers=_sign(body), timeout=30)
    resp.raise_for_status()
    data: dict = resp.json()
    return int(data["wp_post_id"]), data.get("url", "")


# ── Main run ──────────────────────────────────────────────────────────────

def run(dry_run: bool = False, limit: int | None = None) -> None:
    effective_limit = limit if limit is not None else PUBLISH_LIMIT

    if not DATABASE_URL:
        print("ERROR: DATABASE_URL must be set in .env", file=sys.stderr)
        sys.exit(1)
    if not WP_BASE_URL or not WP_MRI_CONTENT_SECRET:
        print("ERROR: WP_BASE_URL and WP_MRI_CONTENT_SECRET must be set in .env", file=sys.stderr)
        sys.exit(1)

    conn = psycopg2.connect(DATABASE_URL)
    cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)

    try:
        cur.execute(SELECT_READY, (effective_limit,))
        rows: list[dict] = cur.fetchall()

        if not rows:
            print("No articles with status='ready'. Nothing to publish.")
            return

        print(f"Found {len(rows)} article(s) to publish (dry_run={dry_run}).\n")

        success_count = 0

        for row in rows:
            article_id: int = row["article_id"]
            title: str = row["title"]

            if dry_run:
                action = "update" if row.get("wp_post_id") else "publish"
                print(f"  [DRY-RUN] Would {action} article {article_id}: {title}")
                success_count += 1
                continue

            try:
                wp_post_id, url = publish_article(row)
                now = datetime.now(timezone.utc)
                cur.execute(UPDATE_PUBLISHED, (wp_post_id, now, article_id))
                conn.commit()
                print(f"  Published article {article_id} → wp_post_id={wp_post_id}  {url}")
                success_count += 1
            except (requests.RequestException, ValueError, KeyError) as exc:
                print(f"  FAILED article {article_id}: {exc}", file=sys.stderr)
                conn.rollback()

        print(f"\nDone. {success_count}/{len(rows)} article(s) published.")
    finally:
        cur.close()
        conn.close()


def main() -> None:
    parser = argparse.ArgumentParser(description="Publish 'ready' articles to WordPress.")
    parser.add_argument("--dry-run", action="store_true", help="Print what would happen without calling WP.")
    parser.add_argument("--limit", type=int, default=None, help="Max articles to publish (default: PUBLISH_LIMIT env).")
    args = parser.parse_args()

    run(dry_run=args.dry_run, limit=args.limit)


if __name__ == "__main__":
    main()
