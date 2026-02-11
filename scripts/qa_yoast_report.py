#!/usr/bin/env python3
"""Pull Yoast SEO metadata from WordPress and store a QA snapshot in Postgres.

For every published article that has a wp_post_id, the script:
    1. GETs /wp-json/wp/v2/posts/<id> to read yoast_head_json (requires Yoast SEO)
    2. Scores title length, meta-description length, focus-keyword presence, and
       canonical URL presence
    3. Inserts a row into yoast_qa
    4. Prints a summary report to stdout

Optionally calls the plugin's /url-index endpoint first (HMAC-auth) to
cross-reference which posts are live on WordPress.

Usage:
    python scripts/qa_yoast_report.py [--min-seo 70] [--min-read 70]
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import sys
import time
from dataclasses import dataclass, field

import psycopg2
import psycopg2.extras
import requests
from dotenv import load_dotenv

load_dotenv()

# ── Configuration ─────────────────────────────────────────────────────────

DATABASE_URL: str = os.getenv("DATABASE_URL", "")

WP_BASE_URL: str = os.getenv("WP_BASE_URL", "").rstrip("/")
WP_MRI_CONTENT_SECRET: str = os.getenv("WP_MRI_CONTENT_SECRET", "")

URL_INDEX_ENDPOINT = f"{WP_BASE_URL}/wp-json/mri-content/v1/url-index"

SELECT_PUBLISHED = """
SELECT article_id, title, slug, focus_keyphrase, wp_post_id
  FROM articles
 WHERE status = 'published' AND wp_post_id IS NOT NULL
 ORDER BY article_id;
"""

INSERT_QA = """
INSERT INTO yoast_qa (article_id, seo_score, readability_score, focus_keyphrase, issues)
VALUES (%s, %s, %s, %s, %s);
"""


@dataclass
class QAResult:
    article_id: int
    title: str
    seo_score: int
    readability_score: int
    focus_keyphrase: str | None
    issues: list[str] = field(default_factory=list)
    passed: bool = False


# ── HMAC signing (for url-index) ──────────────────────────────────────────

def _sign(body: str = "") -> dict[str, str]:
    """Build HMAC-SHA256 auth headers expected by the WP plugin."""
    ts = str(int(time.time()))
    msg = f"{ts}.{body}"
    sig = hmac.new(
        WP_MRI_CONTENT_SECRET.encode(),
        msg.encode(),
        hashlib.sha256,
    ).hexdigest()
    return {
        "X-MRI-Timestamp": ts,
        "X-MRI-Signature": sig,
    }


# ── WP data fetchers ─────────────────────────────────────────────────────

def fetch_url_index() -> dict[int, dict]:
    """GET the plugin url-index; return {wp_post_id: item} mapping."""
    resp = requests.get(URL_INDEX_ENDPOINT, headers=_sign(""), timeout=15)
    resp.raise_for_status()
    items: list[dict] = resp.json().get("items", [])
    return {int(item["wp_post_id"]): item for item in items}


def fetch_yoast_head(wp_post_id: int) -> dict:
    """GET Yoast head JSON from the standard WP REST API.

    Yoast SEO adds a ``yoast_head_json`` field to /wp/v2/posts/<id>.
    """
    url = f"{WP_BASE_URL}/wp-json/wp/v2/posts/{wp_post_id}"
    resp = requests.get(url, params={"_fields": "id,yoast_head_json"}, timeout=15)
    resp.raise_for_status()
    return resp.json().get("yoast_head_json", {})


# ── Scoring heuristics ────────────────────────────────────────────────────

def _score_title(yoast: dict, focus_kw: str | None) -> tuple[int, list[str]]:
    """Score the SEO title (0-100). Returns (score, issues)."""
    title: str = yoast.get("title", "")
    issues: list[str] = []
    score = 100

    if not title:
        return 0, ["missing SEO title"]

    length = len(title)
    if length < 30:
        score -= 30
        issues.append(f"title too short ({length} chars)")
    elif length > 60:
        score -= 20
        issues.append(f"title too long ({length} chars)")

    if focus_kw and focus_kw.lower() not in title.lower():
        score -= 25
        issues.append("focus keyword not in title")

    return max(score, 0), issues


def _score_description(yoast: dict) -> tuple[int, list[str]]:
    """Score the meta description (0-100). Returns (score, issues)."""
    desc: str = yoast.get("description", "")
    issues: list[str] = []
    score = 100

    if not desc:
        return 0, ["missing meta description"]

    length = len(desc)
    if length < 120:
        score -= 30
        issues.append(f"meta description short ({length} chars)")
    elif length > 160:
        score -= 15
        issues.append(f"meta description long ({length} chars)")

    return max(score, 0), issues


def _score_og(yoast: dict) -> tuple[int, list[str]]:
    """Score Open Graph completeness (0-100)."""
    issues: list[str] = []
    score = 100

    if not yoast.get("og_title"):
        score -= 35
        issues.append("missing og:title")
    if not yoast.get("og_description"):
        score -= 35
        issues.append("missing og:description")
    if not yoast.get("canonical"):
        score -= 30
        issues.append("missing canonical URL")

    return max(score, 0), issues


def compute_scores(yoast: dict, focus_kw: str | None) -> tuple[int, int, list[str]]:
    """Return (seo_score, readability_score, issues).

    seo_score: weighted average of title, description, and OG checks.
    readability_score: placeholder — Yoast readability scores are not
    exposed via the REST API; defaults to 0 (unknown).
    """
    t_score, t_issues = _score_title(yoast, focus_kw)
    d_score, d_issues = _score_description(yoast)
    o_score, o_issues = _score_og(yoast)

    all_issues = t_issues + d_issues + o_issues
    seo_score = int(t_score * 0.40 + d_score * 0.35 + o_score * 0.25)
    readability_score = 0  # not available via REST API

    return seo_score, readability_score, all_issues


# ── Main run ──────────────────────────────────────────────────────────────

def run(min_seo: int = 70, min_read: int = 70) -> None:
    if not DATABASE_URL:
        print("ERROR: DATABASE_URL must be set in .env", file=sys.stderr)
        sys.exit(1)
    if not WP_BASE_URL or not WP_MRI_CONTENT_SECRET:
        print("ERROR: WP_BASE_URL and WP_MRI_CONTENT_SECRET must be set in .env", file=sys.stderr)
        sys.exit(1)

    conn = psycopg2.connect(DATABASE_URL)
    cur = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)

    try:
        cur.execute(SELECT_PUBLISHED)
        rows: list[dict] = cur.fetchall()

        if not rows:
            print("No published articles found. Run publish_to_wp.py first.")
            return

        # Cross-reference with WP url-index
        wp_index: dict[int, dict] = {}
        try:
            wp_index = fetch_url_index()
            print(f"Fetched url-index: {len(wp_index)} post(s) on WordPress.\n")
        except requests.RequestException as exc:
            print(f"  WARN: Could not fetch url-index: {exc}", file=sys.stderr)

        results: list[QAResult] = []

        for row in rows:
            article_id: int = row["article_id"]
            wp_post_id: int = row["wp_post_id"]
            title: str = row["title"]
            focus_kw: str | None = row.get("focus_keyphrase")

            # Check if post exists in WP index
            if wp_index and wp_post_id not in wp_index:
                print(f"  WARN: article {article_id} (wp_post_id={wp_post_id}) not in url-index", file=sys.stderr)

            try:
                yoast = fetch_yoast_head(wp_post_id)
            except requests.RequestException as exc:
                print(f"  WARN: Could not fetch Yoast for wp_post_id={wp_post_id}: {exc}", file=sys.stderr)
                continue

            seo, readability, issues = compute_scores(yoast, focus_kw)

            # readability_score threshold only applies when score is available (>0)
            passed = seo >= min_seo and (readability >= min_read or readability == 0)

            # Persist QA snapshot
            cur.execute(
                INSERT_QA,
                (article_id, seo, readability, focus_kw, json.dumps(issues)),
            )
            conn.commit()

            results.append(QAResult(
                article_id=article_id,
                title=title,
                seo_score=seo,
                readability_score=readability,
                focus_keyphrase=focus_kw,
                issues=issues,
                passed=passed,
            ))
    finally:
        cur.close()
        conn.close()

    # ── Pretty report ─────────────────────────────────────────────────
    print(f"\n{'='*72}")
    print(f"  Yoast QA Report  (thresholds: SEO>={min_seo}, Readability>={min_read})")
    print(f"{'='*72}\n")

    pass_count = 0
    for r in results:
        status_str = "PASS" if r.passed else "FAIL"
        if r.passed:
            pass_count += 1
        kp_display = r.focus_keyphrase or "(none)"
        print(
            f"  [{status_str}]  id={r.article_id:<5}  "
            f"SEO={r.seo_score:<4}  Read={r.readability_score:<4}  "
            f"KP={kp_display:<25}  "
            f"{r.title}"
        )
        if r.issues:
            for issue in r.issues:
                print(f"           └─ {issue}")

    total = len(results)
    print(f"\n  {pass_count}/{total} passed  ·  {total - pass_count} need attention\n")


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate a Yoast QA report for published articles.")
    parser.add_argument("--min-seo", type=int, default=70, help="Minimum passing SEO score (0-100).")
    parser.add_argument("--min-read", type=int, default=70, help="Minimum passing readability score (0-100).")
    args = parser.parse_args()

    run(min_seo=args.min_seo, min_read=args.min_read)


if __name__ == "__main__":
    main()
