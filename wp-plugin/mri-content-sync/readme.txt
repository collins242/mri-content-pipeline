=== MRI Content Sync ===
Contributors: mri
Tags: content-sync, rest-api, yoast, pipeline
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes/updates long-form resources posts from the MRI content database and sets Yoast SEO fields + linking modules.

== Description ==

MRI Content Sync exposes three REST endpoints under the `mri-content/v1` namespace:

* **POST /wp-json/mri-content/v1/publish** — Create a new post. Accepts `recommended_title`, `suggested_url_slug`, `body_html`, Yoast SEO fields, taxonomy terms, and MRI metadata (hub, cluster, region, entities, CTA, internal links, schema type).
* **POST /wp-json/mri-content/v1/update** — Update an existing post by `wp_post_id`. Same payload as publish.
* **GET /wp-json/mri-content/v1/url-index** — Returns the 200 most-recently modified posts with `wp_post_id`, `url`, `title`, and `modified_gmt`.

== Installation ==

1. Copy the `mri-content-sync` folder into `wp-content/plugins/`.
2. Add the shared HMAC secret to `wp-config.php`:

    define( 'MRI_CONTENT_SYNC_SECRET', 'your-strong-secret-here' );

3. Activate **MRI Content Sync** from the Plugins screen.

== Authentication ==

All requests must include two headers:

* `X-MRI-Timestamp` — Unix epoch seconds (must be within 300 s of server time).
* `X-MRI-Signature` — `hex(hmac_sha256(secret, "<timestamp>.<raw_body>"))`.

For GET requests the raw body is an empty string.

Requests with a missing, expired, or invalid signature receive a `403 Forbidden` response.

== Yoast SEO fields ==

The publish/update endpoints accept `seo_title`, `seo_description`, `focus_keyword`, and `canonical_url`. These are written to the corresponding Yoast post-meta keys including OpenGraph and Twitter title/description.

== Changelog ==

= 0.1.0 =
* Initial release — publish, update, and url-index endpoints with HMAC auth.
