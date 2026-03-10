<?php
defined('ABSPATH') || exit;

class PF_Feed_Cache {

    /**
     * Henter og cacher et Partner-ads feed i databasen.
     */
    public static function refresh_partnerads_feed(string $feed_url): int {
        $response = wp_remote_get($feed_url, [
            'timeout' => 120,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('ProductFeed: Fejl ved hentning af feed: ' . $response->get_error_message());
            return 0;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            error_log('ProductFeed: Tomt feed-svar fra ' . $feed_url);
            return 0;
        }

        $products = PF_Feed_Parser::parse_partnerads($body);
        if (empty($products)) {
            return 0;
        }

        return self::store_products($products, $feed_url);
    }

    /**
     * Gemmer produkter i cache-tabellen (upsert).
     */
    private static function store_products(array $products, string $feed_url): int {
        global $wpdb;
        $table = $wpdb->prefix . 'pf_products';
        $count = 0;

        foreach ($products as $p) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table
                    (source, external_id, merchant, category, brand, name, description, ean, price, old_price, shipping, stock_status, delivery_time, size, color, image_url, affiliate_url, feed_url, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %f, %f, %f, %s, %s, %s, %s, %s, %s, %s, NOW())
                ON DUPLICATE KEY UPDATE
                    merchant = VALUES(merchant),
                    category = VALUES(category),
                    brand = VALUES(brand),
                    name = VALUES(name),
                    description = VALUES(description),
                    ean = VALUES(ean),
                    price = VALUES(price),
                    old_price = VALUES(old_price),
                    shipping = VALUES(shipping),
                    stock_status = VALUES(stock_status),
                    delivery_time = VALUES(delivery_time),
                    size = VALUES(size),
                    color = VALUES(color),
                    image_url = VALUES(image_url),
                    affiliate_url = VALUES(affiliate_url),
                    feed_url = VALUES(feed_url),
                    updated_at = NOW()",
                $p['source'],
                $p['external_id'],
                $p['merchant'],
                $p['category'],
                $p['brand'],
                $p['name'],
                $p['description'] ?? '',
                $p['ean'],
                $p['price'],
                $p['old_price'] ?? 0,
                $p['shipping'],
                $p['stock_status'],
                $p['delivery_time'],
                $p['size'],
                $p['color'],
                $p['image_url'],
                $p['affiliate_url'],
                $feed_url
            ));

            if ($wpdb->last_error === '') {
                $count++;
            }
        }

        // Gem tidspunkt for sidste refresh
        update_option('pf_last_refresh_' . md5($feed_url), current_time('mysql'));

        return $count;
    }

    /**
     * AJAX: Refresh et feed manuelt fra admin.
     */
    public static function ajax_refresh(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $feed_url = sanitize_url($_POST['feed_url'] ?? '');
        $source = sanitize_text_field($_POST['source'] ?? 'partnerads');

        if (empty($feed_url)) {
            wp_send_json_error('Feed URL mangler');
        }

        $count = 0;
        if ($source === 'partnerads') {
            $count = self::refresh_partnerads_feed($feed_url);
        }

        wp_send_json_success([
            'count' => $count,
            'message' => "$count produkter opdateret",
        ]);
    }

    /**
     * Returnerer antal produkter i cache pr. feed_url (pr. shop).
     */
    public static function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pf_products';

        $results = $wpdb->get_results(
            "SELECT feed_url, COUNT(*) as count, MAX(updated_at) as last_updated
             FROM $table GROUP BY feed_url"
        );

        $stats = [];
        foreach ($results as $row) {
            $stats[$row->feed_url] = [
                'count' => (int) $row->count,
                'last_updated' => $row->last_updated,
            ];
        }

        return $stats;
    }
}
