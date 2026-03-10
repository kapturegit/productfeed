<?php
defined('ABSPATH') || exit;

class PF_Saved_Filters {

    /**
     * Renderer produktgrupper-siden.
     */
    public static function render_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pf_filters';
        $filters = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");
        ?>
        <div class="wrap">
            <h1>ProductFeed — Produktgrupper</h1>
            <p>Dine gemte produktgrupper. Brug shortcoden til at vise produkterne på din side.</p>

            <div class="pf-layout-guide" style="background:#fff;border:1px solid #ccd0d4;padding:20px 24px;margin:16px 0 24px;max-width:1000px;">
                <h3 style="margin-top:0;">Visningsguide</h3>
                <p style="color:#666;margin-bottom:14px;">Samme produktgruppe kan vises på 3 forskellige måder. Vælg layout i shortcoden:</p>
                <table class="widefat" style="border:none;box-shadow:none;">
                    <thead>
                        <tr>
                            <th style="border-bottom:2px solid #e8e8e8;">Layout</th>
                            <th style="border-bottom:2px solid #e8e8e8;">Shortcode</th>
                            <th style="border-bottom:2px solid #e8e8e8;">Beskrivelse</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Kategori</strong></td>
                            <td><code>[produktgruppe id="X" layout="category"]</code></td>
                            <td>Produktkort i grid — god til kategorisider og oversigter</td>
                        </tr>
                        <tr>
                            <td><strong>Prissammenligning</strong></td>
                            <td><code>[produktgruppe id="X" layout="comparison"]</code></td>
                            <td>Pristabel med butikker — god til produktsider</td>
                        </tr>
                        <tr>
                            <td><strong>Inline-tekst</strong></td>
                            <td><code>[produktgruppe id="X" layout="inline"]</code></td>
                            <td>Tekst-link i brødtekst — f.eks. "<em>Easy Care til 49 kr hos Hobbygarn</em>"</td>
                        </tr>
                    </tbody>
                </table>
                <p style="color:#666;margin:12px 0 0;font-size:13px;">Tilpas udseendet under <a href="<?php echo admin_url('admin.php?page=productfeed-design'); ?>">Design</a>.</p>
            </div>

            <?php if (empty($filters)): ?>
                <p>Ingen produktgrupper oprettet endnu. Gå til <a href="<?php echo admin_url('admin.php?page=productfeed'); ?>">Søg produkter</a> og gem et filter som produktgruppe.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1000px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Navn</th>
                            <th>Produkter</th>
                            <th>Shortcode</th>
                            <th>Oprettet</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filters as $filter): ?>
                        <?php $data = json_decode($filter->filter_data, true); ?>
                        <?php $product_count = count($data['product_ids'] ?? []); ?>
                        <tr>
                            <td><?php echo (int) $filter->id; ?></td>
                            <td><strong><?php echo esc_html($filter->name); ?></strong></td>
                            <td><?php echo $product_count; ?> stk.</td>
                            <td>
                                <code>[produktgruppe id="<?php echo (int) $filter->id; ?>"]</code>
                            </td>
                            <td><?php echo esc_html($filter->created_at); ?></td>
                            <td>
                                <a class="button button-primary" href="<?php echo admin_url('admin.php?page=productfeed&add_to_group=' . (int) $filter->id); ?>">Tilføj produkter</a>
                                <button class="button pf-delete-filter" data-id="<?php echo (int) $filter->id; ?>">Slet</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        document.querySelectorAll('.pf-delete-filter').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Slet denne produktgruppe?')) return;

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'pf_delete_filter',
                        nonce: '<?php echo wp_create_nonce("pf_nonce"); ?>',
                        filter_id: this.dataset.id
                    })
                }).then(function() { location.reload(); });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Gem et nyt filter.
     */
    public static function ajax_save(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $name = sanitize_text_field($_POST['name'] ?? '');
        if (empty($name)) {
            wp_send_json_error('Navn mangler');
        }

        $filter_data = [
            'query'        => sanitize_text_field($_POST['query'] ?? ''),
            'merchant'     => sanitize_text_field($_POST['merchant'] ?? ''),
            'category'     => sanitize_text_field($_POST['category'] ?? ''),
            'brand'        => sanitize_text_field($_POST['brand'] ?? ''),
            'price_min'    => floatval($_POST['price_min'] ?? 0),
            'price_max'    => floatval($_POST['price_max'] ?? 0),
            'stock'        => sanitize_text_field($_POST['stock'] ?? ''),
            'product_ids'  => [],
        ];

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'pf_filters',
            [
                'name'        => $name,
                'filter_data' => wp_json_encode($filter_data),
                'created_at'  => current_time('mysql'),
                'updated_at'  => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s']
        );

        $filter_id = $wpdb->insert_id;

        wp_send_json_success([
            'filter_id' => $filter_id,
            'shortcode' => '[produktgruppe id="' . $filter_id . '"]',
            'message'   => 'Produktgruppe gemt!',
        ]);
    }

    /**
     * AJAX: Hent et gemt filter.
     */
    public static function ajax_load(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        $id = intval($_POST['filter_id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Manglende ID');
        }

        global $wpdb;
        $filter = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pf_filters WHERE id = %d",
            $id
        ));

        if (!$filter) {
            wp_send_json_error('Ikke fundet');
        }

        wp_send_json_success([
            'filter' => $filter,
            'data'   => json_decode($filter->filter_data, true),
        ]);
    }

    /**
     * AJAX: Slet et filter.
     */
    public static function ajax_delete(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $id = intval($_POST['filter_id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Manglende ID');
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'pf_filters', ['id' => $id], ['%d']);

        wp_send_json_success(['message' => 'Slettet']);
    }

    /**
     * AJAX: Tilføj produkt-IDs til en eksisterende produktgruppe.
     */
    public static function ajax_add_to(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $filter_id = intval($_POST['filter_id'] ?? 0);
        if (!$filter_id) {
            wp_send_json_error('Manglende gruppe-ID');
        }

        $new_ids = array_map('intval', $_POST['product_ids'] ?? []);
        if (empty($new_ids)) {
            wp_send_json_error('Ingen produkter valgt');
        }

        global $wpdb;
        $filter = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pf_filters WHERE id = %d",
            $filter_id
        ));

        if (!$filter) {
            wp_send_json_error('Produktgruppe ikke fundet');
        }

        // WC-IDs tilføjes via ajax_update_wc_ids efter import
        wp_send_json_success([
            'filter_id'   => $filter_id,
            'added'       => count($new_ids),
            'total'       => count($new_ids),
            'shortcode'   => '[produktgruppe id="' . $filter_id . '"]',
        ]);
    }

    /**
     * AJAX: Opdater produktgruppe med WooCommerce produkt-IDs (erstatter cache-IDs).
     */
    public static function ajax_update_wc_ids(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $filter_id = intval($_POST['filter_id'] ?? 0);
        $wc_ids = array_map('intval', $_POST['wc_ids'] ?? []);

        if (!$filter_id || empty($wc_ids)) {
            wp_send_json_error('Manglende data');
        }

        global $wpdb;
        $filter = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pf_filters WHERE id = %d",
            $filter_id
        ));

        if (!$filter) {
            wp_send_json_error('Produktgruppe ikke fundet');
        }

        $filter_data = json_decode($filter->filter_data, true);
        $existing_wc_ids = $filter_data['product_ids'] ?? [];

        // Merge med eksisterende WC-IDs (undgå dubletter)
        $merged = array_values(array_unique(array_merge($existing_wc_ids, $wc_ids)));
        $filter_data['product_ids'] = $merged;

        $wpdb->update(
            $wpdb->prefix . 'pf_filters',
            [
                'filter_data' => wp_json_encode($filter_data),
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $filter_id],
            ['%s', '%s'],
            ['%d']
        );

        wp_send_json_success();
    }

    /**
     * AJAX: List alle filtre.
     */
    public static function ajax_list(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        global $wpdb;
        $filters = $wpdb->get_results(
            "SELECT id, name, created_at FROM {$wpdb->prefix}pf_filters ORDER BY name ASC"
        );

        wp_send_json_success(['filters' => $filters]);
    }
}
