<?php
if (! defined('ABSPATH')) {
  exit;
}

function fix_profit_margin_and_categories($store_id = 0)
{
  $product_ids = null;

  if ($store_id > 0) {
    $product_ids = get_posts([
      'post_type'      => 'product',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => '_external_store_id',
          'value'   => $store_id,
          'compare' => '=',
        ],
      ],
    ]);
  } else {
    $product_ids = get_posts([
      'post_type'      => 'product',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'fields'         => 'ids',
    ]);
  }

  $stored = get_option('store_import_settings', []);

  foreach ($product_ids as $product_id) {
    $product = wc_get_product($product_id);

    if (!$product) {
      continue;
    }

    $external_store_id = get_post_meta($product_id, '_external_store_id', true);
    $external_category_id = get_post_meta($product_id, '_external_category_id', true);
    $external_category_ids_raw = get_post_meta($product_id, '_external_category_ids', true);

    $external_category_ids = [];
    if (is_array($external_category_ids_raw)) {
      $external_category_ids = array_values(array_filter(array_map('strval', $external_category_ids_raw)));
    } elseif (is_string($external_category_ids_raw) && $external_category_ids_raw !== '') {
      $tokens = array_filter(array_map('trim', explode(',', $external_category_ids_raw)), function ($t) {
        return $t !== '';
      });
      $external_category_ids = array_values(array_unique($tokens));
    } elseif ($external_category_id !== '') {
      $external_category_ids = [(string) $external_category_id];
    }

    $default_profit_margin = $stored["default_profit_margin"] ?? 1000;
    $profit_margin = $default_profit_margin;
    $woo_category_ids = [];
    foreach ($external_category_ids as $cat_id) {
      $mapped_wp_cat = $stored['category_mappings'][$external_store_id][$cat_id]['wp_category'] ?? 0;
      if ($mapped_wp_cat) {
        $woo_category_ids[] = (int) $mapped_wp_cat;
      }
      $cat_margin = $stored['category_mappings'][$external_store_id][$cat_id]['profit_margin'] ?? $default_profit_margin;
      if ($cat_margin > $profit_margin) {
        $profit_margin = $cat_margin;
      }
    }
    $woo_category_ids = array_values(array_unique(array_filter($woo_category_ids)));

    $product->set_category_ids($woo_category_ids);

    $product_id = $product->get_id();
    $current_price = get_post_meta($product_id, '_external_current_price', true);
    $orignal_price = get_post_meta($product_id, '_external_orignal_price', true);
    // SIMPLE PRODUCT
    if ($product->is_type('simple')) {
      update_simple_product_price($product, $profit_margin, $current_price, $orignal_price);
    }

    // VARIABLE PRODUCT
    if ($product->is_type('variable')) {
      update_variable_product_prices($product, $profit_margin, $current_price, $orignal_price);
    }
  }
}

function update_simple_product_price($product, $margin, $orignal_price, $current_price)
{
  $new_price = $orignal_price + $margin;
  $new_sale_price = $current_price + $margin;

  $product->set_regular_price(round($new_price, 2));
  $product->set_sale_price(round($new_sale_price, 2));

  $product->save();
}

function update_variable_product_prices($product, $margin, $orignal_price, $current_price)
{
  foreach ($product->get_children() as $variation_id) {
    $variation = wc_get_product($variation_id);

    if (!$variation) {
      continue;
    }

    $new_price = $orignal_price + $margin;
    $new_sale_price = $current_price + $margin;

    $variation->set_regular_price(round($new_price, 2));
    $variation->set_sale_price(round($new_sale_price, 2));

    $variation->save();
  }

  // Sync min/max prices for variable product
  $product->save();
}
