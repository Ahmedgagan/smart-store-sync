<?php

if (! defined('ABSPATH')) {
  exit;
}

/**
 * Automatically list all item-specific URLs in a clickable Order Note
 */

add_action('woocommerce_store_api_checkout_order_processed', 'add_item_urls_to_mobile_notes', 10, 2);

function add_item_urls_to_mobile_notes($order)
{
  $note_content = "Product Links:\n";
  $has_links = false;

  foreach ($order->get_items() as $item) {
    $product = $item->get_product();

    if ($url = $product->get_meta("_external_product_url")) {
      $note_content .= "- " . $item->get_name() . " X " . $item->get_quantity() . ": " . esc_url_raw($url) . "\n";
      $has_links = true;
    }
  }

  if ($has_links) {
    // Add as a private note so it appears in the app's 'Notes' section
    $order->add_order_note($note_content);
  }
}
