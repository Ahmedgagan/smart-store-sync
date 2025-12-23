<?php

if (! defined('ABSPATH')) exit;

class MSI_Settings
{
    const OPTION_KEY = 'store_import_settings';

    protected MSI_Data_Provider $data_provider;

    public function __construct()
    {
        $this->data_provider = new MSI_Data_Provider();

        add_action('admin_menu', [$this, 'sss_register_menu']);
        add_action('admin_init', [$this, 'sss_handle_form_submissions']);
    }

    public function sss_register_menu()
    {
        add_menu_page(
            'Store Import Mapping',
            'Store Import Mapping',
            'manage_woocommerce',
            'store-import-mapping',
            [$this, 'render_settings_page']
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
    public function sss_handle_form_submissions()
    {
        if (! isset($_POST['store_import_action'])) {
            return;
        }

        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        check_admin_referer('store_import_save', 'store_import_nonce');

        $settings = $this->get_settings();

        switch ($_POST['store_import_action']) {

            case 'save_purchase_token':
                $purchase_token = isset($_POST['purchase_token']) ? sanitize_text_field(wp_unslash($_POST['purchase_token'])) : '';
                $consumer_key = isset($_POST['consumer_key']) ? sanitize_text_field(wp_unslash($_POST['consumer_key'])) : '';
                $consumer_secret = isset($_POST['consumer_secret']) ? sanitize_text_field(wp_unslash($_POST['consumer_secret'])) : '';

                if ($this->update_secrets($settings, $purchase_token, $consumer_key, $consumer_secret)) {
                    add_settings_error(
                        'store-import-mapping',
                        'store_import_purchase_token_saved',
                        __('Secrets Saved Successfully.', 'smart-store-sync'),
                        'updated'
                    );
                } else {
                    add_settings_error(
                        'store-import-mapping',
                        'store_import_purchase_token_saved',
                        __('Error Saving Secrets.', 'smart-store-sync'),
                        'Error'
                    );
                }
                break;
            case 'save_stores':
                $enabled_stores = isset($_POST['enabled_stores']) && is_array($_POST['enabled_stores'])
                    ? array_map('sanitize_text_field', wp_unslash($_POST['enabled_stores']))
                    : [];

                $this->update_enabled_stores($settings, $enabled_stores);

                add_settings_error(
                    'store-import-mapping',
                    'store_import_stores_saved',
                    __('Stores selection saved.', 'smart-store-sync'),
                    'updated'
                );
                break;

            case 'save_mappings':
                $store_id = isset($_POST['store_id']) ? sanitize_text_field(wp_unslash($_POST['store_id'])) : null;

                if ($store_id) {
                    // Save fallback
                    $fallback = isset($_POST['fallback_category'])
                        ? intval($_POST['fallback_category'])
                        : 0;

                    $settings['fallbacks'][$store_id] = $fallback > 0 ? $fallback : 0;

                    // Save category mappings
                    $mappings = [];
                    if (! empty($_POST['mapping']) && is_array($_POST['mapping'])) {
                        //  phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per-item below.
                        foreach (wp_unslash($_POST['mapping']) as $remote_cat_id => $wp_term_id) {
                            $remote_cat_id = sanitize_text_field($remote_cat_id);
                            $wp_term_id    = intval($wp_term_id);

                            if ($wp_term_id > 0) {
                                $mappings[$remote_cat_id]['wp_category'] = $wp_term_id;
                            }
                        }
                    }

                    if (! empty($_POST['profit_margin']) && is_array($_POST['profit_margin'])) {
                        //  phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per-item below.
                        foreach (wp_unslash($_POST['profit_margin']) as $remote_cat_id => $profit_margin) {
                            $remote_cat_id = sanitize_text_field($remote_cat_id);
                            $mappings[$remote_cat_id]['profit_margin'] = $profit_margin;
                        }
                    }

                    if (! isset($settings['category_mappings']) || ! is_array($settings['category_mappings'])) {
                        $settings['category_mappings'] = [];
                    }

                    $settings['category_mappings'][$store_id] = $mappings;

                    $this->update_settings($settings);

                    add_settings_error(
                        'store-import-mapping',
                        'store_import_mappings_saved',
                        __('Category mappings saved.', 'smart-store-sync'),
                        'updated'
                    );
                }

                break;
        }
    }

    /**
     * Render settings page with tabs
     */
    public function render_settings_page()
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = $this->get_settings();

        // Determine active tab (UI navigation only, no data processing)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI state only.
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';

        settings_errors('store-import-mapping');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Store Import Mapping', 'smart-store-sync') . '</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        $this->render_tab_link('settings', __('Account & Plan', 'smart-store-sync'), $active_tab);
        $this->render_tab_link('stores', __('Stores', 'smart-store-sync'), $active_tab);
        $this->render_tab_link('mapping', __('Category Mapping', 'smart-store-sync'), $active_tab);
        echo '</h2>';

        if ($active_tab === 'mapping') {
            $this->render_mapping_tab($settings);
        } else if ($active_tab === 'stores') {
            $this->render_stores_tab($settings);
        } else {
            $this->render_account_and_plan_tab($settings);
        }

        echo '</div>';
    }

    private function render_tab_link($tab, $label, $active_tab)
    {
        $url = add_query_arg(
            [
                'page' => 'store-import-mapping',
                'tab'  => $tab,
            ],
            admin_url('admin.php')
        );

        $class = 'nav-tab';
        if ($tab === $active_tab) {
            $class .= ' nav-tab-active';
        }

        printf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($url),
            esc_attr($class),
            esc_html($label)
        );
    }

    /**
     * STORES TAB
     */
    private function render_stores_tab($settings)
    {
        $stores = $this->get_remote_stores($settings);
        $enabled_stores = $this->get_enabled_stores($settings);
?>
        <form method="post">
            <?php wp_nonce_field('store_import_save', 'store_import_nonce'); ?>
            <input type="hidden" name="store_import_action" value="save_stores" />

            <h2><?php esc_html_e('Stores', 'smart-store-sync'); ?></h2>

            <?php if (empty($stores)) : ?>
                <p><?php esc_html_e('No stores found. Make sure your scraper/API is configured.', 'smart-store-sync'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Enable', 'smart-store-sync'); ?></th>
                            <th><?php esc_html_e('Store Name', 'smart-store-sync'); ?></th>
                            <th><?php esc_html_e('Store ID', 'smart-store-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stores as $store_id => $store) : ?>
                            <?php
                            $checked = in_array((string) $store_id, array_map('strval', $enabled_stores), true);
                            ?>
                            <tr>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="enabled_stores[]"
                                            value="<?php echo esc_attr($store_id); ?>"
                                            <?php checked($checked); ?> />
                                    </label>
                                </td>
                                <td><?php echo esc_html($store['name'] ?? ''); ?></td>
                                <td><code><?php echo esc_html($store_id); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save Stores', 'smart-store-sync'); ?>
                </button>
            </p>
        </form>
    <?php
    }

    /**
     * MAPPING TAB
     */
    private function render_mapping_tab($settings)
    {
        $stores = $this->get_remote_stores($settings);

        if (empty($stores)) {
            echo '<p>' . esc_html__('No stores available. Configure and save stores first.', 'smart-store-sync') . '</p>';
            return;
        }

        // Selected store (UI navigation only)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI state only, no data processing.
        $selected_store = isset($_GET['store_id']) ? sanitize_text_field(wp_unslash($_GET['store_id'])) : array_key_first($stores);

        if (! isset($stores[$selected_store])) {
            $selected_store = array_key_first($stores);
        }

        // Category data for this store from your scraper/source
        $categories = $this->get_remote_categories_for_store($selected_store, $settings);

        // Existing mappings + fallback
        $existing_mappings = $settings['category_mappings'][$selected_store] ?? [];
        $fallback          = $settings['fallbacks'][$selected_store] ?? 0;

        // WooCommerce categories for dropdown
        $wp_categories = $this->get_woocommerce_product_cats();

    ?>
        <form method="get" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="store-import-mapping" />
            <input type="hidden" name="tab" value="mapping" />

            <label for="store_id">
                <?php esc_html_e('Select Store:', 'smart-store-sync'); ?>
            </label>
            <select name="store_id" id="store_id" onchange="this.form.submit();">
                <?php foreach ($stores as $store_id => $store) : ?>
                    <option value="<?php echo esc_attr($store_id); ?>" <?php selected($store_id, $selected_store); ?>>
                        <?php echo esc_html($store['name'] ?? $store_id); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript>
                <button type="submit" class="button"><?php esc_html_e('Go', 'smart-store-sync'); ?></button>
            </noscript>
        </form>

        <form method="post">
            <?php wp_nonce_field('store_import_save', 'store_import_nonce'); ?>
            <input type="hidden" name="store_import_action" value="save_mappings" />
            <input type="hidden" name="store_id" value="<?php echo esc_attr($selected_store); ?>" />

            <h2>
                <?php
                printf(
                    /* translators: %s: store name */
                    esc_html__('Category Mapping for: %s', 'smart-store-sync'),
                    esc_html($stores[$selected_store]['name'] ?? $selected_store)
                );
                ?>
            </h2>

            <p>
                <label for="fallback_category">
                    <?php esc_html_e('Fallback WooCommerce category (used when no specific mapping exists):', 'smart-store-sync'); ?>
                </label>
                <br />
                <select name="fallback_category" id="fallback_category">
                    <option value="0"><?php esc_html_e('— None —', 'smart-store-sync'); ?></option>
                    <?php foreach ($wp_categories as $cat) : ?>
                        <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($cat->term_id, $fallback); ?>>
                            <?php echo esc_html($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php if (empty($categories)) : ?>
                <p><?php esc_html_e('No categories found for this store.', 'smart-store-sync'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source Category', 'smart-store-sync'); ?></th>
                            <th><?php esc_html_e('WooCommerce Category', 'smart-store-sync'); ?></th>
                            <th><?php esc_html_e('Profit Margin', 'smart-store-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $remote_cat) : ?>
                            <?php
                            $remote_id = (string) ($remote_cat['id'] ?? '');
                            $label     = $remote_cat['label'] ?? ($remote_cat['name'] ?? $remote_id);
                            $mapped_id = $existing_mappings[$remote_id]['wp_category'] ?? 0;
                            $profit_margin = $existing_mappings[$remote_id]['profit_margin'] ?? 0;
                            ?>
                            <tr>
                                <td><?php echo esc_html($label); ?></td>
                                <td>
                                    <select name="mapping[<?php echo esc_attr($remote_id); ?>]">
                                        <option value="0"><?php esc_html_e('— Not mapped —', 'smart-store-sync'); ?></option>
                                        <?php foreach ($wp_categories as $cat) : ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($cat->term_id, $mapped_id); ?>>
                                                <?php echo esc_html($cat->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <!-- <input type='number' name="mapping[<?php echo esc_attr($remote_id); ?>]" value=<?php echo esc_attr($profit_margin); ?> /> -->
                                    <input type='number' name="profit_margin[<?php echo esc_attr($remote_id); ?>]" value=<?php echo esc_attr($profit_margin); ?> />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save Mappings', 'smart-store-sync'); ?>
                </button>
            </p>
        </form>
    <?php
    }

    /**
     * ACCOUNT & PLAN TAB
     */
    private function render_account_and_plan_tab($settings)
    {
        $purchase_token = $settings['purchase_token'];
        $consumer_key = $settings['consumer_key'];
        $consumer_secret = $settings['consumer_secret'];
        $subscription_details = $this->get_subscription_details($purchase_token);
    ?>
        <!-- 🔒 NOT ACTIVATED STATE -->
        <?php if (empty($subscription_details)) : ?>
            <div class="notice notice-warning">
                <p><strong>Plugin not activated</strong></p>
                <p>Please check your purchase token to activate Smart Store Sync.</p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('store_import_save', 'store_import_nonce'); ?>
            <input type="hidden" name="store_import_action" value="save_purchase_token" />
            <table class="form-table">
                <tr>
                    <th scope="row">Purchase Token</th>
                    <td>
                        <input
                            type="text"
                            name="purchase_token"
                            class="regular-text"
                            value="<?php echo esc_attr($purchase_token); ?>"
                            placeholder="XXXXXXXXXXXX 30 Chars Token"
                            minlength="36"
                            maxlength="36" />
                        <p class="description">
                            Enter the token you received after purchase.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Woocommerce Customer Key</th>
                    <td>
                        <input
                            type="text"
                            name="consumer_key"
                            class="regular-text"
                            value="<?php echo esc_attr($consumer_key); ?>"
                            placeholder="Woocommerce Consumer Key (32 Chars)"
                            minlength="32"
                            maxlength="32" />
                        <p class="description">
                            Enter your WooCommerce Consumer Key.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Woocommerce Customer Secret</th>
                    <td>
                        <input
                            type="text"
                            name="consumer_secret"
                            class="regular-text"
                            value="<?php echo esc_attr($consumer_secret); ?>"
                            placeholder="Woocommerce Consumer Secret (42-44 Chars)"
                            minlength="42"
                            maxlength="44" />
                        <p class="description">
                            Enter your WooCommerce Consumer Secret.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save & Verify', 'smart-store-sync'); ?>
                </button>
            </p>
        </form>

        <hr>

        <p>
            Don’t have a token?
            <a href="https://kodyt.com/smartstore-sync" target="_blank">
                Buy a plan →
            </a>
        </p>

        <?php if (!empty($subscription_details)) : ?>

            <!-- ✅ ACTIVATED STATE -->
            <div class="notice notice-success">
                <p><strong>License active</strong></p>
            </div>

            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td><strong>Plan Name</strong></td>
                        <td><?php echo esc_html($subscription_details['plan_name'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Status</strong></td>
                        <td><?php echo esc_html($subscription_details['status'] ?? 'Active'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Renews At</strong></td>
                        <td><?php echo esc_html($subscription_details['expires_at'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Active Stores</strong></td>
                        <td><?php echo esc_html(count($subscription_details['selected_stores']) ?? '—'); ?></td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top:15px;">
                <a href="https://kodyt.com/my-account" target="_blank">
                    Manage Subscription →
                </a>
            </p>

        <?php endif; ?>
<?php
    }

    private function get_settings()
    {
        $defaults = [
            'api'               => [],
            'stores'            => [],
            'enabled_stores'    => [],
            'purchase_token'    => '',
            'category_mappings' => [],
            'fallbacks'         => [],
            'last_sync'         => [],
        ];

        $stored = get_option(self::OPTION_KEY, []);

        if (! is_array($stored)) {
            $stored = [];
        }

        return array_replace_recursive($defaults, $stored);
    }

    private function update_settings($settings)
    {
        update_option(self::OPTION_KEY, $settings);
    }

    private function update_secrets($settings, $purchase_token, $consumer_key, $consumer_secret)
    {
        if (!$purchase_token) {
            return false;
        }

        if (!$consumer_key) {
            return false;
        }

        if (!$consumer_secret) {
            return false;
        }

        if ($this->data_provider->save_secrets($purchase_token, $consumer_key, $consumer_secret)) {
            $settings['purchase_token'] = $purchase_token;
            $settings['consumer_key'] = $consumer_key;
            $settings['consumer_secret'] = $consumer_secret;

            $this->update_settings($settings);

            return true;
        }

        return false;
    }

    private function get_remote_stores($settings)
    {
        return $this->data_provider->get_stores();
    }

    private function get_subscription_details($purchase_token)
    {
        if (empty($purchase_token)) {
            return [];
        }

        return $this->data_provider->get_subscription_details($purchase_token);
    }

    private function get_enabled_stores($settings)
    {
        if (empty($settings['purchase_token'])) {
            return [];
        }

        $details = $this->data_provider->get_subscription_details($settings['purchase_token'], true);

        if ($details['selected_stores']) {
            return array_column($details['selected_stores'], 'store_id');
        } else {
            return [];
        }
    }

    private function update_enabled_stores($settings, $enabled_stores)
    {
        return $this->data_provider->set_active_stores($settings['purchase_token'], $enabled_stores);
    }

    private function get_remote_categories_for_store($store_id, $settings)
    {
        return $this->data_provider->get_categories($store_id);
    }

    private function get_woocommerce_product_cats()
    {
        if (! function_exists('wc_get_product_cat_ids')) {
            // WooCommerce not active, but we still try to fetch product_cat terms
        }

        $terms = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ]
        );

        if (is_wp_error($terms)) {
            return [];
        }

        return $terms;
    }
}
