<?php

// includes/rest-handler.php
if (! defined('ABSPATH')) {
    exit;
}

require_once MSI_PATH . 'includes/class-data-provider.php';
require_once MSI_PATH . 'includes/class-settings.php';

/**
 * Register REST route
 */
add_action('rest_api_init', function () {
    register_rest_route(
        'product-sync/v1',
        '/products',
        array(
            'methods'             => array('GET', 'POST'),
            'callback'            => 'sss_handle_request',
            'permission_callback' => '__return_true',
        )
    );
});

add_action('init', function () {
    if (taxonomy_exists('product_brand')) {
        return;
    }
    register_taxonomy(
        'product_brand',
        'product',
        array(
            'label'                 => 'Brands',
            'public'                => true,
            'hierarchical'          => false,
            'show_ui'               => true,
            'show_admin_column'     => true,
            'show_in_quick_edit'    => true,
            'rewrite'               => array('slug' => 'brand'),
        )
    );
});

/**
 * REST API Handler: Saves file and initiates background processing
 */
function sss_handle_request(WP_REST_Request $request)
{
    if ($request->get_method() === 'GET') {
        return new WP_REST_Response(
            array(
                'ok'      => true,
                'message' => 'Product CSV endpoint is working. Send POST with a CSV file.',
            ),
            200
        );
    }

    if (! class_exists('WooCommerce')) {
        return new WP_Error('sss_no_woocommerce', 'WooCommerce must be active.', array('status' => 500));
    }

    $files = $request->get_file_params();
    if (empty($files['file']) || ! isset($files['file']['tmp_name'])) {
        return new WP_Error('sss_no_file', 'No CSV file uploaded.', array('status' => 400));
    }

    $upload_dir = wp_upload_dir();
    $sync_dir   = $upload_dir['basedir'] . '/product-sync-temp/';

    if (! file_exists($sync_dir)) {
        wp_mkdir_p($sync_dir);
    }

    $filename  = 'sync_' . uniqid() . '.csv';
    $full_path = $sync_dir . $filename;

    if (! move_uploaded_file($files['file']['tmp_name'], $full_path)) {
        return new WP_Error('sss_upload_fail', 'Could not save CSV file locally.', array('status' => 500));
    }

    as_enqueue_async_action('sss_process_csv_batch', array(
        'file_path' => $full_path,
        'row_index' => 0,
        'stats'     => array(
            'created'         => 0,
            'stock_updated'   => 0,
            'stock_unchanged' => 0,
            'errors'          => array()
        )
    ), 'product_sync_group');

    return new WP_REST_Response(
        array(
            'ok'      => true,
            'message' => 'File received. Background processing started in batches of 100.',
            'job_id'  => $filename
        ),
        202
    );
}

/**
 * Background Worker Hooked to Action Scheduler
 */
add_action('sss_process_csv_batch', 'sss_run_product_batch', 10, 3);
function sss_run_product_batch($csv_path, $start_row, $stats)
{
    if (! file_exists($csv_path)) return;

    @ini_set('max_execution_time', 300);
    @ini_set('memory_limit', '512M');
    wp_suspend_cache_invalidation(true);

    if (! function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();

    $handle = fopen($csv_path, 'r');
    $header = fgetcsv($handle, 0, ',');

    $batch_size      = 100;
    $current_row     = 0;
    $processed_count = 0;

    while ($current_row < $start_row && ($data = fgetcsv($handle, 0, ',')) !== false) {
        $current_row++;
    }

    $external_map = sss_build_external_map();
    $stored       = get_option('store_import_settings', []);
    $max_errors   = 2000;

    while ($processed_count < $batch_size && ($data = fgetcsv($handle, 0, ',')) !== false) {
        $current_row++;
        $processed_count++;

        try {
            $row = array();
            foreach ($header as $index => $column_name) {
                $key = strtolower(trim($column_name, " \t\n\r\0\x0B\""));
                $row[$key] = isset($data[$index]) ? trim($data[$index]) : '';
            }

            $external_product_id = $row['product_id'] ?? '';
            $product_name        = $row['product_name'] ?? '';
            $image_url           = $row['image_url'] ?? '';
            $image_gallery_raw   = $row['product_images'] ?? '';
            $current_price       = $row['current_price'] ?? '';
            $original_price      = $row['original_price'] ?? '';
            $stock_status_raw    = $row['stock_status'] ?? '';
            $is_active_raw       = $row['is_active'] ?? '';
            $has_variants_raw    = $row['has_variants'] ?? '';
            $variants_raw        = $row['variants'] ?? '';
            $external_store_id   = $row['store_id'];
            $external_store_name = $row['store_name'];
            $external_product_url = $row['product_url'];
            $categories_raw      = $row['categories'] ?? '';

            $external_category_ids = [];
            if ($categories_raw !== '') {
                $tokens = array_filter(array_map('trim', explode(',', $categories_raw)), function ($t) {
                    return $t !== '';
                });
                $external_category_ids = array_values(array_unique($tokens));
            } elseif (isset($row['category_id']) && $row['category_id'] !== '') {
                $external_category_ids = [(string) $row['category_id']];
            }

            $external_category_id = $external_category_ids[0] ?? '';
            $default_profit_margin = $stored["default_profit_margin"] ?? 1000;
            $min_price_filter = $stored["min_price_filter"] ?? 0;
            $max_price_filter = $stored["max_price_filter"] ?? 0;
            $skip_unmapped_cat = $stored["skip_unmapped_cat"] ?? 'yes';
            $profit_margin = $default_profit_margin;
            $woo_category_ids = [];
            foreach ($external_category_ids as $cat_id) {
                $mapped_wp_cat = $stored['category_mappings'][$external_store_id][$cat_id]['wp_category'] ?? 0;
                if ($mapped_wp_cat) $woo_category_ids[] = (int) $mapped_wp_cat;
                $cat_margin = $stored['category_mappings'][$external_store_id][$cat_id]['profit_margin'] ?? $default_profit_margin;
                if ($cat_margin > $profit_margin) $profit_margin = $cat_margin;
            }

            $woo_category_ids = array_values(array_unique(array_filter($woo_category_ids)));

            if ($skip_unmapped_cat === 'yes' && empty($woo_category_ids)) {
                $stats['skip'][] = array('row' => $current_row, 'reason' => 'Category Mapping Not Found.');
                continue;
            }

            if (($min_price_filter > 0 && $current_price < $min_price_filter) || ($max_price_filter > 0 && $current_price > $max_price_filter)) {
                if ($current_price < $min_price_filter) {
                    $stats['skip'][] = array('row' => $current_row, 'reason' => 'Current price is less than minimum price filter.');
                }

                if ($current_price > $max_price_filter) {
                    $stats['skip'][] = array('row' => $current_row, 'reason' => 'Current price is greater than maximum price filter.');
                }

                continue; // Skip product
            }

            $external_category_ids_csv = implode(',', $external_category_ids);
            $external_brand_id   = $row['brand_id'] ?? '';
            $external_brand_name = $row['brand_name'] ?? '';
            $short_description_raw = $row['short_description'] ?? '';
            $short_description = $short_description_raw !== '' ? sss_sanitize_product_html(sss_normalize_video_html($short_description_raw)) : '';
            $description_raw = $row['description'] ?? '';
            $description = $description_raw !== '' ? sss_sanitize_product_html(sss_normalize_video_html($description_raw)) : '';
            $attributes_raw      = $row['attributes'] ?? '';
            $price_with_profit = $current_price;

            if ($profit_margin) $price_with_profit += $profit_margin;
            if ($original_price < $price_with_profit) $original_price = $price_with_profit;

            $gallery_urls = array_filter(array_map('trim', explode(',', $image_gallery_raw)));

            if ($external_product_id === '') {
                if (count($stats['errors']) < $max_errors) {
                    $stats['errors'][] = array('row' => $current_row, 'error' => 'Missing product_id.');
                }
                continue;
            }

            $new_wc_status   = sss_map_stock_status($stock_status_raw);
            $new_post_status = sss_map_active_to_status($is_active_raw);
            $has_variants    = in_array(strtolower(trim($has_variants_raw)), array('1', 'true', 'yes', 'y', 'on'), true);

            $product = null;
            if (isset($external_map[$external_product_id])) {
                $product = wc_get_product($external_map[$external_product_id]);
            }

            $parent_image_id = 0;
            if ($product) {
                $parent_image_id = (int) get_post_thumbnail_id($product->get_id());
                $p_id = $product->get_id();
                $current_type = $product->get_type();

                // Simple to Variable
                if ($has_variants && $current_type === 'simple') {
                    $product_id = sss_convert_product_type($p_id, 'variable');
                    $product = wc_get_product($p_id);
                }
                // Variable to Simple
                elseif (!$has_variants && $current_type === 'variable') {
                    $product_id = sss_convert_product_type($p_id, 'simple');
                    $product = wc_get_product($p_id);
                }
            }

            if ($has_variants) {
                $variants = json_decode($variants_raw, true);
                if (is_array($variants) && ! empty($variants)) {
                    $looks_like_name_value = true;
                    foreach ($variants as $v) {
                        if (! is_array($v) || ! isset($v['name'], $v['value'])) {
                            $looks_like_name_value = false;
                            break;
                        }
                    }
                    if ($looks_like_name_value) {
                        $normalized = array();
                        foreach ($variants as $v) {
                            $attr_name = strtolower(trim((string) $v['name']));
                            $attr_value = (string) $v['value'];
                            if ($attr_name === '' || $attr_value === '') continue;
                            $normalized[] = array(
                                'sku' => '',
                                'sale_price' => $price_with_profit ?: '',
                                'orignal_price' => $original_price ?: '',
                                'stock_status' => $stock_status_raw ?: '',
                                'stock_quantity' => (strtolower(trim($stock_status_raw)) === 'in_stock') ? 1000 : 0,
                                'image_url' => '',
                                'attributes' => array($attr_name => $attr_value),
                            );
                        }
                        $variants = $normalized;
                    }
                }

                if (! is_array($variants) || empty($variants)) {
                    if (count($stats['errors']) < $max_errors) {
                        $stats['errors'][] = array('row' => $current_row, 'product_id' => $external_product_id, 'error' => 'Invalid variants JSON.');
                    }
                    continue;
                }

                $attribute_values = array();
                foreach ($variants as $v) {
                    if (isset($v['attributes']) && is_array($v['attributes'])) {
                        foreach ($v['attributes'] as $attr_name => $attr_value) {
                            $attr_name_l = strtolower(trim($attr_name));
                            $attr_value_s = (string) $attr_value;
                            if ($attr_value_s === '') continue;
                            if (! isset($attribute_values[$attr_name_l])) $attribute_values[$attr_name_l] = array();
                            if (! in_array($attr_value_s, $attribute_values[$attr_name_l], true)) $attribute_values[$attr_name_l][] = $attr_value_s;
                        }
                    }
                }

                if (! $product) {
                    $parent = new WC_Product_Variable();
                    $parent->set_name($product_name ?: 'Variant product ' . $external_product_id);
                    if ($short_description !== '') $parent->set_short_description($short_description);
                    if ($description !== '') $parent->set_description($description);
                    if ($new_post_status) $parent->set_status($new_post_status);
                    if (is_array($woo_category_ids)) $parent->set_category_ids($woo_category_ids);
                    $parent->set_manage_stock(false);
                    $parent_id = $parent->save();
                    save_brands($external_brand_name, $external_brand_id, $parent_id);
                    if (is_wp_error($parent_id)) continue;
                    $parent->update_meta_data('_external_product_id', $external_product_id);
                    $parent->update_meta_data('_external_store_name', $external_store_name);
                    $parent->update_meta_data('_external_product_url', $external_product_url);
                    $parent->update_meta_data('_external_current_price', $current_price);
                    $parent->update_meta_data('_external_orignal_price', $original_price);
                    $parent->update_meta_data('_external_category_id', $external_category_id);
                    $parent->update_meta_data('_external_category_ids', $external_category_ids_csv);
                    $parent->update_meta_data('_external_store_id', $external_store_id);
                    $parent->save();
                    $product = wc_get_product($parent_id);
                    $stats['created']++;
                    if ($image_url !== '') {
                        $parent_image_id = sss_set_product_image_from_url($parent_id, $image_url);
                        if ($parent_image_id) {
                            $product->set_image_id($parent_image_id);
                            $product->update_meta_data('_external_image_src', $image_url);
                            $product->save();
                        }
                    }
                    set_gallery_images($parent_id, $gallery_urls, $image_url);
                } else {
                    if ($product->get_type() !== 'variable') {
                        $parent = new WC_Product_Variable();
                        $parent->set_name($product_name ?: $product->get_name());
                        if ($short_description !== '') $parent->set_short_description($short_description);
                        if ($description !== '') $parent->set_description($description);
                        if ($new_post_status) $parent->set_status($new_post_status);
                        if (is_array($woo_category_ids)) $parent->set_category_ids($woo_category_ids);
                        $parent_id = $parent->save();
                        $parent->update_meta_data('_external_product_id', $external_product_id);
                        $parent->update_meta_data('_external_store_name', $external_store_name);
                        $parent->update_meta_data('_external_product_url', $external_product_url);
                        $parent->update_meta_data('_external_current_price', $current_price);
                        $parent->update_meta_data('_external_orignal_price', $original_price);
                        $parent->update_meta_data('_external_category_id', $external_category_id);
                        $parent->update_meta_data('_external_category_ids', $external_category_ids_csv);
                        $parent->update_meta_data('_external_store_id', $external_store_id);
                        $parent->save();
                        save_brands($external_brand_name, $external_brand_id, $parent_id);
                        $product = wc_get_product($parent_id);
                        if ($image_url !== '') {
                            $parent_image_id = sss_set_product_image_from_url($parent_id, $image_url);
                            if ($parent_image_id) {
                                $product->set_image_id($parent_image_id);
                                $product->update_meta_data('_external_image_src', $image_url);
                                $product->save();
                            }
                        }
                        set_gallery_images($parent_id, $gallery_urls, $image_url);
                    } else {
                        $product->update_meta_data('_external_current_price', $current_price);
                        $product->update_meta_data('_external_orignal_price', $original_price);
                        $product->update_meta_data('_external_category_id', $external_category_id);
                        $product->update_meta_data('_external_category_ids', $external_category_ids_csv);
                        $product->update_meta_data('_external_store_id', $external_store_id);
                        if (is_array($woo_category_ids) && $woo_category_ids !== $product->get_category_ids()) {
                            $product->set_category_ids($woo_category_ids);
                        }
                        if ($short_description !== '') $product->set_short_description($short_description);
                        if ($description !== '') $product->set_description($description);
                        $product->save();
                        $parent_id = $product->get_id();
                        if ($image_url !== '') {
                            $parent_image_id = sss_set_product_image_from_url($parent_id, $image_url);
                            if ($parent_image_id) {
                                $product->set_image_id($parent_image_id);
                                $product->update_meta_data('_external_image_src', $image_url);
                                $product->save();
                            }
                        }
                        set_gallery_images($parent_id, $gallery_urls, $image_url);
                        save_brands($external_brand_name, $external_brand_id, $parent_id);
                        $stats['stock_unchanged']++;
                    }
                }

                $parent_attributes = array();
                foreach ($attribute_values as $attr_name => $values) {
                    $attr = new WC_Product_Attribute();
                    $attr->set_id(0);
                    $attr->set_name($attr_name);
                    $attr->set_options(array_values($values));
                    $attr->set_position(0);
                    $attr->set_visible(true);
                    $attr->set_variation(true);
                    $parent_attributes[] = $attr;
                }

                try {
                    $extra_attributes = sss_build_non_variant_attributes_for_product($parent_id, $attributes_raw, $stats['errors'], $current_row, $external_product_id);
                    $parent_attributes = sss_merge_product_attributes($parent_attributes, $extra_attributes);
                    if (! empty($parent_attributes)) {
                        $product->set_attributes($parent_attributes);
                        $product->save();
                    }
                } catch (Exception $e) {
                }

                $parent_id = $product->get_id();
                $existing_variation_ids = $product->get_children();

                foreach ($variants as $v) {
                    if (empty($v['attributes']) || ! is_array($v['attributes'])) continue;
                    $variation_attributes = array();
                    foreach ($v['attributes'] as $k_attr => $v_attr) {
                        $variation_attributes[strtolower(trim($k_attr))] = (string) $v_attr;
                    }

                    $found_variant = null;
                    if (! empty($existing_variation_ids)) {
                        foreach ($existing_variation_ids as $var_id) {
                            $var = wc_get_product($var_id);
                            if (! $var) continue;
                            $match = true;
                            foreach ($variation_attributes as $k_attr => $k_val) {
                                $meta_key = 'attribute_' . sanitize_title($k_attr);
                                if ((string) get_post_meta($var_id, $meta_key, true) !== (string) $k_val) {
                                    $match = false;
                                    break;
                                }
                            }
                            if ($match) {
                                $found_variant = $var;
                                break;
                            }
                        }
                    }

                    $var_sku = isset($v['sku']) ? trim($v['sku']) : '';
                    $var_price = isset($v['sale_price']) ? trim($v['sale_price']) : ($price_with_profit ?: '');
                    $var_orignal_price = isset($v['orignal_price']) ? trim($v['orignal_price']) : ($original_price ?: '');
                    $var_stock_status = isset($v['stock_status']) ? sss_map_stock_status($v['stock_status']) : $new_wc_status;
                    $var_stock_qty = isset($v['stock_quantity']) ? intval($v['stock_quantity']) : ($var_stock_status === 'in_stock' ? 1000 : 0);
                    $var_image_url = isset($v['image_url']) ? trim($v['image_url']) : '';

                    if (! $var_sku) {
                        $snippet = sanitize_title(implode('-', array_values($variation_attributes)));
                        $var_sku = substr($external_product_id . '-' . ($snippet ?: 'v'), 0, 60);
                    }

                    if ($found_variant) {
                        $variation = $found_variant;
                    } else {
                        $variation = new WC_Product_Variation();
                        $variation->set_parent_id($parent_id);
                    }

                    $variation->set_attributes($variation_attributes);
                    $variation->set_sku($var_sku);
                    if ($var_price !== '') $variation->set_sale_price($var_price);
                    if ($var_orignal_price !== '') $variation->set_regular_price($var_orignal_price);
                    $variation->set_manage_stock(true);
                    $variation->set_stock_status($var_stock_status);
                    $variation->set_stock_quantity($var_stock_qty);

                    $variation_id = $variation->save();
                    foreach ($variation_attributes as $attr_k => $attr_v) {
                        update_post_meta($variation_id, 'attribute_' . sanitize_title($attr_k), (string) $attr_v);
                    }

                    if ($var_image_url !== '') {
                        $att_id = sss_set_product_image_from_url($parent_id, $var_image_url);
                        if ($att_id) update_post_meta($variation_id, '_thumbnail_id', $att_id);
                    } elseif ($parent_image_id) {
                        update_post_meta($variation_id, '_thumbnail_id', (int) $parent_image_id);
                    }
                    $variation->save();
                }
                continue;
            }

            if (! $product) {
                if ($product_name === '') continue;
                try {
                    $product = new WC_Product_Simple();
                    $product->set_name($product_name);
                    if ($short_description !== '') $product->set_short_description($short_description);
                    if ($description !== '') $product->set_description($description);
                    if ($current_price !== '') {
                        $product->set_sale_price($price_with_profit);
                        $product->set_regular_price($original_price);
                    }
                    if (is_array($woo_category_ids)) $product->set_category_ids($woo_category_ids);
                    if ($new_post_status) $product->set_status($new_post_status);
                    $product->set_manage_stock(true);
                    $product->set_stock_status($new_wc_status);
                    $product->set_stock_quantity((strtolower(trim($new_wc_status)) === 'in_stock') ? 1000 : 0);
                    $product_id = $product->save();
                    save_brands($external_brand_name, $external_brand_id, $product_id);
                    $product->update_meta_data('_external_product_id', $external_product_id);
                    $product->update_meta_data('_external_store_name', $external_store_name);
                    $product->update_meta_data('_external_product_url', $external_product_url);
                    if ($image_url !== '') {
                        $attachment_id = sss_set_product_image_from_url($product_id, $image_url);
                        if ($attachment_id) {
                            $product->set_image_id($attachment_id);
                            $product->update_meta_data('_external_image_src', $image_url);
                        }
                    }
                    $product->update_meta_data('_external_current_price', $current_price);
                    $product->update_meta_data('_external_orignal_price', $original_price);
                    $product->update_meta_data('_external_category_id', $external_category_id);
                    $product->update_meta_data('_external_category_ids', $external_category_ids_csv);
                    $product->update_meta_data('_external_store_id', $external_store_id);
                    set_gallery_images($product_id, $gallery_urls, $image_url);
                    $extra_attributes = sss_build_non_variant_attributes_for_product($product_id, $attributes_raw, $stats['errors'], $current_row, $external_product_id);
                    $merged = sss_merge_product_attributes($product->get_attributes(), $extra_attributes);
                    if (! empty($merged)) $product->set_attributes($merged);
                    $product->save();
                    $stats['created']++;
                } catch (Exception $e) {
                }
            } else {
                try {
                    $needs_save = false;
                    if (!empty($product_name) && $product->get_name() !== $product_name) {
                        $product->set_name($product_name);
                        $needs_save = true;
                    }
                    if ($new_wc_status !== $product->get_stock_status()) {
                        $product->set_manage_stock(true);
                        $product->set_stock_status($new_wc_status);
                        $product->set_stock_quantity((strtolower(trim($new_wc_status)) === 'in_stock') ? 1000 : 0);
                        $stats['stock_updated']++;
                        $needs_save = true;
                    } else {
                        $stats['stock_unchanged']++;
                    }

                    if ($new_post_status && $new_post_status !== $product->get_status()) {
                        $product->set_status($new_post_status);
                        $needs_save = true;
                    }
                    if ($short_description !== '' && $short_description !== $product->get_short_description()) {
                        $product->set_short_description($short_description);
                        $needs_save = true;
                    }
                    if ($description !== '' && $description !== $product->get_description()) {
                        $product->set_description($description);
                        $needs_save = true;
                    }
                    if ($original_price !== '' && $original_price != $product->get_regular_price()) {
                        $product->set_regular_price($original_price);
                        $needs_save = true;
                    }
                    if ($current_price !== '' && $current_price != $product->get_sale_price()) {
                        $product->set_sale_price($price_with_profit);
                        $needs_save = true;
                    }
                    if (is_array($woo_category_ids) && $woo_category_ids !== $product->get_category_ids()) {
                        $product->set_category_ids($woo_category_ids);
                        $needs_save = true;
                    }

                    $product_id = $product->get_id();
                    if ($image_url !== '' && $product->get_meta('_external_image_src', true) !== $image_url) {
                        $attachment_id = sss_set_product_image_from_url($product_id, $image_url);
                        if ($attachment_id) {
                            $product->set_image_id($attachment_id);
                            $product->update_meta_data('_external_image_src', $image_url);
                        }
                        $needs_save = true;
                    }
                    $product->update_meta_data('_external_current_price', $current_price);
                    $product->update_meta_data('_external_orignal_price', $original_price);
                    $product->update_meta_data('_external_category_id', $external_category_id);
                    $product->update_meta_data('_external_category_ids', $external_category_ids_csv);
                    $product->update_meta_data('_external_store_id', $external_store_id);
                    set_gallery_images($product_id, $gallery_urls, $image_url);
                    save_brands($external_brand_name, $external_brand_id, $product_id);
                    $extra_attributes = sss_build_non_variant_attributes_for_product($product_id, $attributes_raw, $stats['errors'], $current_row, $external_product_id);
                    $merged = sss_merge_product_attributes($product->get_attributes(), $extra_attributes);
                    if (! empty($merged)) {
                        $product->set_attributes($merged);
                        $needs_save = true;
                    }
                    if ($needs_save) $product->save();
                } catch (Exception $e) {
                }
            }
        } catch (Exception $e) {
            // Log the error to your stats so it doesn't just disappear
            if (count($stats['errors']) < $max_errors) {
                $stats['errors'][] = array(
                    'row' => $current_row,
                    'product_id' => $external_product_id ?? 'Unknown',
                    'error' => $e->getMessage()
                );
            }
            // Continue to the next row instead of exiting the function
            continue;
        }
    }

    $has_more = ! feof($handle);
    fclose($handle);

    if ($has_more) {
        as_enqueue_async_action('sss_process_csv_batch', array(
            'file_path' => $csv_path,
            'row_index' => $current_row,
            'stats'     => $stats
        ), 'product_sync_group');
    } else {
        sss_dispatch_completion_data($stats);
        unlink($csv_path);
    }

    wp_suspend_cache_invalidation(false);
}

/**
 * Sends final data to external API
 */
function sss_dispatch_completion_data($stats)
{
    error_log(json_encode($stats));
    $api_url = 'https://your-external-api.com/callback';
    wp_remote_post($api_url, array(
        'method'    => 'POST',
        'headers'   => array('Content-Type' => 'application/json'),
        'body'      => json_encode(array(
            'status'    => 'completed',
            'summary'   => $stats,
            'timestamp' => current_time('mysql'),
        )),
        'timeout'   => 45,
        'blocking'  => false,
    ));
}

/**
 * --- HELPER FUNCTIONS ---
 */

function sss_build_non_variant_attributes_for_product($product_id, $attributes_raw, &$errors, $row_number, $external_product_id)
{
    if (! $attributes_raw) return [];
    $data = json_decode($attributes_raw, true);
    if (! is_array($data)) return [];

    $attributes = [];
    foreach ($data as $attr) {
        if (! is_array($attr)) continue;
        $taxonomy = isset($attr['taxonomy']) ? (string) $attr['taxonomy'] : '';
        $attr_label = isset($attr['name']) ? (string) $attr['name'] : '';
        if (! $taxonomy || ! empty($attr['has_variations'])) continue;

        if (! taxonomy_exists($taxonomy)) sss_ensure_attribute_taxonomy($taxonomy, $attr_label);
        if (! taxonomy_exists($taxonomy)) continue;

        $terms = isset($attr['terms']) && is_array($attr['terms']) ? $attr['terms'] : [];
        $term_ids = [];
        foreach ($terms as $term) {
            $term_name = isset($term['name']) ? (string) $term['name'] : '';
            $term_slug = isset($term['slug']) ? (string) $term['slug'] : '';
            $existing = $term_slug !== '' ? term_exists($term_slug, $taxonomy) : term_exists($term_name, $taxonomy);

            if (! $existing && $term_name !== '') {
                $inserted = wp_insert_term($term_name, $taxonomy, $term_slug ? ['slug' => $term_slug] : []);
                if (! is_wp_error($inserted)) $term_ids[] = (int) $inserted['term_id'];
            } elseif (is_array($existing)) {
                $term_ids[] = (int) $existing['term_id'];
            }
        }

        $term_ids = array_values(array_unique(array_filter($term_ids)));
        if (empty($term_ids)) continue;

        wp_set_object_terms($product_id, $term_ids, $taxonomy, false);
        $attr_obj = new WC_Product_Attribute();
        $attr_obj->set_name($taxonomy);
        $attr_obj->set_options($term_ids);
        $attr_obj->set_visible(true);
        $attr_obj->set_variation(false);
        $attributes[] = $attr_obj;
    }
    return $attributes;
}

function sss_merge_product_attributes($base_attributes, $extra_attributes)
{
    $merged = [];
    foreach ((array) $base_attributes as $attr) {
        if ($attr instanceof WC_Product_Attribute) $merged[$attr->get_name()] = $attr;
    }
    foreach ((array) $extra_attributes as $attr) {
        if (! $attr instanceof WC_Product_Attribute) continue;
        $name = $attr->get_name();
        if (isset($merged[$name])) {
            $existing = $merged[$name];
            $options = array_unique(array_merge($existing->get_options(), $attr->get_options()));
            $existing->set_options(array_values($options));
            $merged[$name] = $existing;
        } else {
            $merged[$name] = $attr;
        }
    }
    return array_values($merged);
}

function sss_sanitize_product_html($html)
{
    if ($html === '') return '';
    $allowed = wp_kses_allowed_html('post');
    $allowed['div'] = array('style' => true, 'class' => true, 'id' => true);
    $allowed['video'] = array('class' => true, 'width' => true, 'height' => true, 'controls' => true, 'src' => true);
    $allowed['source'] = array('src' => true, 'type' => true);
    return wp_kses($html, $allowed);
}

function sss_normalize_video_html($html)
{
    if ($html === '') return '';
    $pattern = '/<video\\b(?![^>]*\\bsrc=)([^>]*)>(.*?)<\\/video>/is';
    return preg_replace_callback($pattern, function ($m) {
        if (stripos($m[2], '<source') !== false) return $m[0];
        if (preg_match("~<a\\b[^>]*href=([\"'])([^\"']+)\\1[^>]*>.*?</a>~is", $m[2], $am)) {
            if (preg_match('/\\.(mp4|webm|ogg)/i', $am[2])) return '<video' . $m[1] . ' src="' . esc_url($am[2]) . '"></video>';
        }
        return $m[0];
    }, $html);
}

function sss_ensure_attribute_taxonomy($taxonomy, $label = '')
{
    if ($taxonomy === '' || taxonomy_exists($taxonomy)) return true;
    if (strpos($taxonomy, 'pa_') !== 0 || ! function_exists('wc_create_attribute')) return false;
    $slug = wc_sanitize_taxonomy_name(substr($taxonomy, 3));
    wc_create_attribute(['name' => $label ?: ucwords($slug), 'slug' => $slug, 'type' => 'select']);
    register_taxonomy($taxonomy, ['product'], ['hierarchical' => false, 'public' => false]);
    return true;
}

function save_brands($external_brand_name, $external_brand_id, $product_id)
{
    $brand_term_id = sss_get_or_create_brand($external_brand_name, $external_brand_id);
    if ($brand_term_id) wp_set_object_terms($product_id, array($brand_term_id), 'product_brand', false);
}

/**
 * Changes product type while preserving the same Product ID
 */
function sss_convert_product_type($product_id, $new_type)
{
    // Update the term in the database
    wp_set_object_terms($product_id, $new_type, 'product_type');

    // If moving to simple, delete the old leftover variations
    if ($new_type === 'simple') {
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                wp_delete_post($child_id, true);
            }
        }
    }

    // Clear WooCommerce transients so the new class (Simple/Variable) loads correctly
    wc_delete_product_transients($product_id);

    return $product_id;
}
