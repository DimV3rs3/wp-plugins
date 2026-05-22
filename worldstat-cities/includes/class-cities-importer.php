<?php
/**
 * CSV Importer — AJAX batch processing with direct SQL for performance.
 *
 * CSV structure (81 columns):
 *   Row 0 = metric group headers (City Name, Country, Region, ...)
 *   Row 1 = sub-headers (T1, T2, T3, Annual Change, ...)
 *   Row 2+ = data rows
 *
 * Column mapping (0-indexed):
 *   0: City Name, 1: Country, 2: Region
 *   3: Latitude, 4: Longitude
 *   5: Date T1, 6: Date T2, 7: Date T3
 *   8-11: Population (T1, T2, T3, Change)
 *   12-15: Built-up Area Total (T1, T2, T3, Change)
 *   32-35: Urban Extent (T1, T2, T3, Change)
 *   36-39: Built-up Density (T1, T2, T3, Change)
 *   40-43: Urban Extent Density
 *   44-47: Saturation, 48-51: Openness, 52-55: Proximity, 56-59: Cohesion
 *
 * @package WorldStatCities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSCities_Importer {

    /**
     * Country name → ISO2 overrides for names that don't match WSP data.
     */
    const COUNTRY_MAP = [
        'United States'       => 'US',
        'United Kingdom'      => 'GB',
        'Russia'              => 'RU',
        'Russian Federation'  => 'RU',
        'South Korea'         => 'KR',
        'Korea Rep.'          => 'KR',
        'Korea, Rep.'         => 'KR',
        'North Korea'         => 'KP',
        'Korea Dem. Rep.'     => 'KP',
        'Iran'                => 'IR',
        'Iran, Islamic Rep.'  => 'IR',
        'Syria'               => 'SY',
        'Tanzania'            => 'TZ',
        'Vietnam'             => 'VN',
        'Viet Nam'            => 'VN',
        'Ivory Coast'         => 'CI',
        "Cote d'Ivoire"       => 'CI',
        "Côte d'Ivoire"       => 'CI',
        'Congo'               => 'CG',
        'Congo, Rep.'         => 'CG',
        'Congo Dem. Rep.'     => 'CD',
        'Congo, Dem. Rep.'    => 'CD',
        'Czech Republic'      => 'CZ',
        'Czechia'             => 'CZ',
        'Burma'               => 'MM',
        'Myanmar'             => 'MM',
        'Laos'                => 'LA',
        'Bolivia'             => 'BO',
        'Venezuela'           => 'VE',
        'Taiwan'              => 'TW',
        'Palestine'           => 'PS',
        'Moldova'             => 'MD',
        'Macedonia'           => 'MK',
        'North Macedonia'     => 'MK',
        'Eswatini'            => 'SZ',
        'Swaziland'           => 'SZ',
        'USA'                 => 'US',
        'U.S.A.'              => 'US',
        'U.S.A'               => 'US',
        'United States of America' => 'US',
        'UK'                  => 'GB',
        'Great Britain'       => 'GB',
        'Britain'             => 'GB',
        'China'               => 'CN',
        "People's Republic of China" => 'CN',
        'P.R. China'          => 'CN',
        'P.R.China'           => 'CN',
        'Hong Kong SAR'       => 'HK',
        'Hong Kong'           => 'HK',
        'Macao SAR'           => 'MO',
        'Macau'               => 'MO',
        'Turkiye'             => 'TR',
        'Turkey'              => 'TR',
        'Türkiye'             => 'TR',
        'Cabo Verde'          => 'CV',
        'Cape Verde'          => 'CV',
        'Brunei'              => 'BN',
        'Brunei Darussalam'   => 'BN',
        'Slovak Republic'     => 'SK',
        'Slovakia'            => 'SK',
        'Lao PDR'             => 'LA',
        'Lao'                 => 'LA',
        'Timor-Leste'         => 'TL',
        'East Timor'          => 'TL',
        'Bahamas'             => 'BS',
        'Bahamas, The'        => 'BS',
        'Gambia'              => 'GM',
        'Gambia, The'         => 'GM',
        'Yemen'               => 'YE',
        'Yemen, Rep.'         => 'YE',
        'Egypt'               => 'EG',
        'Egypt, Arab Rep.'    => 'EG',
        'Iran, Islamic Republic of' => 'IR',
        'Syrian Arab Republic' => 'SY',
        'Korea'               => 'KR',
        'Republic of Korea'   => 'KR',
        'Korea, Republic of'  => 'KR',
        'Dem. Rep. Korea'     => 'KP',
        'Micronesia'          => 'FM',
        'Micronesia, Fed. Sts.' => 'FM',
        'St. Lucia'           => 'LC',
        'Saint Lucia'         => 'LC',
        'St. Vincent'         => 'VC',
        'Saint Vincent and the Grenadines' => 'VC',
        'St. Kitts and Nevis' => 'KN',
        'Saint Kitts and Nevis' => 'KN',
        'Trinidad and Tobago' => 'TT',
        'Antigua and Barbuda' => 'AG',
        'Dominican Republic'  => 'DO',
        'Bosnia'              => 'BA',
        'Bosnia and Herzegovina' => 'BA',
        'Serbia'              => 'RS',
        'Montenegro'          => 'ME',
        'Kosovo'              => 'XK',
        'West Bank and Gaza'  => 'PS',
        'State of Palestine'  => 'PS',
    ];

    /** @var array<string,string>|null */
    private static $country_iso_map_cache = null;

    /** @var array<string,string>|null */
    private static $country_iso_map_norm_cache = null;

    /** @var array<string,string>|null */
    private static $country_iso3_to_iso2_cache = null;

    /**
     * Phase 1: Upload CSV and count rows. Returns file path + total count.
     */
    public function prepare( string $tmp_path ): array {
        $upload_dir = wp_upload_dir();
        $dest = $upload_dir['basedir'] . '/wscities-import.csv';

        if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
            // Try copy
            if ( ! copy( $tmp_path, $dest ) ) {
                return [ 'error' => 'Не удалось сохранить файл.' ];
            }
        }

        // Count data rows (skip 2 header rows)
        $delimiter = $this->detect_csv_delimiter( $dest );
        $f = fopen( $dest, 'r' );
        fgetcsv( $f, 0, $delimiter ); // row 0 — group headers
        fgetcsv( $f, 0, $delimiter ); // row 1 — sub-headers

        $count = 0;
        while ( fgetcsv( $f, 0, $delimiter ) !== false ) $count++;
        fclose( $f );

        return [
            'file'  => $dest,
            'total' => $count,
        ];
    }

    /**
     * Phase 2: Process a batch of rows.
     *
     * @param array<string, mixed> $resolutions User choices for conflicting fields (from conflict modal).
     */
    public function process_batch( string $file_path, int $offset, int $batch_size, bool $update, array $resolutions = [] ): array {
        global $wpdb;
        set_time_limit( 300 );

        $results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) return [ 'error' => 'Не удалось открыть файл.' ];

        // Read 2 header rows and detect columns dynamically.
        $header1 = fgetcsv( $f, 0, $delimiter );
        $header2 = fgetcsv( $f, 0, $delimiter );
        $cols = $this->detect_main_columns( is_array( $header1 ) ? $header1 : [], is_array( $header2 ) ? $header2 : [] );

        // Skip to offset
        for ( $i = 0; $i < $offset && fgetcsv( $f, 0, $delimiter ) !== false; $i++ ) {}

        $maps = $this->get_country_maps();

        // Cache existing cities: "name|ISO2" => post_id
        $existing = $this->get_existing_cities();

        $now     = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', true );
        $uid     = get_current_user_id() ?: 1;
        $in_batch = 0;

        while ( $in_batch < $batch_size && ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $in_batch++;

            $city_name    = trim( (string) ( $row[ $cols['city'] ] ?? '' ) );
            $country_name = trim( (string) ( $row[ $cols['country'] ] ?? '' ) );

            if ( ! $city_name || ! $country_name ) {
                $results['skipped']++;
                continue;
            }

            $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            if ( ! $iso2 ) {
                $results['errors'][] = $this->country_not_found_message( $country_name, $city_name, $maps );
                $results['skipped']++;
                continue;
            }

            // Parse metrics from CSV columns
            $meta = $this->parse_row( $row, $iso2, $country_name, $cols );

            $cache_key   = $city_name . '|' . $iso2;
            $existing_id = $existing[ $cache_key ] ?? $this->find_city_by_name_flexible( $city_name, $iso2, $existing );

            if ( $existing_id && $update ) {
                // UPDATE
                $wpdb->update( $wpdb->posts, [
                    'post_modified'     => $now,
                    'post_modified_gmt' => $now_gmt,
                ], [ 'ID' => $existing_id ], [ '%s', '%s' ], [ '%d' ] );

                $this->apply_meta_updates( $existing_id, $meta, $resolutions );
                $results['updated']++;

            } elseif ( $existing_id && ! $update ) {
                $results['skipped']++;

            } else {
                // INSERT new city
                $wpdb->insert( $wpdb->posts, [
                    'post_author'       => $uid,
                    'post_date'         => $now,
                    'post_date_gmt'     => $now_gmt,
                    'post_content'      => '',
                    'post_title'        => $city_name,
                    'post_excerpt'      => '',
                    'post_status'       => 'publish',
                    'comment_status'    => 'closed',
                    'ping_status'       => 'closed',
                    'post_password'     => '',
                    'post_name'         => sanitize_title( $city_name . '-' . strtolower( $iso2 ) ),
                    'to_ping'           => '',
                    'pinged'            => '',
                    'post_modified'     => $now,
                    'post_modified_gmt' => $now_gmt,
                    'post_content_filtered' => '',
                    'post_parent'       => 0,
                    'guid'              => '',
                    'menu_order'        => 0,
                    'post_type'         => WSCities_CPT::SLUG,
                    'post_mime_type'    => '',
                    'comment_count'     => 0,
                ] );

                $post_id = (int) $wpdb->insert_id;
                if ( ! $post_id ) {
                    $results['errors'][] = "INSERT failed: $city_name";
                    $results['skipped']++;
                    continue;
                }

                $wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/?p=' . $post_id ) ], [ 'ID' => $post_id ] );

                // Bulk meta insert
                $vals = [];
                $phs  = [];
                foreach ( $meta as $k => $v ) {
                    $vals[] = $post_id;
                    $vals[] = $k;
                    $vals[] = (string) $v;
                    $phs[]  = '(%d,%s,%s)';
                }
                if ( $phs ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $phs ),
                        $vals
                    ) );
                }

                $existing[ $cache_key ] = $post_id;
                $results['imported']++;
            }
        }

        fclose( $f );
        wp_cache_flush();

        return $results;
    }

    /**
     * Parse one CSV data row into meta key=>value pairs.
     */
    private function parse_row( array $r, string $iso2, string $country_name, array $cols = [] ): array {
        $c = function ( string $key, int $fallback ) use ( $cols ): int {
            return isset( $cols[ $key ] ) ? (int) $cols[ $key ] : $fallback;
        };
        return [
            'wscity_country_iso2'     => $iso2,
            'wscity_country_name'     => $country_name,
            'wscity_region'           => trim( (string) ( $r[ $c( 'region', 2 ) ] ?? '' ) ),
            'wscity_lat'              => $this->pn( (string) ( $r[ $c( 'lat', 3 ) ] ?? '' ) ),
            'wscity_lng'              => $this->pn( (string) ( $r[ $c( 'lng', 4 ) ] ?? '' ) ),
            // Dates
            'wscity_date_t1'          => trim( (string) ( $r[ $c( 'date_t1', 5 ) ] ?? '' ) ),
            'wscity_date_t2'          => trim( (string) ( $r[ $c( 'date_t2', 6 ) ] ?? '' ) ),
            'wscity_date_t3'          => trim( (string) ( $r[ $c( 'date_t3', 7 ) ] ?? '' ) ),
            // Population
            'wscity_pop_t1'           => $this->pi( (string) ( $r[ $c( 'pop_t1', 8 ) ] ?? '' ) ),
            'wscity_pop_t2'           => $this->pi( (string) ( $r[ $c( 'pop_t2', 9 ) ] ?? '' ) ),
            'wscity_pop_t3'           => $this->pi( (string) ( $r[ $c( 'pop_t3', 10 ) ] ?? '' ) ),
            'wscity_pop_change'       => trim( (string) ( $r[ $c( 'pop_change', 11 ) ] ?? '' ) ),
            // Built-up area total (ha)
            'wscity_builtup_t1'       => $this->pn( (string) ( $r[ $c( 'builtup_t1', 12 ) ] ?? '' ) ),
            'wscity_builtup_t2'       => $this->pn( (string) ( $r[ $c( 'builtup_t2', 13 ) ] ?? '' ) ),
            'wscity_builtup_t3'       => $this->pn( (string) ( $r[ $c( 'builtup_t3', 14 ) ] ?? '' ) ),
            'wscity_builtup_change'   => trim( (string) ( $r[ $c( 'builtup_change', 15 ) ] ?? '' ) ),
            // Urban extent (ha)
            'wscity_extent_t1'        => $this->pn( (string) ( $r[ $c( 'extent_t1', 32 ) ] ?? '' ) ),
            'wscity_extent_t2'        => $this->pn( (string) ( $r[ $c( 'extent_t2', 33 ) ] ?? '' ) ),
            'wscity_extent_t3'        => $this->pn( (string) ( $r[ $c( 'extent_t3', 34 ) ] ?? '' ) ),
            'wscity_extent_change'    => trim( (string) ( $r[ $c( 'extent_change', 35 ) ] ?? '' ) ),
            // Density at T3
            'wscity_density_builtup'  => $this->pn( (string) ( $r[ $c( 'density_builtup_t3', 38 ) ] ?? '' ) ),
            'wscity_density_extent'   => $this->pn( (string) ( $r[ $c( 'density_extent_t3', 42 ) ] ?? '' ) ),
            // Fragmentation & Compactness at T3
            'wscity_saturation'       => $this->pn( (string) ( $r[ $c( 'saturation_t3', 46 ) ] ?? '' ) ),
            'wscity_openness'         => $this->pn( (string) ( $r[ $c( 'openness_t3', 50 ) ] ?? '' ) ),
            'wscity_proximity'        => $this->pn( (string) ( $r[ $c( 'proximity_t3', 54 ) ] ?? '' ) ),
            'wscity_cohesion'         => $this->pn( (string) ( $r[ $c( 'cohesion_t3', 58 ) ] ?? '' ) ),
        ];
    }

    /**
     * Detect key columns in combined_atlas CSV using two header rows.
     */
    private function detect_main_columns( array $h1, array $h2 ): array {
        $max = max( count( $h1 ), count( $h2 ) );
        $merged = [];
        for ( $i = 0; $i < $max; $i++ ) {
            $a = strtolower( trim( (string) ( $h1[ $i ] ?? '' ) ) );
            $b = strtolower( trim( (string) ( $h2[ $i ] ?? '' ) ) );
            $merged[ $i ] = trim( $a . ' ' . $b );
        }

        $find = function ( array $patterns, int $fallback ) use ( $merged ): int {
            foreach ( $merged as $i => $txt ) {
                foreach ( $patterns as $re ) {
                    if ( preg_match( $re, $txt ) ) return (int) $i;
                }
            }
            return $fallback;
        };

        return [
            'city'               => $find( [ '/\bcity name\b/', '/\burban area\b/', '/\bcity\b/' ], 0 ),
            'country'            => $find( [ '/\bcountry\b/' ], 1 ),
            'region'             => $find( [ '/\bregion\b/' ], 2 ),
            'lat'                => $find( [ '/\blatitude\b/' ], 3 ),
            'lng'                => $find( [ '/\blongitude\b/', '/\blong\b/', '/\blon\b/' ], 4 ),
            'date_t1'            => $find( [ '/\bt1\b.*\bdate\b/', '/\bdate\b.*\bt1\b/' ], 5 ),
            'date_t2'            => $find( [ '/\bt2\b.*\bdate\b/', '/\bdate\b.*\bt2\b/' ], 6 ),
            'date_t3'            => $find( [ '/\bt3\b.*\bdate\b/', '/\bdate\b.*\bt3\b/' ], 7 ),
            'pop_t1'             => $find( [ '/urban extent population.*\bt1\b/', '/population.*\bt1\b/' ], 8 ),
            'pop_t2'             => $find( [ '/urban extent population.*\bt2\b/', '/population.*\bt2\b/' ], 9 ),
            'pop_t3'             => $find( [ '/urban extent population.*\bt3\b/', '/population.*\bt3\b/' ], 10 ),
            'pop_change'         => $find( [ '/urban extent population.*annual change/', '/population.*annual change/' ], 11 ),
            'builtup_t1'         => $find( [ '/built-up area total.*\bt1\b/' ], 12 ),
            'builtup_t2'         => $find( [ '/built-up area total.*\bt2\b/' ], 13 ),
            'builtup_t3'         => $find( [ '/built-up area total.*\bt3\b/' ], 14 ),
            'builtup_change'     => $find( [ '/built-up area total.*annual change/' ], 15 ),
            'extent_t1'          => $find( [ '/urban extent \(ha\).*\bt1\b/', '/urban extent.*\bt1\b/' ], 32 ),
            'extent_t2'          => $find( [ '/urban extent \(ha\).*\bt2\b/', '/urban extent.*\bt2\b/' ], 33 ),
            'extent_t3'          => $find( [ '/urban extent \(ha\).*\bt3\b/', '/urban extent.*\bt3\b/' ], 34 ),
            'extent_change'      => $find( [ '/urban extent \(ha\).*annual change/', '/urban extent.*annual change/' ], 35 ),
            'density_builtup_t3' => $find( [ '/built-up area density.*\bt3\b/' ], 38 ),
            'density_extent_t3'  => $find( [ '/urban extent density.*\bt3\b/' ], 42 ),
            'saturation_t3'      => $find( [ '/saturation.*\bt3\b/' ], 46 ),
            'openness_t3'        => $find( [ '/openness.*\bt3\b/' ], 50 ),
            'proximity_t3'       => $find( [ '/proximity.*\bt3\b/' ], 54 ),
            'cohesion_t3'        => $find( [ '/cohesion.*\bt3\b/' ], 58 ),
        ];
    }

    /** Parse integer (remove commas). */
    private function pi( string $v ): int {
        return (int) str_replace( [ ',', ' ' ], '', trim( $v ) );
    }

    /** Parse float (remove commas, handle %). */
    private function pn( string $v ): float {
        $v = trim( $v );
        $v = str_replace( [ ',', ' ', '%' ], '', $v );
        return (float) $v;
    }

    /**
     * Cached country maps for import (exact + normalized names, ISO3).
     *
     * @return array{iso_map: array<string,string>, iso_map_norm: array<string,string>, iso3_to_iso2: array<string,string>}
     */
    private function get_country_maps(): array {
        if ( self::$country_iso_map_cache === null ) {
            self::$country_iso_map_cache     = $this->build_country_iso_map();
            self::$country_iso3_to_iso2_cache = $this->build_country_iso3_to_iso2_map();
            self::$country_iso_map_norm_cache = [];

            foreach ( self::$country_iso_map_cache as $name => $iso2 ) {
                $norm = $this->normalize_country_name_for_match( $name );
                if ( $norm !== '' ) {
                    self::$country_iso_map_norm_cache[ $norm ] = $iso2;
                }
            }
            foreach ( self::COUNTRY_MAP as $name => $iso2 ) {
                $norm = $this->normalize_country_name_for_match( $name );
                if ( $norm !== '' ) {
                    self::$country_iso_map_norm_cache[ $norm ] = $iso2;
                }
            }
        }

        return [
            'iso_map'      => self::$country_iso_map_cache,
            'iso_map_norm' => self::$country_iso_map_norm_cache,
            'iso3_to_iso2' => self::$country_iso3_to_iso2_cache,
        ];
    }

    /**
     * Resolve country label from CSV to ISO2 (aliases, ISO codes, fuzzy match).
     */
    public function resolve_country_iso2( string $country_name, ?array $maps = null ): ?string {
        $country_name = trim( $country_name );
        if ( $country_name === '' ) {
            return null;
        }

        $maps = $maps ?? $this->get_country_maps();
        $iso_map      = $maps['iso_map'];
        $iso_map_norm = $maps['iso_map_norm'];
        $iso3_to_iso2 = $maps['iso3_to_iso2'];

        if ( isset( self::COUNTRY_MAP[ $country_name ] ) ) {
            return self::COUNTRY_MAP[ $country_name ];
        }
        if ( isset( $iso_map[ $country_name ] ) ) {
            return $iso_map[ $country_name ];
        }

        $upper = strtoupper( $country_name );
        if ( preg_match( '/^[A-Z]{2}$/', $upper ) ) {
            return $upper;
        }
        if ( preg_match( '/^[A-Z]{3}$/', $upper ) ) {
            return $iso3_to_iso2[ $upper ] ?? null;
        }

        foreach ( $this->country_name_variants_for_match( $country_name ) as $variant ) {
            if ( isset( self::COUNTRY_MAP[ $variant ] ) ) {
                return self::COUNTRY_MAP[ $variant ];
            }
            if ( isset( $iso_map[ $variant ] ) ) {
                return $iso_map[ $variant ];
            }
            $norm = $this->normalize_country_name_for_match( $variant );
            if ( $norm !== '' && isset( $iso_map_norm[ $norm ] ) ) {
                return $iso_map_norm[ $norm ];
            }
        }

        $fuzzy = $this->fuzzy_match_country_iso2( $country_name, $iso_map );
        if ( $fuzzy ) {
            return $fuzzy;
        }

        return apply_filters( 'wscities_resolve_country_iso2', null, $country_name, $maps );
    }

    /**
     * Suggest closest country name when resolution fails.
     *
     * @return array{name: string, iso2: string}|null
     */
    public function suggest_country_match( string $country_name, ?array $maps = null ): ?array {
        $maps = $maps ?? $this->get_country_maps();
        $iso_map = $maps['iso_map'];
        $best_name = '';
        $best_iso2 = '';
        $best_pct  = 0.0;

        foreach ( $this->country_name_variants_for_match( $country_name ) as $variant ) {
            foreach ( $iso_map as $name => $iso2 ) {
                similar_text(
                    $this->normalize_country_name_for_match( $variant ),
                    $this->normalize_country_name_for_match( $name ),
                    $pct
                );
                if ( $pct > $best_pct ) {
                    $best_pct  = $pct;
                    $best_name = $name;
                    $best_iso2 = $iso2;
                }
            }
        }

        if ( $best_pct >= 72.0 && $best_iso2 ) {
            return [ 'name' => $best_name, 'iso2' => $best_iso2, 'score' => round( $best_pct, 1 ) ];
        }

        return null;
    }

    /**
     * Human-readable labels for city meta fields (conflict UI).
     *
     * @return array<string, string>
     */
    public static function meta_field_labels(): array {
        return [
            'wscity_country_name'     => 'Страна',
            'wscity_region'           => 'Регион',
            'wscity_lat'              => 'Широта',
            'wscity_lng'              => 'Долгота',
            'wscity_date_t1'          => 'Дата T1',
            'wscity_date_t2'          => 'Дата T2',
            'wscity_date_t3'          => 'Дата T3',
            'wscity_pop_t1'           => 'Население T1',
            'wscity_pop_t2'           => 'Население T2',
            'wscity_pop_t3'           => 'Население T3',
            'wscity_pop_change'       => 'Изменение населения',
            'wscity_builtup_t1'       => 'Застройка T1 (га)',
            'wscity_builtup_t2'       => 'Застройка T2 (га)',
            'wscity_builtup_t3'       => 'Застройка T3 (га)',
            'wscity_builtup_change'   => 'Изменение застройки',
            'wscity_extent_t1'        => 'Urban extent T1 (га)',
            'wscity_extent_t2'        => 'Urban extent T2 (га)',
            'wscity_extent_t3'        => 'Urban extent T3 (га)',
            'wscity_extent_change'    => 'Изменение urban extent',
            'wscity_density_builtup'  => 'Плотность (застр.)',
            'wscity_density_extent'   => 'Плотность (extent)',
            'wscity_saturation'       => 'Saturation',
            'wscity_openness'         => 'Openness',
            'wscity_proximity'        => 'Proximity',
            'wscity_cohesion'         => 'Cohesion',
            'wscity_blocks_roads'     => 'Кварталы и дороги (T1)',
            'wscity_blocks_roads_hist'=> 'Кварталы и дороги (история)',
            'wscity_green_metrics'    => 'Greenspace',
            'wscity_pop_history'      => 'История населения (GHS/WUP)',
        ];
    }

    /**
     * Scan file for value conflicts when updating existing cities.
     *
     * @return array{conflicts: array<int, array>, truncated: bool, cities_checked: int}
     */
    public function scan_conflicts( string $file_path, string $import_type = 'main', int $max_conflicts = 250 ): array {
        $import_type = sanitize_key( $import_type );
        if ( $import_type === 'main' ) {
            return $this->scan_conflicts_main( $file_path, $max_conflicts );
        }
        if ( $import_type === 'br1' ) {
            return $this->scan_conflicts_br1( $file_path, $max_conflicts );
        }
        if ( $import_type === 'greenspace' ) {
            return $this->scan_conflicts_greenspace( $file_path, $max_conflicts );
        }
        if ( $import_type === 'br2' ) {
            return $this->scan_conflicts_br2( $file_path, $max_conflicts );
        }

        return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
    }

    /**
     * Build country_name => ISO2 map from WSP data.
     */
    private function build_country_iso_map(): array {
        global $wpdb;
        $map = [];

        // Get all countries with their names and ISO2 codes
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.post_title, pm.meta_value AS iso2
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key = 'wsp_iso_alpha2'",
            'wsp_country'
        ) );

        foreach ( $rows as $r ) {
            $iso2 = strtoupper( $r->iso2 );
            $map[ $r->post_title ] = $iso2;
        }

        // Also add English names
        $en_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm_name.meta_value AS name_en, pm_iso.meta_value AS iso2
             FROM {$wpdb->postmeta} pm_name
             JOIN {$wpdb->postmeta} pm_iso ON pm_iso.post_id = pm_name.post_id AND pm_iso.meta_key = 'wsp_iso_alpha2'
             JOIN {$wpdb->posts} p ON p.ID = pm_name.post_id
             WHERE pm_name.meta_key = 'wsp_name_short'
               AND p.post_type = %s AND p.post_status = 'publish'",
            'wsp_country'
        ) );

        foreach ( $en_rows as $r ) {
            if ( $r->name_en ) {
                $map[ $r->name_en ] = strtoupper( $r->iso2 );
            }
        }

        // Also add official names (EN/RU) if present.
        $official_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm_key.meta_key AS k, pm_key.meta_value AS v, pm_iso.meta_value AS iso2
             FROM {$wpdb->postmeta} pm_key
             JOIN {$wpdb->postmeta} pm_iso ON pm_iso.post_id = pm_key.post_id AND pm_iso.meta_key = 'wsp_iso_alpha2'
             JOIN {$wpdb->posts} p ON p.ID = pm_key.post_id
             WHERE pm_key.meta_key IN ('wsp_name_official','wsp_name_short_ru','wsp_name_official_ru')
               AND p.post_type = %s AND p.post_status = 'publish'",
            'wsp_country'
        ) );
        foreach ( $official_rows as $r ) {
            $name = trim( (string) ( $r->v ?? '' ) );
            $iso2 = strtoupper( (string) ( $r->iso2 ?? '' ) );
            if ( $name && $iso2 ) {
                $map[ $name ] = $iso2;
            }
        }

        return $map;
    }

    /**
     * Build ISO3 => ISO2 map from WSP data.
     */
    private function build_country_iso3_to_iso2_map(): array {
        global $wpdb;
        $map = [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm_iso2.meta_value AS iso2, pm_iso3.meta_value AS iso3
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_iso2 ON pm_iso2.post_id = p.ID AND pm_iso2.meta_key = 'wsp_iso_alpha2'
             JOIN {$wpdb->postmeta} pm_iso3 ON pm_iso3.post_id = p.ID AND pm_iso3.meta_key = 'wsp_iso_alpha3'
             WHERE p.post_type = %s AND p.post_status = 'publish'",
            'wsp_country'
        ) );

        foreach ( $rows as $r ) {
            $iso2 = strtoupper( (string) ( $r->iso2 ?? '' ) );
            $iso3 = strtoupper( (string) ( $r->iso3 ?? '' ) );
            if ( $iso2 && $iso3 ) {
                $map[ $iso3 ] = $iso2;
            }
        }

        return $map;
    }

    /**
     * @return string[]
     */
    private function country_name_variants_for_match( string $name ): array {
        $variants = [ trim( $name ) ];
        if ( str_contains( $name, ',' ) ) {
            $parts = array_map( 'trim', explode( ',', $name ) );
            foreach ( $parts as $part ) {
                if ( $part !== '' ) {
                    $variants[] = $part;
                }
            }
        }
        $no_the_suffix = preg_replace( '/,\s*the$/iu', '', $name );
        if ( is_string( $no_the_suffix ) && trim( $no_the_suffix ) !== '' ) {
            $variants[] = trim( $no_the_suffix );
        }
        $no_the_prefix = preg_replace( '/^the\s+/iu', '', $name );
        if ( is_string( $no_the_prefix ) && trim( $no_the_prefix ) !== '' ) {
            $variants[] = trim( $no_the_prefix );
        }

        return array_values( array_unique( array_filter( $variants ) ) );
    }

    private function normalize_country_name_for_match( string $name ): string {
        $v = mb_strtolower( trim( $name ), 'UTF-8' );
        if ( $v === '' ) {
            return '';
        }
        if ( function_exists( 'remove_accents' ) ) {
            $v = remove_accents( $v );
        }
        $v = preg_replace( '/[.\'"`´’]/u', '', $v );
        $v = preg_replace( '/\s+/', ' ', $v );
        return trim( (string) $v );
    }

    /**
     * @param array<string, string> $iso_map
     */
    private function fuzzy_match_country_iso2( string $country_name, array $iso_map ): ?string {
        $target = $this->normalize_country_name_for_match( $country_name );
        if ( $target === '' ) {
            return null;
        }

        $best_iso2 = null;
        $best_pct  = 0.0;

        foreach ( $iso_map as $name => $iso2 ) {
            $candidate = $this->normalize_country_name_for_match( $name );
            if ( $candidate === '' ) {
                continue;
            }
            similar_text( $target, $candidate, $pct );
            if ( $pct > $best_pct ) {
                $best_pct  = $pct;
                $best_iso2 = $iso2;
            }
        }

        return $best_pct >= 88.0 ? $best_iso2 : null;
    }

    /**
     * @param array<string, mixed> $resolutions
     */
    private function should_apply_meta_value( int $post_id, string $meta_key, array $resolutions ): bool {
        if ( empty( $resolutions['cities'] ) || ! is_array( $resolutions['cities'] ) ) {
            return true;
        }
        $city = $resolutions['cities'][ (string) $post_id ] ?? $resolutions['cities'][ $post_id ] ?? null;
        if ( ! is_array( $city ) ) {
            return true;
        }
        if ( ! array_key_exists( $meta_key, $city ) ) {
            return true;
        }

        return (string) $city[ $meta_key ] === 'replace';
    }

    /**
     * @param array<string, mixed> $resolutions
     */
    private function apply_meta_updates( int $post_id, array $meta, array $resolutions ): void {
        global $wpdb;

        foreach ( $meta as $k => $v ) {
            if ( ! $this->should_apply_meta_value( $post_id, $k, $resolutions ) ) {
                continue;
            }
            $exists_meta = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $post_id, $k
            ) );
            if ( $exists_meta ) {
                $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $v ], [ 'post_id' => $post_id, 'meta_key' => $k ] );
            } else {
                $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => $k, 'meta_value' => $v ] );
            }
        }
    }

    private function format_meta_for_display( $value ): string {
        if ( is_array( $value ) || is_object( $value ) ) {
            $value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
        }
        $s = trim( (string) $value );
        if ( strlen( $s ) > 120 ) {
            return substr( $s, 0, 117 ) . '…';
        }
        return $s === '' ? '—' : $s;
    }

    private function meta_values_differ( $old, $new, string $type = 'scalar' ): bool {
        if ( $type === 'json' ) {
            $a = is_string( $old ) ? json_decode( $old, true ) : $old;
            $b = is_string( $new ) ? json_decode( $new, true ) : $new;
            if ( ! is_array( $a ) ) {
                $a = [];
            }
            if ( ! is_array( $b ) ) {
                $b = [];
            }
            return wp_json_encode( $a ) !== wp_json_encode( $b );
        }

        if ( is_numeric( $old ) || is_numeric( $new ) ) {
            return abs( (float) $old - (float) $new ) > 0.0001;
        }

        return trim( (string) $old ) !== trim( (string) $new );
    }

    /**
     * @return array{conflicts: array<int, array>, truncated: bool, cities_checked: int}
     */
    private function scan_conflicts_main( string $file_path, int $max_conflicts ): array {
        $conflicts      = [];
        $cities_checked = 0;
        $truncated      = false;
        $labels         = self::meta_field_labels();

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) {
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $header1 = fgetcsv( $f, 0, $delimiter );
        $header2 = fgetcsv( $f, 0, $delimiter );
        $cols    = $this->detect_main_columns( is_array( $header1 ) ? $header1 : [], is_array( $header2 ) ? $header2 : [] );
        $maps    = $this->get_country_maps();
        $existing = $this->get_existing_cities();

        while ( ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $city_name    = trim( (string) ( $row[ $cols['city'] ] ?? '' ) );
            $country_name = trim( (string) ( $row[ $cols['country'] ] ?? '' ) );
            if ( ! $city_name || ! $country_name ) {
                continue;
            }

            $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            if ( ! $iso2 ) {
                continue;
            }

            $existing_id = $existing[ $city_name . '|' . $iso2 ] ?? null;
            if ( ! $existing_id ) {
                $existing_id = $this->find_city_by_name_flexible( $city_name, $iso2, $existing );
            }
            if ( ! $existing_id ) {
                continue;
            }

            $cities_checked++;
            $meta_new = $this->parse_row( $row, $iso2, $country_name, $cols );
            $diffs    = [];

            foreach ( $meta_new as $key => $new_val ) {
                $old_val = get_post_meta( $existing_id, $key, true );
                if ( $this->meta_values_differ( $old_val, $new_val ) ) {
                    $diffs[] = [
                        'key'   => $key,
                        'label' => $labels[ $key ] ?? $key,
                        'old'   => $this->format_meta_for_display( $old_val ),
                        'new'   => $this->format_meta_for_display( $new_val ),
                    ];
                }
            }

            if ( empty( $diffs ) ) {
                continue;
            }

            $conflicts[] = [
                'city_id'   => $existing_id,
                'city_name' => $city_name,
                'country'   => $country_name,
                'iso2'      => $iso2,
                'fields'    => $diffs,
            ];

            if ( count( $conflicts ) >= $max_conflicts ) {
                $truncated = true;
                break;
            }
        }

        fclose( $f );

        return [
            'conflicts'      => $conflicts,
            'truncated'      => $truncated,
            'cities_checked' => $cities_checked,
        ];
    }

    /**
     * @return array{conflicts: array<int, array>, truncated: bool, cities_checked: int}
     */
    private function scan_conflicts_br1( string $file_path, int $max_conflicts ): array {
        $conflicts      = [];
        $cities_checked = 0;
        $truncated      = false;

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) {
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        fgetcsv( $f, 0, $delimiter );
        $headers = fgetcsv( $f, 0, $delimiter );
        if ( ! is_array( $headers ) ) {
            fclose( $f );
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $idx_country = array_search( 'Geography', $headers, true );
        $idx_city    = array_search( 'Urban Area', $headers, true );
        if ( $idx_country === false || $idx_city === false ) {
            fclose( $f );
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $maps     = $this->get_country_maps();
        $existing = $this->get_existing_cities();

        while ( ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $city_name    = trim( (string) ( $row[ $idx_city ] ?? '' ) );
            $country_name = trim( (string) ( $row[ $idx_country ] ?? '' ) );
            if ( $city_name === '' || $country_name === '' ) {
                continue;
            }

            $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            if ( ! $iso2 ) {
                continue;
            }

            $existing_id = $existing[ $city_name . '|' . $iso2 ] ?? $this->find_city_by_name_flexible( $city_name, $iso2, $existing );
            if ( ! $existing_id ) {
                continue;
            }

            $cities_checked++;
            $diffs = [];

            $idx_pop      = array_search( 'Population Estimate', $headers, true );
            $idx_km2      = array_search( 'Square Kilometers', $headers, true );
            $idx_dens_km2 = array_search( 'Per Square Kilometer', $headers, true );

            $pop      = $idx_pop !== false ? $this->pi( (string) ( $row[ $idx_pop ] ?? '' ) ) : 0;
            $km2      = $idx_km2 !== false ? (float) $this->pn( (string) ( $row[ $idx_km2 ] ?? '' ) ) : 0.0;
            $ha       = $km2 > 0 ? $km2 * 100.0 : 0.0;
            $dens_km2 = $idx_dens_km2 !== false ? (float) $this->pn( (string) ( $row[ $idx_dens_km2 ] ?? '' ) ) : 0.0;
            $dens_ha  = $dens_km2 > 0 ? $dens_km2 / 100.0 : 0.0;

            $meta_new = [
                'wscity_country_name' => $country_name,
            ];
            if ( $pop > 0 ) {
                $meta_new['wscity_pop_t3'] = $pop;
            }
            if ( $ha > 0 ) {
                $meta_new['wscity_builtup_t3'] = $ha;
            }
            if ( $dens_ha > 0 ) {
                $meta_new['wscity_density_builtup'] = $dens_ha;
            }

            $labels = self::meta_field_labels();
            foreach ( $meta_new as $key => $new_val ) {
                $old_val = get_post_meta( $existing_id, $key, true );
                if ( $this->meta_values_differ( $old_val, $new_val ) ) {
                    $diffs[] = [
                        'key'   => $key,
                        'label' => $labels[ $key ] ?? $key,
                        'old'   => $this->format_meta_for_display( $old_val ),
                        'new'   => $this->format_meta_for_display( $new_val ),
                    ];
                }
            }

            $old_br = (string) get_post_meta( $existing_id, 'wscity_blocks_roads', true );
            $new_br = $this->preview_br1_blocks_json( $row );
            if ( $new_br !== '' && $this->meta_values_differ( $old_br, $new_br, 'json' ) ) {
                $diffs[] = [
                    'key'   => 'wscity_blocks_roads',
                    'label' => 'Кварталы и дороги (T1)',
                    'old'   => $this->format_meta_for_display( $old_br ),
                    'new'   => $this->format_meta_for_display( $new_br ),
                ];
            }

            if ( empty( $diffs ) ) {
                continue;
            }

            $conflicts[] = [
                'city_id'   => $existing_id,
                'city_name' => $city_name,
                'country'   => $country_name,
                'iso2'      => $iso2,
                'fields'    => $diffs,
            ];

            if ( count( $conflicts ) >= $max_conflicts ) {
                $truncated = true;
                break;
            }
        }

        fclose( $f );

        return [
            'conflicts'      => $conflicts,
            'truncated'      => $truncated,
            'cities_checked' => $cities_checked,
        ];
    }

    /**
     * GHS/WUP: несколько строк на город (по годам) — сравниваем итоговую историю населения.
     *
     * @return array{conflicts: array<int, array>, truncated: bool, cities_checked: int}
     */
    private function scan_conflicts_br2( string $file_path, int $max_conflicts ): array {
        $conflicts      = [];
        $cities_checked = 0;
        $truncated      = false;
        $labels         = self::meta_field_labels();

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) {
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $headers = fgetcsv( $f, 0, $delimiter );
        if ( ! is_array( $headers ) ) {
            fclose( $f );
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $idx_city    = array_search( 'UCname', $headers, true );
        $idx_country = array_search( 'UNLocName', $headers, true );
        $idx_year    = array_search( 'Year', $headers, true );
        $idx_pop     = array_search( 'POP', $headers, true );
        $idx_lat     = array_search( 'Lat', $headers, true );
        $idx_lon     = array_search( 'Lon', $headers, true );

        if ( $idx_city === false || $idx_country === false || $idx_year === false || $idx_pop === false ) {
            fclose( $f );
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $maps     = $this->get_country_maps();
        $existing = $this->get_existing_cities();
        $pending  = [];

        while ( ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $city_name    = trim( (string) ( $row[ $idx_city ] ?? '' ) );
            $country_name = trim( (string) ( $row[ $idx_country ] ?? '' ) );
            if ( $city_name === '' || $country_name === '' ) {
                continue;
            }

            $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            if ( ! $iso2 ) {
                continue;
            }

            $post_id = $existing[ $city_name . '|' . $iso2 ] ?? null;
            if ( ! $post_id ) {
                $post_id = $this->find_city_by_name_flexible( $city_name, $iso2, $existing );
            }
            if ( ! $post_id ) {
                continue;
            }

            $year = (int) trim( (string) ( $row[ $idx_year ] ?? '' ) );
            $pop  = $this->pi( (string) ( $row[ $idx_pop ] ?? '' ) );
            if ( ! $year || $pop <= 0 ) {
                continue;
            }

            $key = (string) $post_id;
            if ( ! isset( $pending[ $key ] ) ) {
                $pending[ $key ] = [
                    'city_id'   => $post_id,
                    'city_name' => $city_name,
                    'country'   => $country_name,
                    'iso2'      => $iso2,
                    'hist'      => [],
                    'max_year'  => 0,
                    'max_pop'   => 0,
                    'lat'       => 0.0,
                    'lng'       => 0.0,
                ];
            }

            $pending[ $key ]['hist'][ (string) $year ] = $pop;
            if ( $year >= $pending[ $key ]['max_year'] ) {
                $pending[ $key ]['max_year'] = $year;
                $pending[ $key ]['max_pop']  = $pop;
            }

            if ( $idx_lat !== false && $idx_lon !== false ) {
                $lat = (float) $this->pn( (string) ( $row[ $idx_lat ] ?? '' ) );
                $lon = (float) $this->pn( (string) ( $row[ $idx_lon ] ?? '' ) );
                if ( $lat && $lon ) {
                    $pending[ $key ]['lat'] = $lat;
                    $pending[ $key ]['lng'] = $lon;
                }
            }
        }
        fclose( $f );

        foreach ( $pending as $item ) {
            $cities_checked++;
            $post_id = (int) $item['city_id'];
            $diffs   = [];

            $old_hist_raw = get_post_meta( $post_id, 'wscity_pop_history', true );
            $old_hist     = $old_hist_raw ? json_decode( (string) $old_hist_raw, true ) : [];
            if ( ! is_array( $old_hist ) ) {
                $old_hist = [];
            }

            $new_hist = $old_hist;
            foreach ( $item['hist'] as $y => $p ) {
                $new_hist[ (string) $y ] = (int) $p;
            }
            ksort( $new_hist, SORT_NUMERIC );

            if ( $this->meta_values_differ( $old_hist, $new_hist, 'json' ) ) {
                $diffs[] = [
                    'key'   => 'wscity_pop_history',
                    'label' => $labels['wscity_pop_history'] ?? 'wscity_pop_history',
                    'old'   => $this->format_meta_for_display( $old_hist ),
                    'new'   => $this->format_meta_for_display( $new_hist ),
                ];
            }

            $old_pop_t3 = get_post_meta( $post_id, 'wscity_pop_t3', true );
            if ( $item['max_pop'] > 0 && $this->meta_values_differ( $old_pop_t3, $item['max_pop'] ) ) {
                $diffs[] = [
                    'key'   => 'wscity_pop_t3',
                    'label' => $labels['wscity_pop_t3'] ?? 'wscity_pop_t3',
                    'old'   => $this->format_meta_for_display( $old_pop_t3 ),
                    'new'   => $this->format_meta_for_display( $item['max_pop'] ),
                ];
            }

            $old_lat = (float) get_post_meta( $post_id, 'wscity_lat', true );
            if ( $old_lat == 0.0 && $item['lat'] && $item['lng'] ) {
                $diffs[] = [
                    'key'   => 'wscity_lat',
                    'label' => $labels['wscity_lat'] ?? 'wscity_lat',
                    'old'   => '—',
                    'new'   => $this->format_meta_for_display( $item['lat'] ),
                ];
                $diffs[] = [
                    'key'   => 'wscity_lng',
                    'label' => $labels['wscity_lng'] ?? 'wscity_lng',
                    'old'   => '—',
                    'new'   => $this->format_meta_for_display( $item['lng'] ),
                ];
            }

            $old_country = (string) get_post_meta( $post_id, 'wscity_country_name', true );
            if ( $this->meta_values_differ( $old_country, $item['country'] ) ) {
                $diffs[] = [
                    'key'   => 'wscity_country_name',
                    'label' => $labels['wscity_country_name'] ?? 'wscity_country_name',
                    'old'   => $this->format_meta_for_display( $old_country ),
                    'new'   => $this->format_meta_for_display( $item['country'] ),
                ];
            }

            if ( empty( $diffs ) ) {
                continue;
            }

            $conflicts[] = [
                'city_id'   => $post_id,
                'city_name' => $item['city_name'],
                'country'   => $item['country'],
                'iso2'      => $item['iso2'],
                'fields'    => $diffs,
            ];

            if ( count( $conflicts ) >= $max_conflicts ) {
                $truncated = true;
                break;
            }
        }

        return [
            'conflicts'      => $conflicts,
            'truncated'      => $truncated,
            'cities_checked' => $cities_checked,
        ];
    }

    /**
     * @return array{conflicts: array<int, array>, truncated: bool, cities_checked: int}
     */
    private function scan_conflicts_greenspace( string $file_path, int $max_conflicts ): array {
        $conflicts      = [];
        $cities_checked = 0;
        $truncated      = false;

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) {
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $row1 = fgetcsv( $f, 0, $delimiter );
        $row2 = fgetcsv( $f, 0, $delimiter );
        if ( ! is_array( $row1 ) ) {
            fclose( $f );
            return [ 'conflicts' => [], 'truncated' => false, 'cities_checked' => 0 ];
        }

        $headers = [];
        if ( $this->looks_like_header_row( $row1 ) ) {
            $headers = is_array( $row2 ) && $this->looks_like_header_row( $row2 ) ? $row2 : $row1;
        }

        $col_idx  = $headers ? $this->detect_city_country_columns( $headers ) : [ 'city' => 0, 'country' => 1 ];
        $maps     = $this->get_country_maps();
        $existing = $this->get_existing_cities();
        $stream   = $this->looks_like_header_row( $row1 ) ? [] : [ $row1 ];
        if ( is_array( $row2 ) && ! $this->looks_like_header_row( $row2 ) ) {
            $stream[] = $row2;
        }
        while ( ( $r = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $stream[] = $r;
        }
        fclose( $f );

        foreach ( $stream as $row ) {
            $city_name = trim( (string) ( $row[ $col_idx['city'] ] ?? '' ) );
            if ( $city_name === '' ) {
                continue;
            }

            $iso2 = null;
            if ( $col_idx['iso2'] !== null ) {
                $maybe = strtoupper( trim( (string) ( $row[ $col_idx['iso2'] ] ?? '' ) ) );
                if ( preg_match( '/^[A-Z]{2}$/', $maybe ) ) {
                    $iso2 = $maybe;
                }
            }
            $country_name = $col_idx['country'] !== null ? trim( (string) ( $row[ $col_idx['country'] ] ?? '' ) ) : '';
            if ( ! $iso2 && $country_name ) {
                $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            }
            if ( ! $iso2 ) {
                continue;
            }

            $post_id = $existing[ $city_name . '|' . $iso2 ] ?? $this->find_city_by_name_flexible( $city_name, $iso2, $existing );
            if ( ! $post_id ) {
                continue;
            }

            $cities_checked++;
            $old_json = (string) get_post_meta( $post_id, 'wscity_green_metrics', true );
            $new_json = $this->preview_greenspace_json( $row, $headers, $col_idx );
            if ( $new_json === '' || ! $this->meta_values_differ( $old_json, $new_json, 'json' ) ) {
                continue;
            }

            $conflicts[] = [
                'city_id'   => $post_id,
                'city_name' => $city_name,
                'country'   => $country_name,
                'iso2'      => $iso2,
                'fields'    => [ [
                    'key'   => 'wscity_green_metrics',
                    'label' => 'Greenspace',
                    'old'   => $this->format_meta_for_display( $old_json ),
                    'new'   => $this->format_meta_for_display( $new_json ),
                ] ],
            ];

            if ( count( $conflicts ) >= $max_conflicts ) {
                $truncated = true;
                break;
            }
        }

        return [
            'conflicts'      => $conflicts,
            'truncated'      => $truncated,
            'cities_checked' => $cities_checked,
        ];
    }

    /**
     * Build blocks_roads JSON from db-worldua row without saving (for conflict scan).
     */
    private function preview_br1_blocks_json( array $row ): string {
        return $this->encode_blocks_roads_from_br1_row( $row ) ?? '';
    }

    /**
     * Build greenspace JSON from row without saving.
     *
     * @param array<int, string> $headers
     * @param array<string, int|null> $col_idx
     */
    private function preview_greenspace_json( array $row, array $headers, array $col_idx ): string {
        $green_metrics = [];
        $max_col = $headers ? count( $headers ) : count( $row );

        for ( $col = 0; $col < $max_col; $col++ ) {
            if ( $col === $col_idx['city'] ) {
                continue;
            }
            if ( $col_idx['country'] !== null && $col === $col_idx['country'] ) {
                continue;
            }
            if ( $col_idx['iso2'] !== null && $col === $col_idx['iso2'] ) {
                continue;
            }
            if ( $col_idx['iso3'] !== null && $col === $col_idx['iso3'] ) {
                continue;
            }

            $h = $headers ? trim( (string) ( $headers[ $col ] ?? '' ) ) : '';
            if ( $h === '' ) {
                $h = 'col_' . $col;
            }

            $norm = $this->normalize_any_header_key( $h );
            $val  = $this->pn_or_null( (string) ( $row[ $col ] ?? '' ) );
            if ( $val === null ) {
                continue;
            }
            if ( $norm === '' ) {
                $norm = 'greenspace_col_' . $col;
            }
            $unit = $this->infer_unit_from_header( $h );
            $green_metrics[ $norm ] = [ 'value' => (float) $val, 'unit' => $unit, 'label' => $h ];
        }

        return empty( $green_metrics ) ? '' : wp_json_encode( $green_metrics, JSON_UNESCAPED_UNICODE );
    }

    private function country_not_found_message( string $country_name, string $city_name, ?array $maps = null ): string {
        $msg = "Страна не найдена: $country_name ($city_name)";
        $hint = $this->suggest_country_match( $country_name, $maps );
        if ( $hint ) {
            $msg .= sprintf( '. Возможно: %s (%s, %.0f%%)', $hint['name'], $hint['iso2'], $hint['score'] ?? 0 );
        }
        return $msg;
    }

    private function looks_like_header_row( array $row ): bool {
        $hay = strtolower( implode( ' ', array_map( fn( $v ) => (string) $v, $row ) ) );
        return (bool) preg_match( '/\b(city|country|iso|name|город|страна)\b/u', $hay );
    }

    /**
     * Detect column indexes for city/country/iso2/iso3 using header cells.
     */
    private function detect_city_country_columns( array $headers ): array {
        $city = null; $country = null; $iso2 = null; $iso3 = null;

        foreach ( $headers as $i => $h ) {
            $hh = strtolower( trim( (string) $h ) );
            if ( $hh === '' ) continue;

            if ( $city === null && preg_match( '/\b(city|city_name|urban|место|город)\b/u', $hh ) ) {
                $city = (int) $i;
                continue;
            }
            if ( $iso2 === null && preg_match( '/\biso\s*2\b|\biso2\b|\balpha[-_ ]?2\b|\biso_alpha2\b/u', $hh ) ) {
                $iso2 = (int) $i;
                continue;
            }
            if ( $iso3 === null && preg_match( '/\biso\s*3\b|\biso3\b|\balpha[-_ ]?3\b|\biso_alpha3\b/u', $hh ) ) {
                $iso3 = (int) $i;
                continue;
            }
            if ( $country === null && preg_match( '/\b(country|country_name|страна)\b/u', $hh ) ) {
                $country = (int) $i;
                continue;
            }
        }

        // Fallbacks to legacy layout.
        if ( $city === null ) $city = 0;
        if ( $country === null && $iso2 === null && $iso3 === null ) $country = 1;

        return [ 'city' => $city, 'country' => $country, 'iso2' => $iso2, 'iso3' => $iso3 ];
    }

    /**
     * Build "name|ISO2" => post_id cache for existing cities.
     */
    private function get_existing_cities(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS iso2
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key = 'wscity_country_iso2'",
            WSCities_CPT::SLUG
        ) );

        $map = [];
        foreach ( $rows as $r ) {
            $map[ $r->post_title . '|' . strtoupper( $r->iso2 ) ] = (int) $r->ID;
        }
        return $map;
    }

    /* ═══════════════════════════════════════════════════════
       BLOCKS & ROADS TABLE 1 — 200 cities, 2 periods
       CSV: 52 columns, 2 header rows, city/country/region/lat/lng/dates + metrics
    ═══════════════════════════════════════════════════════ */

    /**
     * Phase 1 for Blocks & Roads Table 1: upload and count rows.
     */
    public function prepare_br1( string $tmp_path ): array {
        $upload_dir = wp_upload_dir();
        $dest = $upload_dir['basedir'] . '/wscities-import-br1.csv';

        if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
            if ( ! copy( $tmp_path, $dest ) ) {
                return [ 'error' => 'Не удалось сохранить файл.' ];
            }
        }

        $delimiter = $this->detect_csv_delimiter( $dest );
        $f = fopen( $dest, 'r' );
        if ( ! $f ) return [ 'error' => 'Не удалось открыть файл.' ];

        // db-worldua.csv has 2 header rows; the second contains real column names.
        $h1 = fgetcsv( $f, 0, $delimiter );
        $h2 = fgetcsv( $f, 0, $delimiter );
        $headers = is_array( $h2 ) ? $h2 : [];
        $idx_city = array_search( 'Urban Area', $headers, true );

        $count = 0;
        while ( ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $cell = $idx_city !== false ? trim( (string) ( $row[ $idx_city ] ?? '' ) ) : trim( (string) ( $row[2] ?? '' ) );
            if ( $cell !== '' ) $count++;
        }
        fclose( $f );

        return [ 'file' => $dest, 'total' => $count ];
    }

    /**
     * Phase 2 for Blocks & Roads Table 1: process a batch.
     */
    /**
     * @param array<string, mixed> $resolutions
     */
    public function process_batch_br1( string $file_path, int $offset, int $batch_size, bool $update, array $resolutions = [] ): array {
        global $wpdb;
        set_time_limit( 300 );

        $results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) return [ 'error' => 'Не удалось открыть файл.' ];

        // db-worldua.csv headers
        fgetcsv( $f, 0, $delimiter );
        $headers = fgetcsv( $f, 0, $delimiter );
        if ( ! is_array( $headers ) ) {
            fclose( $f );
            return [ 'error' => 'Некорректный заголовок db-worldua.' ];
        }

        $idx_country = array_search( 'Geography', $headers, true );
        $idx_city    = array_search( 'Urban Area', $headers, true );
        $idx_pop     = array_search( 'Population Estimate', $headers, true );
        $idx_km2     = array_search( 'Square Kilometers', $headers, true );
        $idx_dens_km2 = array_search( 'Per Square Kilometer', $headers, true );

        if ( $idx_country === false || $idx_city === false ) {
            fclose( $f );
            return [ 'error' => 'Не найдены колонки Geography/Urban Area в db-worldua.csv.' ];
        }

        // Skip to offset on non-empty city rows
        $skipped = 0;
        while ( $skipped < $offset && ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $city_cell = trim( (string) ( $row[ $idx_city ] ?? '' ) );
            if ( $city_cell === '' ) continue;
            $skipped++;
        }

        $maps     = $this->get_country_maps();
        $existing = $this->get_existing_cities();
        $now     = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', true );
        $uid     = get_current_user_id() ?: 1;

        $in_batch = 0;
        while ( $in_batch < $batch_size && ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $city_name    = trim( (string) ( $row[ $idx_city ] ?? '' ) );
            $country_name = trim( (string) ( $row[ $idx_country ] ?? '' ) );
            if ( $city_name === '' || $country_name === '' ) continue;
            $in_batch++;

            $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            if ( ! $iso2 ) {
                $results['errors'][] = $this->country_not_found_message( $country_name, $city_name, $maps ) . ' (db-worldua)';
                $results['skipped']++;
                continue;
            }

            $cache_key   = $city_name . '|' . $iso2;
            $existing_id = $existing[ $cache_key ] ?? $this->find_city_by_name_flexible( $city_name, $iso2, $existing );

            $pop = $idx_pop !== false ? $this->pi( (string) ( $row[ $idx_pop ] ?? '' ) ) : 0;
            $km2 = $idx_km2 !== false ? (float) $this->pn( (string) ( $row[ $idx_km2 ] ?? '' ) ) : 0.0;
            $ha  = $km2 > 0 ? $km2 * 100.0 : 0.0;
            $dens_km2 = $idx_dens_km2 !== false ? (float) $this->pn( (string) ( $row[ $idx_dens_km2 ] ?? '' ) ) : 0.0;
            $dens_ha  = $dens_km2 > 0 ? $dens_km2 / 100.0 : 0.0;

            $meta = [
                'wscity_country_iso2' => $iso2,
                'wscity_country_name' => $country_name,
            ];
            if ( $pop > 0 ) $meta['wscity_pop_t3'] = $pop;
            if ( $ha > 0 ) $meta['wscity_builtup_t3'] = $ha;
            if ( $dens_ha > 0 ) $meta['wscity_density_builtup'] = $dens_ha;

            if ( $existing_id && $update ) {
                $wpdb->update( $wpdb->posts, [
                    'post_modified'     => $now,
                    'post_modified_gmt' => $now_gmt,
                ], [ 'ID' => $existing_id ], [ '%s', '%s' ], [ '%d' ] );

                $this->apply_meta_updates( $existing_id, $meta, $resolutions );
                if ( $this->should_apply_meta_value( $existing_id, 'wscity_blocks_roads', $resolutions ) ) {
                    $this->save_br1_blocks_meta( $existing_id, $row );
                }
                $results['updated']++;
            } elseif ( $existing_id && ! $update ) {
                // Без «обновлять существующие» всё равно подставляем Blocks & Roads, если мета ещё пуста.
                $had_br = (string) get_post_meta( $existing_id, 'wscity_blocks_roads', true );
                if ( $had_br === '' && $this->save_br1_blocks_meta( $existing_id, $row ) ) {
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            } else {
                $wpdb->insert( $wpdb->posts, [
                    'post_author'       => $uid,
                    'post_date'         => $now,
                    'post_date_gmt'     => $now_gmt,
                    'post_content'      => '',
                    'post_title'        => $city_name,
                    'post_excerpt'      => '',
                    'post_status'       => 'publish',
                    'comment_status'    => 'closed',
                    'ping_status'       => 'closed',
                    'post_password'     => '',
                    'post_name'         => sanitize_title( $city_name . '-' . strtolower( $iso2 ) ),
                    'to_ping'           => '',
                    'pinged'            => '',
                    'post_modified'     => $now,
                    'post_modified_gmt' => $now_gmt,
                    'post_content_filtered' => '',
                    'post_parent'       => 0,
                    'guid'              => '',
                    'menu_order'        => 0,
                    'post_type'         => WSCities_CPT::SLUG,
                    'post_mime_type'    => '',
                    'comment_count'     => 0,
                ] );
                $post_id = (int) $wpdb->insert_id;
                if ( ! $post_id ) {
                    $results['errors'][] = "INSERT failed (db-worldua): $city_name";
                    $results['skipped']++;
                    continue;
                }
                $wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/?p=' . $post_id ) ], [ 'ID' => $post_id ] );
                foreach ( $meta as $k => $v ) update_post_meta( $post_id, $k, $v );
                $this->save_br1_blocks_meta( $post_id, $row );
                $existing[ $cache_key ] = $post_id;
                $results['imported']++;
            }
        }

        fclose( $f );
        wp_cache_flush();
        return $results;
    }

    /**
     * Detect CSV delimiter from first non-empty line.
     */
    private function detect_csv_delimiter( string $file_path ): string {
        $line = '';
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) return ',';
        while ( ( $line = fgets( $f ) ) !== false ) {
            $line = trim( (string) $line );
            if ( $line !== '' ) break;
        }
        fclose( $f );

        if ( $line === '' ) return ',';
        $line = preg_replace( '/^\xEF\xBB\xBF/', '', $line ); // UTF-8 BOM
        $candidates = [
            ';'  => substr_count( $line, ';' ),
            ','  => substr_count( $line, ',' ),
            "\t" => substr_count( $line, "\t" ),
            '|'  => substr_count( $line, '|' ),
        ];
        arsort( $candidates );
        $best = array_key_first( $candidates );
        $max  = (int) ( $candidates[ $best ] ?? 0 );
        return $max > 0 ? (string) $best : ',';
    }

    /**
     * Сохранить wscity_blocks_roads из строки db-worldua (≥52 колонок, см. parse_br1_row).
     */
    private function save_br1_blocks_meta( int $post_id, array $row ): bool {
        $json = $this->encode_blocks_roads_from_br1_row( $row );
        if ( ! $json ) {
            return false;
        }
        update_post_meta( $post_id, 'wscity_blocks_roads', $json );
        return true;
    }

    /**
     * @return non-falsy string JSON or null, если в строке нет блока метрик.
     */
    private function encode_blocks_roads_from_br1_row( array $row ): ?string {
        if ( count( $row ) < 52 ) {
            return null;
        }
        $data = $this->parse_br1_row( $row );
        return wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
    }

    /**
     * Parse one row from Blocks & Roads Table 1.
     *
     * Column mapping (0-indexed):
     *  0: City Name, 1: Country, 2: Region, 3: Lat, 4: Lng
     *  5-7: Land Cover Dates (T1, T2, T3)
     *  8-9:   Share of Built-up Area Occupied by Roads (Pre-1990, 1990-2015)
     *  10-11: Average Road Width (meters)
     *  12-13: Share of Roads Less Than 4m Wide
     *  14-15: Share of Roads More Than 16m Wide
     *  16-17: Density of All Arterial Roads (km/km2)
     *  18-19: Average Beeline Distance to All Arterial Roads (m)
     *  20-21: Share of Area within Walking Distance of All Arterial Roads
     *  22-23: Share of Area within Walking Distance of Wide Arterial Roads
     *  24-25: Average Block Size (ha)
     *  26-27: 3-Way Intersection Density (per km2)
     *  28-29: 4-Way Intersection Density (per km2)
     *  30-31: Share of Intersections that are 4-Way
     *  32-33: Walkability Ratio
     *  34-35: Share of Built-up Area That Is Residential
     *  36-37: Share of Residential Areas Laid Out Before Development
     *  38-39: Share of Residential Areas Not Laid Out Before Development
     *  40-41: Share of Built-up Area That Is Gridded
     *  42-43: Share of Residential Area in Informal Land Subdivisions
     *  44-45: Share of Residential Area in Formal Land Subdivisions
     *  46-47: Share of Residential Area in Housing Projects
     *  48-49: Average Plot Size in Informal Land Subdivisions
     *  50-51: Average Plot Size in Formal Land Subdivisions
     */
    private function parse_br1_row( array $r ): array {
        return [
            'dates'             => [ trim( $r[5] ?? '' ), trim( $r[6] ?? '' ), trim( $r[7] ?? '' ) ],
            'road_share'        => [ 'pre1990' => $this->pp( $r[8] ?? '' ),  'post1990' => $this->pp( $r[9] ?? '' ) ],
            'road_width'        => [ 'pre1990' => $this->pn( $r[10] ?? '' ), 'post1990' => $this->pn( $r[11] ?? '' ) ],
            'road_narrow'       => [ 'pre1990' => $this->pp( $r[12] ?? '' ), 'post1990' => $this->pp( $r[13] ?? '' ) ],
            'road_wide'         => [ 'pre1990' => $this->pp( $r[14] ?? '' ), 'post1990' => $this->pp( $r[15] ?? '' ) ],
            'arterial_density'  => [ 'pre1990' => $this->pn( $r[16] ?? '' ), 'post1990' => $this->pn( $r[17] ?? '' ) ],
            'arterial_distance' => [ 'pre1990' => $this->pn( $r[18] ?? '' ), 'post1990' => $this->pn( $r[19] ?? '' ) ],
            'walk_all'          => [ 'pre1990' => $this->pp( $r[20] ?? '' ), 'post1990' => $this->pp( $r[21] ?? '' ) ],
            'walk_wide'         => [ 'pre1990' => $this->pp( $r[22] ?? '' ), 'post1990' => $this->pp( $r[23] ?? '' ) ],
            'block_size'        => [ 'pre1990' => $this->pn( $r[24] ?? '' ), 'post1990' => $this->pn( $r[25] ?? '' ) ],
            'intersect_3way'    => [ 'pre1990' => $this->pn( $r[26] ?? '' ), 'post1990' => $this->pn( $r[27] ?? '' ) ],
            'intersect_4way'    => [ 'pre1990' => $this->pn( $r[28] ?? '' ), 'post1990' => $this->pn( $r[29] ?? '' ) ],
            'intersect_4way_share' => [ 'pre1990' => $this->pp( $r[30] ?? '' ), 'post1990' => $this->pp( $r[31] ?? '' ) ],
            'walkability'       => [ 'pre1990' => $this->pn( $r[32] ?? '' ), 'post1990' => $this->pn( $r[33] ?? '' ) ],
            'residential'       => [ 'pre1990' => $this->pp( $r[34] ?? '' ), 'post1990' => $this->pp( $r[35] ?? '' ) ],
            'laid_out'          => [ 'pre1990' => $this->pp( $r[36] ?? '' ), 'post1990' => $this->pp( $r[37] ?? '' ) ],
            'not_laid_out'      => [ 'pre1990' => $this->pp( $r[38] ?? '' ), 'post1990' => $this->pp( $r[39] ?? '' ) ],
            'gridded'           => [ 'pre1990' => $this->pp( $r[40] ?? '' ), 'post1990' => $this->pp( $r[41] ?? '' ) ],
            'informal'          => [ 'pre1990' => $this->pp( $r[42] ?? '' ), 'post1990' => $this->pp( $r[43] ?? '' ) ],
            'formal'            => [ 'pre1990' => $this->pp( $r[44] ?? '' ), 'post1990' => $this->pp( $r[45] ?? '' ) ],
            'housing_projects'  => [ 'pre1990' => $this->pp( $r[46] ?? '' ), 'post1990' => $this->pp( $r[47] ?? '' ) ],
            'plot_informal'     => [ 'pre1990' => $this->pn( $r[48] ?? '' ), 'post1990' => $this->pn( $r[49] ?? '' ) ],
            'plot_formal'       => [ 'pre1990' => $this->pn( $r[50] ?? '' ), 'post1990' => $this->pn( $r[51] ?? '' ) ],
        ];
    }

    /* ═══════════════════════════════════════════════════════
       BLOCKS & ROADS TABLE 2 — 30 cities, 5 historical periods
       CSV: 95+ columns, 2 header rows
    ═══════════════════════════════════════════════════════ */

    /**
     * Phase 1 for Blocks & Roads Table 2: upload and count rows.
     */
    public function prepare_br2( string $tmp_path ): array {
        $upload_dir = wp_upload_dir();
        $dest = $upload_dir['basedir'] . '/wscities-import-br2.csv';

        if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
            if ( ! copy( $tmp_path, $dest ) ) {
                return [ 'error' => 'Не удалось сохранить файл.' ];
            }
        }

        return $this->prepare_br2_existing( $dest );
    }

    /**
     * Prepare existing BR2 CSV already stored on disk.
     */
    public function prepare_br2_existing( string $file_path ): array {
        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) return [ 'error' => 'Не удалось открыть файл.' ];
        $headers = fgetcsv( $f, 0, $delimiter ); // GHS header
        if ( ! is_array( $headers ) ) {
            fclose( $f );
            return [ 'error' => 'Некорректный заголовок GHS/WUP файла.' ];
        }
        $idx_city = array_search( 'UCname', $headers, true );
        if ( $idx_city === false ) $idx_city = 2;

        $count = 0;
        while ( ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $cell = trim( (string) ( $row[ $idx_city ] ?? '' ) );
            if ( $cell !== '' ) $count++;
        }
        fclose( $f );

        return [ 'file' => $file_path, 'total' => $count ];
    }

    /**
     * Phase 2 for Blocks & Roads Table 2: process a batch.
     *
     * @param array<string, mixed> $resolutions
     */
    public function process_batch_br2( string $file_path, int $offset, int $batch_size, bool $update, array $resolutions = [] ): array {
        global $wpdb;
        set_time_limit( 300 );

        $results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) return [ 'error' => 'Не удалось открыть файл.' ];

        $headers = fgetcsv( $f, 0, $delimiter );
        if ( ! is_array( $headers ) ) {
            fclose( $f );
            return [ 'error' => 'Некорректный заголовок GHS/WUP файла.' ];
        }

        $idx_city    = array_search( 'UCname', $headers, true );
        $idx_country = array_search( 'UNLocName', $headers, true );
        $idx_year    = array_search( 'Year', $headers, true );
        $idx_pop     = array_search( 'POP', $headers, true );
        $idx_lat     = array_search( 'Lat', $headers, true );
        $idx_lon     = array_search( 'Lon', $headers, true );

        if ( $idx_city === false || $idx_country === false || $idx_year === false || $idx_pop === false ) {
            fclose( $f );
            return [ 'error' => 'Не найдены ключевые колонки UCname/UNLocName/Year/POP в GHS/WUP CSV.' ];
        }

        // Skip to offset on non-empty city rows
        $skipped = 0;
        while ( $skipped < $offset && ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $cell = trim( (string) ( $row[ $idx_city ] ?? '' ) );
            if ( $cell === '' ) continue;
            $skipped++;
        }

        $maps     = $this->get_country_maps();
        $existing = $this->get_existing_cities();
        $now     = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', true );
        $uid     = get_current_user_id() ?: 1;

        $in_batch = 0;
        while ( $in_batch < $batch_size && ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $city_name    = trim( (string) ( $row[ $idx_city ] ?? '' ) );
            $country_name = trim( (string) ( $row[ $idx_country ] ?? '' ) );
            if ( $city_name === '' || $country_name === '' ) continue;
            $in_batch++;

            $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            if ( ! $iso2 ) {
                $results['errors'][] = $this->country_not_found_message( $country_name, $city_name, $maps ) . ' (GHS/WUP)';
                $results['skipped']++;
                continue;
            }

            $cache_key   = $city_name . '|' . $iso2;
            $existing_id = $existing[ $cache_key ] ?? $this->find_city_by_name_flexible( $city_name, $iso2, $existing );

            $year = (int) trim( (string) ( $row[ $idx_year ] ?? '' ) );
            $pop  = $this->pi( (string) ( $row[ $idx_pop ] ?? '' ) );
            $lat  = $idx_lat !== false ? (float) $this->pn( (string) ( $row[ $idx_lat ] ?? '' ) ) : 0.0;
            $lon  = $idx_lon !== false ? (float) $this->pn( (string) ( $row[ $idx_lon ] ?? '' ) ) : 0.0;

            if ( ! $year || $pop <= 0 ) {
                $results['skipped']++;
                continue;
            }

            $meta_base = [
                'wscity_country_iso2' => $iso2,
                'wscity_country_name' => $country_name,
            ];

            $post_id = $existing_id;
            if ( ! $post_id ) {
                // create city if missing
                $wpdb->insert( $wpdb->posts, [
                    'post_author'       => $uid,
                    'post_date'         => $now,
                    'post_date_gmt'     => $now_gmt,
                    'post_content'      => '',
                    'post_title'        => $city_name,
                    'post_excerpt'      => '',
                    'post_status'       => 'publish',
                    'comment_status'    => 'closed',
                    'ping_status'       => 'closed',
                    'post_password'     => '',
                    'post_name'         => sanitize_title( $city_name . '-' . strtolower( $iso2 ) ),
                    'to_ping'           => '',
                    'pinged'            => '',
                    'post_modified'     => $now,
                    'post_modified_gmt' => $now_gmt,
                    'post_content_filtered' => '',
                    'post_parent'       => 0,
                    'guid'              => '',
                    'menu_order'        => 0,
                    'post_type'         => WSCities_CPT::SLUG,
                    'post_mime_type'    => '',
                    'comment_count'     => 0,
                ] );
                $post_id = (int) $wpdb->insert_id;
                if ( ! $post_id ) {
                    $results['errors'][] = "INSERT failed (GHS/WUP): $city_name";
                    $results['skipped']++;
                    continue;
                }
                $wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/?p=' . $post_id ) ], [ 'ID' => $post_id ] );
                foreach ( $meta_base as $k => $v ) update_post_meta( $post_id, $k, $v );
                $existing[ $cache_key ] = $post_id;
                $results['imported']++;
            } else {
                if ( $update ) {
                    $this->apply_meta_updates( $post_id, $meta_base, $resolutions );
                }
                $results['updated']++;
            }

            if ( $lat && $lon ) {
                $cur_lat = (float) get_post_meta( $post_id, 'wscity_lat', true );
                if ( $cur_lat == 0.0 ) {
                    if ( $this->should_apply_meta_value( $post_id, 'wscity_lat', $resolutions ) ) {
                        update_post_meta( $post_id, 'wscity_lat', $lat );
                    }
                    if ( $this->should_apply_meta_value( $post_id, 'wscity_lng', $resolutions ) ) {
                        update_post_meta( $post_id, 'wscity_lng', $lon );
                    }
                }
            }

            if ( $this->should_apply_meta_value( $post_id, 'wscity_pop_history', $resolutions ) ) {
                $hist_raw = get_post_meta( $post_id, 'wscity_pop_history', true );
                $hist     = $hist_raw ? json_decode( (string) $hist_raw, true ) : [];
                if ( ! is_array( $hist ) ) {
                    $hist = [];
                }
                $hist[ (string) $year ] = $pop;
                update_post_meta( $post_id, 'wscity_pop_history', wp_json_encode( $hist, JSON_UNESCAPED_UNICODE ) );
            }

            $max_year = (int) get_post_meta( $post_id, 'wscity_pop_year_max', true );
            if ( $year > $max_year ) {
                update_post_meta( $post_id, 'wscity_pop_year_max', $year );
                if ( $this->should_apply_meta_value( $post_id, 'wscity_pop_t3', $resolutions ) ) {
                    update_post_meta( $post_id, 'wscity_pop_t3', $pop );
                }
            }
        }

        fclose( $f );
        wp_cache_flush();
        return $results;
    }

    /**
     * Parse one row from Blocks & Roads Table 2.
     *
     * Column mapping:
     *  0-4:   City Name, Country, Region, Lat, Lng
     *  5-14:  Period dates (5 periods × 2: start, end)
     *  15-19: Density of All Arterial Roads (P1-P5)
     *  20-24: Average Beeline Distance to Arterial Roads
     *  25-29: Share of Area within Walking Distance
     *  30-34: Average Block Size (ha)
     *  35-39: 3-Way Intersection Density
     *  40-44: 4-Way Intersection Density
     *  45-49: Share of Intersections that are 4-Way
     *  50-54: Walkability Ratio
     *  55-59: Share of Built-up Area That Is Residential
     *  60-64: Share of Residential Areas Not Laid Out
     *  65-69: Share of Built-up Area That Is Gridded
     *  70-74: Share of Informal Land Subdivisions
     *  75-79: Share of Formal Land Subdivisions
     *  80-84: Share of Housing Projects
     *  85-89: Average Plot Size Informal
     *  90-94: Average Plot Size Formal
     */
    private function parse_br2_row( array $r ): array {
        // Parse 5 periods
        $periods = [];
        for ( $i = 0; $i < 5; $i++ ) {
            $start = trim( $r[ 5 + $i * 2 ] ?? '' );
            $end   = trim( $r[ 6 + $i * 2 ] ?? '' );
            $periods[] = [ 'start' => $start, 'end' => $end ];
        }

        // Parse metrics — each has 5 values (P1-P5)
        $metrics_map = [
            'arterial_density'  => [ 15, 'pn' ],
            'arterial_distance' => [ 20, 'pn' ],
            'walk_all'          => [ 25, 'pp' ],
            'block_size'        => [ 30, 'pn' ],
            'intersect_3way'    => [ 35, 'pn' ],
            'intersect_4way'    => [ 40, 'pn' ],
            'intersect_4way_share' => [ 45, 'pp' ],
            'walkability'       => [ 50, 'pn' ],
            'residential'       => [ 55, 'pp' ],
            'not_laid_out'      => [ 60, 'pp' ],
            'gridded'           => [ 65, 'pp' ],
            'informal'          => [ 70, 'pp' ],
            'formal'            => [ 75, 'pp' ],
            'housing_projects'  => [ 80, 'pp' ],
            'plot_informal'     => [ 85, 'pn' ],
            'plot_formal'       => [ 90, 'pn' ],
        ];

        $data = [ 'periods' => $periods ];

        foreach ( $metrics_map as $key => [ $start_col, $parser ] ) {
            $vals = [];
            for ( $i = 0; $i < 5; $i++ ) {
                $raw = trim( $r[ $start_col + $i ] ?? '' );
                $vals[] = $raw !== '' ? $this->$parser( $raw ) : null;
            }
            $data[ $key ] = $vals;
        }

        return $data;
    }

    /* ═══════════════════════════════════════════════════════
       HELPER: flexible city name matching
    ═══════════════════════════════════════════════════════ */

    /**
     * Try to find a city by flexible name matching.
     * Handles cases like "Beijing, Beijing" → "Beijing" or quoted names.
     */
    private function find_city_by_name_flexible( string $name, string $iso2, array $existing ): ?int {
        // Strip quotes
        $clean = trim( $name, '"' );
        $target_variants = $this->city_name_variants_for_match( $clean );
        $norm_target = $target_variants[0] ?? '';

        // Try exact match with cleaned name
        $key = $clean . '|' . $iso2;
        if ( isset( $existing[ $key ] ) ) return $existing[ $key ];

        // Try first part before comma (e.g. "Beijing, Beijing" → "Beijing")
        if ( str_contains( $clean, ',' ) ) {
            $first = trim( explode( ',', $clean )[0] );
            $key   = $first . '|' . $iso2;
            if ( isset( $existing[ $key ] ) ) return $existing[ $key ];
        }

        // Try partial match
        foreach ( $existing as $cache_key => $pid ) {
            [ $existing_name, $existing_iso ] = explode( '|', $cache_key, 2 );
            if ( $existing_iso !== $iso2 ) continue;
            $existing_variants = $this->city_name_variants_for_match( $existing_name );
            $norm_existing = $existing_variants[0] ?? '';

            if ( $norm_target !== '' && $norm_existing !== '' ) {
                // Exact variant intersection (e.g. "Lisboa (Lisbon)" vs "Lisbon",
                // "Derry/Londonderry" vs "Derry").
                $inter = array_intersect( $target_variants, $existing_variants );
                if ( ! empty( $inter ) ) {
                    return $pid;
                }
                if ( str_contains( $norm_existing, $norm_target ) || str_contains( $norm_target, $norm_existing ) ) {
                    return $pid;
                }
            }

            // Check if either name contains the other
            if ( stripos( $existing_name, $clean ) !== false || stripos( $clean, $existing_name ) !== false ) {
                return $pid;
            }
            // Check first word match
            $first_part = explode( ',', $clean )[0];
            if ( stripos( $existing_name, trim( $first_part ) ) !== false ) {
                return $pid;
            }
        }

        return null;
    }

    /**
     * Normalize city names for resilient matching between CSV variants.
     */
    private function normalize_city_name_for_match( string $name ): string {
        $v = mb_strtolower( trim( $name ), 'UTF-8' );
        if ( $v === '' ) return '';
        // Drop bracketed clarifications: "Moscow (city)" -> "Moscow"
        $v = preg_replace( '/\([^)]*\)/u', ' ', $v );
        $v = preg_replace( '/\[[^\]]*\]/u', ' ', $v );
        // Common English variants: "St."/"St" -> "saint"
        $v = preg_replace( '/\bst[.]?\b/u', 'saint', $v );
        // Keep letters/digits from any alphabet.
        $v = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $v );
        $v = preg_replace( '/\s+/u', ' ', $v );
        return trim( (string) $v );
    }

    /**
     * Build normalized name variants for resilient matching.
     *
     * Handles aliases often found in GHS/WUP source names:
     * - "Derry/Londonderry" => "derry", "londonderry"
     * - "Lisboa (Lisbon)"   => "lisboa", "lisbon"
     *
     * @return string[]
     */
    private function city_name_variants_for_match( string $name ): array {
        $parts = [ trim( $name ) ];

        if ( preg_match_all( '/\(([^)]{1,120})\)/u', $name, $m ) ) {
            foreach ( (array) ( $m[1] ?? [] ) as $inside ) {
                $inside = trim( (string) $inside );
                if ( $inside !== '' ) {
                    $parts[] = $inside;
                }
            }
        }

        $outside = preg_replace( '/\([^)]*\)/u', ' ', $name );
        if ( is_string( $outside ) ) {
            $outside = trim( $outside );
            if ( $outside !== '' ) {
                $parts[] = $outside;
            }
        }

        $expanded = [];
        foreach ( $parts as $p ) {
            $expanded[] = $p;
            if ( preg_match( '/\s*\/\s*/u', $p ) ) {
                foreach ( preg_split( '/\s*\/\s*/u', $p ) as $sp ) {
                    $sp = trim( (string) $sp );
                    if ( $sp !== '' ) {
                        $expanded[] = $sp;
                    }
                }
            }
        }

        $out = [];
        foreach ( $expanded as $cand ) {
            $norm = $this->normalize_city_name_for_match( $cand );
            if ( $norm !== '' ) {
                $out[ $norm ] = true;
            }
        }

        return array_keys( $out );
    }

    /** Parse percentage value (remove %, trim spaces). Returns float 0-100 or null. */
    private function pp( string $v ): ?float {
        $v = trim( $v );
        if ( $v === '' ) return null;
        $v = str_replace( [ ',', ' ', '%' ], '', $v );
        return $v !== '' ? (float) $v : null;
    }

    /* ═══════════════════════════════════════════════════════
       GREENSPEACE IMPORT
       Input: CSV equivalent of greenspace.xlsx
       Store: JSON map meta key `wscity_green_metrics`
       ──────────────────────────────────────────────────────── */

    /**
     * Phase 1: upload CSV (converted from XLSX) and count rows.
     */
    public function prepare_greenspace( string $tmp_path ): array {
        $upload_dir = wp_upload_dir();
        $dest = $upload_dir['basedir'] . '/wscities-import-greenspace.csv';

        if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
            if ( ! copy( $tmp_path, $dest ) ) {
                return [ 'error' => 'Не удалось сохранить файл greenspace.' ];
            }
        }

        $delimiter = $this->detect_csv_delimiter( $dest );
        $f = fopen( $dest, 'r' );
        if ( ! $f ) return [ 'error' => 'Не удалось открыть greenspace-файл.' ];

        $row1 = fgetcsv( $f, 0, $delimiter );
        if ( ! is_array( $row1 ) ) {
            fclose( $f );
            return [ 'error' => 'Пустой greenspace-файл.' ];
        }

        $row2 = fgetcsv( $f, 0, $delimiter );

        $has_header1 = $this->looks_like_header_row( $row1 );
        $has_header2 = is_array( $row2 ) && $row2 && $this->looks_like_header_row( $row2 );

        $headers = [];
        if ( $has_header1 ) {
            if ( $has_header2 ) {
                $max = max( count( $row1 ), count( $row2 ) );
                for ( $i = 0; $i < $max; $i++ ) {
                    $h1 = trim( (string) ( $row1[ $i ] ?? '' ) );
                    $h2 = trim( (string) ( $row2[ $i ] ?? '' ) );
                    $h  = trim( $h1 . ( $h1 && $h2 ? ' ' : '' ) . $h2 );
                    $headers[ $i ] = $h;
                }
            } else {
                $headers = $row1;
            }
        }

        $col_idx = $headers ? $this->detect_city_country_columns( $headers ) : [ 'city' => 0 ];
        $city_col = (int) ( $col_idx['city'] ?? 0 );

        $count = 0;
        if ( ! $has_header1 ) {
            // row1 is already data
            $cell = trim( (string) ( $row1[ $city_col ] ?? '' ) );
            if ( $cell !== '' ) $count++;
            // row2 might be data too
            if ( is_array( $row2 ) ) {
                $cell = trim( (string) ( $row2[ $city_col ] ?? '' ) );
                if ( $cell !== '' ) $count++;
            }
        } else {
            // if header1 and header2 looks like header, data starts after them; otherwise row2 is data
            if ( $has_header1 && ! $has_header2 && is_array( $row2 ) ) {
                $cell = trim( (string) ( $row2[ $city_col ] ?? '' ) );
                if ( $cell !== '' ) $count++;
            }
        }

        while ( ( $row = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $cell = trim( (string) ( $row[ $city_col ] ?? '' ) );
            if ( $cell !== '' ) $count++;
        }

        fclose( $f );

        return [
            'file'  => $dest,
            'total' => $count,
        ];
    }

    /**
     * Phase 2: process a batch from greenspace CSV.
     */
    /**
     * @param array<string, mixed> $resolutions
     */
    public function process_batch_greenspace( string $file_path, int $offset, int $batch_size, bool $update, array $resolutions = [] ): array {
        global $wpdb;
        set_time_limit( 300 );

        $results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $f = fopen( $file_path, 'r' );
        if ( ! $f ) return [ 'error' => 'Не удалось открыть greenspace-файл.' ];

        // Read first two rows and decide where headers end and data starts.
        $row1 = fgetcsv( $f, 0, $delimiter );
        $row2 = fgetcsv( $f, 0, $delimiter );
        if ( ! is_array( $row1 ) ) return [ 'error' => 'Пустой greenspace-файл.' ];

        $headers = [];
        $buffered_data_rows = [];

        $has_header1 = $this->looks_like_header_row( $row1 );
        $has_header2 = is_array( $row2 ) && $row2 && $this->looks_like_header_row( $row2 );

        if ( $has_header1 ) {
            if ( $has_header2 ) {
                $max = max( count( $row1 ), count( $row2 ) );
                for ( $i = 0; $i < $max; $i++ ) {
                    $h1 = trim( (string) ( $row1[ $i ] ?? '' ) );
                    $h2 = trim( (string) ( $row2[ $i ] ?? '' ) );
                    $h  = trim( $h1 . ( $h1 && $h2 ? ' ' : '' ) . $h2 );
                    $headers[ $i ] = $h;
                }
            } else {
                $headers = $row1;
                if ( is_array( $row2 ) ) $buffered_data_rows[] = $row2;
            }
        } else {
            // No header: treat both as data (and use generic headers by column number).
            $buffered_data_rows[] = $row1;
            if ( is_array( $row2 ) ) $buffered_data_rows[] = $row2;
        }

        $col_idx = $headers
            ? $this->detect_city_country_columns( $headers )
            : [ 'city' => 0, 'country' => 1, 'iso2' => null, 'iso3' => null ];

        $maps         = $this->get_country_maps();
        $iso3_to_iso2 = $maps['iso3_to_iso2'];
        $existing     = $this->get_existing_cities();

        // Build a flat stream: buffered rows first, then file rows.
        $stream = $buffered_data_rows;
        while ( ( $r = fgetcsv( $f, 0, $delimiter ) ) !== false ) {
            $stream[] = $r;
        }

        // Apply offset on non-empty "city" rows.
        $seen = 0;
        $processed = 0;

        foreach ( $stream as $row ) {
            $city_name = trim( (string) ( $row[ $col_idx['city'] ] ?? '' ) );
            if ( $city_name === '' ) continue;

            if ( $seen < $offset ) {
                $seen++;
                continue;
            }

            if ( $processed >= $batch_size ) break;
            $processed++;

            // Resolve ISO2 in multiple ways: ISO2/ISO3 columns, or country name.
            $iso2 = null;
            if ( $col_idx['iso2'] !== null ) {
                $maybe = strtoupper( trim( (string) ( $row[ $col_idx['iso2'] ] ?? '' ) ) );
                if ( preg_match( '/^[A-Z]{2}$/', $maybe ) ) $iso2 = $maybe;
            }
            if ( ! $iso2 && $col_idx['iso3'] !== null ) {
                $maybe3 = strtoupper( trim( (string) ( $row[ $col_idx['iso3'] ] ?? '' ) ) );
                if ( preg_match( '/^[A-Z]{3}$/', $maybe3 ) ) $iso2 = $iso3_to_iso2[ $maybe3 ] ?? null;
            }

            $country_name = $col_idx['country'] !== null ? trim( (string) ( $row[ $col_idx['country'] ] ?? '' ) ) : '';
            if ( ! $iso2 && $country_name ) {
                $iso2 = $this->resolve_country_iso2( $country_name, $maps );
            }

            if ( ! $iso2 ) {
                $results['errors'][] = $this->country_not_found_message( $country_name ?: '—', $city_name, $maps ) . ' (greenspace)';
                $results['skipped']++;
                continue;
            }

            $cache_key = $city_name . '|' . $iso2;
            $post_id   = $existing[ $cache_key ] ?? null;
            if ( ! $post_id ) {
                $post_id = $this->find_city_by_name_flexible( $city_name, $iso2, $existing );
            }
            if ( ! $post_id ) {
                $results['skipped']++;
                continue;
            }

            $existing_json = get_post_meta( $post_id, 'wscity_green_metrics', true );
            if ( $existing_json && ! $update ) {
                $results['skipped']++;
                continue;
            }

            $green_metrics = [];
            $max_col = $headers ? count( $headers ) : count( $row );
            for ( $col = 0; $col < $max_col; $col++ ) {
                // Skip identity columns (city/country/iso2/iso3)
                if ( $col === $col_idx['city'] ) continue;
                if ( $col_idx['country'] !== null && $col === $col_idx['country'] ) continue;
                if ( $col_idx['iso2'] !== null && $col === $col_idx['iso2'] ) continue;
                if ( $col_idx['iso3'] !== null && $col === $col_idx['iso3'] ) continue;

                $h = $headers ? trim( (string) ( $headers[ $col ] ?? '' ) ) : '';
                if ( $h === '' ) $h = 'col_' . $col;

                // For greenspace.csv we keep ALL columns by their header names.
                $norm = $this->normalize_any_header_key( $h );
                $raw = (string) ( $row[ $col ] ?? '' );
                $val = $this->pn_or_null( $raw );
                if ( $val === null ) continue;

                if ( $norm === '' ) $norm = 'greenspace_col_' . $col;
                $unit = $this->infer_unit_from_header( $h );
                $green_metrics[ $norm ] = [ 'value' => (float) $val, 'unit' => $unit, 'label' => $h ];
            }

            if ( $this->should_apply_meta_value( $post_id, 'wscity_green_metrics', $resolutions ) ) {
                update_post_meta( $post_id, 'wscity_green_metrics', wp_json_encode( $green_metrics, JSON_UNESCAPED_UNICODE ) );
            }
            if ( $existing_json ) {
                $results['updated']++;
            } else {
                $results['imported']++;
            }
        }

        fclose( $f );
        wp_cache_flush();

        return $results;
    }

    private function normalize_green_header_key( string $header ): string {
        $h = strtolower( trim( $header ) );
        // We only take columns that look green-related; otherwise ignore.
        if ( ! ( str_contains( $h, 'green' ) || str_contains( $h, 'greenspace' ) || str_contains( $h, 'park' ) || str_contains( $h, 'tree' ) ) ) {
            // Still allow keys if file author used Russian words.
            if ( ! ( str_contains( $h, 'зел' ) || str_contains( $h, 'пар' ) || str_contains( $h, 'дерев' ) || str_contains( $h, 'озелен' ) ) ) {
                return '';
            }
        }

        $h = preg_replace( '/[^a-z0-9%]+/u', '_', $h );
        $h = trim( $h, '_' );
        return $h;
    }

    /**
     * Normalize any header into a stable metric key (no semantic filtering).
     */
    private function normalize_any_header_key( string $header ): string {
        $h = strtolower( trim( $header ) );
        $h = preg_replace( '/[^a-z0-9]+/u', '_', $h );
        $h = trim( $h, '_' );
        return $h;
    }

    private function infer_unit_from_header( string $header ): string {
        $h = strtolower( $header );
        if ( str_contains( $h, '%' ) || str_contains( $h, 'percent' ) || str_contains( $h, 'процент' ) ) return '%';
        if ( str_contains( $h, 'ha' ) || str_contains( $h, 'г' ) ) return 'ha';
        if ( str_contains( $h, 'm2' ) || str_contains( $h, 'км2' ) ) return 'area';
        return '';
    }

    private function pn_or_null( string $v ): ?float {
        $v = trim( $v );
        if ( $v === '' ) return null;
        $v = str_replace( [ ',', ' ', '%' ], '', $v );
        if ( $v === '' ) return null;
        if ( ! is_numeric( $v ) ) return null;
        return (float) $v;
    }

    /**
     * Delete all imported cities (batch).
     */
    public static function delete_all(): int {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            WSCities_CPT::SLUG
        ) );

        if ( empty( $ids ) ) return 0;

        $id_list = implode( ',', array_map( 'intval', $ids ) );
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($id_list)" );
        $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = '" . WSCities_CPT::SLUG . "'" );

        return count( $ids );
    }

    /**
     * Merge duplicated city posts by normalized name + country ISO2.
     *
     * Keeps the oldest post in each duplicate group and moves missing meta from duplicates.
     *
     * @return array{groups:int,deleted:int,examples:array}
     */
    public static function merge_duplicates(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS iso2
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wscity_country_iso2'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             ORDER BY p.ID ASC",
            WSCities_CPT::SLUG
        ) );

        if ( empty( $rows ) ) {
            return [ 'groups' => 0, 'deleted' => 0, 'examples' => [] ];
        }

        $by_variant = [];
        $all_ids    = [];
        foreach ( $rows as $r ) {
            $iso2 = strtoupper( (string) ( $r->iso2 ?? '' ) );
            if ( $iso2 === '' ) {
                continue;
            }
            $id = (int) $r->ID;
            if ( $id <= 0 ) {
                continue;
            }
            $all_ids[ $id ] = true;
            $variants = self::city_name_variants_static( (string) $r->post_title );
            foreach ( $variants as $norm ) {
                $key = $iso2 . '|' . $norm;
                if ( ! isset( $by_variant[ $key ] ) ) {
                    $by_variant[ $key ] = [];
                }
                $by_variant[ $key ][] = $id;
            }
        }

        $parent = [];
        foreach ( array_keys( $all_ids ) as $id ) {
            $parent[ $id ] = $id;
        }
        $find = function( int $x ) use ( &$parent, &$find ): int {
            if ( $parent[ $x ] !== $x ) {
                $parent[ $x ] = $find( $parent[ $x ] );
            }
            return $parent[ $x ];
        };
        $union = function( int $a, int $b ) use ( &$parent, $find ): void {
            $ra = $find( $a );
            $rb = $find( $b );
            if ( $ra !== $rb ) {
                $parent[ $rb ] = $ra;
            }
        };

        foreach ( $by_variant as $ids ) {
            $ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
            if ( count( $ids ) < 2 ) {
                continue;
            }
            $anchor = (int) $ids[0];
            for ( $i = 1, $n = count( $ids ); $i < $n; $i++ ) {
                $union( $anchor, (int) $ids[ $i ] );
            }
        }

        $groups = [];
        foreach ( array_keys( $all_ids ) as $id ) {
            $root = $find( (int) $id );
            if ( ! isset( $groups[ $root ] ) ) {
                $groups[ $root ] = [];
            }
            $groups[ $root ][] = (int) $id;
        }

        $merged_groups = 0;
        $deleted_posts = 0;
        $examples = [];

        foreach ( $groups as $root => $ids ) {
            if ( count( $ids ) < 2 ) continue;

            sort( $ids, SORT_NUMERIC );
            $keeper_id = (int) array_shift( $ids );
            $dup_ids   = array_map( 'intval', $ids );
            if ( empty( $dup_ids ) ) continue;

            self::merge_meta_into_keeper( $keeper_id, $dup_ids );

            foreach ( $dup_ids as $dup_id ) {
                wp_delete_post( $dup_id, true );
                $deleted_posts++;
            }

            $merged_groups++;
            if ( count( $examples ) < 10 ) {
                $examples[] = [
                    'keeper' => $keeper_id,
                    'deleted' => $dup_ids,
                    'key' => (string) $root,
                ];
            }
        }

        wp_cache_flush();
        return [
            'groups' => $merged_groups,
            'deleted' => $deleted_posts,
            'examples' => $examples,
        ];
    }

    /**
     * Copy missing meta values from duplicates into keeper.
     */
    private static function merge_meta_into_keeper( int $keeper_id, array $dup_ids ): void {
        foreach ( $dup_ids as $dup_id ) {
            $all_meta = get_post_meta( $dup_id );
            if ( ! is_array( $all_meta ) ) continue;

            foreach ( $all_meta as $meta_key => $meta_values ) {
                if ( $meta_key === '' ) continue;
                $dup_val = is_array( $meta_values ) ? (string) ( $meta_values[0] ?? '' ) : (string) $meta_values;
                if ( $dup_val === '' ) continue;

                $keeper_val = (string) get_post_meta( $keeper_id, $meta_key, true );
                if ( $keeper_val === '' ) {
                    update_post_meta( $keeper_id, $meta_key, $dup_val );
                    continue;
                }

                // Prefer longer JSON payloads when both sides have JSON.
                $dup_is_json    = self::is_json_string( $dup_val );
                $keeper_is_json = self::is_json_string( $keeper_val );
                if ( $dup_is_json && $keeper_is_json && strlen( $dup_val ) > strlen( $keeper_val ) ) {
                    update_post_meta( $keeper_id, $meta_key, $dup_val );
                }
            }
        }
    }

    private static function is_json_string( string $value ): bool {
        $v = trim( $value );
        if ( $v === '' ) return false;
        if ( ( $v[0] !== '{' && $v[0] !== '[' ) ) return false;
        json_decode( $v, true );
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Static variant of city-name normalization for dedupe routines.
     */
    private static function normalize_city_name_static( string $name ): string {
        $v = mb_strtolower( trim( $name ), 'UTF-8' );
        if ( $v === '' ) return '';
        $v = preg_replace( '/\([^)]*\)/u', ' ', $v );
        $v = preg_replace( '/\[[^\]]*\]/u', ' ', $v );
        $v = preg_replace( '/\bst[.]?\b/u', 'saint', $v );
        $v = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $v );
        $v = preg_replace( '/\s+/u', ' ', $v );
        return trim( (string) $v );
    }

    /**
     * Static variant builder used by dedupe routine.
     *
     * @return string[]
     */
    private static function city_name_variants_static( string $name ): array {
        $parts = [ trim( $name ) ];

        if ( preg_match_all( '/\(([^)]{1,120})\)/u', $name, $m ) ) {
            foreach ( (array) ( $m[1] ?? [] ) as $inside ) {
                $inside = trim( (string) $inside );
                if ( $inside !== '' ) {
                    $parts[] = $inside;
                }
            }
        }

        $outside = preg_replace( '/\([^)]*\)/u', ' ', $name );
        if ( is_string( $outside ) ) {
            $outside = trim( $outside );
            if ( $outside !== '' ) {
                $parts[] = $outside;
            }
        }

        $expanded = [];
        foreach ( $parts as $p ) {
            $expanded[] = $p;
            if ( preg_match( '/\s*\/\s*/u', $p ) ) {
                foreach ( preg_split( '/\s*\/\s*/u', $p ) as $sp ) {
                    $sp = trim( (string) $sp );
                    if ( $sp !== '' ) {
                        $expanded[] = $sp;
                    }
                }
            }
        }

        $out = [];
        foreach ( $expanded as $cand ) {
            $norm = self::normalize_city_name_static( $cand );
            if ( $norm !== '' ) {
                $out[ $norm ] = true;
            }
        }

        return array_keys( $out );
    }
}
