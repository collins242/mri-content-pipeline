<?php
/**
 * Plugin Name: MRI Content Sync
 * Description: Publishes/updates long-form resources posts from MRI content database and sets Yoast SEO fields + linking modules.
 * Version: 0.1.0
 * Author: MRI
 */
if (!defined('ABSPATH')) exit;

class MRI_Content_Sync {
  const ROUTE_NS = 'mri-content/v1';

  // Admin menu slugs
  const ADMIN_MENU_SLUG = 'mri-content-sync';
  const ADMIN_IMPORT_SLUG = 'mri-content-sync-import';

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);

    // Admin UI (Option A)
    add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
  }

  /* ──────────────────────────────────────────────────────────────────────────
   * REST ROUTES
   * ────────────────────────────────────────────────────────────────────────── */

  public static function register_routes() {
    register_rest_route(self::ROUTE_NS, '/publish', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'handle_publish'],
      'permission_callback' => [__CLASS__, 'verify_request'],
    ]);

    register_rest_route(self::ROUTE_NS, '/update', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'handle_update'],
      'permission_callback' => [__CLASS__, 'verify_request'],
    ]);

    register_rest_route(self::ROUTE_NS, '/url-index', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'handle_url_index'],
      'permission_callback' => [__CLASS__, 'verify_request'],
    ]);
  }

  /**
   * HMAC auth:
   * Headers required:
   *  - X-MRI-Timestamp: unix epoch seconds
   *  - X-MRI-Signature: hex(hmac_sha256(secret, timestamp + "." + raw_body))
   * Secret should be defined in wp-config.php:
   *  define('MRI_CONTENT_SYNC_SECRET', '...');
   */
  public static function verify_request(\WP_REST_Request $req) {
    $secret = defined('MRI_CONTENT_SYNC_SECRET') ? MRI_CONTENT_SYNC_SECRET : null;
    if (!$secret) return false;

    $ts = $req->get_header('x-mri-timestamp');
    $sig = $req->get_header('x-mri-signature');
    if (!$ts || !$sig) return false;

    if (abs(time() - intval($ts)) > 300) return false;

    $raw = $req->get_body();
    $msg = $ts . "." . $raw;
    $calc = hash_hmac('sha256', $msg, $secret);

    return hash_equals($calc, $sig);
  }

  public static function handle_publish(\WP_REST_Request $req) {
    $payload = json_decode($req->get_body(), true);
    if (!is_array($payload)) return new \WP_REST_Response(['error'=>'Invalid JSON'], 400);

    $post_id = self::upsert_post($payload, null);
    if (is_wp_error($post_id)) return new \WP_REST_Response(['error'=>$post_id->get_error_message()], 400);

    return new \WP_REST_Response(['wp_post_id'=>$post_id, 'url'=>get_permalink($post_id)], 200);
  }

  public static function handle_update(\WP_REST_Request $req) {
    $payload = json_decode($req->get_body(), true);
    if (!is_array($payload)) return new \WP_REST_Response(['error'=>'Invalid JSON'], 400);

    $post_id = isset($payload['wp_post_id']) ? intval($payload['wp_post_id']) : null;
    if (!$post_id) return new \WP_REST_Response(['error'=>'wp_post_id required for update'], 400);

    $updated = self::upsert_post($payload, $post_id);
    if (is_wp_error($updated)) return new \WP_REST_Response(['error'=>$updated->get_error_message()], 400);

    return new \WP_REST_Response(['wp_post_id'=>$updated, 'url'=>get_permalink($updated)], 200);
  }

  public static function handle_url_index(\WP_REST_Request $req) {
    $args = [
      'post_type'      => 'post',
      'post_status'    => ['publish','draft','pending','future'],
      'posts_per_page' => 200,
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'fields'         => 'ids',
    ];
    $ids = get_posts($args);

    $items = array_map(function($id) {
      return [
        'wp_post_id' => $id,
        'url'        => get_permalink($id),
        'title'      => get_the_title($id),
        'modified_gmt' => get_post_modified_time('c', true, $id),
      ];
    }, $ids);

    return new \WP_REST_Response(['items'=>$items], 200);
  }

  /* ──────────────────────────────────────────────────────────────────────────
   * ADMIN UI (Option A): MRI Content Sync → Import Batch
   * ────────────────────────────────────────────────────────────────────────── */

  public static function register_admin_pages() {
    add_menu_page(
      'MRI Content Sync',
      'MRI Content Sync',
      'manage_options',
      self::ADMIN_MENU_SLUG,
      [__CLASS__, 'render_admin_home'],
      'dashicons-media-document',
      81
    );

    add_submenu_page(
      self::ADMIN_MENU_SLUG,
      'Import Batch (CSV)',
      'Import Batch',
      'manage_options',
      self::ADMIN_IMPORT_SLUG,
      [__CLASS__, 'render_import_page']
    );
  }

  public static function render_admin_home() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap">';
    echo '<h1>MRI Content Sync</h1>';
    echo '<p>Use <b>Import Batch</b> to upload a CSV and create/update posts, including Yoast fields and ACF fields.</p>';
    echo '</div>';
  }

  public static function render_import_page() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap">';
    echo '<h1>Import Batch (CSV)</h1>';
    echo '<p>Upload a CSV. Recommended: import as <b>Draft</b>, then review and publish.</p>';

    if (!empty($_POST['mri_import_submit'])) {
      check_admin_referer('mri_import_csv');
      $result = self::handle_csv_import();
      self::render_import_result($result);
    }

    echo '<hr />';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('mri_import_csv');

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="mri_csv">CSV File</label></th>';
    echo '<td><input type="file" id="mri_csv" name="mri_csv" accept=".csv,text/csv" required></td></tr>';

    echo '<tr><th scope="row">Import Status</th><td>';
    echo '<label><input type="radio" name="import_status" value="draft" checked> Draft</label> &nbsp; ';
    echo '<label><input type="radio" name="import_status" value="publish"> Publish</label>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Post Type</th><td>';
    echo '<input type="text" name="import_post_type" value="resources" class="regular-text" />';
    echo '<p class="description">Use <code>post</code> unless your environment stores Resources as a custom post type.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p><button class="button button-primary" name="mri_import_submit" value="1">Upload & Import</button></p>';
    echo '</form>';

    echo '<hr />';
    echo '<h2>Required CSV Columns</h2>';
    echo '<p><code>title, slug, abstract, h1, h2_sections</code></p>';

    echo '<h2>ACF Columns Supported</h2>';
    echo '<p><code>schema_jsonld_snippet</code> (Text Area) and <code>dynamic_resource_select</code> (Relationship, semicolon-separated Post IDs)</p>';

    echo '</div>';
  }

  private static function render_import_result(array $result) {
    if (!empty($result['error'])) {
      echo '<div class="notice notice-error"><p><b>Import failed:</b> ' . esc_html($result['error']) . '</p></div>';
      return;
    }

    echo '<div class="notice notice-success"><p>';
    echo '<b>Import complete.</b> Created: ' . intval($result['created']) . ' | Updated: ' . intval($result['updated']) . ' | Failed: ' . intval($result['failed']);
    echo '</p></div>';

    if (!empty($result['items'])) {
      echo '<h2>Imported Posts</h2>';
      echo '<table class="widefat fixed striped"><thead><tr>';
      echo '<th>Post ID</th><th>Title</th><th>Status</th><th>Link</th>';
      echo '</tr></thead><tbody>';
      foreach ($result['items'] as $it) {
        $pid = intval($it['post_id']);
        echo '<tr>';
        echo '<td>' . $pid . '</td>';
        echo '<td>' . esc_html($it['title']) . '</td>';
        echo '<td>' . esc_html($it['status']) . '</td>';
        echo '<td><a href="' . esc_url($it['url']) . '" target="_blank" rel="noopener">View</a> | <a href="' . esc_url(get_edit_post_link($pid)) . '">Edit</a></td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
    }
  }

  private static function handle_csv_import(): array {
    if (empty($_FILES['mri_csv']['tmp_name'])) {
      return ['error' => 'No file uploaded.'];
    }

    $uploaded = wp_handle_upload($_FILES['mri_csv'], ['test_form' => false]);
    if (!empty($uploaded['error'])) {
      return ['error' => $uploaded['error']];
    }

    $file = $uploaded['file'];
    $status = (!empty($_POST['import_status']) && $_POST['import_status'] === 'publish') ? 'publish' : 'draft';
    $post_type = !empty($_POST['import_post_type']) ? sanitize_key($_POST['import_post_type']) : 'post';

    $fh = fopen($file, 'r');
    if (!$fh) return ['error' => 'Could not read uploaded file.'];

    $header = fgetcsv($fh);
    if (!$header) {
      fclose($fh);
      return ['error' => 'CSV header row missing or unreadable.'];
    }

    $map = [];
    foreach ($header as $i => $col) {
      $map[strtolower(trim((string)$col))] = $i;
    }

    $required = ['title','slug','abstract','h1','h2_sections'];
    foreach ($required as $col) {
      if (!array_key_exists($col, $map)) {
        fclose($fh);
        return ['error' => "Missing required column: {$col}"];
      }
    }

    $result = ['created'=>0,'updated'=>0,'failed'=>0,'items'=>[]];

    while (($row = fgetcsv($fh)) !== false) {
      $get = function(string $key) use ($map, $row) {
        $k = strtolower($key);
        if (!isset($map[$k])) return '';
        $val = $row[$map[$k]] ?? '';
        return is_string($val) ? trim($val) : '';
      };

      $title = $get('title');
      $slug  = sanitize_title($get('slug'));
      if (!$title || !$slug) continue;

      // Optional: if wp_post_id exists in CSV, update instead of create
      $wp_post_id = $get('wp_post_id');
      $wp_post_id = $wp_post_id ? intval($wp_post_id) : null;

      // Build body_html if not provided
      $body_html = $get('body_html');
      if (!$body_html) {
        $body_html = self::build_html_from_outline(
          $get('abstract'),
          $get('h1'),
          $get('h2_sections'),
          $get('h3_subheads'),
          $get('related_urls'),
          $get('cta_text'),
          $get('cta_url')
        );
      }

      $payload = [
        'recommended_title' => $title,
        'suggested_url_slug' => $slug,
        'post_type' => $post_type,
        'post_status' => $status,
        'body_html' => $body_html,

        // Yoast
        'seo_title' => $get('seo_title') ?: $title,
        'seo_description' => $get('seo_description') ?: wp_trim_words(wp_strip_all_tags($get('abstract')), 30, ''),
        'focus_keyword' => $get('focus_keyphrase') ?: '',

        // Taxonomies (semicolon or comma separated)
        'categories' => $get('categories') ?: $get('category'),
        'tags' => $get('tags'),

        // Optional
        'canonical_url' => $get('canonical_url'),
        'schema_type' => $get('schema_type') ?: 'Article',
        'internal_link_targets' => $get('internal_link_targets'),
      ];

      $post_id = self::upsert_post($payload, $wp_post_id);

      if (is_wp_error($post_id)) {
        $result['failed']++;
        continue;
      }

      // ── ACF SETTERS (THE TWO YOU ASKED FOR) ──────────────────────────────
      // Requires ACF Pro; uses field names you confirmed in screenshots.

      // 1) Schema JSON-LD text area
      $schema = $get('schema_jsonld_snippet');
      if (!empty($schema) && function_exists('update_field')) {
        update_field('schema_jsonld_snippet', $schema, $post_id);
      }

      // 2) Relationship: dynamic_resource_select (expects Post IDs)
      $dyn = $get('dynamic_resource_select');
      if (!empty($dyn) && function_exists('update_field')) {
        $ids = array_map('intval', array_filter(array_map('trim', explode(';', $dyn))));
        update_field('dynamic_resource_select', $ids, $post_id);
      }
      // ─────────────────────────────────────────────────────────────────────

      $result['items'][] = [
        'post_id' => $post_id,
        'title'   => $title,
        'status'  => get_post_status($post_id),
        'url'     => get_permalink($post_id),
      ];

      if ($wp_post_id) $result['updated']++;
      else $result['created']++;
    }

    fclose($fh);
    return $result;
  }

  /**
   * Outline → HTML builder (abstract, H1, H2/H3, related articles, CTA)
   */
  private static function build_html_from_outline(
    string $abstract,
    string $h1,
    string $h2_sections,
    string $h3_subheads,
    string $related_urls,
    string $cta_text,
    string $cta_url
  ): string {
    $html = '';

    if ($abstract) {
      $html .= '<p><strong>Abstract:</strong> ' . esc_html($abstract) . '</p>';
    }

    // If your theme already renders the post title as H1, you can remove this line to avoid double-H1.
    if ($h1) {
      $html .= '<h1>' . esc_html($h1) . '</h1>';
    }

    if ($h2_sections) {
      $lines = preg_split("/\\r\\n|\\r|\\n/", $h2_sections);
      foreach ($lines as $line) {
        $line = trim((string)$line);
        if (!$line) continue;
        $html .= '<h2>' . esc_html($line) . '</h2>';
        $html .= '<p></p>';
      }
    }

    if ($h3_subheads) {
      $lines = preg_split("/\\r\\n|\\r|\\n/", $h3_subheads);
      foreach ($lines as $line) {
        $line = trim((string)$line);
        if (!$line) continue;
        $html .= '<h3>' . esc_html($line) . '</h3>';
        $html .= '<p></p>';
      }
    }

    if ($cta_text && $cta_url) {
      $html .= '<p><a href="' . esc_url($cta_url) . '">' . esc_html($cta_text) . '</a></p>';
    }

    if ($related_urls) {
      $html .= '<h2>Related Articles</h2><ul>';
      $urls = array_filter(array_map('trim', preg_split('/[;|,]/', $related_urls)));
      foreach ($urls as $u) {
        if (!$u) continue;
        $html .= '<li><a href="' . esc_url($u) . '">' . esc_html($u) . '</a></li>';
      }
      $html .= '</ul>';
    }

    return $html;
  }

  /* ──────────────────────────────────────────────────────────────────────────
   * EXISTING UPSERT + YOAST META (UNCHANGED)
   * ────────────────────────────────────────────────────────────────────────── */

  private static function upsert_post(array $p, $post_id = null) {
    $title = $p['recommended_title'] ?? null;
    $slug  = $p['suggested_url_slug'] ?? null;
    $status = $p['post_status'] ?? 'draft';
    $content = $p['body_html'] ?? $p['post_content'] ?? '';

    if (!$title || !$slug) return new \WP_Error('bad_request', 'recommended_title and suggested_url_slug required');

    $postarr = [
      'ID'           => $post_id ? intval($post_id) : 0,
      'post_type'    => $p['post_type'] ?? 'post',
      'post_status'  => $status,
      'post_title'   => $title,
      'post_name'    => sanitize_title($slug),
      'post_excerpt' => $p['excerpt'] ?? '',
      'post_content' => $content,
    ];

    $new_id = $post_id ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
    if (is_wp_error($new_id)) return $new_id;

    if (!empty($p['categories'])) {
      $cats = is_array($p['categories']) ? $p['categories'] : array_map('trim', explode(',', $p['categories']));
      wp_set_post_terms($new_id, $cats, 'category', false);
    }
    if (!empty($p['tags'])) {
      $tags = is_array($p['tags']) ? $p['tags'] : array_map('trim', explode(',', $p['tags']));
      wp_set_post_terms($new_id, $tags, 'post_tag', false);
    }

    self::set_yoast_meta($new_id, $p);

    update_post_meta($new_id, 'mri_hub', $p['hub'] ?? '');
    update_post_meta($new_id, 'mri_cluster', $p['cluster'] ?? '');
    update_post_meta($new_id, 'mri_pillar_or_cluster', $p['pillar_or_cluster'] ?? '');
    update_post_meta($new_id, 'mri_region', $p['region'] ?? '');
    update_post_meta($new_id, 'mri_language', $p['language'] ?? '');
    update_post_meta($new_id, 'mri_funnel_stage', $p['funnel_stage'] ?? '');
    update_post_meta($new_id, 'mri_target_audience', $p['target_audience'] ?? '');
    update_post_meta($new_id, 'mri_primary_entity', $p['primary_entity'] ?? '');

    if (!empty($p['supporting_entities'])) {
      $entities = is_array($p['supporting_entities'])
        ? $p['supporting_entities']
        : array_filter(array_map('trim', preg_split('/[;|,]/', $p['supporting_entities'])));
      update_post_meta($new_id, 'mri_supporting_entities', $entities);
    }

    update_post_meta($new_id, 'mri_cta_text', $p['cta_text'] ?? '');
    update_post_meta($new_id, 'mri_cta_url',  $p['cta_url'] ?? '');

    if (!empty($p['internal_link_targets'])) {
      $targets = is_array($p['internal_link_targets'])
        ? $p['internal_link_targets']
        : array_filter(array_map('trim', explode(';', $p['internal_link_targets'])));
      update_post_meta($new_id, 'mri_internal_link_targets', $targets);
    }

    update_post_meta($new_id, 'mri_schema_type', $p['schema_type'] ?? 'Article');

    return $new_id;
  }

  private static function set_yoast_meta($post_id, array $p) {
    if (!empty($p['seo_title'])) {
      update_post_meta($post_id, '_yoast_wpseo_title', $p['seo_title']);
      update_post_meta($post_id, '_yoast_wpseo_opengraph-title', $p['seo_title']);
      update_post_meta($post_id, '_yoast_wpseo_twitter-title', $p['seo_title']);
    }
    if (!empty($p['seo_description'])) {
      update_post_meta($post_id, '_yoast_wpseo_metadesc', $p['seo_description']);
      update_post_meta($post_id, '_yoast_wpseo_opengraph-description', $p['seo_description']);
      update_post_meta($post_id, '_yoast_wpseo_twitter-description', $p['seo_description']);
    }
    if (!empty($p['focus_keyword'])) {
      update_post_meta($post_id, '_yoast_wpseo_focuskw', $p['focus_keyword']);
    }
    if (!empty($p['canonical_url'])) {
      $canon = $p['canonical_url'];
      if (strpos($canon, 'http') !== 0) $canon = rtrim(get_site_url(), '/') . '/' . ltrim($canon, '/');
      update_post_meta($post_id, '_yoast_wpseo_canonical', $canon);
    }
  }
}

MRI_Content_Sync::init();