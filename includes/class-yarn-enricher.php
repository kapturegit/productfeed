<?php
defined('ABSPATH') || exit;

class PF_Yarn_Enricher {

    private static array $yarn_cache = [];

    /**
     * Beriger et produkt med garndata fra Garnstudio hvis brand er DROPS.
     * Returnerer array med ekstra attributter, eller tomt array.
     */
    public static function enrich(object $row): array {
        $brand = strtolower(trim($row->brand ?? ''));
        if (strpos($brand, 'drops') === false) {
            return [];
        }

        $yarn_slug = self::extract_yarn_slug($row->name, $row->category ?? '');
        if (empty($yarn_slug)) {
            error_log('ProductFeed Enricher: Kunne ikke udlede garnnavn fra "' . $row->name . '"');
            return [];
        }

        error_log('ProductFeed Enricher: Beriger "' . $row->name . '" → slug: ' . $yarn_slug);
        return self::fetch_yarn_data($yarn_slug);
    }

    /**
     * Udtrækker garnets slug fra produktnavn eller kategori.
     * F.eks. "Drops Kid Silk" -> "drops-kid-silk"
     * F.eks. "DROPS Alpaca Bouclé" -> "drops-alpaca-boucle"
     */
    private static function extract_yarn_slug(string $name, string $category): string {
        // Prøv først produktnavn, derefter kategori
        $candidates = [$name, $category];

        foreach ($candidates as $text) {
            $text = strtolower(trim($text));

            // Match "drops [garnnavn]" mønster
            if (preg_match('/drops\s+([a-zæøåé\s\-]+)/i', $text, $m)) {
                $yarn_name = trim($m[1]);
                // Fjern farve/variant info efter garnnavn (tal, komma osv.)
                $yarn_name = preg_replace('/\s*[\d,].*$/', '', $yarn_name);
                $yarn_name = trim($yarn_name);

                if (!empty($yarn_name)) {
                    // Konverter til slug: "kid silk" -> "drops-kid-silk"
                    $slug = 'drops-' . preg_replace('/[^a-z0-9]+/', '-', $yarn_name);
                    $slug = trim($slug, '-');

                    // Håndter specielle tegn
                    $slug = str_replace(
                        ['é', 'æ', 'ø', 'å'],
                        ['e', 'ae', 'o', 'a'],
                        $slug
                    );

                    return $slug;
                }
            }
        }

        return '';
    }

    /**
     * Henter garndata fra Garnstudio.com med caching.
     */
    private static function fetch_yarn_data(string $yarn_slug): array {
        // Check memory cache
        if (isset(self::$yarn_cache[$yarn_slug])) {
            return self::$yarn_cache[$yarn_slug];
        }

        // Check transient cache (24 timer)
        $cache_key = 'pf_yarn_' . md5($yarn_slug);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            self::$yarn_cache[$yarn_slug] = $cached;
            return $cached;
        }

        $url = 'https://www.garnstudio.com/yarn.php?show=' . urlencode($yarn_slug) . '&cid=3';

        $response = wp_remote_get($url, [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [
                'Accept-Language' => 'da,da-DK;q=0.9',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('ProductFeed Enricher: Fejl ved hentning af ' . $url . ': ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [];
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return [];
        }

        $data = self::parse_yarn_page($html);

        if (empty($data)) {
            error_log('ProductFeed Enricher: Ingen data fundet for ' . $yarn_slug . ' — URL: ' . $url);
        } else {
            error_log('ProductFeed Enricher: Fandt ' . count($data) . ' felter for ' . $yarn_slug . ': ' . implode(', ', array_keys($data)));
        }

        // Cache kun hvis vi fandt data (undgå at cache tomme resultater)
        if (!empty($data)) {
            set_transient($cache_key, $data, DAY_IN_SECONDS);
        }
        self::$yarn_cache[$yarn_slug] = $data;

        return $data;
    }

    /**
     * Parser garndata fra Garnstudio HTML-side.
     * HTML-format: <strong>Label:</strong> value
     */
    private static function parse_yarn_page(string $html): array {
        $data = [];

        // Strip tags for lettere matching, men behold original til specifikke søgninger
        $text = strip_tags($html);
        // Normaliser whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Strikkefasthed: "Strikkefasthed: 10 x 10 cm = 23 m x 30 p"
        if (preg_match('/(?:Strikkefasthed|Gauge)[^:]*:\s*10\s*x\s*10\s*cm\s*=\s*(\d+)\s*m\s*x\s*(\d+)\s*p/iu', $text, $m)) {
            $data['strikkefasthed'] = $m[1] . ' masker x ' . $m[2] . ' pinde pr. 10x10 cm';
        } elseif (preg_match('/10\s*x\s*10\s*cm\s*=\s*(\d+)\s*m\s*x\s*(\d+)\s*p/i', $text, $m)) {
            $data['strikkefasthed'] = $m[1] . ' masker x ' . $m[2] . ' pinde pr. 10x10 cm';
        }

        // Pindestørrelse: "Anbefalede pinde: 3,5 mm"
        if (preg_match('/(?:Anbefalede pinde|Recommended needles?|Pindest)[^:]*:\s*([\d]+[,.]?[\d]*)\s*mm/iu', $text, $m)) {
            $data['pindestorrelse'] = str_replace('.', ',', $m[1]) . ' mm';
        }

        // Materiale: "Indhold: 75% Mohair, 25% Silke"
        if (preg_match('/(?:Indhold|Content|Fiber)[^:]*:\s*([\d]+%\s*[\wæøåÆØÅ]+(?:[\s,]+[\d]+%\s*[\wæøåÆØÅ]+)*)/iu', $text, $m)) {
            $data['materiale'] = trim($m[1]);
        }

        // Vægt og længde: "Vægt/længde: 25 g = ca. 210 meter"
        if (preg_match('/(?:Vægt|Weight)[^:]*:\s*(\d+)\s*g\s*=\s*(?:ca\.?\s*)?(\d+)\s*m/iu', $text, $m)) {
            $data['vaegt'] = $m[1] . ' g';
            $data['loeblaengde'] = $m[2] . ' meter';
        }

        // Garngruppe: "Garngruppe: A (23 - 26 masker)"
        if (preg_match('/(?:Garngruppe|Yarn group)[^:]*:.*?([A-F])\s*\(([^)]+)\)/iu', $text, $m)) {
            $data['garngruppe'] = $m[1] . ' (' . trim($m[2]) . ')';
        }

        // Vaskeanvisning
        if (preg_match('/((?:Håndvask|Maskinvask|Hand wash|Machine wash)[^.]*?(?:\d+\s*°C)?)/iu', $text, $m)) {
            $data['vask'] = trim($m[1]);
        }

        return $data;
    }

    /**
     * Sætter berigede attributter på et WooCommerce-produkt.
     */
    public static function apply_to_product(WC_Product_External $product, array $yarn_data): void {
        if (empty($yarn_data)) {
            return;
        }

        $existing = $product->get_attributes();
        $attributes = is_array($existing) ? $existing : [];

        $attr_map = [
            'strikkefasthed' => 'Strikkefasthed',
            'pindestorrelse'  => 'Pindestørrelse',
            'materiale'       => 'Materiale',
            'vaegt'           => 'Vægt',
            'loeblaengde'     => 'Løbelængde',
            'garngruppe'      => 'Garngruppe',
            'vask'            => 'Vaskeanvisning',
        ];

        foreach ($attr_map as $key => $label) {
            if (!empty($yarn_data[$key])) {
                $attr = new WC_Product_Attribute();
                $attr->set_name($label);
                $attr->set_options([$yarn_data[$key]]);
                $attr->set_visible(true);
                $attributes[] = $attr;
            }
        }

        $product->set_attributes($attributes);
    }
}
