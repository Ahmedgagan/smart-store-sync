<?php
// includes/image-handler.php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create (or reuse) a "virtual" attachment pointing to a remote image URL,
 * ensure minimal meta exists so WooCommerce will accept it as a thumbnail,
 * and set it as the product thumbnail.
 *
 * Returns attachment ID (int) on success, or 0 on failure.
 */
function product_csv_sync_set_product_image_from_url($product_id, $image_url)
{
    if (! filter_var($image_url, FILTER_VALIDATE_URL)) {
        return 0;
    }

    global $wpdb;

    // 1) Try dedupe: find existing attachment with same guid.
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1",
        $image_url
    ));
    if ($existing) {
        // ensure meta exists and return existing ID
        if (! get_post_meta($existing, '_external_image_src', true)) {
            update_post_meta($existing, '_external_image_src', $image_url);
        }
        // ensure _wp_attached_file exists (best-effort)
        if (! get_post_meta($existing, '_wp_attached_file', true)) {
            update_post_meta($existing, '_wp_attached_file', $image_url);
        }
        return (int) $existing;
    }

    // Build an attachment post
    $filename = basename(parse_url($image_url, PHP_URL_PATH));
    $title    = sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME));

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime_map = array(
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
    );
    $mime = isset($mime_map[$ext]) ? $mime_map[$ext] : 'image/*';

    $attachment_post = array(
        'post_mime_type' => $mime,
        'post_title'     => wp_strip_all_tags($title ? $title : $filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'guid'           => $image_url,
        'post_parent'    => $product_id,
    );

    $attach_id = wp_insert_attachment($attachment_post, 0, $product_id);
    if (! $attach_id || is_wp_error($attach_id)) {
        return 0;
    }

    // store your plugin meta for tracking
    update_post_meta($attach_id, '_external_image_src', $image_url);

    // minimal placeholder metadata (no local file)
    if (! get_post_meta($attach_id, '_wp_attachment_metadata', true)) {
        update_post_meta($attach_id, '_wp_attachment_metadata', array());
    }

    return (int) $attach_id;
}

/**
 * Ensure gallery thumbnails / img tags include data attributes PhotoSwipe expects
 * when attachment is virtual (external image).
 */
add_filter('wp_get_attachment_image_attributes', 'product_csv_sync_add_gallery_attrs_for_external', 20, 3);
function product_csv_sync_add_gallery_attrs_for_external($attr, $attachment, $size)
{
    $attachment_id = is_object($attachment) && isset($attachment->ID) ? $attachment->ID : intval($attachment);
    if (! $attachment_id) return $attr;

    $external = get_post_meta($attachment_id, '_external_image_src', true);
    if (! $external) {
        $guid = get_post_field('guid', $attachment_id);
        if ($guid && filter_var($guid, FILTER_VALIDATE_URL)) $external = $guid;
    }
    if (! $external) return $attr;

    // Ensure src and PhotoSwipe attrs point to external URL
    $attr['src'] = esc_url($external);
    $attr['data-large_image'] = esc_url($external);

    // keep existing data-large_image_width/height if present, otherwise leave them (JS supplies defaults)
    $attr['data-large_image_width']  = isset($attr['data-large_image_width']) ? (int) $attr['data-large_image_width'] : 0;
    $attr['data-large_image_height'] = isset($attr['data-large_image_height']) ? (int) $attr['data-large_image_height'] : 0;

    // avoid broken srcset/sizes values (leave empty strings)
    if (empty($attr['data-srcset']))  $attr['data-srcset'] = '';
    if (empty($attr['data-sizes']))   $attr['data-sizes']  = '';

    return $attr;
}

/**
 * Force gallery anchor href and anchor data-size attributes for external images
 */
add_filter('woocommerce_single_product_image_thumbnail_html', 'product_csv_sync_force_gallery_anchor_href_and_size', 40, 2);
function product_csv_sync_force_gallery_anchor_href_and_size($html, $post_thumbnail_id)
{
    $attachment_id = intval($post_thumbnail_id);
    if (! $attachment_id) {
        return $html;
    }

    // find external URL (meta or guid)
    $external = get_post_meta($attachment_id, '_external_image_src', true);
    if (! $external) {
        $guid = get_post_field('guid', $attachment_id);
        if ($guid && filter_var($guid, FILTER_VALIDATE_URL)) {
            $external = $guid;
        }
    }

    if (! $external) {
        return $html;
    }

    // Build a default data-size if real sizes not available ( PhotoSwipe needs it to open properly )
    $default_w = 1200;
    $default_h = 1200;
    $data_size = sprintf('%dx%d', $default_w, $default_h);

    // 1) Replace the anchor href (first occurrence) with external URL
    $html = preg_replace('/<a\s+([^>]*?)href=(["\'])(.*?)\2(.*?)>/i', '<a $1href="$2' . esc_url($external) . '$2$4>', $html, 1);

    // 2) Ensure data-size exists on the anchor
    if (false === strpos($html, 'data-size=')) {
        $html = preg_replace('/<a\s+([^>]*?)href=(["\'])(.*?)\2(.*?)>/i', '<a $1href="$2' . esc_url($external) . '$2 data-size="' . esc_attr($data_size) . '"$4>', $html, 1);
    } else {
        $html = preg_replace_callback('/<a\s+([^>]*?)data-size=(["\'])(.*?)\2(.*?)>/i', function ($m) use ($data_size) {
            $value = trim($m[3]);
            if ($value === '' || preg_match('/^0x0$/', $value)) {
                return '<a ' . $m[1] . 'data-size="' . esc_attr($data_size) . '"' . $m[4] . '>';
            }
            return $m[0];
        }, $html, 1);
    }

    return $html;
}

/**
 * image_downsize filter to serve external URL when WP lacks local file.
 */
add_filter('image_downsize', 'product_csv_sync_image_downsize_for_external', 10, 3);
function product_csv_sync_image_downsize_for_external($out, $id, $size)
{
    // If WP already has a useful result, respect it
    if (! empty($out)) {
        return $out;
    }

    // Only handle attachments
    $post_type = get_post_type($id);
    if ($post_type !== 'attachment') {
        return $out;
    }

    // Our plugin meta key (you use _external_image_src)
    $external = get_post_meta($id, '_external_image_src', true);
    if (! $external) {
        // Also consider guid fallback if you used guid
        $guid = get_post_field('guid', $id);
        if ($guid && filter_var($guid, FILTER_VALIDATE_URL)) {
            $external = $guid;
        }
    }

    if (! $external) {
        return $out;
    }

    // We don't have real dimensions (no local file). Use best-effort:
    // return [ url, width, height, is_intermediate ]
    // width and height set to 0 so markup still has src attribute.
    return array($external, 0, 0, false);
}

/**
 * Enqueue tiny JS asset (PhotoSwipe size-fix) on single product pages.
 * The script implements the "simple size fix" you used earlier.
 */
add_action('wp_enqueue_scripts', 'product_csv_sync_enqueue_photoswipe_fix');
function product_csv_sync_enqueue_photoswipe_fix()
{
    if (! is_product()) {
        return;
    }

    $handle = 'product-csv-sync-pswp-fix';
    $src    = MSI_URL . 'assets/js/photoswipe-simple-size-fix.js';
    wp_register_script($handle, $src, array(), '1.0.0', true);
    wp_enqueue_script($handle);
}
