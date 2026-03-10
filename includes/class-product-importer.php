<?php
defined('ABSPATH') || exit;

class PF_Product_Importer {

    /**
     * Importerer produkter fra pf_products-cache til WooCommerce.
     * Opretter "external" affiliate-produkter.
     */
    public static function import_to_woocommerce(array $product_ids): array {
        if (!class_exists('WC_Product_External')) {
            return ['error' => 'WooCommerce er ikke aktiveret'];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pf_products';
        $imported = 0;
        $skipped = 0;
        $wc_ids = [];

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE id IN ($placeholders)",
            ...$product_ids
        ));

        foreach ($rows as $row) {
            $existing = self::find_existing_product($row->source, $row->external_id);

            if ($existing) {
                self::update_wc_product($existing, $row);
                self::maybe_enrich($existing, $row);
                $wc_ids[] = $existing->get_id();
                $skipped++;
            } else {
                $wc_id = self::create_wc_product($row);
                $wc_ids[] = $wc_id;
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'updated' => $skipped,
            'wc_ids'  => $wc_ids,
        ];
    }

    /**
     * Opretter et WooCommerce external/affiliate-produkt.
     */
    private static function create_wc_product(object $row): int {
        $product = new WC_Product_External();

        $product->set_name($row->name);
        $product->set_regular_price((string) $row->price);
        $product->set_product_url($row->affiliate_url);
        $product->set_button_text('Køb hos ' . $row->merchant);
        $product->set_catalog_visibility('visible');
        $product->set_status('publish');

        // Produktbeskrivelse: prøv feed først, ellers hent fra produktsiden
        $desc = !empty($row->description) ? $row->description : '';
        if (empty($desc) || mb_strlen($desc) < 100) {
            $scraped = self::fetch_product_description($row->affiliate_url);
            if (!empty($scraped) && mb_strlen($scraped) > mb_strlen($desc)) {
                $desc = $scraped;
            }
        }
        if (!empty($desc)) {
            $clean_desc = wp_kses_post($desc);
            $product->set_description($clean_desc);
            $product->set_short_description($clean_desc);
        }

        // Sæt gammel pris som "udsalgspris" visuelt
        if ($row->old_price > 0 && $row->old_price > $row->price) {
            $product->set_regular_price((string) $row->old_price);
            $product->set_sale_price((string) $row->price);
        }

        // Lagerstatus
        $stock = PF_Feed_Parser::normalize_stock($row->stock_status);
        $product->set_stock_status($stock);

        // Gem meta-data til tracking
        $product->update_meta_data('_pf_source', $row->source);
        $product->update_meta_data('_pf_external_id', $row->external_id);
        $product->update_meta_data('_pf_merchant', $row->merchant);
        $product->update_meta_data('_pf_feed_url', $row->feed_url);
        $product->update_meta_data('_pf_ean', $row->ean);
        $product->update_meta_data('_pf_shipping', $row->shipping);
        $product->update_meta_data('_pf_delivery_time', $row->delivery_time);

        $product_id = $product->save();

        // Sæt produktbillede
        if (!empty($row->image_url)) {
            self::set_product_image($product_id, $row->image_url);
        }

        // Sæt attributter
        self::set_product_attributes($product, $row);

        // Berig med garndata fra Garnstudio (DROPS-produkter)
        $yarn_data = PF_Yarn_Enricher::enrich($row);
        if (!empty($yarn_data)) {
            PF_Yarn_Enricher::apply_to_product($product, $yarn_data);
            $product->save();
        }

        return $product_id;
    }

    /**
     * Opdaterer et eksisterende WooCommerce-produkt med nye data.
     */
    private static function update_wc_product(WC_Product_External $product, object $row): void {
        $product->set_regular_price((string) $row->price);
        $product->set_product_url($row->affiliate_url);

        // Kun hent beskrivelse hvis produktet mangler en
        $existing_desc = $product->get_description();
        if (empty($existing_desc)) {
            $desc = !empty($row->description) ? $row->description : '';
            if (empty($desc) || mb_strlen($desc) < 100) {
                $scraped = self::fetch_product_description($row->affiliate_url);
                if (!empty($scraped) && mb_strlen($scraped) > mb_strlen($desc)) {
                    $desc = $scraped;
                }
            }
            if (!empty($desc)) {
                $clean_desc = wp_kses_post($desc);
                $product->set_description($clean_desc);
                $product->set_short_description($clean_desc);
            }
        }

        if ($row->old_price > 0 && $row->old_price > $row->price) {
            $product->set_regular_price((string) $row->old_price);
            $product->set_sale_price((string) $row->price);
        } else {
            $product->set_sale_price('');
        }

        $stock = PF_Feed_Parser::normalize_stock($row->stock_status);
        $product->set_stock_status($stock);

        $product->update_meta_data('_pf_shipping', $row->shipping);
        $product->update_meta_data('_pf_delivery_time', $row->delivery_time);

        $product->save();
    }

    /**
     * Finder eksisterende WC-produkt baseret på source + external_id.
     */
    private static function find_existing_product(string $source, string $external_id): ?WC_Product_External {
        $posts = get_posts([
            'post_type'  => 'product',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_pf_source', 'value' => $source],
                ['key' => '_pf_external_id', 'value' => $external_id],
            ],
            'posts_per_page' => 1,
        ]);

        if (empty($posts)) {
            return null;
        }

        $product = wc_get_product($posts[0]->ID);
        return ($product instanceof WC_Product_External) ? $product : null;
    }

    /**
     * Downloader og sætter produktbillede.
     */
    private static function set_product_image(int $product_id, string $url): void {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = media_sideload_image($url, $product_id, '', 'id');

        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($product_id, $attachment_id);
        }
    }

    /**
     * Sætter WooCommerce-attributter (brand, merchant).
     */
    private static function set_product_attributes(WC_Product_External $product, object $row): void {
        $attributes = [];

        if (!empty($row->brand)) {
            $attr = new WC_Product_Attribute();
            $attr->set_name('Brand');
            $attr->set_options([$row->brand]);
            $attr->set_visible(true);
            $attributes[] = $attr;
        }

        if (!empty($row->merchant)) {
            $attr = new WC_Product_Attribute();
            $attr->set_name('Forhandler');
            $attr->set_options([$row->merchant]);
            $attr->set_visible(true);
            $attributes[] = $attr;
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
            $product->save();
        }
    }

    /**
     * Beriger et WooCommerce-produkt med garndata hvis det er et DROPS-produkt.
     */
    private static function maybe_enrich(WC_Product_External $product, object $row): void {
        $yarn_data = PF_Yarn_Enricher::enrich($row);
        if (!empty($yarn_data)) {
            PF_Yarn_Enricher::apply_to_product($product, $yarn_data);
            $product->save();
        }
    }

    /**
     * Udtrækker den rigtige produkt-URL fra en Partner-ads affiliate-URL
     * og henter den fulde beskrivelse fra produktsiden.
     */
    private static function fetch_product_description(string $affiliate_url): string {
        // Udtræk den rigtige URL fra htmlurl-parameteren
        $parsed = parse_url($affiliate_url);
        if (empty($parsed['query'])) {
            return '';
        }

        parse_str($parsed['query'], $params);
        $real_url = $params['htmlurl'] ?? '';
        if (empty($real_url)) {
            return '';
        }

        $response = wp_remote_get($real_url, [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [
                'User-Agent' => 'Mozilla/5.0 (compatible; ProductFeed/1.0)',
            ],
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return '';
        }

        // 1. Prøv LD+JSON (product.description) — mest pålidelig
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $json_matches)) {
            foreach ($json_matches[1] as $json_str) {
                $data = json_decode(trim($json_str), true);
                if (!$data) continue;

                // Kan være direkte Product eller @graph array
                $products = [];
                if (($data['@type'] ?? '') === 'Product') {
                    $products[] = $data;
                } elseif (!empty($data['@graph'])) {
                    foreach ($data['@graph'] as $item) {
                        if (($item['@type'] ?? '') === 'Product') {
                            $products[] = $item;
                        }
                    }
                }

                foreach ($products as $p) {
                    $desc = $p['description'] ?? '';
                    if (!empty($desc) && mb_strlen($desc) > 50) {
                        return trim(wp_strip_all_tags($desc));
                    }
                }
            }
        }

        // 2. Fallback: og:description meta-tag
        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/si', $html, $m)) {
            $desc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if (mb_strlen($desc) > 50) {
                return trim($desc);
            }
        }

        // 3. Fallback: standard meta description
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/si', $html, $m)) {
            $desc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if (mb_strlen($desc) > 50) {
                return trim($desc);
            }
        }

        return '';
    }

    /**
     * AJAX: Importer valgte produkter til WooCommerce.
     */
    public static function ajax_import(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $ids = array_map('intval', $_POST['product_ids'] ?? []);
        if (empty($ids)) {
            wp_send_json_error('Ingen produkter valgt');
        }

        $result = self::import_to_woocommerce($ids);
        wp_send_json_success($result);
    }
}
