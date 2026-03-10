<?php
defined('ABSPATH') || exit;

class PF_Feed_Parser {

    /**
     * Parser Partner-ads XML feed og returnerer normaliserede produkter.
     */
    public static function parse_partnerads(string $xml_content): array {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);

        if ($xml === false) {
            error_log('ProductFeed: Kunne ikke parse Partner-ads XML');
            return [];
        }

        $products = [];
        foreach ($xml->produkt as $p) {
            $price = floatval((string) $p->nypris);
            $old_price = floatval((string) $p->glpris);

            // Beskrivelse kan ligge i flere felter afhængig af feed
            $desc = (string) ($p->produktbeskrivelse ?? '');
            if (empty($desc)) {
                $desc = (string) ($p->beskrivelse ?? '');
            }
            if (empty($desc)) {
                $desc = (string) ($p->description ?? '');
            }

            $products[] = [
                'source'        => 'partnerads',
                'external_id'   => (string) $p->produktid,
                'merchant'      => (string) $p->forhandler,
                'category'      => (string) $p->kategorinavn,
                'brand'         => (string) $p->brand,
                'name'          => (string) $p->produktnavn,
                'description'   => $desc,
                'ean'           => (string) $p->ean,
                'price'         => $price,
                'old_price'     => ($old_price > $price) ? $old_price : null,
                'shipping'      => floatval((string) $p->fragtomk),
                'stock_status'  => (string) $p->lagerantal,
                'delivery_time' => (string) $p->leveringstid,
                'size'          => (string) ($p->size ?? ''),
                'color'         => (string) ($p->color ?? ''),
                'image_url'     => (string) $p->billedurl,
                'affiliate_url' => (string) $p->vareurl,
            ];
        }

        return $products;
    }

    /**
     * Parser Adtraction feed (TODO: implementer når format kendes).
     */
    public static function parse_adtraction(string $content): array {
        // TODO: Adtraction feed parsing
        return [];
    }

    /**
     * Normaliserer lagerstatus til en ensartet værdi.
     */
    public static function normalize_stock(string $status): string {
        $status = strtolower(trim($status));
        $in_stock = ['in stock', 'i lager', 'på lager', 'yes', '1', 'true'];
        $out_of_stock = ['out of stock', 'ikke på lager', 'udsolgt', 'no', '0', 'false'];

        if (in_array($status, $in_stock, true)) {
            return 'instock';
        }
        if (in_array($status, $out_of_stock, true)) {
            return 'outofstock';
        }
        // Hvis det er et tal > 0, er den på lager
        if (is_numeric($status) && intval($status) > 0) {
            return 'instock';
        }

        return 'instock'; // Default
    }
}
