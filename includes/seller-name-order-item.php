<?php

if (! defined('ABSPATH')) {
  exit;
}

define('SELLER_META_KEY', 'store_name'); // order item meta key
define('PRODUCT_URL_META_KEY', 'product_url'); // order item meta key

add_filter(
  'woocommerce_order_item_get_formatted_meta_data',
  'hide_seller_name_from_customers_formatted',
  10,
  2
);

function hide_seller_name_from_customers_formatted($formatted_meta, $item)
{

  // hide product url from admins too
  foreach ($formatted_meta as $key => $meta) {
    if ($meta->key === PRODUCT_URL_META_KEY) {
      unset($formatted_meta[$key]);
    }
  }

  // Admin screens should still see it
  if (is_admin()) {
    return $formatted_meta;
  }

  // Remove seller meta from customer-facing output
  foreach ($formatted_meta as $key => $meta) {
    if ($meta->key === SELLER_META_KEY) {
      unset($formatted_meta[$key]);
    }
  }

  return $formatted_meta;
}


/**
 * 1. COPY PRODUCT SELLER NAME → ORDER ITEM META
 */
add_action(
  'woocommerce_store_api_checkout_order_processed',
  'add_seller_name_to_order_items_store_api',
  10,
  1
);

function add_seller_name_to_order_items_store_api($order)
{

  foreach ($order->get_items() as $item) {

    $product = $item->get_product();
    if (! $product) {
      continue;
    }

    $seller_name = $product->get_meta("_external_store_name");
    $product_url = $product->get_meta("_external_product_url");

    if ($seller_name) {
      $item->add_meta_data(
        SELLER_META_KEY,
        $seller_name,
        true
      );
    }

    if ($product_url) {
      $item->add_meta_data(
        PRODUCT_URL_META_KEY,
        $product_url,
        true
      );
    }
  }

  // IMPORTANT: persist changes
  $order->save();
}

add_filter(
  'woocommerce_order_item_display_meta_value',
  'sss_make_seller_clickable',
  10,
  3
);

function sss_make_seller_clickable($display_value, $meta, $item)
{

  // Only target seller name meta
  if ($meta->key !== SELLER_META_KEY) {
    return $display_value;
  }

  // Get URL stored on the same item
  $product_url = $item->get_meta(PRODUCT_URL_META_KEY, true);

  if (! $product_url) {
    return $display_value;
  }

  return sprintf(
    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
    esc_url($product_url),
    esc_html($display_value)
  );
}
