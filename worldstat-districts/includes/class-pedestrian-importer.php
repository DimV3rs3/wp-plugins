<?php
/**
 * Pedestrian Mobility Data Importer for NYC Districts
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSPedestrian_Importer {

    /**
     * Import pedestrian mobility data from CSV
     */
    public static function import_from_csv( string $csv_path ): array {
        if ( ! file_exists( $csv_path ) ) {
            return [ 'error' => 'CSV file not found' ];
        }

        $handle = fopen( $csv_path, 'r' );
        if ( ! $handle ) {
            return [ 'error' => 'Could not open CSV file' ];
        }

        // Read header
        $headers = fgetcsv( $handle );
        
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        // Get district map
        $district_map = self::get_district_map();
        
        error_log('=== PEDESTRIAN IMPORT DEBUG ===');
        error_log('District map: ' . print_r($district_map, true));

        // Find column indices
        $boro_idx = array_search('BoroName', $headers);
        $street_idx = array_search('street', $headers);
        $rank_idx = array_search('Rank', $headers);
        $category_idx = array_search('category', $headers);
        $segment_idx = array_search('segmentid', $headers);
        $nta_idx = array_search('nta2020', $headers);
        
        error_log("Headers: " . print_r($headers, true));
        error_log("Boro index: $boro_idx, Street index: $street_idx, Rank index: $rank_idx");

        $row_count = 0;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_count++;
            
            // The NYC pedestrian file contains 6 columns:
            // BoroName, street, Rank, category, segmentid, nta2020.
            if ( count( $row ) < 4 ) {
                $skipped++;
                continue;
            }
            
            // Extract data
            $borough = trim( $row[$boro_idx] ?? '' );
            $street_name = trim( $row[$street_idx] ?? '' );
            $rank = intval( $row[$rank_idx] ?? 0 );
            $category = trim( $row[$category_idx] ?? '' );
            $segment_id = trim( (string) ( $row[$segment_idx] ?? '' ) );
            $nta_code = trim( (string) ( $row[$nta_idx] ?? '' ) );
            
            // Debug первых 10 строк
            if ($row_count <= 10) {
                error_log("Row $row_count: borough='$borough', street='$street_name', rank=$rank");
            }
            
            if ( empty( $borough ) || empty( $street_name ) ) {
                error_log("Row $row_count: SKIPPED - empty borough or street");
                $skipped++;
                continue;
            }
            
            // Find district ID by borough name
            $district_id = self::find_district_id( $borough, $district_map );
            
            if ( ! $district_id ) {
                $errors[] = "District not found: $borough";
                error_log("Row $row_count: District NOT found for borough: '$borough'");
                $skipped++;
                continue;
            }
            
            error_log("Row $row_count: Found district_id=$district_id for borough='$borough'");
            
            // Save to database
            $result = self::save_pedestrian_data( $district_id, $street_name, $category, $rank, $segment_id, $borough, $nta_code );
            
            if ( $result === 'inserted' ) {
                $imported++;
                error_log("Row $row_count: INSERTED successfully");
            } elseif ( $result === 'updated' ) {
                $updated++;
                error_log("Row $row_count: UPDATED successfully");
            } else {
                $skipped++;
                error_log("Row $row_count: SAVE FAILED");
            }
        }
        
        fclose( $handle );
        
        error_log("IMPORT SUMMARY: imported=$imported, updated=$updated, skipped=$skipped, total_rows=$row_count");
        
        // Run pedestrian mobility analysis if any data was imported
        $analysis = [];
        if ( $imported > 0 ) {
            $analysis = self::run_pedestrian_analysis();
        }
        
        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice( $errors, 0, 20 ),
            'analysis' => $analysis,
        ];
    }
    
    /**
     * Find district ID by borough name
     */
    private static function find_district_id( string $borough, array $district_map ): ?int {
        $borough = trim($borough);
        
        error_log("Looking for borough: '$borough'");
        
        // Прямое соответствие
        if ( isset( $district_map[$borough] ) ) {
            error_log("Direct match found: $borough -> {$district_map[$borough]}");
            return $district_map[$borough];
        }
        
        // Соответствие без учета регистра
        foreach ( $district_map as $name => $id ) {
            if ( strcasecmp( $name, $borough ) === 0 ) {
                error_log("Case-insensitive match: $borough -> $id");
                return $id;
            }
        }
        
        // Маппинг возможных вариантов написания
        $mapping = [
            'Manhattan' => ['Manhattan', 'NEW YORK', 'New York County', 'MANHATTAN', 'manhattan', 'NYC - Manhattan'],
            'Brooklyn' => ['Brooklyn', 'Kings County', 'BROOKLYN', 'brooklyn', 'KINGS', 'NYC - Brooklyn'],
            'Queens' => ['Queens', 'Queens County', 'QUEENS', 'queens', 'QUEEN', 'NYC - Queens'],
            'Bronx' => ['Bronx', 'Bronx County', 'BRONX', 'bronx', 'THE BRONX', 'NYC - Bronx'],
            'Staten Island' => ['Staten Island', 'Richmond County', 'STATEN ISLAND', 'staten island', 'RICHMOND', 'NYC - Staten Island']
        ];
        
        foreach ($mapping as $district_name => $aliases) {
            if ( in_array($borough, $aliases) || in_array(strtolower($borough), array_map('strtolower', $aliases)) ) {
                if ( isset($district_map[$district_name]) ) {
                    error_log("Alias match: $borough -> $district_name -> {$district_map[$district_name]}");
                    return $district_map[$district_name];
                }
                // Пробуем найти по ID из других таблиц
                $fallback_id = self::get_fallback_district_id($district_name);
                if ($fallback_id) {
                    error_log("Fallback match: $borough -> $district_name -> $fallback_id");
                    return $fallback_id;
                }
            }
        }
        
        return null;
    }
    
    private static function get_fallback_district_id( string $district_name ): ?int {
        global $wpdb;
        
        // Ищем район по названию в других таблицах
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT district_id FROM {$wpdb->prefix}district_air_quality 
             WHERE district_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s)
             LIMIT 1",
            '%' . $wpdb->esc_like($district_name) . '%'
        ) );
        
        if ($id) {
            return (int) $id;
        }
        
        // Ищем в постах
        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wsp_district' AND post_title LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($district_name) . '%'
        ) );
        
        return $post_id ? (int) $post_id : null;
    }
    
    private static function save_pedestrian_data( int $district_id, string $street_name, string $category, int $rank, string $segment_id, string $borough, string $nta_code ): string {
        global $wpdb;
        
        $table = $wpdb->prefix . 'district_pedestrian_data';
        
        // Проверяем существование таблицы
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            error_log("Table $table does not exist!");
            return 'error';
        }
        
        $wpdb->insert(
            $table,
            [
                'district_id' => $district_id,
                'street_name' => $street_name,
                'category' => $category,
                'rank' => $rank,
                'segment_id' => $segment_id ?: uniqid(),
                'borough' => $borough,
                'nta_code' => $nta_code,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );
        
        if ($wpdb->insert_id) {
            return 'inserted';
        }
        
        error_log("Database insert error: " . $wpdb->last_error);
        return 'error';
    }
    
    private static function get_district_map(): array {
        global $wpdb;
        
        // Сначала пытаемся получить районы из постов
        $districts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            WSDistricts_CPT::SLUG
        ) );
        
        $map = [];
        foreach ( $districts as $d ) {
            $map[ $d->post_title ] = (int) $d->ID;
            $map[ strtolower( $d->post_title ) ] = (int) $d->ID;
        }
        
        // Если районов нет, создаем их
        if (empty($map)) {
            error_log("No districts found, creating default districts...");
            $default_districts = ['Manhattan', 'Brooklyn', 'Queens', 'Bronx', 'Staten Island'];
            foreach ($default_districts as $district) {
                $post_id = wp_insert_post([
                    'post_title' => $district,
                    'post_type' => WSDistricts_CPT::SLUG,
                    'post_status' => 'publish',
                    'post_author' => 1,
                    'post_content' => "$district is a borough of New York City."
                ]);
                if ($post_id && !is_wp_error($post_id)) {
                    $map[$district] = $post_id;
                    $map[strtolower($district)] = $post_id;
                    error_log("Created district: $district (ID: $post_id)");
                }
            }
        }
        
        return $map;
    }
    
    /**
     * Run pedestrian mobility analysis
     */
    public static function run_pedestrian_analysis(): array {
        global $wpdb;
        
        $table_pedestrian = $wpdb->prefix . 'district_pedestrian_data';
        $table_results = $wpdb->prefix . 'district_ml_results';
        
        // Проверяем наличие таблицы
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_pedestrian'");
        if (!$table_exists) {
            return [ 'updated' => 0, 'message' => 'Таблица пешеходных данных не найдена' ];
        }
        
        $pedestrian_stats = $wpdb->get_results( "
            SELECT district_id,
                   COUNT(*) as total_streets,
                   AVG(rank) as avg_rank,
                   SUM(CASE WHEN rank <= 2 THEN 1 ELSE 0 END) as high_demand_streets,
                   SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as critical_streets
            FROM {$table_pedestrian}
            GROUP BY district_id
        " );
        
        if ( empty( $pedestrian_stats ) ) {
            return [ 'updated' => 0, 'message' => 'Нет данных о пешеходной мобильности' ];
        }
        
        $all_ranks = [];
        foreach ( $pedestrian_stats as $stat ) {
            $all_ranks[] = $stat->avg_rank;
        }
        
        $mean_rank = array_sum( $all_ranks ) / count( $all_ranks );
        $std_dev = sqrt( array_sum( array_map( function($x) use ($mean_rank) {
            return pow($x - $mean_rank, 2);
        }, $all_ranks ) ) / count( $all_ranks ) );
        
        $updated = 0;
        
        foreach ( $pedestrian_stats as $stat ) {
            $avg_rank = floatval( $stat->avg_rank );
            $high_demand_pct = $stat->total_streets > 0 ? ($stat->high_demand_streets / $stat->total_streets) * 100 : 0;
            $critical_pct = $stat->total_streets > 0 ? ($stat->critical_streets / $stat->total_streets) * 100 : 0;
            
            if ( $std_dev > 0 ) {
                $z_score = ($avg_rank - $mean_rank) / $std_dev;
                $walkability_score = max(0, min(100, 100 - (($z_score + 2) / 4 * 100)));
            } else {
                $walkability_score = 50;
            }
            
            $pedestrian_demand_score = round( ($high_demand_pct * 0.6) + ((100 - $critical_pct) * 0.4), 1 );
            
            update_post_meta( $stat->district_id, 'wsdistrict_walkability_score', round($walkability_score, 1) );
            update_post_meta( $stat->district_id, 'wsdistrict_pedestrian_demand_score', $pedestrian_demand_score );
            update_post_meta( $stat->district_id, 'wsdistrict_pedestrian_streets_count', $stat->total_streets );
            update_post_meta( $stat->district_id, 'wsdistrict_high_demand_streets', $stat->high_demand_streets );
            update_post_meta( $stat->district_id, 'wsdistrict_critical_streets', $stat->critical_streets );
            
            $updated++;
        }
        
        return [
            'updated' => $updated,
            'message' => "Анализ пешеходной мобильности завершен. Обновлено $updated районов.\n" .
                         "Средний уровень пешеходного трафика: " . round($mean_rank, 2),
            'statistics' => [
                'mean_rank' => round($mean_rank, 2),
                'std_deviation' => round($std_dev, 2),
                'total_districts' => count($pedestrian_stats)
            ]
        ];
    }
    
    public static function delete_all(): int {
        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}district_pedestrian_data" );
        return intval( $deleted );
    }
}