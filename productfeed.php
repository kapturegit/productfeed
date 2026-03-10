<?php
/**
 * Plugin Name: ProductFeed
 * Description: Import affiliate-produkter fra Partner-ads og Adtraction til WooCommerce
 * Version: 1.7.1
 * Author: Magnus Nøhr
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: productfeed
 */

defined('ABSPATH') || exit;

define('PF_VERSION', '1.7.1');
define('PF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PF_PLUGIN_FILE', __FILE__);

require_once PF_PLUGIN_DIR . 'includes/class-feed-parser.php';
require_once PF_PLUGIN_DIR . 'includes/class-feed-cache.php';
require_once PF_PLUGIN_DIR . 'includes/class-partnerads-api.php';
require_once PF_PLUGIN_DIR . 'includes/class-product-importer.php';
require_once PF_PLUGIN_DIR . 'includes/class-product-updater.php';
require_once PF_PLUGIN_DIR . 'includes/class-yarn-enricher.php';
require_once PF_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once PF_PLUGIN_DIR . 'includes/class-github-updater.php';
require_once PF_PLUGIN_DIR . 'admin/class-admin-page.php';
require_once PF_PLUGIN_DIR . 'admin/class-saved-filters.php';
require_once PF_PLUGIN_DIR . 'admin/class-design-page.php';

class ProductFeed {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(PF_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(PF_PLUGIN_FILE, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('pf_daily_update', [$this, 'run_daily_update']);
        add_action('pf_weekly_feed_sync', [$this, 'run_weekly_feed_sync']);

        PF_Shortcodes::register();

        // GitHub auto-updater — tjekker kapturegit/productfeed for nye releases
        new PF_GitHub_Updater(PF_PLUGIN_FILE, 'kapturegit/productfeed');
    }

    public function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Tabel til cached feed-produkter (søgning)
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pf_products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(20) NOT NULL DEFAULT 'partnerads',
            external_id VARCHAR(100) NOT NULL,
            merchant VARCHAR(255) NOT NULL,
            category VARCHAR(255) DEFAULT '',
            brand VARCHAR(255) DEFAULT '',
            name VARCHAR(500) NOT NULL,
            description TEXT DEFAULT '',
            ean VARCHAR(50) DEFAULT '',
            price DECIMAL(10,2) NOT NULL,
            old_price DECIMAL(10,2) DEFAULT NULL,
            shipping DECIMAL(10,2) DEFAULT 0,
            stock_status VARCHAR(50) DEFAULT '',
            delivery_time VARCHAR(100) DEFAULT '',
            size VARCHAR(100) DEFAULT '',
            color VARCHAR(100) DEFAULT '',
            image_url TEXT DEFAULT '',
            affiliate_url TEXT NOT NULL,
            feed_url TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_source (source),
            INDEX idx_merchant (merchant),
            INDEX idx_category (category),
            INDEX idx_brand (brand),
            INDEX idx_name (name(100)),
            INDEX idx_ean (ean),
            UNIQUE KEY unique_product (source, external_id)
        ) $charset");

        // Tabel til gemte filtre ("produktgrupper")
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pf_filters (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            filter_data LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset");

        // Daglig cron — opdater importerede WC-produkter
        if (!wp_next_scheduled('pf_daily_update')) {
            wp_schedule_event(time(), 'daily', 'pf_daily_update');
        }

        // Ugentlig cron — sync alle feeds for nye produkter
        if (!wp_next_scheduled('pf_weekly_feed_sync')) {
            wp_schedule_event(time(), 'weekly', 'pf_weekly_feed_sync');
        }

        flush_rewrite_rules();
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook('pf_daily_update');
        wp_clear_scheduled_hook('pf_weekly_feed_sync');
    }

    public function init(): void {
        // Auto-migration: konverter cache-IDs til WC-IDs i produktgrupper
        self::maybe_migrate_filter_ids();

        // AJAX handlers til admin
        add_action('wp_ajax_pf_search_products', [PF_Admin_Page::class, 'ajax_search']);
        add_action('wp_ajax_pf_import_products', [PF_Product_Importer::class, 'ajax_import']);
        add_action('wp_ajax_pf_save_filter', [PF_Saved_Filters::class, 'ajax_save']);
        add_action('wp_ajax_pf_load_filter', [PF_Saved_Filters::class, 'ajax_load']);
        add_action('wp_ajax_pf_delete_filter', [PF_Saved_Filters::class, 'ajax_delete']);
        add_action('wp_ajax_pf_list_filters', [PF_Saved_Filters::class, 'ajax_list']);
        add_action('wp_ajax_pf_add_to_filter', [PF_Saved_Filters::class, 'ajax_add_to']);
        add_action('wp_ajax_pf_update_filter_wc_ids', [PF_Saved_Filters::class, 'ajax_update_wc_ids']);
        add_action('wp_ajax_pf_get_group_products', [PF_Saved_Filters::class, 'ajax_get_group_products']);
        add_action('wp_ajax_pf_remove_from_group', [PF_Saved_Filters::class, 'ajax_remove_from_group']);
        add_action('wp_ajax_pf_refresh_feed', [PF_Feed_Cache::class, 'ajax_refresh']);
        add_action('wp_ajax_pf_fetch_programs', [PF_Partnerads_API::class, 'ajax_fetch_programs']);
        add_action('wp_ajax_pf_reset_template', [PF_Design_Page::class, 'ajax_reset_template']);
    }

    public function admin_menu(): void {
        add_menu_page(
            'ProductFeed',
            'ProductFeed',
            'manage_options',
            'productfeed',
            [PF_Admin_Page::class, 'render'],
            'dashicons-products',
            30
        );

        add_submenu_page(
            'productfeed',
            'Søg produkter',
            'Søg produkter',
            'manage_options',
            'productfeed',
            [PF_Admin_Page::class, 'render']
        );

        add_submenu_page(
            'productfeed',
            'Produktgrupper',
            'Produktgrupper',
            'manage_options',
            'productfeed-filters',
            [PF_Saved_Filters::class, 'render_page']
        );

        add_submenu_page(
            'productfeed',
            'Design',
            'Design',
            'manage_options',
            'productfeed-design',
            [PF_Design_Page::class, 'render']
        );

        add_submenu_page(
            'productfeed',
            'Indstillinger',
            'Indstillinger',
            'manage_options',
            'productfeed-settings',
            [PF_Admin_Page::class, 'render_settings']
        );
    }

    /**
     * Migrerer produktgrupper fra cache-IDs til WooCommerce-IDs.
     * Kører automatisk ved behov — sikrer at gamle grupper også virker.
     */
    private static function maybe_migrate_filter_ids(): void {
        if (get_option('pf_migrated_wc_ids', false)) {
            return;
        }

        global $wpdb;
        $filters = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pf_filters");

        if (empty($filters)) {
            update_option('pf_migrated_wc_ids', true);
            return;
        }

        $cache_table = $wpdb->prefix . 'pf_products';

        foreach ($filters as $filter) {
            $filter_data = json_decode($filter->filter_data, true);
            $old_ids = $filter_data['product_ids'] ?? [];

            if (empty($old_ids)) {
                continue;
            }

            // Tjek om IDs allerede er WC-IDs (post_type = product)
            $first_id = (int) $old_ids[0];
            $is_wc = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'product'",
                $first_id
            ));

            if ($is_wc) {
                continue; // Allerede WC-IDs
            }

            // Slå cache-IDs op og find matchende WC-produkter via source + external_id
            $wc_ids = [];
            $placeholders = implode(',', array_fill(0, count($old_ids), '%d'));
            $cache_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT source, external_id FROM $cache_table WHERE id IN ($placeholders)",
                ...$old_ids
            ));

            foreach ($cache_rows as $row) {
                $wc_post = $wpdb->get_var($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_pf_source' AND m1.meta_value = %s
                     INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_pf_external_id' AND m2.meta_value = %s
                     WHERE p.post_type = 'product' LIMIT 1",
                    $row->source,
                    $row->external_id
                ));

                if ($wc_post) {
                    $wc_ids[] = (int) $wc_post;
                }
            }

            if (!empty($wc_ids)) {
                $filter_data['product_ids'] = array_values(array_unique($wc_ids));
                $wpdb->update(
                    $wpdb->prefix . 'pf_filters',
                    ['filter_data' => wp_json_encode($filter_data)],
                    ['id' => $filter->id],
                    ['%s'],
                    ['%d']
                );
            }
        }

        update_option('pf_migrated_wc_ids', true);
    }

    public function run_daily_update(): void {
        PF_Product_Updater::update_active_products();
    }

    /**
     * Ugentlig sync: Refresher alle feeds for at opdage nye produkter.
     */
    public function run_weekly_feed_sync(): void {
        $feeds = get_option('pf_feeds', []);
        foreach ($feeds as $feed) {
            if (!empty($feed['url'])) {
                PF_Feed_Cache::refresh_partnerads_feed($feed['url']);
            }
        }
        update_option('pf_last_weekly_sync', current_time('mysql'));
    }
}

ProductFeed::instance();
