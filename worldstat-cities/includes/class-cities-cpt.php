<?php
/**
 * Custom Post Type for cities.
 *
 * @package WorldStatCities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSCities_CPT {

    const SLUG = 'wsp_city';

    /**
     * Meta fields stored per city (imported from CSV).
     */
    const META_FIELDS = [
        'wscity_country_iso2'     => 'string',   // linked country
        'wscity_country_name'     => 'string',
        'wscity_region'           => 'string',
        'wscity_lat'              => 'number',
        'wscity_lng'              => 'number',
        // Dates
        'wscity_date_t1'          => 'string',
        'wscity_date_t2'          => 'string',
        'wscity_date_t3'          => 'string',
        // Population
        'wscity_pop_t1'           => 'integer',
        'wscity_pop_t2'           => 'integer',
        'wscity_pop_t3'           => 'integer',
        'wscity_pop_change'       => 'string',
        // Built-up area total (ha)
        'wscity_builtup_t1'       => 'number',
        'wscity_builtup_t2'       => 'number',
        'wscity_builtup_t3'       => 'number',
        'wscity_builtup_change'   => 'string',
        // Urban extent (ha)
        'wscity_extent_t1'        => 'number',
        'wscity_extent_t2'        => 'number',
        'wscity_extent_t3'        => 'number',
        'wscity_extent_change'    => 'string',
        // Density
        'wscity_density_builtup'  => 'number',   // persons/ha at T3
        'wscity_density_extent'   => 'number',   // persons/ha at T3
        // Fragmentation & Compactness at T3
        'wscity_saturation'       => 'number',
        'wscity_openness'         => 'number',
        'wscity_proximity'        => 'number',
        'wscity_cohesion'         => 'number',

        // Greenspace metrics (from greenspace.xlsx)
        'wscity_green_metrics'    => 'string',   // JSON map { metric_key: {value, unit}, ... }

        // GHS/WUP population time series (from GHS_WUP_MTUC...csv)
        'wscity_pop_history'      => 'string',   // JSON map { "1950": 12345, ... }
        'wscity_pop_year_max'     => 'integer',  // latest year stored in history

        // Blocks & Roads — Table 1 (200 cities, 2 periods: pre-1990, 1990-2015)
        'wscity_blocks_roads'      => 'string',  // JSON: road/block/intersection metrics
        // Blocks & Roads — Table 2 (30 cities, 5 historical periods)
        'wscity_blocks_roads_hist' => 'string',  // JSON: historical road/block metrics
    ];

    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
        add_action( 'init', [ $this, 'register_meta' ] );
    }

    public function register(): void {
        register_post_type( self::SLUG, [
            'labels' => [
                'name'          => 'Города',
                'singular_name' => 'Город',
                'all_items'     => 'Все города',
                'add_new_item'  => 'Добавить город',
                'search_items'  => 'Поиск городов',
            ],
            'public'          => true,
            'show_ui'         => true,
            'show_in_menu'    => false,
            'show_in_rest'    => true,
            'rest_base'       => 'cities',
            'rewrite'         => [ 'slug' => 'city', 'with_front' => false ],
            'has_archive'     => false,
            'supports'        => [ 'title', 'custom-fields' ],
            'capability_type' => 'post',
        ] );
    }

    public function register_meta(): void {
        foreach ( self::META_FIELDS as $key => $type ) {
            $schema = match ( $type ) {
                'integer' => 'integer',
                'number'  => 'number',
                default   => 'string',
            };
            register_post_meta( self::SLUG, $key, [
                'type'         => $schema,
                'single'       => true,
                'show_in_rest' => true,
            ] );
        }
    }

    /**
     * Get cities for a country.
     */
    public static function get_cities_for_country( string $iso2, string $orderby = 'pop_desc' ): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key = 'wscity_country_iso2' AND pm.meta_value = %s",
            self::SLUG, strtoupper( $iso2 )
        );

        $posts = $wpdb->get_results( $sql );
        if ( ! $posts ) return [];

        $cities = [];
        foreach ( $posts as $p ) {
            $cities[] = [
                'id'         => (int) $p->ID,
                'name'       => $p->post_title,
                'pop_t3'     => (int) get_post_meta( $p->ID, 'wscity_pop_t3', true ),
                'pop_t2'     => (int) get_post_meta( $p->ID, 'wscity_pop_t2', true ),
                'pop_change' => get_post_meta( $p->ID, 'wscity_pop_change', true ),
                'builtup_t3' => (float) get_post_meta( $p->ID, 'wscity_builtup_t3', true ),
                'extent_t3'  => (float) get_post_meta( $p->ID, 'wscity_extent_t3', true ),
                'density'    => (float) get_post_meta( $p->ID, 'wscity_density_builtup', true ),
                'lat'        => (float) get_post_meta( $p->ID, 'wscity_lat', true ),
                'lng'        => (float) get_post_meta( $p->ID, 'wscity_lng', true ),
                'saturation' => (float) get_post_meta( $p->ID, 'wscity_saturation', true ),
                'cohesion'   => (float) get_post_meta( $p->ID, 'wscity_cohesion', true ),
                'green_metrics' => get_post_meta( $p->ID, 'wscity_green_metrics', true ),
            ];
        }

        // Sort
        usort( $cities, fn( $a, $b ) => $b['pop_t3'] <=> $a['pop_t3'] );

        return $cities;
    }

    /**
     * Get Blocks & Roads data for a city post.
     */
    public static function get_blocks_roads( int $post_id ): ?array {
        $json = get_post_meta( $post_id, 'wscity_blocks_roads', true );
        return $json ? json_decode( $json, true ) : null;
    }

    /**
     * Get historical Blocks & Roads data for a city post (Table 2).
     */
    public static function get_blocks_roads_hist( int $post_id ): ?array {
        $json = get_post_meta( $post_id, 'wscity_blocks_roads_hist', true );
        return $json ? json_decode( $json, true ) : null;
    }

    /**
     * Get cities with Blocks & Roads data for a country.
     */
    public static function get_cities_with_blocks_roads( string $iso2 ): array {
        $cities = self::get_cities_for_country( $iso2 );
        $result = [];
        foreach ( $cities as $c ) {
            $br = self::get_blocks_roads( $c['id'] );
            if ( $br ) {
                $c['blocks_roads'] = $br;
                $result[] = $c;
            }
        }
        return $result;
    }

    /**
     * Get cities with historical Blocks & Roads data for a country.
     */
    public static function get_cities_with_blocks_roads_hist( string $iso2 ): array {
        $cities = self::get_cities_for_country( $iso2 );
        $result = [];
        foreach ( $cities as $c ) {
            $br = self::get_blocks_roads_hist( $c['id'] );
            if ( $br ) {
                $c['blocks_roads_hist'] = $br;
                $result[] = $c;
            }
        }
        return $result;
    }

    public static function count_cities_for_country( string $iso2 ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key = 'wscity_country_iso2' AND pm.meta_value = %s",
            self::SLUG, strtoupper( $iso2 )
        ) );
    }

    /**
     * URL страницы страны с hash для открытия вкладки (и опциональной подвкладки).
     *
     * Примеры: #cities, #ergonomics/cities
     */
    public static function get_country_tab_url( string $iso2, string $tab = '', string $subtab = '' ): string {
        $iso2 = strtoupper( trim( $iso2 ) );
        if ( ! $iso2 || ! class_exists( 'WorldStat_Country_CPT' ) ) {
            return '';
        }

        $country_post = WorldStat_Country_CPT::get_by_code( $iso2 );
        if ( ! $country_post ) {
            return '';
        }

        $url = get_permalink( $country_post );
        if ( ! $url ) {
            return '';
        }

        $tab = sanitize_key( $tab );
        if ( ! $tab ) {
            return $url;
        }

        $hash = $tab;
        $subtab = sanitize_key( $subtab );
        if ( $subtab ) {
            $hash .= '/' . $subtab;
        }

        return $url . '#' . $hash;
    }
}
