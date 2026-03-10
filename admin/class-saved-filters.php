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
                            <td>
                                <a href="#" class="pf-view-group" data-id="<?php echo (int) $filter->id; ?>" data-name="<?php echo esc_attr($filter->name); ?>">
                                    <strong><?php echo esc_html($filter->name); ?></strong>
                                </a>
                            </td>
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

            <!-- Produktvisning for valgt gruppe -->
            <div id="pf-group-detail" style="display:none;margin-top:24px;max-width:1000px;">
                <h2 id="pf-group-detail-title"></h2>
                <table class="widefat striped" id="pf-group-products-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">Billede</th>
                            <th>Produkt</th>
                            <th>Forhandler</th>
                            <th>Pris</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="pf-group-products-body">
                    </tbody>
                </table>
                <p id="pf-group-empty" style="display:none;color:#666;">Ingen produkter i denne gruppe.</p>
            </div>
        </div>

        <script>
        (function() {
            var nonce = '<?php echo wp_create_nonce("pf_nonce"); ?>';
            var currentGroupId = null;

            // Slet produktgruppe
            document.querySelectorAll('.pf-delete-filter').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Slet denne produktgruppe?')) return;

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action: 'pf_delete_filter',
                            nonce: nonce,
                            filter_id: this.dataset.id
                        })
                    }).then(function() { location.reload(); });
                });
            });

            // Vis produkter i gruppe
            document.querySelectorAll('.pf-view-group').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var id = this.dataset.id;
                    var name = this.dataset.name;
                    currentGroupId = id;

                    document.getElementById('pf-group-detail-title').textContent = 'Produkter i: ' + name;
                    document.getElementById('pf-group-detail').style.display = 'block';

                    loadGroupProducts(id);
                });
            });

            function loadGroupProducts(filterId) {
                var body = document.getElementById('pf-group-products-body');
                var empty = document.getElementById('pf-group-empty');
                body.innerHTML = '<tr><td colspan="5">Indlæser...</td></tr>';
                empty.style.display = 'none';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'pf_get_group_products',
                        nonce: nonce,
                        filter_id: filterId
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    body.innerHTML = '';
                    if (!res.success || !res.data.products.length) {
                        empty.style.display = 'block';
                        document.getElementById('pf-group-products-table').style.display = 'none';
                        return;
                    }

                    document.getElementById('pf-group-products-table').style.display = '';
                    empty.style.display = 'none';

                    res.data.products.forEach(function(p) {
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td><img src="' + (p.image || '') + '" style="width:50px;height:50px;object-fit:contain;background:#f7f7f7;" onerror="this.style.display=\'none\'"></td>' +
                            '<td><strong>' + p.name + '</strong></td>' +
                            '<td>' + (p.merchant || '—') + '</td>' +
                            '<td>' + p.price + ' kr</td>' +
                            '<td><button class="button pf-remove-product" data-wc-id="' + p.id + '" title="Fjern fra gruppe">✕</button></td>';
                        body.appendChild(tr);
                    });
                });
            }

            // Fjern produkt fra gruppe
            document.getElementById('pf-group-products-body').addEventListener('click', function(e) {
                var btn = e.target.closest('.pf-remove-product');
                if (!btn) return;
                if (!confirm('Fjern dette produkt fra gruppen?')) return;

                var wcId = btn.dataset.wcId;
                btn.disabled = true;
                btn.textContent = '...';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'pf_remove_from_group',
                        nonce: nonce,
                        filter_id: currentGroupId,
                        wc_id: wcId
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        btn.closest('tr').remove();
                        // Opdater antal i tabellen
                        var countCell = document.querySelector('.pf-view-group[data-id="' + currentGroupId + '"]').closest('tr').children[2];
                        var current = parseInt(countCell.textContent);
                        countCell.textContent = (current - 1) + ' stk.';
                    } else {
                        btn.disabled = false;
                        btn.textContent = '✕';
                        alert('Fejl: ' + (res.data || 'Ukendt fejl'));
                    }
                });
            });
        })();
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

    /**
     * AJAX: Hent produkter i en produktgruppe.
     */
    public static function ajax_get_group_products(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        $filter_id = intval($_POST['filter_id'] ?? 0);
        if (!$filter_id) {
            wp_send_json_error('Manglende ID');
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
        $product_ids = $filter_data['product_ids'] ?? [];

        $products = [];
        foreach ($product_ids as $pid) {
            $product = wc_get_product((int) $pid);
            if (!$product) {
                continue;
            }
            $image = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
            $products[] = [
                'id'       => $product->get_id(),
                'name'     => $product->get_name(),
                'image'    => $image ?: '',
                'merchant' => $product->get_meta('_pf_merchant'),
                'price'    => $product->get_price(),
            ];
        }

        wp_send_json_success(['products' => $products]);
    }

    /**
     * AJAX: Fjern et produkt fra en produktgruppe.
     */
    public static function ajax_remove_from_group(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $filter_id = intval($_POST['filter_id'] ?? 0);
        $wc_id = intval($_POST['wc_id'] ?? 0);

        if (!$filter_id || !$wc_id) {
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
        $product_ids = $filter_data['product_ids'] ?? [];

        $filter_data['product_ids'] = array_values(array_filter($product_ids, function ($id) use ($wc_id) {
            return (int) $id !== $wc_id;
        }));

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
}
