<?php
defined('ABSPATH') || exit;

class PF_Partnerads_API {

    /**
     * Henter liste over godkendte programmer fra Partner-ads.
     * Returnerer array af programmer med navn, ID, feed-URL osv.
     */
    public static function get_approved_programs(): array {
        $key = get_option('pf_partnerads_key', '');
        if (empty($key)) {
            set_transient('pf_last_api_error', 'Ingen API-nøgle konfigureret.', 300);
            return [];
        }

        $url = 'https://www.partner-ads.com/dk/programoversigt_xml.php?key=' . urlencode($key) . '&godkendte=1';

        $response = wp_remote_get($url, [
            'timeout'   => 30,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            error_log('ProductFeed: Fejl ved hentning af programoversigt: ' . $err);
            set_transient('pf_last_api_error', 'HTTP-fejl: ' . $err, 300);
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $msg = 'Partner-ads svarede med HTTP ' . $code;
            error_log('ProductFeed: ' . $msg);
            set_transient('pf_last_api_error', $msg, 300);
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            set_transient('pf_last_api_error', 'Tomt svar fra Partner-ads. Tjek at API-nøglen er korrekt.', 300);
            return [];
        }

        $programs = self::parse_programs($body);

        if (empty($programs)) {
            $preview = mb_substr(strip_tags($body), 0, 200);
            set_transient('pf_last_api_error', 'Kunne ikke parse programmer fra svar. Preview: ' . $preview, 300);
        } else {
            delete_transient('pf_last_api_error');
        }

        return $programs;
    }

    /**
     * Parser programoversigt XML.
     */
    private static function parse_programs(string $xml_content): array {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);

        if ($xml === false) {
            error_log('ProductFeed: Kunne ikke parse programoversigt XML');
            return [];
        }

        $programs = [];

        foreach ($xml->program as $p) {
            $has_feed = strtolower((string) ($p->feed ?? '')) === 'ja';

            $program = [
                'id'            => (string) $p->programid,
                'name'          => (string) $p->programnavn,
                'url'           => (string) $p->programurl,
                'description'   => (string) $p->programbeskrivelse,
                'category'      => (string) $p->kategorinavn,
                'subcategory'   => (string) $p->underkategori,
                'feed_url'      => $has_feed ? (string) $p->feedlink : '',
                'has_feed'      => $has_feed,
                'status'        => (string) $p->status,
                'commission'    => (string) $p->provision,
                'epc'           => (string) $p->epc,
                'affiliate_url' => (string) $p->affiliatelink,
                'feed_currency' => (string) $p->feedcur,
                'feed_market'   => (string) $p->feedmarket,
                'feed_updated'  => (string) $p->feedupdated,
                'sem_ppc'       => (string) $p->SEM_PPC,
                'shopping_ads'  => (string) $p->ShoppingAds,
            ];

            if (empty($program['id'])) {
                continue;
            }

            $programs[] = $program;
        }

        return $programs;
    }

    /**
     * Henter saldo/indtjening fra Partner-ads.
     */
    public static function get_earnings(): array {
        $key = get_option('pf_partnerads_key', '');
        if (empty($key)) {
            return [];
        }

        $url = 'https://www.partner-ads.com/dk/partnerindtjening_xml.php?key=' . urlencode($key);

        $response = wp_remote_get($url, [
            'timeout'   => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            return [];
        }

        // Konverter hele XML-strukturen til array
        return json_decode(json_encode($xml), true) ?: [];
    }

    /**
     * AJAX: Hent programmer og gem i transient cache (1 time).
     */
    public static function ajax_fetch_programs(): void {
        check_ajax_referer('pf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen adgang');
        }

        $key = get_option('pf_partnerads_key', '');
        if (empty($key)) {
            wp_send_json_error('Ingen API-nøgle indtastet. Gem din nøgle under Indstillinger først.');
        }

        // Slet cache så vi henter frisk
        delete_transient('pf_partnerads_programs');

        $programs = self::get_approved_programs();

        if (empty($programs)) {
            // Hent seneste fejl fra get_approved_programs for bedre diagnostik
            $last_error = get_transient('pf_last_api_error');
            $msg = $last_error ?: 'Ingen programmer fundet. Tjek din API-nøgle.';
            wp_send_json_error($msg);
        }

        // Cache i 1 time
        set_transient('pf_partnerads_programs', $programs, HOUR_IN_SECONDS);

        wp_send_json_success([
            'programs' => $programs,
            'count'    => count($programs),
        ]);
    }

    /**
     * Returnerer cachede programmer (eller henter frisk).
     */
    public static function get_cached_programs(): array {
        $cached = get_transient('pf_partnerads_programs');
        if ($cached !== false) {
            return $cached;
        }

        $programs = self::get_approved_programs();
        if (!empty($programs)) {
            set_transient('pf_partnerads_programs', $programs, HOUR_IN_SECONDS);
        }

        return $programs;
    }
}
