<?php
// includes/rest-handler.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register REST route
 */
add_action( 'rest_api_init', function () {
    register_rest_route(
        'product-sync/v1',
        '/products',
        array(
            'methods'             => array( 'GET', 'POST' ),
            'callback'            => 'product_csv_sync_handle_request',
            'permission_callback' => '__return_true',
        )
    );
} );

/**
 * Main handler
 *
 * Note: this function relies on helpers in includes/helpers.php
 * and image helper product_csv_sync_set_product_image_from_url() from includes/image-handler.php
 */
function product_csv_sync_handle_request( WP_REST_Request $request ) {

    if ( $request->get_method() === 'GET' ) {
        return new WP_REST_Response(
            array(
                'ok'      => true,
                'message' => 'Product CSV endpoint is working. Send POST with a CSV file (field name: file).',
            ),
            200
        );
    }

    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'product_csv_sync_no_woocommerce', 'WooCommerce must be active.', array( 'status' => 500 ) );
    }

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 0 );
    }

    $files = $request->get_file_params();
    if ( empty( $files['file'] ) || ! isset( $files['file']['tmp_name'] ) ) {
        return new WP_Error( 'product_csv_sync_no_file', 'No CSV file uploaded. Please send it as "file" (multipart/form-data).', array( 'status' => 400 ) );
    }

    $csv_path = $files['file']['tmp_name'];
    if ( ! file_exists( $csv_path ) ) {
        return new WP_Error( 'product_csv_sync_file_missing', 'Uploaded file not found on server.', array( 'status' => 500 ) );
    }

    $handle = fopen( $csv_path, 'r' );
    if ( ! $handle ) {
        return new WP_Error( 'product_csv_sync_cannot_open', 'Cannot open uploaded CSV file.', array( 'status' => 500 ) );
    }

    // Build external map
    $external_map = product_csv_sync_build_external_map();

    $row_number      = 0;
    $created_count   = 0;
    $stock_updated   = 0;
    $stock_unchanged = 0;
    $errors          = array();
    $header          = array();
    $max_errors      = 200;

    while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
        $row_number++;

        // header
        if ( $row_number === 1 ) {
            $header = $data;
            continue;
        }

        // map row
        $row = array();
        foreach ( $header as $index => $column_name ) {
            $key = strtolower( trim( $column_name, " \t\n\r\0\x0B\"" ) );
            $row[ $key ] = isset( $data[ $index ] ) ? trim( $data[ $index ] ) : '';
        }

        // expected CSV columns
        $external_product_id = $row['product_id'] ?? '';
        $product_name        = $row['product_name'] ?? '';
        $image_url           = $row['image_url'] ?? '';
        $current_price       = $row['current_price'] ?? '';
        $stock_status_raw    = $row['stock_status'] ?? '';
        $is_active_raw       = $row['is_active'] ?? '';
        $has_variants_raw    = $row['has_variants'] ?? '';
        $variants_raw        = $row['variants'] ?? '';

        if ( $external_product_id === '' ) {
            if ( count( $errors ) < $max_errors ) {
                $errors[] = array( 'row' => $row_number, 'error' => 'Missing product_id.' );
            }
            continue;
        }

        $new_wc_status   = product_csv_sync_map_stock_status( $stock_status_raw );
        $new_post_status = product_csv_sync_map_active_to_status( $is_active_raw );
        $has_variants    = in_array( strtolower( trim( $has_variants_raw ) ), array( '1', 'true', 'yes', 'y', 'on' ), true );

        $product = null;
        if ( isset( $external_map[ $external_product_id ] ) ) {
            $product = wc_get_product( $external_map[ $external_product_id ] );
        }

        // Keep a parent attachment id variable so we can reuse
        $parent_image_id = 0;
        if ( $product ) {
            $parent_image_id = (int) get_post_thumbnail_id( $product->get_id() );
            // if _external_image_src is present we may still have parent_image_id empty; try to find thumbnail by meta
            if ( ! $parent_image_id ) {
                $external_src = get_post_meta( $product->get_id(), '_external_image_src', true );
                if ( $external_src ) {
                    // try to find attachment by file name or url (expensive) — skip for now
                }
            }
        }

        // VARIANTS HANDLING: supports JSON array (detailed) OR simple comma-separated list
        if ( $has_variants ) {

            // Try JSON first
            $variants = json_decode( $variants_raw, true );

            // If not JSON array, fallback to comma-separated list => treat as size list
            if ( ! is_array( $variants ) ) {
                // split by comma
                $tokens = array_filter( array_map( 'trim', explode( ',', $variants_raw ) ), function( $t ) { return $t !== ''; } );
                $variants = array();

                foreach ( $tokens as $tk ) {
                    // each token becomes a minimal variant with attributes => ['size' => $tk]
                    $variants[] = array(
                        'sku' => '', // will be generated below if empty
                        'price' => $current_price ?: '',
                        'stock_status' => $stock_status_raw ?: '',
                        'stock_quantity' => ( strtolower( trim( $stock_status_raw ) ) === 'in_stock' ) ? 1000 : 0,
                        'image_url' => '', // leave empty so variation uses parent image
                        'attributes' => array( 'size' => $tk ),
                    );
                }
            }

            // gather attribute values from variants (we'll use local attributes on parent)
            $attribute_values = array();
            foreach ( $variants as $v ) {
                if ( isset( $v['attributes'] ) && is_array( $v['attributes'] ) ) {
                    foreach ( $v['attributes'] as $attr_name => $attr_value ) {
                        $attr_name_l = strtolower( trim( $attr_name ) );
                        $attr_value_s = (string) $attr_value;
                        if ( $attr_value_s === '' ) {
                            continue;
                        }
                        if ( ! isset( $attribute_values[ $attr_name_l ] ) ) {
                            $attribute_values[ $attr_name_l ] = array();
                        }
                        if ( ! in_array( $attr_value_s, $attribute_values[ $attr_name_l ], true ) ) {
                            $attribute_values[ $attr_name_l ][] = $attr_value_s;
                        }
                    }
                }
            }

            // create variable parent if missing
            if ( ! $product ) {
                $parent = new WC_Product_Variable();
                $parent->set_name( $product_name ?: 'Variant product ' . $external_product_id );
                if ( $new_post_status ) {
                    $parent->set_status( $new_post_status );
                }
                // disable parent stock management (variations manage stock)
                $parent->set_manage_stock( false );
                $parent_id = $parent->save();
                if ( is_wp_error( $parent_id ) ) {
                    if ( count( $errors ) < $max_errors ) {
                        $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => 'Cannot create variable product: ' . $parent_id->get_error_message() );
                    }
                    continue;
                }
                $parent->update_meta_data( '_external_product_id', $external_product_id );
                $parent->save();
                $product = wc_get_product( $parent_id );
                $external_map[ $external_product_id ] = $parent_id;
                $created_count++;

                // attach parent image here once (if provided)
                if ( $image_url !== '' ) {
                    $parent_image_id = product_csv_sync_set_product_image_from_url( $parent_id, $image_url );
                    if ( $parent_image_id ) {
                        // set thumbnail for parent
                        $product->set_image_id( $parent_image_id );
                        $product->update_meta_data( '_external_image_src', $image_url );
                        $product->save();
                    }
                }
            } else {
                // Ensure product is variable
                if ( $product->get_type() !== 'variable' ) {
                    // convert/create new variable product and update map
                    $parent = new WC_Product_Variable();
                    $parent->set_name( $product_name ?: $product->get_name() );
                    if ( $new_post_status ) {
                        $parent->set_status( $new_post_status );
                    }
                    $parent_id = $parent->save();
                    $parent->update_meta_data( '_external_product_id', $external_product_id );
                    $parent->save();
                    $product = wc_get_product( $parent_id );
                    $external_map[ $external_product_id ] = $parent_id;

                    // attach parent image if provided
                    if ( $image_url !== '' ) {
                        $parent_image_id = product_csv_sync_set_product_image_from_url( $parent_id, $image_url );
                        if ( $parent_image_id ) {
                            $product->set_image_id( $parent_image_id );
                            $product->update_meta_data( '_external_image_src', $image_url );
                            $product->save();
                        }
                    }
                } else {
                    // product exists and is variable — ensure parent has image if not and CSV provides one
                    $parent_id = $product->get_id();
                    if ( ! $parent_image_id && $image_url !== '' ) {
                        $parent_image_id = product_csv_sync_set_product_image_from_url( $parent_id, $image_url );
                        if ( $parent_image_id ) {
                            $product->set_image_id( $parent_image_id );
                            $product->update_meta_data( '_external_image_src', $image_url );
                            $product->save();
                        }
                    }
                }
            }

            if ( ! $product || $product->get_type() !== 'variable' ) {
                if ( count( $errors ) < $max_errors ) {
                    $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => 'Parent product not available as variable.' );
                }
                continue;
            }

            // set local attributes on parent
            $parent_attributes = array();
            foreach ( $attribute_values as $attr_name => $values ) {
                $attr = new WC_Product_Attribute();
                $attr->set_id( 0 );
                // keep visible name (human), WC will map variation meta by slug 'attribute_{slug}'
                $attr->set_name( $attr_name );
                $attr->set_options( array_values( $values ) );
                $attr->set_position( 0 );
                $attr->set_visible( true );
                $attr->set_variation( true );
                $parent_attributes[] = $attr;
            }

            try {
                if ( ! empty( $parent_attributes ) ) {
                    $product->set_attributes( $parent_attributes );
                    $product->save();
                }
            } catch ( Exception $e ) {
                if ( count( $errors ) < $max_errors ) {
                    $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => 'Cannot set attributes on parent: ' . $e->getMessage() );
                }
            }

            // create/update each variant
            $parent_id = $product->get_id();
            $existing_variation_ids = $product->get_children(); // variation post IDs

            foreach ( $variants as $v ) {
                if ( empty( $v['attributes'] ) || ! is_array( $v['attributes'] ) ) {
                    if ( count( $errors ) < $max_errors ) {
                        $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => 'Variant missing attributes object.' );
                    }
                    continue;
                }

                // normalize variant attributes (lower-case keys)
                $variation_attributes = array();
                foreach ( $v['attributes'] as $k_attr => $v_attr ) {
                    $variation_attributes[ strtolower( trim( $k_attr ) ) ] = (string) $v_attr;
                }

                // Try to find matching existing variation by attributes (compare attribute_{slug} meta)
                $found_variant = null;
                if ( ! empty( $existing_variation_ids ) ) {
                    foreach ( $existing_variation_ids as $var_id ) {
                        $var = wc_get_product( $var_id );
                        if ( ! $var || $var->get_type() !== 'variation' ) {
                            continue;
                        }
                        $match = true;
                        foreach ( $variation_attributes as $k_attr => $k_val ) {
                            $meta_key = 'attribute_' . sanitize_title( $k_attr );
                            $existing_meta = get_post_meta( $var_id, $meta_key, true );
                            if ( (string) $existing_meta !== (string) $k_val ) {
                                $match = false;
                                break;
                            }
                        }
                        if ( $match ) {
                            $found_variant = $var;
                            break;
                        }
                    }
                }

                // prepare variant values with sensible fallbacks
                $var_sku = isset( $v['sku'] ) ? trim( $v['sku'] ) : '';
                $var_price = isset( $v['price'] ) ? trim( $v['price'] ) : ( $current_price ?: '' );
                $var_stock_status = isset( $v['stock_status'] ) ? product_csv_sync_map_stock_status( $v['stock_status'] ) : $new_wc_status;
                $var_stock_qty = isset( $v['stock_quantity'] ) ? intval( $v['stock_quantity'] ) : ( $var_stock_status === 'in_stock' ? 1000 : 0 );
                $var_image_url = isset( $v['image_url'] ) ? trim( $v['image_url'] ) : '';

                if ( ! $var_sku ) {
                    // generate SKU: external_product_id + sanitized attribute snippet
                    $snippet = sanitize_title( implode('-', array_values( $variation_attributes ) ) );
                    $var_sku = substr( $external_product_id . '-' . ( $snippet ?: 'v' ), 0, 60 );
                }

                // --- Robust variation creation/update & meta setup ---

                // Build meta-style keys we will persist: 'attribute_{slug}' => value
                $variation_meta_attrs = array();
                foreach ( $variation_attributes as $attr_k => $attr_v ) {
                    $meta_key = 'attribute_' . sanitize_title( $attr_k );
                    $variation_meta_attrs[ $meta_key ] = $attr_v;
                }

                if ( $found_variant ) {
                    $variation = $found_variant;
                } else {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id( $parent_id );
                }

                // Set readable attributes for CRUD layer (keys are raw names like 'size' => 'M')
                $variation->set_attributes( $variation_attributes );

                // Set sku/price/stock on the object
                $variation->set_sku( $var_sku );
                if ( $var_price !== '' ) {
                    $variation->set_regular_price( $var_price );
                }

                // Ensure variation manages stock and values set on object
                $variation->set_manage_stock( true );
                $variation->set_stock_status( $var_stock_status );
                $variation->set_stock_quantity( $var_stock_qty );

                // Save variation once to obtain an ID (if new). We will update the meta keys after.
                try {
                    $variation_id = $variation->save();
                } catch ( Exception $e ) {
                    if ( count( $errors ) < $max_errors ) {
                        $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => 'Cannot save variation (initial save): ' . $e->getMessage() );
                    }
                    continue;
                }

                // Persist attribute_{slug} meta keys so WC recognizes variations properly
                foreach ( $variation_meta_attrs as $meta_key => $meta_val ) {
                    update_post_meta( $variation_id, $meta_key, (string) $meta_val );
                }

                // Ensure stock meta matches what we set via CRUD (helps some WC versions)
                update_post_meta( $variation_id, '_stock', (int) $var_stock_qty );
                update_post_meta( $variation_id, '_stock_status', $var_stock_status );

                // Attach per-variant image (if provided) OR reuse parent image when variant image not provided
                if ( $var_image_url !== '' ) {
                    $prev_src = get_post_meta( $variation_id, '_external_image_src', true );
                    if ( $prev_src !== $var_image_url ) {
                        $att_id = product_csv_sync_set_product_image_from_url( $parent_id, $var_image_url );
                        if ( $att_id ) {
                            update_post_meta( $variation_id, '_thumbnail_id', $att_id );
                            update_post_meta( $variation_id, '_external_image_src', $var_image_url );
                        }
                    }
                } else {
                    // No variant image provided — use parent image if available
                    if ( $parent_image_id ) {
                        update_post_meta( $variation_id, '_thumbnail_id', (int) $parent_image_id );
                        // set external image src on variation so future imports know it's using parent's image
                        update_post_meta( $variation_id, '_external_image_src', get_post_meta( $parent_id, '_external_image_src', true ) );
                    }
                }

                // Finalize: reload & save to let Woo update caches
                try {
                    wc_delete_product_transients( $parent_id ); // clear parent caches
                    $variation = wc_get_product( $variation_id );
                    if ( $variation ) {
                        $variation->save();
                    }
                } catch ( Exception $e ) {
                    if ( count( $errors ) < $max_errors ) {
                        $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => 'Cannot finalize variation save: ' . $e->getMessage() );
                    }
                    continue;
                }

                // end variant loop
            }

            // done with this variable-row
            continue;
        } // end has_variants branch

        // ---------- SIMPLE PRODUCT FLOW (unchanged) ----------
        if ( ! $product ) {
            if ( $product_name === '' ) {
                if ( count( $errors ) < $max_errors ) {
                    $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => 'Product not found and product_name is empty, cannot create.' );
                }
                continue;
            }

            try {
                $product = new WC_Product_Simple();
                $product->set_name( $product_name );
                if ( $current_price !== '' ) {
                    $product->set_regular_price( $current_price );
                }
                if ( $new_post_status ) {
                    $product->set_status( $new_post_status );
                }
                $product->set_manage_stock( true );
                $product->set_stock_status( $new_wc_status );
                $product->set_stock_quantity( $new_wc_status === 'in_stock' ? 1 : 0 );

                $product_id = $product->save();
                if ( is_wp_error( $product_id ) ) {
                    if ( count( $errors ) < $max_errors ) {
                        $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => $product_id->get_error_message() );
                    }
                    continue;
                }

                $product->update_meta_data( '_external_product_id', $external_product_id );

                if ( $image_url !== '' ) {
                    $attachment_id = product_csv_sync_set_product_image_from_url( $product_id, $image_url );
                    if ( $attachment_id ) {
                        $product->set_image_id( $attachment_id );
                        $product->update_meta_data( '_external_image_src', $image_url );
                    }
                }

                $product->save();
                $external_map[ $external_product_id ] = $product_id;
                $created_count++;

            } catch ( Exception $e ) {
                if ( count( $errors ) < $max_errors ) {
                    $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => $e->getMessage() );
                }
            }

        } else {
            // update existing simple product
            try {
                $needs_save = false;
                $current_wc_status = $product->get_stock_status();
                $current_post_stat = $product->get_status();

                if ( $new_wc_status !== $current_wc_status ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_status( $new_wc_status );
                    $product->set_stock_quantity( $new_wc_status === 'in_stock' ? 1 : 0 );
                    $stock_updated++;
                    $needs_save = true;
                } else {
                    $stock_unchanged++;
                }

                if ( $new_post_status && $new_post_status !== $current_post_stat ) {
                    $product->set_status( $new_post_status );
                    $needs_save = true;
                }

                if ( $current_price !== '' && $current_price != $product->get_regular_price() ) {
                    $product->set_regular_price( $current_price );
                    $needs_save = true;
                }

                if ( $image_url !== '' ) {
                    $previous_src = $product->get_meta( '_external_image_src', true );
                    if ( $previous_src !== $image_url ) {
                        $attachment_id = product_csv_sync_set_product_image_from_url( $product->get_id(), $image_url );
                        if ( $attachment_id ) {
                            $product->set_image_id( $attachment_id );
                            $product->update_meta_data( '_external_image_src', $image_url );
                            $needs_save = true;
                        }
                    }
                }

                if ( $needs_save ) {
                    $result = $product->save();
                    if ( is_wp_error( $result ) && count( $errors ) < $max_errors ) {
                        $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => $result->get_error_message() );
                    }
                }

            } catch ( Exception $e ) {
                if ( count( $errors ) < $max_errors ) {
                    $errors[] = array( 'row' => $row_number, 'product_id' => $external_product_id, 'error' => $e->getMessage() );
                }
            }
        }

    } // end while

    fclose( $handle );

    $error_truncated = ( count( $errors ) >= $max_errors );

    return new WP_REST_Response(
        array(
            'message'          => 'CSV processed.',
            'created'          => $created_count,
            'stock_updated'    => $stock_updated,
            'stock_unchanged'  => $stock_unchanged,
            'error_count'      => count( $errors ),
            'errors_truncated' => $error_truncated,
            'errors'           => $errors,
        ),
        200
    );
}
