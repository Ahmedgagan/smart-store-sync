<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class MSI_Settings {

    const OPTION_KEY = 'store_import_settings';

    public function __construct() {
      $this->data_provider = new MSI_Data_Provider();

      add_action( 'admin_menu', [ $this, 'register_menu' ] );
      add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
    }

    public function register_menu() {
        add_menu_page(
            'Store Import Mapping',
            'Store Import Mapping',
            'manage_woocommerce',
            'store-import-mapping',
            [ $this, 'render_settings_page' ]
        );
    }

    // ---- Everything we wrote earlier (tabs, forms, save logic) lives here ---
    // render_settings_page()
    // render_stores_tab()
    // render_mapping_tab()
    // get_settings()
    // update_settings()
    // etc.

    /**
     * Handle form submissions for both tabs
     */
    public function handle_form_submissions() {
        if ( ! isset( $_POST['store_import_action'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        check_admin_referer( 'store_import_save', 'store_import_nonce' );

        $settings = $this->get_settings();

        switch ( $_POST['store_import_action'] ) {

            case 'save_stores':
                $enabled_stores = isset( $_POST['enabled_stores'] ) && is_array( $_POST['enabled_stores'] )
                    ? array_map( 'sanitize_text_field', $_POST['enabled_stores'] )
                    : [];

                $settings['enabled_stores'] = array_values( $enabled_stores );

                // You could also store / update API settings here later.
                $this->update_settings( $settings );
                echo "<script>console.log('PHP Variable:', '" . json_encode($settings) . "');</script>";
                add_settings_error(
                    'store-import-mapping',
                    'store_import_stores_saved',
                    __( 'Stores selection saved.', 'multi-store-import' ),
                    'updated'
                );
                break;

            case 'save_mappings':
                $store_id = isset( $_POST['store_id'] ) ? sanitize_text_field( $_POST['store_id'] ) : null;

                if ( $store_id ) {
                    // Save fallback
                    $fallback = isset( $_POST['fallback_category'] )
                        ? intval( $_POST['fallback_category'] )
                        : 0;

                    $settings['fallbacks'][ $store_id ] = $fallback > 0 ? $fallback : 0;

                    // Save category mappings
                    $mappings = [];
                    if ( ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ) {
                        foreach ( $_POST['mapping'] as $remote_cat_id => $wp_term_id ) {
                            $remote_cat_id = sanitize_text_field( $remote_cat_id );
                            $wp_term_id    = intval( $wp_term_id );

                            if ( $wp_term_id > 0 ) {
                                $mappings[ $remote_cat_id ] = $wp_term_id;
                            }
                        }
                    }

                    if ( ! isset( $settings['category_mappings'] ) || ! is_array( $settings['category_mappings'] ) ) {
                        $settings['category_mappings'] = [];
                    }

                    $settings['category_mappings'][ $store_id ] = $mappings;

                    echo "<script>console.log('PHP Variable:', '" . json_encode($settings) . "');</script>";
                    // echo $settings;

                    $this->update_settings( $settings );

                    add_settings_error(
                        'store-import-mapping',
                        'store_import_mappings_saved',
                        __( 'Category mappings saved.', 'multi-store-import' ),
                        'updated'
                    );
                }

                break;
        }
    }

    /**
     * Render settings page with tabs
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = $this->get_settings();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'stores';

        settings_errors( 'store-import-mapping' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Store Import Mapping', 'multi-store-import' ) . '</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        $this->render_tab_link( 'settings', __( 'Settings', 'multi-store-import' ), $active_tab );
        $this->render_tab_link( 'stores', __( 'Stores', 'multi-store-import' ), $active_tab );
        $this->render_tab_link( 'mapping', __( 'Category Mapping', 'multi-store-import' ), $active_tab );
        echo '</h2>';

        if ( $active_tab === 'mapping' ) {
          $this->render_mapping_tab( $settings );
        } else if ( $active_tab === 'stores' ) {
          $this->render_stores_tab( $settings );
        } else {
          $this->render_settings_tab( $settings );
        }

        echo '</div>';
    }

    private function render_tab_link( $tab, $label, $active_tab ) {
        $url = add_query_arg(
            [
                'page' => 'store-import-mapping',
                'tab'  => $tab,
            ],
            admin_url( 'admin.php' )
        );

        $class = 'nav-tab';
        if ( $tab === $active_tab ) {
            $class .= ' nav-tab-active';
        }

        printf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( $url ),
            esc_attr( $class ),
            esc_html( $label )
        );
    }

    /**
     * STORES TAB
     */
    private function render_stores_tab( $settings ) {
        $stores         = $this->get_remote_stores( $settings );
        $enabled_stores = isset( $settings['enabled_stores'] ) && is_array( $settings['enabled_stores'] )
            ? $settings['enabled_stores']
            : [];

        ?>
        <form method="post">
            <?php wp_nonce_field( 'store_import_save', 'store_import_nonce' ); ?>
            <input type="hidden" name="store_import_action" value="save_stores" />

            <h2><?php esc_html_e( 'Stores', 'multi-store-import' ); ?></h2>

            <?php if ( empty( $stores ) ) : ?>
                <p><?php esc_html_e( 'No stores found. Make sure your scraper/API is configured.', 'multi-store-import' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e( 'Enable', 'multi-store-import' ); ?></th>
                        <th><?php esc_html_e( 'Store Name', 'multi-store-import' ); ?></th>
                        <th><?php esc_html_e( 'Store ID', 'multi-store-import' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $stores as $store_id => $store ) : ?>
                        <?php
                        $checked = in_array( (string) $store_id, array_map( 'strval', $enabled_stores ), true );
                        ?>
                        <tr>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="enabled_stores[]"
                                           value="<?php echo esc_attr( $store_id ); ?>"
                                        <?php checked( $checked ); ?>
                                    />
                                </label>
                            </td>
                            <td><?php echo esc_html( $store['name'] ?? '' ); ?></td>
                            <td><code><?php echo esc_html( $store_id ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Save Stores', 'multi-store-import' ); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * MAPPING TAB
     */
    private function render_mapping_tab( $settings ) {
        $stores = $this->get_remote_stores( $settings );

        if ( empty( $stores ) ) {
            echo '<p>' . esc_html__( 'No stores available. Configure and save stores first.', 'multi-store-import' ) . '</p>';
            return;
        }

        $selected_store = isset( $_GET['store_id'] )
            ? sanitize_text_field( $_GET['store_id'] )
            : array_key_first( $stores );

        if ( ! isset( $stores[ $selected_store ] ) ) {
            $selected_store = array_key_first( $stores );
        }

        // Category data for this store from your scraper/source
        $categories = $this->get_remote_categories_for_store( $selected_store, $settings );

        // Existing mappings + fallback
        $existing_mappings = $settings['category_mappings'][ $selected_store ] ?? [];
        $fallback          = $settings['fallbacks'][ $selected_store ] ?? 0;

        // WooCommerce categories for dropdown
        $wp_categories = $this->get_woocommerce_product_cats();

        ?>
        <form method="get" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="store-import-mapping" />
            <input type="hidden" name="tab" value="mapping" />

            <label for="store_id">
                <?php esc_html_e( 'Select Store:', 'multi-store-import' ); ?>
            </label>
            <select name="store_id" id="store_id" onchange="this.form.submit();">
                <?php foreach ( $stores as $store_id => $store ) : ?>
                    <option value="<?php echo esc_attr( $store_id ); ?>" <?php selected( $store_id, $selected_store ); ?>>
                        <?php echo esc_html( $store['name'] ?? $store_id ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript>
                <button type="submit" class="button"><?php esc_html_e( 'Go', 'multi-store-import' ); ?></button>
            </noscript>
        </form>

        <form method="post">
            <?php wp_nonce_field( 'store_import_save', 'store_import_nonce' ); ?>
            <input type="hidden" name="store_import_action" value="save_mappings" />
            <input type="hidden" name="store_id" value="<?php echo esc_attr( $selected_store ); ?>" />

            <h2>
                <?php
                printf(
                    /* translators: %s: store name */
                    esc_html__( 'Category Mapping for: %s', 'multi-store-import' ),
                    esc_html( $stores[ $selected_store ]['name'] ?? $selected_store )
                );
                ?>
            </h2>

            <p>
                <label for="fallback_category">
                    <?php esc_html_e( 'Fallback WooCommerce category (used when no specific mapping exists):', 'multi-store-import' ); ?>
                </label>
                <br />
                <select name="fallback_category" id="fallback_category">
                    <option value="0"><?php esc_html_e( '— None —', 'multi-store-import' ); ?></option>
                    <?php foreach ( $wp_categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $cat->term_id, $fallback ); ?>>
                            <?php echo esc_html( $cat->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php if ( empty( $categories ) ) : ?>
                <p><?php esc_html_e( 'No categories found for this store.', 'multi-store-import' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e( 'Source Category', 'multi-store-import' ); ?></th>
                        <th><?php esc_html_e( 'Source Category ID', 'multi-store-import' ); ?></th>
                        <th><?php esc_html_e( 'WooCommerce Category', 'multi-store-import' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $categories as $remote_cat ) : ?>
                        <?php
                        $remote_id = (string) ( $remote_cat['id'] ?? '' );
                        $label     = $remote_cat['label'] ?? ( $remote_cat['name'] ?? $remote_id );
                        $mapped_id = $existing_mappings[ $remote_id ] ?? 0;
                        ?>
                        <tr>
                            <td><?php echo esc_html( $label ); ?></td>
                            <td><code><?php echo esc_html( $remote_id ); ?></code></td>
                            <td>
                                <select name="mapping[<?php echo esc_attr( $remote_id ); ?>]">
                                    <option value="0"><?php esc_html_e( '— Not mapped —', 'multi-store-import' ); ?></option>
                                    <?php foreach ( $wp_categories as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $cat->term_id, $mapped_id ); ?>>
                                            <?php echo esc_html( $cat->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Save Mappings', 'multi-store-import' ); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * SETTINGS TAB
     */
    private function render_settings_tab( $settings ) {
        $stores         = $this->get_remote_stores( $settings );
        $enabled_stores = isset( $settings['enabled_stores'] ) && is_array( $settings['enabled_stores'] )
            ? $settings['enabled_stores']
            : [];

        ?>
        <form method="post">
            <?php wp_nonce_field( 'store_import_save', 'store_import_nonce' ); ?>
            <h2><?php esc_html_e( 'Stores', 'multi-store-import' ); ?></h2>
            <input type="text" name="web_token" value="" placeholder="1234xyz" />

            <h2><?php esc_html_e( 'Stores', 'multi-store-import' ); ?></h2>

            <?php if ( empty( $stores ) ) : ?>
                <p><?php esc_html_e( 'No stores found. Make sure your scraper/API is configured.', 'multi-store-import' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e( 'Enable', 'multi-store-import' ); ?></th>
                        <th><?php esc_html_e( 'Store Name', 'multi-store-import' ); ?></th>
                        <th><?php esc_html_e( 'Store ID', 'multi-store-import' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $stores as $store_id => $store ) : ?>
                        <?php
                        $checked = in_array( (string) $store_id, array_map( 'strval', $enabled_stores ), true );
                        ?>
                        <tr>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="enabled_stores[]"
                                           value="<?php echo esc_attr( $store_id ); ?>"
                                        <?php checked( $checked ); ?>
                                    />
                                </label>
                            </td>
                            <td><?php echo esc_html( $store['name'] ?? '' ); ?></td>
                            <td><code><?php echo esc_html( $store_id ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Save Stores', 'multi-store-import' ); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Get + normalize settings
     */
    private function get_settings() {
        $defaults = [
            'api'               => [],
            'stores'            => [],
            'enabled_stores'    => [],
            'category_mappings' => [],
            'fallbacks'         => [],
            'last_sync'         => [],
        ];

        $stored = get_option( self::OPTION_KEY, [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return array_replace_recursive( $defaults, $stored );
    }

    private function update_settings( $settings ) {
        update_option( self::OPTION_KEY, $settings );
    }

    /**
     * PLACEHOLDER: Fetch stores from your scraper / API.
     *
     * You should replace the body of this with a call into your scraper.
     * It should return something like:
     *
     * [
     *   '101' => [ 'name' => 'Fashion Hub' ],
     *   '102' => [ 'name' => 'Tech Bazaar' ],
     * ]
     */
    private function get_remote_stores( $settings ) {
      return $this->data_provider->get_stores();
    }

    /**
     * PLACEHOLDER: Fetch categories for a given store from your scraper / API.
     *
     * Should return an array like:
     *
     * [
     *   [ 'id' => '501', 'label' => 'Men > Shoes' ],
     *   [ 'id' => '502', 'label' => 'Men > Shirts' ],
     * ]
     */
    private function get_remote_categories_for_store( $store_id, $settings ) {
      return $this->data_provider->get_categories( $store_id );
    }

    /**
     * Get WooCommerce product categories
     */
    private function get_woocommerce_product_cats() {
        if ( ! function_exists( 'wc_get_product_cat_ids' ) ) {
            // WooCommerce not active, but we still try to fetch product_cat terms
        }

        $terms = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ]
        );

        if ( is_wp_error( $terms ) ) {
            return [];
        }

        return $terms;
    }
}
