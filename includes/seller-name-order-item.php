<?php

if (! defined('ABSPATH')) {
  exit;
}

/**
 * Automatically list all item-specific URLs in a clickable Order Note
 */

add_action('woocommerce_store_api_checkout_order_processed', 'sss_add_item_urls_to_order_notes', 10, 2);

function sss_add_item_urls_to_order_notes($order)
{

  $note_content = "Product Links:\n";
  $has_links    = false;

  foreach ($order->get_items() as $item) {

    $product = $item->get_product();

    if (! $product) {
      continue;
    }

    $url        = '';
    $store_name = '';

    // Try variation meta first
    if ($product->is_type('variation')) {

      $url        = $product->get_meta('_external_product_url');
      $store_name = $product->get_meta('_external_store_name');

      // Fallback to parent product
      if (! $url) {
        $parent = wc_get_product($product->get_parent_id());

        if ($parent) {
          $url        = $parent->get_meta('_external_product_url');
          $store_name = $parent->get_meta('_external_store_name');
        }
      }
    } else {
      // Simple product
      $url        = $product->get_meta('_external_product_url');
      $store_name = $product->get_meta('_external_store_name');
    }

    if ($url) {
      $note_content .= sprintf(
        "- %s × %d (%s): %s\n",
        $item->get_name(),
        $item->get_quantity(),
        $store_name ?: '—',
        esc_url_raw($url)
      );
      $has_links = true;
    }
  }

  if ($has_links) {
    // Private note → visible in admin + Woo app
    $order->add_order_note($note_content, false);
  }
}
