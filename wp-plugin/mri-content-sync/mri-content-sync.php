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

  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

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
