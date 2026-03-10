<?php
defined('ABSPATH') || exit;

class PF_Admin_Page {

    /**
     * Renderer hovedsiden: Søg produkter.
     */
    public static function render(): void {
        $stats = PF_Feed_Cache::get_stats();
        $feeds = get_option('pf_feeds', []);
        ?>
        <div class="wrap">
            <h1>ProductFeed — Søg produkter</h1>

            <!-- Feed Status -->
            <div class="pf-feed-status" style="margin:20px 0;padding:15px;background:#fff;border:1px solid #ccd0d4;">
                <h2>Feed-status</h2>
                <?php if (empty($feeds)): ?>
                    <p>Ingen feeds tilføjet endnu. Gå til <a href="<?php echo admin_url('admin.php?page=productfeed-settings'); ?>">Indstillinger</a> for at tilføje dine feed-URLs.</p>
                <?php else: ?>
                    <table class="widefat striped" style="max-width:800px;">
                        <thead>
                            <tr><th>Shop</th><th>Feed URL</th><th>Produkter</th><th>Sidst opdateret</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($feeds as $i => $feed): ?>
                            <tr>
                                <td><strong><?php echo esc_html($feed['name'] ?: ucfirst($feed['source'])); ?></strong></td>
                                <td><code style="font-size:11px;"><?php echo esc_html(substr($feed['url'], 0, 80)); ?>...</code></td>
                                <td><?php echo isset($stats[$feed['url']]) ? number_format($stats[$feed['url']]['count']) : '0'; ?></td>
                                <td><?php echo isset($stats[$feed['url']]) ? esc_html($stats[$feed['url']]['last_updated']) : 'Aldrig'; ?></td>
                                <td>
                                    <button class="button pf-refresh-feed"
                                            data-url="<?php echo esc_attr($feed['url']); ?>"
                                            data-source="<?php echo esc_attr($feed['source']); ?>">
                                        Opdater nu
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Tilføj-til-gruppe besked -->
            <div id="pf-auto-add-notice" style="display:none;margin:20px 0;padding:12px 15px;background:#e7f5fe;border-left:4px solid #0073aa;font-size:14px;">
                Du er ved at tilføje produkter til en eksisterende produktgruppe. Søg og vælg de produkter du vil tilføje, og klik derefter <strong>"Importer valgte til WooCommerce"</strong>.
            </div>

            <!-- Søg & Filtrer -->
            <div class="pf-search-panel" style="margin:20px 0;padding:15px;background:#fff;border:1px solid #ccd0d4;">
                <h2>Søg & Filtrer</h2>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                    <div>
                        <label>Søgetekst</label><br>
                        <input type="text" id="pf-search-query" style="width:250px;" placeholder="Produktnavn, EAN...">
                    </div>
                    <div>
                        <label>Forhandler</label><br>
                        <select id="pf-search-merchant" style="width:200px;">
                            <option value="">Alle</option>
                        </select>
                    </div>
                    <div>
                        <label>Kategori</label><br>
                        <select id="pf-search-category" style="width:200px;">
                            <option value="">Alle</option>
                        </select>
                    </div>
                    <div>
                        <label>Brand</label><br>
                        <select id="pf-search-brand" style="width:200px;">
                            <option value="">Alle</option>
                        </select>
                    </div>
                    <div>
                        <label>Pris fra</label><br>
                        <input type="number" id="pf-search-price-min" style="width:100px;" min="0">
                    </div>
                    <div>
                        <label>Pris til</label><br>
                        <input type="number" id="pf-search-price-max" style="width:100px;" min="0">
                    </div>
                    <div>
                        <label>Lagerstatus</label><br>
                        <select id="pf-search-stock">
                            <option value="">Alle</option>
                            <option value="instock">På lager</option>
                            <option value="outofstock">Udsolgt</option>
                        </select>
                    </div>
                    <div>
                        <button class="button button-primary" id="pf-search-btn">Søg</button>
                    </div>
                </div>
            </div>

            <!-- Resultater -->
            <div class="pf-results-panel" style="margin:20px 0;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h2>Resultater <span id="pf-result-count"></span></h2>
                    <div>
                        <button class="button" id="pf-select-all">Vælg alle</button>
                        <button class="button button-primary" id="pf-import-btn">Importer valgte til WooCommerce</button>
                    </div>
                </div>
                <table class="widefat striped" id="pf-results-table" style="display:none;">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="pf-check-all"></th>
                            <th style="width:60px;">Billede</th>
                            <th>Produkt</th>
                            <th>Webshop</th>
                            <th>Forhandler</th>
                            <th>Kategori</th>
                            <th>Brand</th>
                            <th>Pris</th>
                            <th>Gl. pris</th>
                            <th>Fragt</th>
                            <th>Lager</th>
                        </tr>
                    </thead>
                    <tbody id="pf-results-body"></tbody>
                </table>
                <div id="pf-no-results" style="display:none;padding:20px;text-align:center;color:#666;">
                    Ingen produkter fundet. Prøv at ændre dine filtre.
                </div>
                <div id="pf-loading" style="display:none;padding:20px;text-align:center;">
                    <span class="spinner is-active" style="float:none;"></span> Søger...
                </div>
            </div>

            <!-- Gem filter dialog -->
            <div id="pf-save-dialog" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:99999;">
                <div style="background:#fff;padding:30px;max-width:450px;margin:100px auto;border-radius:4px;">
                    <h3>Importer til WooCommerce</h3>
                    <p>Produkterne importeres til WooCommerce og gemmes i en produktgruppe.</p>

                    <!-- Vælg: ny eller eksisterende gruppe -->
                    <p>
                        <label><input type="radio" name="pf_group_mode" value="new" checked id="pf-mode-new"> Opret ny produktgruppe</label><br>
                        <label><input type="radio" name="pf_group_mode" value="existing" id="pf-mode-existing"> Tilføj til eksisterende gruppe</label>
                    </p>

                    <!-- Ny gruppe -->
                    <div id="pf-new-group-fields">
                        <p>
                            <label>Navn på produktgruppe</label><br>
                            <input type="text" id="pf-filter-name" style="width:100%;" placeholder="F.eks. 'Drops Kid Silk'">
                        </p>
                        <p class="description">Visning vælges via shortcode: <code>layout="category"</code>, <code>layout="comparison"</code> eller <code>layout="inline"</code></p>
                    </div>

                    <!-- Eksisterende gruppe -->
                    <div id="pf-existing-group-fields" style="display:none;">
                        <p>
                            <label>Vælg produktgruppe</label><br>
                            <select id="pf-existing-filter" style="width:100%;">
                                <option value="">Indlæser...</option>
                            </select>
                        </p>
                    </div>

                    <p style="text-align:right;">
                        <button class="button" id="pf-save-cancel">Annuller</button>
                        <button class="button button-primary" id="pf-save-confirm">Gem & Importer</button>
                    </p>
                </div>
            </div>
        </div>

        <?php
        self::enqueue_scripts();
    }

    /**
     * Renderer indstillingssiden.
     */
    public static function render_settings(): void {
        // Gem indstillinger
        if (isset($_POST['pf_save_settings']) && check_admin_referer('pf_settings')) {
            // API-nøgle
            $api_key = sanitize_text_field($_POST['pf_partnerads_key'] ?? '');
            update_option('pf_partnerads_key', $api_key);

            // Valgte programmer → gem som feeds
            $selected = $_POST['pf_selected_programs'] ?? [];
            $feeds = [];
            foreach ($selected as $program_json) {
                $program = json_decode(stripslashes($program_json), true);
                if ($program && !empty($program['feed_url'])) {
                    $feeds[] = [
                        'url'        => sanitize_url($program['feed_url']),
                        'source'     => 'partnerads',
                        'program_id' => sanitize_text_field($program['id'] ?? ''),
                        'name'       => sanitize_text_field($program['name'] ?? ''),
                    ];
                }
            }

            // Manuelle feeds (Adtraction m.fl.)
            $manual_urls = $_POST['pf_feed_url'] ?? [];
            $manual_sources = $_POST['pf_feed_source'] ?? [];
            foreach ($manual_urls as $i => $url) {
                $url = sanitize_url(trim($url));
                $source = sanitize_text_field($manual_sources[$i] ?? 'adtraction');
                if (!empty($url)) {
                    $feeds[] = [
                        'url'    => $url,
                        'source' => $source,
                        'name'   => '',
                    ];
                }
            }

            // Detect nye feeds der skal auto-synces
            $old_feeds = get_option('pf_feeds', []);
            $old_urls = array_column($old_feeds, 'url');

            update_option('pf_feeds', $feeds);

            // Auto-sync nye feeds
            $new_feeds = array_filter($feeds, fn($f) => !in_array($f['url'], $old_urls, true));
            $synced = 0;
            foreach ($new_feeds as $feed) {
                if (!empty($feed['url'])) {
                    $count = PF_Feed_Cache::refresh_partnerads_feed($feed['url']);
                    $synced += $count;
                }
            }

            // Ryd program-cache så den hentes frisk næste gang
            delete_transient('pf_partnerads_programs');

            $msg = 'Indstillinger gemt!';
            if (!empty($new_feeds)) {
                $msg .= ' ' . count($new_feeds) . ' nye feeds synkroniseret (' . $synced . ' produkter hentet).';
            }
            echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
        }

        $api_key = get_option('pf_partnerads_key', '');
        $feeds = get_option('pf_feeds', []);
        $selected_program_ids = array_column(
            array_filter($feeds, fn($f) => $f['source'] === 'partnerads'),
            'program_id'
        );
        $manual_feeds = array_filter($feeds, fn($f) => $f['source'] !== 'partnerads');
        if (empty($manual_feeds)) {
            $manual_feeds = [];
        }

        // Hent programmer hvis vi har API-nøgle
        $programs = [];
        if (!empty($api_key)) {
            $programs = PF_Partnerads_API::get_cached_programs();
        }
        ?>
        <div class="wrap">
            <h1>ProductFeed — Indstillinger</h1>

            <form method="post">
                <?php wp_nonce_field('pf_settings'); ?>

                <!-- Partner-ads API -->
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0;max-width:900px;">
                    <h2>Partner-ads</h2>

                    <table class="form-table">
                        <tr>
                            <th><label for="pf_partnerads_key">API-nøgle</label></th>
                            <td>
                                <input type="text" id="pf_partnerads_key" name="pf_partnerads_key"
                                       value="<?php echo esc_attr($api_key); ?>"
                                       style="width:400px;" placeholder="Din unikke Partner-ads key">
                                <p class="description">Find din nøgle under "Udtræk af data" i Partner-ads.</p>
                            </td>
                        </tr>
                    </table>

                    <?php if (!empty($api_key)): ?>
                        <h3>Godkendte programmer
                            <button type="button" class="button" id="pf-refresh-programs" style="margin-left:10px;">
                                Opdater programliste
                            </button>
                            <span id="pf-programs-status" style="margin-left:10px;color:#666;"></span>
                        </h3>

                        <?php if (!empty($programs)): ?>
                            <p>Vælg de programmer du vil hente produktfeeds fra:</p>
                            <div style="max-height:400px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fafafa;">
                                <table class="widefat striped" id="pf-programs-table">
                                    <thead>
                                        <tr>
                                            <th style="width:30px;"><input type="checkbox" id="pf-check-all-programs"></th>
                                            <th>Program</th>
                                            <th>Kategori</th>
                                            <th>Provision %</th>
                                            <th>EPC</th>
                                            <th>Feed opdateret</th>
                                            <th>Feed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($programs as $prog): ?>
                                        <?php
                                        $is_selected = in_array($prog['id'], $selected_program_ids, true);
                                        $has_feed = !empty($prog['feed_url']);
                                        $prog_json = esc_attr(wp_json_encode($prog));
                                        ?>
                                        <tr class="<?php echo $has_feed ? '' : 'pf-no-feed'; ?>">
                                            <td>
                                                <?php if ($has_feed): ?>
                                                    <input type="checkbox"
                                                           name="pf_selected_programs[]"
                                                           value="<?php echo $prog_json; ?>"
                                                           class="pf-program-check"
                                                           <?php checked($is_selected); ?>>
                                                <?php else: ?>
                                                    <span title="Intet produktfeed tilgængeligt" style="color:#999;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo esc_html($prog['name']); ?></strong>
                                                <?php if (!empty($prog['url'])): ?>
                                                    <br><small><a href="<?php echo esc_url($prog['url']); ?>" target="_blank"><?php echo esc_html($prog['url']); ?></a></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($prog['category']); ?></td>
                                            <td><?php echo esc_html($prog['commission']); ?>%</td>
                                            <td><?php echo esc_html($prog['epc'] ?? ''); ?> kr</td>
                                            <td>
                                                <?php if (!empty($prog['feed_updated'])): ?>
                                                    <small><?php echo esc_html($prog['feed_updated']); ?></small>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($has_feed): ?>
                                                    <span style="color:#46b450;">Ja</span>
                                                <?php else: ?>
                                                    <span style="color:#999;">Nej</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p><strong><?php echo count($programs); ?></strong> godkendte programmer fundet.
                               <strong><?php echo count(array_filter($programs, fn($p) => !empty($p['feed_url']))); ?></strong> har produktfeed.</p>
                        <?php else: ?>
                            <p>Ingen programmer fundet. Klik "Gem indstillinger" for at hente din programliste, eller tjek at API-nøglen er korrekt.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="color:#666;">Indtast din API-nøgle og gem for at hente dine godkendte programmer.</p>
                    <?php endif; ?>
                </div>

                <!-- Manuelle feeds (Adtraction m.fl.) -->
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0;max-width:900px;">
                    <h2>Andre feeds (Adtraction m.fl.)</h2>
                    <p>Tilføj feed-URLs manuelt for andre netværk.</p>

                    <table class="widefat" id="pf-feeds-table">
                        <thead>
                            <tr>
                                <th>Kilde</th>
                                <th>Feed URL</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($manual_feeds)): ?>
                            <?php foreach ($manual_feeds as $feed): ?>
                                <tr>
                                    <td>
                                        <select name="pf_feed_source[]">
                                            <option value="adtraction" <?php selected($feed['source'], 'adtraction'); ?>>Adtraction</option>
                                            <option value="partnerads" <?php selected($feed['source'], 'partnerads'); ?>>Partner-ads (manuelt)</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="url" name="pf_feed_url[]" value="<?php echo esc_attr($feed['url']); ?>" style="width:100%;">
                                    </td>
                                    <td><button type="button" class="button pf-remove-feed">Fjern</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td>
                                    <select name="pf_feed_source[]">
                                        <option value="adtraction">Adtraction</option>
                                        <option value="partnerads">Partner-ads (manuelt)</option>
                                    </select>
                                </td>
                                <td><input type="url" name="pf_feed_url[]" style="width:100%;" placeholder="https://..."></td>
                                <td><button type="button" class="button pf-remove-feed">Fjern</button></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>

                    <p>
                        <button type="button" class="button" id="pf-add-feed">+ Tilføj feed</button>
                    </p>
                </div>

                <p>
                    <input type="submit" name="pf_save_settings" class="button button-primary button-hero" value="Gem indstillinger">
                </p>
            </form>
        </div>

        <script>
        // Tilføj manuelt feed
        document.getElementById('pf-add-feed').addEventListener('click', function() {
            const tbody = document.querySelector('#pf-feeds-table tbody');
            const row = document.createElement('tr');
            row.innerHTML = '<td><select name="pf_feed_source[]"><option value="adtraction">Adtraction</option><option value="partnerads">Partner-ads (manuelt)</option></select></td>' +
                '<td><input type="url" name="pf_feed_url[]" style="width:100%;" placeholder="https://..."></td>' +
                '<td><button type="button" class="button pf-remove-feed">Fjern</button></td>';
            tbody.appendChild(row);
        });

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('pf-remove-feed')) {
                e.target.closest('tr').remove();
            }
        });

        // Vælg alle programmer
        var checkAll = document.getElementById('pf-check-all-programs');
        if (checkAll) {
            checkAll.addEventListener('change', function() {
                document.querySelectorAll('.pf-program-check').forEach(function(cb) {
                    cb.checked = checkAll.checked;
                });
            });
        }

        // Opdater programliste via AJAX
        var refreshBtn = document.getElementById('pf-refresh-programs');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                var status = document.getElementById('pf-programs-status');
                status.textContent = 'Henter programmer...';
                refreshBtn.disabled = true;

                jQuery.post(ajaxurl, {
                    action: 'pf_fetch_programs',
                    nonce: '<?php echo wp_create_nonce("pf_nonce"); ?>'
                }, function(res) {
                    refreshBtn.disabled = false;
                    if (res.success) {
                        status.textContent = res.data.count + ' programmer fundet. Genindlæser...';
                        location.reload();
                    } else {
                        status.textContent = 'Fejl: ' + (res.data || 'Ukendt fejl');
                        status.style.color = '#dc3232';
                    }
                });
            });
        }
        </script>
        <?php
    }

    /**
     * AJAX: Søg i cached produkter.
     */
    public static function ajax_search(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pf_products';

        $where = ['1=1'];
        $params = [];

        $query = sanitize_text_field($_POST['query'] ?? '');
        if (!empty($query)) {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(name LIKE %s OR ean LIKE %s OR brand LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $merchant = sanitize_text_field($_POST['merchant'] ?? '');
        if (!empty($merchant)) {
            $where[] = 'merchant = %s';
            $params[] = $merchant;
        }

        $category = sanitize_text_field($_POST['category'] ?? '');
        if (!empty($category)) {
            $where[] = 'category = %s';
            $params[] = $category;
        }

        $brand = sanitize_text_field($_POST['brand'] ?? '');
        if (!empty($brand)) {
            $where[] = 'brand = %s';
            $params[] = $brand;
        }

        $price_min = floatval($_POST['price_min'] ?? 0);
        if ($price_min > 0) {
            $where[] = 'price >= %f';
            $params[] = $price_min;
        }

        $price_max = floatval($_POST['price_max'] ?? 0);
        if ($price_max > 0) {
            $where[] = 'price <= %f';
            $params[] = $price_max;
        }

        $stock = sanitize_text_field($_POST['stock'] ?? '');
        if ($stock === 'instock') {
            $where[] = "stock_status IN ('in stock', 'i lager', 'på lager', 'yes', '1', 'instock')";
        } elseif ($stock === 'outofstock') {
            $where[] = "stock_status IN ('out of stock', 'udsolgt', 'no', '0', 'outofstock')";
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY name ASC LIMIT 200";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $products = $wpdb->get_results($sql);

        // Map feed_url → webshop-navn fra gemte feeds
        $feeds = get_option('pf_feeds', []);
        $feed_name_map = [];
        foreach ($feeds as $feed) {
            if (!empty($feed['url']) && !empty($feed['name'])) {
                $feed_name_map[$feed['url']] = $feed['name'];
            }
        }
        foreach ($products as &$product) {
            $product->webshop = $feed_name_map[$product->feed_url] ?? '';
        }
        unset($product);

        // Hent unikke værdier til dropdowns
        $merchants = $wpdb->get_col("SELECT DISTINCT merchant FROM $table WHERE merchant != '' ORDER BY merchant");
        $categories = $wpdb->get_col("SELECT DISTINCT category FROM $table WHERE category != '' ORDER BY category");
        $brands = $wpdb->get_col("SELECT DISTINCT brand FROM $table WHERE brand != '' ORDER BY brand");

        wp_send_json_success([
            'products'   => $products,
            'merchants'  => $merchants,
            'categories' => $categories,
            'brands'     => $brands,
            'total'      => count($products),
        ]);
    }

    /**
     * Enqueue admin scripts.
     */
    private static function enqueue_scripts(): void {
        wp_enqueue_script(
            'pf-admin',
            PF_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            PF_VERSION,
            true
        );

        wp_localize_script('pf-admin', 'pfAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('pf_nonce'),
        ]);

        wp_enqueue_style(
            'pf-admin',
            PF_PLUGIN_URL . 'admin/css/admin.css',
            [],
            PF_VERSION
        );
    }
}
