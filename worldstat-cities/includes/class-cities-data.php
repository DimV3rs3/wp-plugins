<?php
/**
 * Data provider — supplies metrics to the platform.
 *
 * Includes Blocks & Roads aggregate metrics.
 *
 * @package WorldStatCities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSCities_Data {

    /**
     * Number of cities in DB for this country.
     */
    public static function get_cities_count( string $iso2 ): int {
        return WSCities_CPT::count_cities_for_country( $iso2 );
    }

    /**
     * Population of the largest city.
     */
    public static function get_largest_city_population( string $iso2 ): int {
        $cities = WSCities_CPT::get_cities_for_country( $iso2 );
        if ( empty( $cities ) ) return 0;
        return $cities[0]['pop_t3']; // already sorted desc
    }

    /**
     * Sum of all city populations (T3).
     */
    public static function get_total_urban_population( string $iso2 ): int {
        $cities = WSCities_CPT::get_cities_for_country( $iso2 );
        $total  = 0;
        foreach ( $cities as $c ) {
            $total += $c['pop_t3'];
        }
        return $total;
    }

    /**
     * Average arterial road density (1990-2015 period).
     */
    public static function get_avg_arterial_density( string $iso2 ): float {
        return self::avg_br_metric( $iso2, 'arterial_density', 'post1990' );
    }

    /**
     * Average block size (1990-2015 period).
     */
    public static function get_avg_block_size( string $iso2 ): float {
        return self::avg_br_metric( $iso2, 'block_size', 'post1990' );
    }

    /**
     * Average walkability ratio (1990-2015 period).
     */
    public static function get_avg_walkability( string $iso2 ): float {
        return self::avg_br_metric( $iso2, 'walkability', 'post1990' );
    }

    /**
     * Calculate average of a Blocks & Roads metric for a country.
     */
    private static function avg_br_metric( string $iso2, string $metric, string $period ): float {
        $cities = WSCities_CPT::get_cities_with_blocks_roads( $iso2 );
        if ( empty( $cities ) ) return 0;

        $sum   = 0;
        $count = 0;

        foreach ( $cities as $c ) {
            $val = $c['blocks_roads'][ $metric ][ $period ] ?? null;
            if ( $val !== null && $val > 0 ) {
                $sum += (float) $val;
                $count++;
            }
        }

        return $count > 0 ? round( $sum / $count, 2 ) : 0;
    }

    /**
     * Map layer data: ISO2 → total urban population.
     */
    public static function get_map_data(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm_iso.meta_value AS iso2, SUM(CAST(pm_pop.meta_value AS UNSIGNED)) AS total_pop
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_iso ON pm_iso.post_id = p.ID AND pm_iso.meta_key = 'wscity_country_iso2'
             JOIN {$wpdb->postmeta} pm_pop ON pm_pop.post_id = p.ID AND pm_pop.meta_key = 'wscity_pop_t3'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             GROUP BY pm_iso.meta_value",
            WSCities_CPT::SLUG
        ) );

        $result = [];
        foreach ( $rows as $r ) {
            $result[ strtoupper( $r->iso2 ) ] = (int) $r->total_pop;
        }
        return $result;
    }

    /* ═══════════════════════════════════════════════════════
       MAP MARKERS — coordinate-based city points
    ═══════════════════════════════════════════════════════ */

    /**
     * Get all city markers for the global map.
     * Returns an array of [ lat, lng, title, value, popup, country, color, radius ].
     *
     * Marker radius is proportional to population (log scale).
     */
    public static function get_all_city_markers(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    pm_lat.meta_value AS lat,
                    pm_lng.meta_value AS lng,
                    pm_pop.meta_value AS pop,
                    pm_iso.meta_value AS iso2,
                    pm_cn.meta_value  AS country_name
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = 'wscity_lat'
             JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = 'wscity_lng'
             LEFT JOIN {$wpdb->postmeta} pm_pop ON pm_pop.post_id = p.ID AND pm_pop.meta_key = 'wscity_pop_t3'
             LEFT JOIN {$wpdb->postmeta} pm_iso ON pm_iso.post_id = p.ID AND pm_iso.meta_key = 'wscity_country_iso2'
             LEFT JOIN {$wpdb->postmeta} pm_cn  ON pm_cn.post_id  = p.ID AND pm_cn.meta_key  = 'wscity_country_name'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm_lat.meta_value != '' AND pm_lng.meta_value != ''
             ORDER BY CAST(pm_pop.meta_value AS UNSIGNED) DESC",
            WSCities_CPT::SLUG
        ) );

        return self::build_markers( $rows );
    }

    /**
     * Get city markers for a specific country.
     *
     * @param string $iso2 Country ISO2 code.
     */
    public static function get_country_city_markers( string $iso2 ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title,
                    pm_lat.meta_value AS lat,
                    pm_lng.meta_value AS lng,
                    pm_pop.meta_value AS pop,
                    pm_iso.meta_value AS iso2,
                    pm_cn.meta_value  AS country_name
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = 'wscity_lat'
             JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = 'wscity_lng'
             JOIN {$wpdb->postmeta} pm_iso ON pm_iso.post_id = p.ID AND pm_iso.meta_key = 'wscity_country_iso2'
             LEFT JOIN {$wpdb->postmeta} pm_pop ON pm_pop.post_id = p.ID AND pm_pop.meta_key = 'wscity_pop_t3'
             LEFT JOIN {$wpdb->postmeta} pm_cn  ON pm_cn.post_id  = p.ID AND pm_cn.meta_key  = 'wscity_country_name'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm_iso.meta_value = %s
               AND pm_lat.meta_value != '' AND pm_lng.meta_value != ''
             ORDER BY CAST(pm_pop.meta_value AS UNSIGNED) DESC",
            WSCities_CPT::SLUG,
            strtoupper( $iso2 )
        ) );

        return self::build_markers( $rows );
    }

    /**
     * Build markers array from DB rows.
     *
     * @param array $rows DB result rows.
     * @return array Markers for the map.
     */
    private static function build_markers( array $rows ): array {
        $markers = [];

        foreach ( $rows as $r ) {
            $lat = (float) $r->lat;
            $lng = (float) $r->lng;
            if ( ! $lat && ! $lng ) continue;

            $pop     = (int) ( $r->pop ?? 0 );
            $country = $r->country_name ?? '';
            $iso2    = strtoupper( $r->iso2 ?? '' );

            // Dynamic radius: log scale (min 3px, max 14px)
            $radius = $pop > 0 ? min( 14, max( 3, (int) round( log10( $pop ) * 1.8 ) ) ) : 4;

            // Format population for display
            $pop_display = $pop > 0 ? self::format_population( $pop ) : '—';

            // City page link
            $city_url = get_permalink( (int) $r->ID );

            $markers[] = [
                'lat'     => $lat,
                'lng'     => $lng,
                'title'   => $r->post_title,
                'value'   => $pop_display,
                'popup'   => sprintf(
                    '<strong><a href="%s" style="color:#1d4ed8;text-decoration:none">%s</a></strong><br>%s%s',
                    esc_url( $city_url ),
                    esc_html( $r->post_title ),
                    $country ? esc_html( $country ) . '<br>' : '',
                    $pop > 0 ? 'Население: ' . esc_html( $pop_display ) : ''
                ),
                'country' => $iso2,
                'radius'  => $radius,
            ];
        }

        return $markers;
    }

    /**
     * Format population number for display (short form).
     */
    private static function format_population( int $pop ): string {
        if ( $pop >= 1_000_000 ) {
            return round( $pop / 1_000_000, 1 ) . ' млн';
        }
        if ( $pop >= 1_000 ) {
            return round( $pop / 1_000, 1 ) . ' тыс.';
        }
        return (string) $pop;
    }
}
