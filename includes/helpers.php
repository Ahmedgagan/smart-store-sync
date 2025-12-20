<?php
// includes/helpers.php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Build external map
 */
function product_csv_sync_build_external_map()
{
    global $wpdb;
    $meta_key = '_external_product_id';
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
    return $map;
}

/**
 * Map stock status
 */
function product_csv_sync_map_stock_status($value)
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
function product_csv_sync_map_active_to_status($value)
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
