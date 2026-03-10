<?php
defined('ABSPATH') || exit;

class PF_Product_Updater {

    /**
     * Opdaterer kun WooCommerce-produkter der er importeret via ProductFeed.
     * Køres dagligt via WP Cron.
     */
    public static function update_active_products(): void {
        if (!class_exists('WC_Product_External')) {
            return;
        }

        // Find alle WC-produkter med _pf_source meta (= importeret via os)
        $product_ids = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => '_pf_source', 'compare' => 'EXISTS'],
            ],
        ]);

        if (empty($product_ids)) {
            return;
        }

        // Grupper efter feed_url for at minimere antal HTTP-kald
        $feeds_needed = self::group_by_feed($product_ids);

        foreach ($feeds_needed as $feed_url => $wc_products) {
            self::update_from_feed($feed_url, $wc_products);
        }

        update_option('pf_last_daily_update', current_time('mysql'));
    }

    /**
     * Grupperer WC-produkter efter deres feed_url.
     */
    private static function group_by_feed(array $product_ids): array {
        $grouped = [];

        foreach ($product_ids as $pid) {
            $feed_url = get_post_meta($pid, '_pf_feed_url', true);
            $source = get_post_meta($pid, '_pf_source', true);
            $external_id = get_post_meta($pid, '_pf_external_id', true);

            if (empty($feed_url) || empty($external_id)) {
                continue;
            }

            $grouped[$feed_url][] = [
                'wc_product_id' => $pid,
                'source'        => $source,
                'external_id'   => $external_id,
            ];
        }

        return $grouped;
    }

    /**
     * Henter ét feed og opdaterer alle tilhørende WC-produkter.
     */
    private static function update_from_feed(string $feed_url, array $wc_products): void {
        $response = wp_remote_get($feed_url, [
            'timeout'   => 120,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('ProductFeed update: Fejl ved ' . $feed_url . ': ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return;
        }

        // Bestem parser baseret på første produkt i gruppen
        $source = $wc_products[0]['source'];
        $feed_products = [];

        if ($source === 'partnerads') {
            $feed_products = PF_Feed_Parser::parse_partnerads($body);
        } elseif ($source === 'adtraction') {
            $feed_products = PF_Feed_Parser::parse_adtraction($body);
        }

        if (empty($feed_products)) {
            return;
        }

        // Indekser feed-produkter efter external_id
        $indexed = [];
        foreach ($feed_products as $fp) {
            $indexed[$fp['external_id']] = $fp;
        }

        // Opdater hvert WC-produkt
        foreach ($wc_products as $wcp) {
            $product = wc_get_product($wcp['wc_product_id']);
            if (!$product || !($product instanceof WC_Product_External)) {
                continue;
            }

            if (isset($indexed[$wcp['external_id']])) {
                $fp = $indexed[$wcp['external_id']];

                // Opdater pris
                if ($fp['old_price'] && $fp['old_price'] > $fp['price']) {
                    $product->set_regular_price((string) $fp['old_price']);
                    $product->set_sale_price((string) $fp['price']);
                } else {
                    $product->set_regular_price((string) $fp['price']);
                    $product->set_sale_price('');
                }

                // Opdater lagerstatus
                $stock = PF_Feed_Parser::normalize_stock($fp['stock_status']);
                $product->set_stock_status($stock);

                // Opdater affiliate-link
                $product->set_product_url($fp['affiliate_url']);

                $product->save();
            } else {
                // Produkt findes ikke længere i feed — marker som udsolgt
                $product->set_stock_status('outofstock');
                $product->save();
            }
        }
    }
}
