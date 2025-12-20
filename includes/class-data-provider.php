<?php

if (! defined('ABSPATH')) {
    exit;
}

class MSI_Data_Provider
{
    const STORES_ENDPOINT = 'https://api.kodyt.com/api/stores';
    const SUBSCRIPTION_DETAILS_ENDPOINT = 'https://api.kodyt.com/api/subscriptions/status';
    const SET_ENABLED_STORED_ENDPOINT = 'https://api.kodyt.com/api/subscriptions/permissions';

    /**
     * Cache key + lifetime (to avoid hitting API on every page load).
     */
    const TRANSIENT_KEY   = 'msi_stores_cache';
    // const TRANSIENT_KEY_SUBSCRIPTION_DETAILS   = 'msi_subscription_details_cache';
    const CACHE_TTL       = 600; // seconds (10 minutes) – adjust as you like


    public function get_raw_stores()
    {
        $site_url = site_url();
        $parsed_url = parse_url($site_url); // Parse the URL into components
        $domain = $parsed_url['host']; // Extract only the host (domain name)

        // Try cache first
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::STORES_ENDPOINT, [
            'timeout' => 20,
            'api_key' => hash('sha256', $domain),
        ]);

        if (is_wp_error($response)) {
            // You could log the error here
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return [];
        }

        // We expect $data to already be the array of stores as per your example.
        // If later the API wraps it in a "data" key, you can adapt here.
        $stores = $data;

        // Cache it
        set_transient(self::TRANSIENT_KEY, $stores, self::CACHE_TTL);

        return $stores;
    }

    public function get_subscription_details($purchase_token, $reset_catch = false)
    {
        // Try cache first
        $cached = get_transient($purchase_token);
        if (is_array($cached) && !$reset_catch) {
            return $cached;
        }

        $body_params = array(
            'token' => $purchase_token,
            'buyer_domain' => 'https://lightgoldenrodyellow-wildcat-986857.hostingersite.com',
        );

        $response = wp_remote_post(self::SUBSCRIPTION_DETAILS_ENDPOINT, [
            'headers' => array(
                'Content-Type' => 'application/json;'
            ),
            'timeout' => 20,
            'body' => json_encode($body_params),
        ]);

        if (is_wp_error($response)) {
            // You could log the error here
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return [];
        }

        // We expect $data to already be the array of stores as per your example.
        // If later the API wraps it in a "data" key, you can adapt here.
        $plan_details = $data['data'];

        // Cache it
        set_transient($purchase_token, $plan_details, self::CACHE_TTL);

        return $plan_details;
    }

    public function get_allowed_store_selection_limit($purchase_token)
    {
        $cached = get_transient($purchase_token . 'limit');
        if (is_array($cached)) {
            return $cached;
        }

        $body_params = array(
            'token' => $purchase_token,
            'buyer_domain' => 'https://lightgoldenrodyellow-wildcat-986857.hostingersite.com',
        );

        $response = wp_remote_post(self::SUBSCRIPTION_DETAILS_ENDPOINT, [
            'headers' => array(
                'Content-Type' => 'application/json;'
            ),
            'timeout' => 20,
            'body' => json_encode($body_params),
        ]);

        if (is_wp_error($response)) {
            // You could log the error here
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return [];
        }

        // We expect $data to already be the array of stores as per your example.
        // If later the API wraps it in a "data" key, you can adapt here.
        $plan_details = $data['data'];

        $max_allowed_stores = $plan_details['max_allowed_stores'] ?? 0;

        // Cache it
        set_transient($purchase_token . 'limit', $max_allowed_stores, self::CACHE_TTL);

        return $max_allowed_stores;
    }

    public function set_active_stores($purchase_token, $enabled_stores)
    {
        $body_params = array(
            'token' => $purchase_token,
            'buyer_domain' => 'https://lightgoldenrodyellow-wildcat-986857.hostingersite.com',
            'store_ids' => $enabled_stores
        );

        echo "<script>console.log('PHP Variable:', '" . json_encode($body_params) . "');</script>";

        $response = wp_remote_post(self::SET_ENABLED_STORED_ENDPOINT, [
            'headers' => array(
                'Content-Type' => 'application/json;'
            ),
            'timeout' => 20,
            'body' => json_encode($body_params),
        ]);

        if (is_wp_error($response)) {
            // You could log the error here
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return [];
        }

        // We expect $data to already be the array of stores as per your example.
        // If later the API wraps it in a "data" key, you can adapt here.
        $plan_details = $data['data'];

        // Cache it
        set_transient($purchase_token, $plan_details, self::CACHE_TTL);

        return $plan_details;
    }

    public function get_stores()
    {
        $raw = $this->get_raw_stores();
        $stores = [];

        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['store_id'])) {
                continue;
            }

            $id   = (string) $item['store_id'];
            $name = isset($item['store_name']) ? $item['store_name'] : $id;

            $stores[$id] = [
                'name'     => $name,
                'slug'     => $item['store_slug']  ?? '',
                'base_url' => $item['base_url']    ?? '',
                // We *could* include categories here too if needed later.
            ];
        }

        return $stores;
    }

    public function get_categories($store_id)
    {
        $store_id = (string) $store_id;
        $raw      = $this->get_raw_stores();

        foreach ($raw as $item) {
            if ((string) ($item['store_id'] ?? '') !== $store_id) {
                continue;
            }

            $categories = $item['categories'] ?? [];
            $result     = [];

            foreach ($categories as $cat) {
                if (! isset($cat['category_id'])) {
                    continue;
                }

                $result[] = [
                    'id'    => (string) $cat['category_id'],
                    'label' => $cat['category_name'] ?? (string) $cat['category_id'],
                    'slug'  => $cat['category_slug'] ?? '',
                    'url'   => $cat['category_url']  ?? '',
                    'raw'   => $cat, // Keep full data if you need it later
                ];
            }

            return $result;
        }

        // If store not found or no categories
        return [];
    }

    public function clear_cache()
    {
        delete_transient(self::TRANSIENT_KEY);
    }
}
