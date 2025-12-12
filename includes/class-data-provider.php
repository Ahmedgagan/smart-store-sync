<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSI_Data_Provider {

    /**
     * Endpoint for fetching stores + categories.
     */
    const STORES_ENDPOINT = 'https://api.kodyt.com/api/stores';

    /**
     * Cache key + lifetime (to avoid hitting API on every page load).
     */
    const TRANSIENT_KEY   = 'msi_stores_cache';
    const CACHE_TTL       = 600; // seconds (10 minutes) – adjust as you like

    /**
     * Fetch raw stores data from API (or from cache).
     *
     * Raw format (from your example):
     * [
     *   {
     *     "store_id": 1,
     *     "store_name": "Watch House",
     *     "store_slug": "watchhouse11",
     *     "base_url": "https://watchhouse11.cartpe.in/",
     *     "categories": [...]
     *   },
     *   ...
     * ]
     *
     * @return array List of raw store objects (as associative arrays).
     */
    public function get_raw_stores() {
        // Try cache first
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get( self::STORES_ENDPOINT, [
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            // You could log the error here
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return [];
        }

        // We expect $data to already be the array of stores as per your example.
        // If later the API wraps it in a "data" key, you can adapt here.
        $stores = $data;

        // Cache it
        set_transient( self::TRANSIENT_KEY, $stores, self::CACHE_TTL );

        return $stores;
    }

    /**
     * Normalized store list for the "Stores" tab.
     *
     * Returns:
     * [
     *   '1' => [
     *     'name'     => 'Watch House',
     *     'slug'     => 'watchhouse11',
     *     'base_url' => 'https://watchhouse11.cartpe.in/',
     *   ],
     *   '2' => [
     *     'name'     => 'Shoe Gallery 66',
     *     'slug'     => 'shoegallery66',
     *     'base_url' => 'https://shoegallery66.cartpe.in/',
     *   ],
     * ]
     *
     * @return array
     */
    public function get_stores() {
        $raw = $this->get_raw_stores();
        $stores = [];

        foreach ( $raw as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['store_id'] ) ) {
                continue;
            }

            $id   = (string) $item['store_id'];
            $name = isset( $item['store_name'] ) ? $item['store_name'] : $id;

            $stores[ $id ] = [
                'name'     => $name,
                'slug'     => $item['store_slug']  ?? '',
                'base_url' => $item['base_url']    ?? '',
                // We *could* include categories here too if needed later.
            ];
        }

        return $stores;
    }

    /**
     * Normalized categories for a given store.
     *
     * From your example input, we produce:
     *
     * [
     *   [ 'id' => '1', 'label' => 'Ladies Watch',          'slug' => 'ladies-watch-watches',              'url' => 'https://...' ],
     *   [ 'id' => '3', 'label' => 'Luxury Watch Collection','slug' => 'luxury-watch-collection-watches',  'url' => 'https://...' ],
     *   ...
     * ]
     *
     * @param string|int $store_id
     * @return array
     */
    public function get_categories( $store_id ) {
        $store_id = (string) $store_id;
        $raw      = $this->get_raw_stores();

        foreach ( $raw as $item ) {
            if ( (string) ( $item['store_id'] ?? '' ) !== $store_id ) {
                continue;
            }

            $categories = $item['categories'] ?? [];
            $result     = [];

            foreach ( $categories as $cat ) {
                if ( ! isset( $cat['category_id'] ) ) {
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

    /**
     * Helper to clear cache manually if you add a "Refresh" button later.
     */
    public function clear_cache() {
        delete_transient( self::TRANSIENT_KEY );
    }
}
