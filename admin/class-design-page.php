<?php
defined('ABSPATH') || exit;

class PF_Design_Page {

    /**
     * Renderer design-siden med 2 faner: Kategori og Prissammenligning.
     */
    public static function render(): void {
        $active_tab = sanitize_text_field($_GET['tab'] ?? 'catalog');

        // Gem skabelon hvis POST
        if (isset($_POST['pf_save_template']) && check_admin_referer('pf_design')) {
            $template_key = sanitize_text_field($_POST['pf_template_key'] ?? '');
            $template_html = wp_unslash($_POST['pf_template_code'] ?? '');
            $template_css = wp_unslash($_POST['pf_template_css'] ?? '');

            if (in_array($template_key, ['catalog', 'pricecompare', 'inline'], true)) {
                update_option("pf_template_{$template_key}_html", $template_html);
                update_option("pf_template_{$template_key}_css", $template_css);
                echo '<div class="notice notice-success"><p>Skabelon gemt!</p></div>';
            }
        }

        // Hent gemte skabeloner (eller defaults)
        $catalog_html = get_option('pf_template_catalog_html', self::default_catalog_html());
        $catalog_css = get_option('pf_template_catalog_css', self::default_catalog_css());
        $pricecompare_html = get_option('pf_template_pricecompare_html', self::default_pricecompare_html());
        $pricecompare_css = get_option('pf_template_pricecompare_css', self::default_pricecompare_css());
        $inline_html = get_option('pf_template_inline_html', self::default_inline_html());
        $inline_css = get_option('pf_template_inline_css', self::default_inline_css());

        ?>
        <div class="wrap">
            <h1>ProductFeed — Design</h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="?page=productfeed-design&tab=catalog"
                   class="nav-tab <?php echo $active_tab === 'catalog' ? 'nav-tab-active' : ''; ?>">
                    Kategori-visning
                </a>
                <a href="?page=productfeed-design&tab=pricecompare"
                   class="nav-tab <?php echo $active_tab === 'pricecompare' ? 'nav-tab-active' : ''; ?>">
                    Prissammenligning
                </a>
                <a href="?page=productfeed-design&tab=inline"
                   class="nav-tab <?php echo $active_tab === 'inline' ? 'nav-tab-active' : ''; ?>">
                    Inline-tekst
                </a>
            </nav>

            <?php if ($active_tab === 'catalog'): ?>
                <?php self::render_tab('catalog', $catalog_html, $catalog_css); ?>
            <?php elseif ($active_tab === 'inline'): ?>
                <?php self::render_tab('inline', $inline_html, $inline_css); ?>
            <?php else: ?>
                <?php self::render_tab('pricecompare', $pricecompare_html, $pricecompare_css); ?>
            <?php endif; ?>
        </div>

        <style>
            .pf-design-layout {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-top: 10px;
            }
            .pf-design-preview {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                min-height: 400px;
                overflow: auto;
            }
            .pf-design-editor {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }
            .pf-design-editor label {
                font-weight: 600;
                font-size: 13px;
                margin-bottom: 4px;
                display: block;
            }
            .pf-code-editor {
                width: 100%;
                min-height: 250px;
                font-family: 'SF Mono', 'Consolas', 'Monaco', 'Menlo', monospace;
                font-size: 13px;
                line-height: 1.5;
                padding: 12px;
                border: 1px solid #ccd0d4;
                background: #1e1e1e;
                color: #d4d4d4;
                resize: vertical;
                tab-size: 2;
                white-space: pre;
                overflow-wrap: normal;
                overflow-x: auto;
            }
            .pf-code-editor:focus {
                outline: none;
                border-color: #0073aa;
                box-shadow: 0 0 0 1px #0073aa;
            }
            #pf-design-preview-iframe {
                background: #fff;
            }
            .pf-design-actions {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            @media (max-width: 1200px) {
                .pf-design-layout {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <script>
        (function() {
            var htmlEditor = document.getElementById('pf-template-html');
            var cssEditor = document.getElementById('pf-template-css');
            var iframe = document.getElementById('pf-design-preview-iframe');

            function updatePreview() {
                if (!htmlEditor || !cssEditor || !iframe) return;
                var doc = iframe.contentDocument || iframe.contentWindow.document;
                var links = '';
                (window.pfThemeStyles || []).forEach(function(url) {
                    links += '<link rel="stylesheet" href="' + url + '">';
                });
                doc.open();
                doc.write('<!DOCTYPE html><html><head><meta charset="utf-8">' + links + '<style>' + cssEditor.value + '</style></head><body style="margin:0;padding:20px;">' + htmlEditor.value + '</body></html>');
                doc.close();

                // Auto-resize iframe til indhold
                setTimeout(function() {
                    try {
                        var h = doc.documentElement.scrollHeight;
                        if (h > 100) iframe.style.height = h + 'px';
                    } catch(e) {}
                }, 200);
            }

            if (htmlEditor) {
                htmlEditor.addEventListener('input', updatePreview);
                cssEditor.addEventListener('input', updatePreview);

                // Indledende preview
                if (iframe) {
                    iframe.addEventListener('load', updatePreview, { once: true });
                    updatePreview();
                }

                // Tab-support i textarea
                [htmlEditor, cssEditor].forEach(function(editor) {
                    editor.addEventListener('keydown', function(e) {
                        if (e.key === 'Tab') {
                            e.preventDefault();
                            var start = this.selectionStart;
                            var end = this.selectionEnd;
                            this.value = this.value.substring(0, start) + '  ' + this.value.substring(end);
                            this.selectionStart = this.selectionEnd = start + 2;
                        }
                    });
                });
            }

            // Nulstil til default
            var resetBtn = document.getElementById('pf-reset-template');
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    if (!confirm('Er du sikker? Dette nulstiller skabelonen til standard.')) return;

                    var form = document.getElementById('pf-design-form');
                    var key = form.querySelector('[name="pf_template_key"]').value;

                    jQuery.post(ajaxurl, {
                        action: 'pf_reset_template',
                        nonce: '<?php echo wp_create_nonce('pf_nonce'); ?>',
                        template_key: key
                    }, function(res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            alert('Fejl: ' + (res.data || 'Ukendt fejl'));
                        }
                    });
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Henter temaets frontend stylesheet-URLs (inkl. Google Fonts, Elementor osv.).
     */
    private static function get_theme_style_urls(): array {
        $urls = [];

        // Temaets hoved-stylesheet
        $urls[] = get_stylesheet_uri();

        // Hvis child-theme, inkluder parent
        if (is_child_theme()) {
            $urls[] = get_template_directory_uri() . '/style.css';
        }

        // Hent alle enqueue'de frontend-styles ved at simulere wp_enqueue_scripts
        global $wp_styles;
        $backup = $wp_styles;
        $wp_styles = new WP_Styles();

        do_action('wp_enqueue_scripts');

        foreach ($wp_styles->registered as $handle => $style) {
            if ($style->src && strpos($style->src, 'admin') === false) {
                $src = $style->src;
                // Relative URLs
                if (strpos($src, '//') === false && strpos($src, 'http') !== 0) {
                    $src = site_url($src);
                }
                $urls[] = $src;
            }
        }

        $wp_styles = $backup;

        return array_unique($urls);
    }

    /**
     * Renderer en enkelt fane med preview + editor.
     */
    private static function render_tab(string $key, string $html, string $css): void {
        $labels = [
            'catalog'      => 'Kategori-visning (produktkort)',
            'pricecompare' => 'Prissammenligning (prisrække)',
            'inline'       => 'Inline-tekst (til brødtekst)',
        ];
        $label = $labels[$key] ?? $key;
        $theme_urls = self::get_theme_style_urls();
        ?>
        <h2><?php echo esc_html($label); ?></h2>
        <p class="description">
            <?php if ($key === 'catalog'): ?>
                Rediger hvordan et enkelt produkt vises i kategori-visningen. Brug pladsholdere som <code>{{name}}</code>, <code>{{price}}</code>, <code>{{image}}</code>, <code>{{url}}</code>, <code>{{merchant}}</code>, <code>{{brand}}</code>, <code>{{stock_label}}</code>, <code>{{shipping_text}}</code>.
            <?php elseif ($key === 'inline'): ?>
                Rediger inline-teksten der vises i brødtekst. Brug pladsholdere som <code>{{name}}</code>, <code>{{price}}</code>, <code>{{url}}</code>, <code>{{merchant}}</code>, <code>{{shipping_text}}</code>.
            <?php else: ?>
                Rediger hvordan en prisrække vises i prissammenligningen. Brug pladsholdere som <code>{{merchant}}</code>, <code>{{price}}</code>, <code>{{url}}</code>, <code>{{shipping_text}}</code>.
            <?php endif; ?>
        </p>

        <div class="pf-design-layout">
            <!-- Preview -->
            <div>
                <h3>Eksempel <small style="font-weight:normal;color:#666;">(med sidens styling)</small></h3>
                <div class="pf-design-preview">
                    <iframe id="pf-design-preview-iframe" style="width:100%;min-height:400px;border:none;"></iframe>
                </div>
            </div>
            <script>
            var pfThemeStyles = <?php echo wp_json_encode($theme_urls); ?>;
            var pfInitialHtml = <?php echo wp_json_encode($html); ?>;
            var pfInitialCss = <?php echo wp_json_encode($css); ?>;
            </script>

            <!-- Editor -->
            <div class="pf-design-editor">
                <form method="post" id="pf-design-form">
                    <?php wp_nonce_field('pf_design'); ?>
                    <input type="hidden" name="pf_template_key" value="<?php echo esc_attr($key); ?>">

                    <div>
                        <label for="pf-template-html">HTML</label>
                        <textarea id="pf-template-html" name="pf_template_code" class="pf-code-editor" rows="14"><?php echo esc_textarea($html); ?></textarea>
                    </div>

                    <div>
                        <label for="pf-template-css">CSS</label>
                        <textarea id="pf-template-css" name="pf_template_css" class="pf-code-editor" rows="12"><?php echo esc_textarea($css); ?></textarea>
                    </div>

                    <div class="pf-design-actions">
                        <button type="submit" name="pf_save_template" class="button button-primary">Gem skabelon</button>
                        <button type="button" id="pf-reset-template" class="button">Nulstil til standard</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Nulstil skabelon til default.
     */
    public static function ajax_reset_template(): void {
        check_ajax_referer('pf_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $key = sanitize_text_field($_POST['template_key'] ?? '');
        if (!in_array($key, ['catalog', 'pricecompare', 'inline'], true)) {
            wp_send_json_error('Ugyldig skabelon');
        }

        delete_option("pf_template_{$key}_html");
        delete_option("pf_template_{$key}_css");

        wp_send_json_success();
    }

    // =========================================================================
    // Default skabeloner
    // =========================================================================

    public static function default_catalog_html(): string {
        return '<div class="pf-product-card">
    <a href="{{url}}" target="_blank" rel="nofollow sponsored" class="pf-product-image">
        <img src="{{image}}" alt="{{name}}" loading="lazy">
    </a>
    <div class="pf-product-info">
        <div class="pf-product-brand">{{brand}}</div>
        <h3 class="pf-product-name">
            <a href="{{url}}" target="_blank" rel="nofollow sponsored">{{name}}</a>
        </h3>
        <div class="pf-product-price">
            <span class="pf-price-current">{{price}} kr</span>
        </div>
        <div class="pf-product-meta">
            <span class="pf-meta-merchant">{{merchant}}</span>
            <span class="pf-meta-stock">{{stock_label}}</span>
            <span class="pf-meta-shipping">{{shipping_text}}</span>
        </div>
        <a href="{{url}}" class="pf-buy-button" target="_blank" rel="nofollow sponsored">
            Se pris hos {{merchant}}
        </a>
    </div>
</div>';
    }

    public static function default_catalog_css(): string {
        return '.pf-product-card {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #fff;
    border: 1px solid #e8e8e8;
    overflow: hidden;
    max-width: 300px;
}
.pf-product-card a { text-decoration: none; color: inherit; }
.pf-product-image {
    display: block;
    aspect-ratio: 1;
    overflow: hidden;
    background: #f7f7f7;
}
.pf-product-image img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.pf-product-info { padding: 16px; }
.pf-product-brand {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #666;
    margin-bottom: 4px;
}
.pf-product-name {
    font-size: 14px;
    font-weight: 400;
    line-height: 1.4;
    margin: 0 0 10px;
}
.pf-price-current {
    font-size: 16px;
    font-weight: 600;
}
.pf-product-meta {
    font-size: 12px;
    color: #666;
    margin: 12px 0 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
}
.pf-buy-button {
    display: block;
    width: 100%;
    padding: 11px 20px;
    background: #1e1e1e;
    color: #fff;
    text-align: center;
    font-size: 13px;
    font-weight: 500;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    border: none;
    box-sizing: border-box;
}
.pf-buy-button:hover { background: #333; color: #fff; }';
    }

    public static function default_pricecompare_html(): string {
        return '<table class="pf-compare-table">
    <thead>
        <tr>
            <th>Butik</th>
            <th>Pris</th>
            <th>Fragt</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <tr class="pf-cheapest">
            <td class="pf-compare-merchant">Butik A</td>
            <td class="pf-compare-price">149,00 kr.</td>
            <td class="pf-compare-shipping">Gratis fragt</td>
            <td><a href="#" class="pf-compare-buy">KOB HER</a></td>
        </tr>
        <tr>
            <td class="pf-compare-merchant">Butik B</td>
            <td class="pf-compare-price">159,00 kr.</td>
            <td class="pf-compare-shipping">29 kr fragt</td>
            <td><a href="#" class="pf-compare-buy">KOB HER</a></td>
        </tr>
        <tr>
            <td class="pf-compare-merchant">Butik C</td>
            <td class="pf-compare-price">179,00 kr.</td>
            <td class="pf-compare-shipping">Gratis fragt</td>
            <td><a href="#" class="pf-compare-buy">KOB HER</a></td>
        </tr>
    </tbody>
</table>';
    }

    public static function default_pricecompare_css(): string {
        return '.pf-compare-table {
    width: 100%;
    border-collapse: collapse;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.pf-compare-table thead th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #666;
    font-weight: 500;
    padding: 10px 24px;
    text-align: left;
    border-bottom: 2px solid #e8e8e8;
}
.pf-compare-table td {
    padding: 14px 24px;
    border-bottom: 1px solid #e8e8e8;
    vertical-align: middle;
    font-size: 14px;
}
.pf-compare-table tr:hover { background: #fafafa; }
.pf-compare-table tr.pf-cheapest { background: #f0faf0; }
.pf-compare-table tr.pf-cheapest:hover { background: #e5f5e5; }
.pf-compare-merchant { font-weight: 500; }
.pf-compare-price {
    font-weight: 600;
    font-size: 15px;
    white-space: nowrap;
}
.pf-compare-shipping {
    font-size: 13px;
    color: #666;
}
.pf-compare-buy {
    display: inline-block;
    padding: 8px 20px;
    background: #1e1e1e;
    color: #fff;
    text-decoration: none;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    white-space: nowrap;
}
.pf-compare-buy:hover { background: #333; color: #fff; }';
    }

    public static function default_inline_html(): string {
        return '<span class="pf-inline"><a href="{{url}}" target="_blank" rel="nofollow sponsored" class="pf-inline-link">{{name}}</a> til <strong class="pf-inline-price">{{price}} kr</strong> hos <span class="pf-inline-merchant">{{merchant}}</span></span>';
    }

    public static function default_inline_css(): string {
        return '.pf-inline {
    font: inherit;
}
.pf-inline-link {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.pf-inline-link:hover {
    opacity: 0.7;
}
.pf-inline-price {
    font-weight: 600;
}
.pf-inline-merchant {
    font-weight: 500;
}';
    }
}
