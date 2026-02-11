-- mri-content-pipeline  ·  Postgres 15+
-- Run once: psql -f db/schema.sql
-- Safe to re-run (all statements are idempotent).

BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Topics – imported from the topic model (BERTopic / LDA export)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS topics (
    topic_id        SERIAL PRIMARY KEY,
    label           TEXT        NOT NULL,          -- human-readable label
    keywords        TEXT[]      NOT NULL DEFAULT '{}', -- top-N terms
    coherence_score NUMERIC(6,4),                  -- model coherence metric
    source_file     TEXT,                           -- originating model file
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_topics_label ON topics (label);

-- Unique constraint so ON CONFLICT works for repeated imports.
DO $$ BEGIN
    ALTER TABLE topics ADD CONSTRAINT uq_topics_label_source UNIQUE (label, source_file);
EXCEPTION WHEN duplicate_table THEN NULL;
END $$;

-- ---------------------------------------------------------------------------
-- 2. Articles – content pieces mapped to topics
-- ---------------------------------------------------------------------------
DO $$ BEGIN
    CREATE TYPE article_status AS ENUM (
        'draft',
        'ready',
        'published',
        'archived'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

CREATE TABLE IF NOT EXISTS articles (
    article_id       SERIAL PRIMARY KEY,
    topic_id         INTEGER      REFERENCES topics(topic_id) ON DELETE SET NULL,
    title            TEXT         NOT NULL,
    slug             TEXT         NOT NULL UNIQUE,
    body_html        TEXT         NOT NULL DEFAULT '',
    meta_description TEXT         NOT NULL DEFAULT '',
    focus_keyphrase  TEXT,
    status           article_status NOT NULL DEFAULT 'draft',
    wp_post_id       BIGINT,                        -- NULL until published
    published_at     TIMESTAMPTZ,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_articles_status    ON articles (status);
CREATE INDEX IF NOT EXISTS idx_articles_topic_id  ON articles (topic_id);
CREATE INDEX IF NOT EXISTS idx_articles_wp_post   ON articles (wp_post_id);

-- ---------------------------------------------------------------------------
-- 3. Yoast QA snapshots – one row per check run
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS yoast_qa (
    qa_id              SERIAL PRIMARY KEY,
    article_id         INTEGER      NOT NULL REFERENCES articles(article_id) ON DELETE CASCADE,
    seo_score          INTEGER,                     -- 0-100
    readability_score  INTEGER,                     -- 0-100
    focus_keyphrase    TEXT,
    issues             JSONB        NOT NULL DEFAULT '[]',  -- array of issue objects
    checked_at         TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_yoast_qa_article ON yoast_qa (article_id);

-- ---------------------------------------------------------------------------
-- Helper: auto-update updated_at on row change
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- OR REPLACE requires Postgres 14+
CREATE OR REPLACE TRIGGER trg_topics_updated
    BEFORE UPDATE ON topics
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE OR REPLACE TRIGGER trg_articles_updated
    BEFORE UPDATE ON articles
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMIT;
