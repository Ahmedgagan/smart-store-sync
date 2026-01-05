<?php
// includes/helpers.php
if (! defined('ABSPATH')) {
    exit;
}

const CACHE_KEY   = 'smart_store_sync_build_external_map';
const CACHE_GRP   = 'smart_store_sync';
const CACHE_TTL       = 600; // seconds (10 minutes) – adjust as you like

/**
 * Build external map
 */
function sss_build_external_map()
{
    global $wpdb;
    $meta_key = '_external_product_id';

    // Try cache first
    $cached = wp_cache_get(CACHE_KEY, CACHE_GRP);
    if (false !== $cached && is_array($cached)) {
        return $cached;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $results = $wpdb->get_results(
        $wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key),
        ARRAY_A
    );

    $map = array();
    if (! empty($results)) {
        foreach ($results as $row) {
            $external_id = (string) $row['meta_value'];
            $post_id     = (int) $row['post_id'];
            if ($external_id !== '') {
                $map[$external_id] = $post_id;
            }
        }
    }

    // Cache it
    wp_cache_set(CACHE_KEY, $map, CACHE_GRP, CACHE_TTL);

    return $map;
}

/**
 * Map stock status
 */
function sss_map_stock_status($value)
{
    $value = strtolower(trim($value));
    $in_values  = array('instock', 'in_stock', 'available', '1', 'true', 'yes');
    $out_values = array('outofstock', 'out_of_stock', '0', 'false', 'no');
    if (in_array($value, $in_values, true)) {
        return 'in_stock';
    }
    if (in_array($value, $out_values, true)) {
        return 'out_of_stock';
    }
    return 'out_of_stock';
}

/**
 * Map active -> post status
 */
function sss_map_active_to_status($value)
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    $active_values   = array('1', 'true', 'yes', 'active');
    $inactive_values = array('0', 'false', 'no', 'inactive');
    if (in_array($value, $active_values, true)) {
        return 'publish';
    }
    if (in_array($value, $inactive_values, true)) {
        return 'draft';
    }
    return '';
}

/**
 * get or create brands
 */

function sss_get_or_create_brand($brand_name, $external_brand_id = '')
{

    if (empty($brand_name)) {
        return 0;
    }

    // 1. Try exact name match
    $term = term_exists($brand_name, 'product_brand');

    if ($term && ! is_wp_error($term)) {
        return (int) $term['term_id'];
    }

    // 2. Try external brand ID match
    if ($external_brand_id !== '') {
        $terms = get_terms(array(
            'taxonomy'   => 'product_brand',
            'hide_empty' => false,
            'meta_query' => array(
                array(
                    'key'   => '_external_brand_id',
                    'value' => (string) $external_brand_id,
                ),
            ),
        ));

        if (! is_wp_error($terms) && ! empty($terms)) {
            return (int) $terms[0]->term_id;
        }
    }

    // 3. Create brand
    $term = wp_insert_term($brand_name, 'product_brand');

    if (is_wp_error($term)) {
        return 0;
    }

    if ($external_brand_id !== '') {
        update_term_meta(
            $term['term_id'],
            '_external_brand_id',
            (string) $external_brand_id
        );
    }

    return (int) $term['term_id'];
}
