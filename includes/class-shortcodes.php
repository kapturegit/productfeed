<?php
defined('ABSPATH') || exit;

class PF_Shortcodes {

    public static function register(): void {
        add_shortcode('produktgruppe', [self::class, 'render_product_group']);
        add_shortcode('produktkort', [self::class, 'render_product_card']);

        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_styles']);
    }

    public static function enqueue_frontend_styles(): void {
        wp_enqueue_style(
            'pf-frontend',
            PF_PLUGIN_URL . 'admin/css/admin.css',
            [],
            PF_VERSION
        );
    }

    /**
     * [produktgruppe id="123" layout="category|comparison|inline" columns="3"]
     *
     * Layout-aliases:
     *   category / catalog / grid  → produktkort-grid (Design: Kategori-visning)
     *   comparison / pricecompare  → prissammenlignings-tabel (Design: Prissammenligning)
     *   inline                     → inline-tekst i brødtekst (Design: Inline-tekst)
     */
    public static function render_product_group(array $atts): string {
        $atts = shortcode_atts([
            'id'      => 0,
            'layout'  => '',
            'columns' => 3,
        ], $atts, 'produktgruppe');

        $filter_id = intval($atts['id']);
        if (!$filter_id) {
            return '<!-- ProductFeed: Manglende produktgruppe ID -->';
        }

        global $wpdb;
        $filter = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pf_filters WHERE id = %d",
            $filter_id
        ));

        if (!$filter) {
            return '<!-- ProductFeed: Produktgruppe ikke fundet -->';
        }

        $filter_data = json_decode($filter->filter_data, true);
        $products = self::get_wc_products_for_filter($filter_data);

        if (empty($products)) {
            return '<p class="pf-no-products">Ingen produkter fundet.</p>';
        }

        // Normalisér layout-navn (aliases)
        $layout = strtolower(trim($atts['layout']));
        $layout = match ($layout) {
            'catalog', 'grid'     => 'category',
            'pricecompare'        => 'comparison',
            default               => $layout,
        };

        // Fallback fra gemt display_mode
        if (empty($layout)) {
            $display_mode = $filter_data['display_mode'] ?? 'catalog';
            $layout = ($display_mode === 'pricecompare') ? 'comparison' : 'category';
        }

        $columns = (int) $atts['columns'];

        return match ($layout) {
            'comparison' => self::render_pricecompare($products),
            'inline'     => self::render_inline($products),
            default      => self::render_grid($products, $columns),
        };
    }

    /**
     * [produktkort product_id="456" layout="card|compact"]
     */
    public static function render_product_card(array $atts): string {
        $atts = shortcode_atts([
            'product_id' => 0,
            'layout'     => 'card',
        ], $atts, 'produktkort');

        $product = wc_get_product(intval($atts['product_id']));
        if (!$product) {
            return '';
        }

        if ($atts['layout'] === 'compact') {
            return self::render_single_compact($product);
        }

        return self::render_single_card($product);
    }

    /**
     * Finder WC-produkter baseret på gemte produkt-IDs i produktgruppen.
     */
    private static function get_wc_products_for_filter(array $filter_data): array {
        $product_ids = $filter_data['product_ids'] ?? [];

        if (empty($product_ids)) {
            return [];
        }

        $products = [];
        foreach ($product_ids as $pid) {
            $product = wc_get_product((int) $pid);
            if ($product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Henter produkt-data til rendering.
     */
    private static function get_product_data(WC_Product $product): array {
        $price = (float) $product->get_price();
        $regular = (float) $product->get_regular_price();
        $sale = $product->get_sale_price();
        $stock = $product->get_stock_status();
        $merchant = $product->get_meta('_pf_merchant');
        $shipping = (float) $product->get_meta('_pf_shipping');
        $delivery = $product->get_meta('_pf_delivery_time');
        $description = $product->get_short_description();

        $is_on_sale = !empty($sale) && $regular > $price;
        $discount_pct = $is_on_sale ? round((($regular - $price) / $regular) * 100) : 0;

        return [
            'name'          => $product->get_name(),
            'url'           => $product->get_product_url(),
            'image'         => wp_get_attachment_url($product->get_image_id()),
            'price'         => $price,
            'regular_price' => $regular,
            'is_on_sale'    => $is_on_sale,
            'discount_pct'  => $discount_pct,
            'stock'         => $stock,
            'stock_label'   => ($stock === 'instock') ? 'På lager' : 'Udsolgt',
            'merchant'      => $merchant,
            'shipping'      => $shipping,
            'shipping_text' => ($shipping > 0) ? number_format($shipping, 0) . ' kr fragt' : 'Gratis fragt',
            'delivery'      => $delivery,
            'brand'         => $product->get_meta('_pf_brand') ?: $product->get_attribute('Brand'),
            'description'   => $description,
        ];
    }

    // =========================================================================
    // Skabelon-hjælper: erstatter {{pladsholdere}} med produktdata
    // =========================================================================

    private static function apply_template(string $template, array $d): string {
        $replacements = [
            '{{name}}'          => esc_html($d['name']),
            '{{price}}'         => number_format($d['price'], 0, ',', '.'),
            '{{url}}'           => esc_url($d['url']),
            '{{image}}'         => esc_url($d['image']),
            '{{merchant}}'      => esc_html($d['merchant']),
            '{{brand}}'         => esc_html($d['brand']),
            '{{stock_label}}'   => $d['stock_label'],
            '{{shipping_text}}' => esc_html($d['shipping_text']),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    // =========================================================================
    // CATEGORY LAYOUT (grid med produktkort fra Design-skabelon)
    // =========================================================================

    private static function render_grid(array $products, int $columns): string {
        $template_html = get_option('pf_template_catalog_html', PF_Design_Page::default_catalog_html());
        $template_css = get_option('pf_template_catalog_css', PF_Design_Page::default_catalog_css());

        $html = '<style>' . $template_css . '</style>';
        $html .= '<div class="pf-product-grid pf-cols-' . $columns . '">';
        foreach ($products as $product) {
            $d = self::get_product_data($product);
            $html .= self::apply_template($template_html, $d);
        }
        $html .= '</div>';
        return $html;
    }

    private static function render_single_card(WC_Product $product): string {
        $template_html = get_option('pf_template_catalog_html', PF_Design_Page::default_catalog_html());
        $template_css = get_option('pf_template_catalog_css', PF_Design_Page::default_catalog_css());
        $d = self::get_product_data($product);
        return '<style>' . $template_css . '</style>' . self::apply_template($template_html, $d);
    }

    // =========================================================================
    // LIST LAYOUT
    // =========================================================================

    private static function render_list(array $products): string {
        $html = '<div class="pf-product-grid pf-product-list" style="grid-template-columns:1fr;">';
        foreach ($products as $product) {
            $d = self::get_product_data($product);

            $badge = $d['is_on_sale']
                ? '<span class="pf-badge-sale">-' . $d['discount_pct'] . '%</span>'
                : '';

            $desc = !empty($d['description'])
                ? '<div class="pf-product-description">' . esc_html(wp_strip_all_tags($d['description'])) . '</div>'
                : '';

            $price_html = '<span class="pf-price-current">' . number_format($d['price'], 0, ',', '.') . ' kr</span>';
            if ($d['is_on_sale']) {
                $price_html .= ' <span class="pf-price-old">' . number_format($d['regular_price'], 0, ',', '.') . ' kr</span>';
            }

            $html .= '
            <div class="pf-product-card">
                <a href="' . esc_url($d['url']) . '" target="_blank" rel="nofollow sponsored" class="pf-product-image">
                    ' . $badge . '
                    <img src="' . esc_url($d['image']) . '" alt="' . esc_attr($d['name']) . '" loading="lazy">
                </a>
                <div class="pf-product-info">
                    <h3 class="pf-product-name"><a href="' . esc_url($d['url']) . '" target="_blank" rel="nofollow sponsored">' . esc_html($d['name']) . '</a></h3>
                    ' . $desc . '
                    <div class="pf-product-price">' . $price_html . '</div>
                    <div class="pf-product-meta">
                        <span class="pf-meta-merchant">' . esc_html($d['merchant']) . '</span>
                        <span class="pf-meta-shipping">' . esc_html($d['shipping_text']) . '</span>
                    </div>
                    <a href="' . esc_url($d['url']) . '" class="pf-buy-button" target="_blank" rel="nofollow sponsored">
                        Se pris hos ' . esc_html($d['merchant']) . '
                    </a>
                </div>
            </div>';
        }
        $html .= '</div>';
        return $html;
    }

    // =========================================================================
    // TABLE LAYOUT (prissammenligning)
    // =========================================================================

    private static function render_table(array $products): string {
        // Sortér efter pris (billigst først)
        usort($products, function($a, $b) {
            $price_a = (float) $a->get_price();
            $price_b = (float) $b->get_price();
            return $price_a <=> $price_b;
        });

        $html = '<table class="pf-product-table">
            <thead>
                <tr>
                    <th>Produkt</th>
                    <th>Forhandler</th>
                    <th>Pris</th>
                    <th>Fragt</th>
                    <th>Levering</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>';

        foreach ($products as $product) {
            $d = self::get_product_data($product);

            $price_cell = '<span class="pf-table-price">' . number_format($d['price'], 0, ',', '.') . ' kr</span>';
            if ($d['is_on_sale']) {
                $price_cell .= ' <span class="pf-price-old">' . number_format($d['regular_price'], 0, ',', '.') . ' kr</span>';
            }

            $html .= '<tr>
                <td>
                    <div class="pf-table-product">
                        <img src="' . esc_url($d['image']) . '" alt="" class="pf-table-thumb" loading="lazy">
                        <span class="pf-table-name">' . esc_html($d['name']) . '</span>
                    </div>
                </td>
                <td>' . esc_html($d['merchant']) . '</td>
                <td>' . $price_cell . '</td>
                <td>' . esc_html($d['shipping_text']) . '</td>
                <td>' . esc_html($d['delivery'] ?: '—') . '</td>
                <td><a href="' . esc_url($d['url']) . '" target="_blank" rel="nofollow sponsored" class="pf-table-buy">Se pris</a></td>
            </tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    // =========================================================================
    // PRISSAMMENLIGNING LAYOUT
    // =========================================================================

    private static function render_pricecompare(array $products): string {
        // Hent data for alle produkter
        $offers = [];
        $main = null;
        foreach ($products as $product) {
            $d = self::get_product_data($product);
            $offers[] = $d;

            // Brug det første produkt til billede og info
            if ($main === null) {
                $main = $d;
            }
        }

        if (empty($offers) || !$main) {
            return '';
        }

        // Sortér efter pris (billigst først)
        usort($offers, fn($a, $b) => $a['price'] <=> $b['price']);

        $html = '<div class="pf-compare-product">';

        // Produktheader med ét billede fra det første tilføjede produkt
        $html .= '<div class="pf-compare-header">';
        if (!empty($main['image'])) {
            $html .= '<div class="pf-compare-image"><img src="' . esc_url($main['image']) . '" alt="' . esc_attr($main['name']) . '" loading="lazy"></div>';
        }
        $html .= '<div class="pf-compare-details">';
        if (!empty($main['brand'])) {
            $html .= '<div class="pf-product-brand">' . esc_html($main['brand']) . '</div>';
        }
        $html .= '<h3 class="pf-compare-name">' . esc_html($main['name']) . '</h3>';
        if (!empty($main['description'])) {
            $html .= '<div class="pf-compare-description">' . wp_kses_post($main['description']) . '</div>';
        }
        $html .= '<p class="pf-compare-cta">Find den billigste pris her:</p>';
        $html .= '</div>'; // .pf-compare-details
        $html .= '</div>'; // .pf-compare-header

        // Pristabel med alle shops som rækker
        $html .= '<table class="pf-compare-table">';
        $html .= '<thead><tr><th>Butik</th><th>Pris</th><th></th></tr></thead>';
        $html .= '<tbody>';

        $cheapest = true;
        foreach ($offers as $offer) {
            $row_class = $cheapest ? ' class="pf-cheapest"' : '';
            $cheapest = false;

            $price_html = number_format($offer['price'], 2, ',', '.') . ' kr.';
            if ($offer['is_on_sale']) {
                $price_html .= ' <span class="pf-price-old">' . number_format($offer['regular_price'], 2, ',', '.') . ' kr.</span>';
            }

            $html .= '<tr' . $row_class . '>';
            $html .= '<td class="pf-compare-merchant">' . esc_html($offer['merchant']) . '</td>';
            $html .= '<td class="pf-compare-price">' . $price_html . '</td>';
            $html .= '<td><a href="' . esc_url($offer['url']) . '" target="_blank" rel="nofollow sponsored" class="pf-compare-buy">KØB HER</a></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>'; // .pf-compare-product

        return $html;
    }

    /**
     * Grupperer produkter efter garnnavn (fjerner farve/variant-info).
     */
    private static function get_product_group_key(string $name, string $brand): string {
        $key = strtolower(trim($name));

        // Fjern farve-info (f.eks. "01 Natur", "0100 hvid" osv.)
        $key = preg_replace('/\s+\d{1,4}\s+\w+$/u', '', $key);
        // Fjern parenteser med indhold
        $key = preg_replace('/\s*\([^)]*\)/', '', $key);
        $key = trim($key);

        return $key ?: strtolower($name);
    }

    /**
     * Henter synlige attributter fra et WooCommerce-produkt.
     */
    private static function get_visible_attributes(WC_Product $product): array {
        $result = [];
        $attributes = $product->get_attributes();

        if (empty($attributes)) {
            return $result;
        }

        foreach ($attributes as $attr) {
            if ($attr instanceof WC_Product_Attribute && $attr->get_visible()) {
                $name = $attr->get_name();
                $options = $attr->get_options();
                if (!empty($options)) {
                    $result[$name] = implode(', ', $options);
                }
            }
        }

        return $result;
    }

    // =========================================================================
    // COMPACT LAYOUT (til inline i brødtekst)
    // =========================================================================

    private static function render_compact(array $products): string {
        $html = '';
        foreach ($products as $product) {
            $html .= self::render_single_compact($product);
        }
        return $html;
    }

    // =========================================================================
    // INLINE LAYOUT (tekst-link til brødtekst)
    // =========================================================================

    private static function render_inline(array $products): string {
        $template_html = get_option('pf_template_inline_html', PF_Design_Page::default_inline_html());
        $template_css = get_option('pf_template_inline_css', PF_Design_Page::default_inline_css());

        $html = '<style>' . $template_css . '</style>';
        foreach ($products as $product) {
            $d = self::get_product_data($product);
            $html .= self::apply_template($template_html, $d) . ' ';
        }
        return trim($html);
    }

    private static function render_single_compact(WC_Product $product): string {
        $d = self::get_product_data($product);

        return '
        <div class="pf-product-compact">
            <img src="' . esc_url($d['image']) . '" alt="' . esc_attr($d['name']) . '" loading="lazy">
            <div class="pf-compact-info">
                <div class="pf-compact-name">' . esc_html($d['name']) . '</div>
                <div class="pf-compact-meta">' . esc_html($d['merchant']) . ' · ' . esc_html($d['shipping_text']) . '</div>
            </div>
            <div class="pf-compact-price">' . number_format($d['price'], 0, ',', '.') . ' kr</div>
            <a href="' . esc_url($d['url']) . '" target="_blank" rel="nofollow sponsored" class="pf-compact-buy">Se pris</a>
        </div>';
    }
}
