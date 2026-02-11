# MRI Content Pipeline (DB + Yoast + WP publish)

## What this does
- Imports topic model CSV into Postgres
- Creates pinned internal link plan entries
- Publishes/updates posts in WordPress via a private plugin endpoint
- Sets Yoast SEO fields (title, metadesc, focuskw, canonical)
- Generates a QA report for SEO hygiene

## Setup

### 1) Database
1. Create Postgres DB
2. Run: `psql $DATABASE_URL -f db/schema.sql`

### 2) WordPress plugin
1. Copy `wp-plugin/mri-content-sync/` to `wp-content/plugins/`
2. Add secret in `wp-config.php`:
   ```php
   define('MRI_CONTENT_SYNC_SECRET', '...strong secret...');
   ```

### 3) Python env
```bash
pip install -r requirements.txt
cp .env.example .env
# fill env vars
```

## Run

### Import CSV into DB
```bash
python scripts/import_topic_model.py
```

### Publish to WP
```bash
python scripts/publish_to_wp.py
```

### QA report
```bash
python scripts/qa_yoast_report.py
# outputs yoast_qa_report.csv
```

## Dev rules
- Don't change the payload contract.
- Do not add new dependencies.
- Do not invent ACF fields; only write post meta.
- Keep it idempotent.
