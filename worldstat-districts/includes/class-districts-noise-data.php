<?php
/**
 * Data access layer for Noise Records linked to Districts
 * Слой доступа к данным для шумовых записей, связанных с районами
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Noise_Data {
    
    /**
     * Get noise records for a specific district
     * Получить шумовые записи для конкретного района
     */
    public static function get_for_district( int $district_id ): array {
        $args = [
            'post_type'      => WSDistricts_Noise_CPT::SLUG,
            'posts_per_page' => -1,
            'post_parent'    => $district_id,
            'orderby'        => 'meta_value',
            'meta_key'       => 'wsnoise_effective_date',
            'order'          => 'DESC',
        ];
        
        $query = new WP_Query( $args );
        return $query->posts;
    }
    
    /**
     * Get statistics for a district
     * Получить статистику для района
     */
    public static function get_stats_for_district( int $district_id ): array {
        global $wpdb;
        
        $stats = $wpdb->get_row( $wpdb->prepare( "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN pm_noise.meta_value = '1' THEN 1 ELSE 0 END) as has_noise,
                SUM(CASE WHEN pm_air.meta_value = '1' THEN 1 ELSE 0 END) as has_air,
                SUM(CASE WHEN pm_hazmat.meta_value = '1' THEN 1 ELSE 0 END) as has_hazmat,
                SUM(CASE WHEN pm_any.meta_value = '1' THEN 1 ELSE 0 END) as has_any
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_noise ON pm_noise.post_id = p.ID AND pm_noise.meta_key = 'wsnoise_has_noise_restriction'
            LEFT JOIN {$wpdb->postmeta} pm_air ON pm_air.post_id = p.ID AND pm_air.meta_key = 'wsnoise_has_air_restriction'
            LEFT JOIN {$wpdb->postmeta} pm_hazmat ON pm_hazmat.post_id = p.ID AND pm_hazmat.meta_key = 'wsnoise_has_hazmat_restriction'
            LEFT JOIN {$wpdb->postmeta} pm_any ON pm_any.post_id = p.ID AND pm_any.meta_key = 'wsnoise_has_any_restriction'
            WHERE p.post_type = %s 
            AND p.post_parent = %d
            AND p.post_status = 'publish'
        ", WSDistricts_Noise_CPT::SLUG, $district_id ) );
        
        $total = (int) ( $stats->total ?? 0 );
        $noise_count = (int) ( $stats->has_noise ?? 0 );
        
        return [
            'total' => $total,
            'has_noise' => $noise_count,
            'has_air' => (int) ( $stats->has_air ?? 0 ),
            'has_hazmat' => (int) ( $stats->has_hazmat ?? 0 ),
            'has_any' => (int) ( $stats->has_any ?? 0 ),
            'noise_percentage' => $total > 0 ? round( $noise_count / $total * 100, 2 ) : 0,
        ];
    }
    
    /**
     * Get noise records by BBL
     * Получить шумовые записи по BBL
     */
    public static function get_by_bbl( string $bbl ): array {
        $args = [
            'post_type'      => WSDistricts_Noise_CPT::SLUG,
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => 'wsnoise_bbl',
                    'value' => $bbl,
                ],
            ],
        ];
        
        $query = new WP_Query( $args );
        return $query->posts;
    }
    
    /**
     * Get noise records by borough
     * Получить шумовые записи по району города
     */
    public static function get_by_borough( string $borough ): array {
        $args = [
            'post_type'      => WSDistricts_Noise_CPT::SLUG,
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => 'wsnoise_borough',
                    'value' => $borough,
                ],
            ],
        ];
        
        $query = new WP_Query( $args );
        return $query->posts;
    }
    
    /**
     * Get statistics by borough
     * Получить статистику по районам города
     */
    public static function get_stats_by_borough(): array {
        global $wpdb;
        
        $results = $wpdb->get_results( "
            SELECT 
                pm_borough.meta_value as borough,
                COUNT(*) as total,
                SUM(CASE WHEN pm_noise.meta_value = '1' THEN 1 ELSE 0 END) as noise_count,
                SUM(CASE WHEN pm_air.meta_value = '1' THEN 1 ELSE 0 END) as air_count,
                SUM(CASE WHEN pm_hazmat.meta_value = '1' THEN 1 ELSE 0 END) as hazmat_count
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm_borough ON pm_borough.post_id = p.ID AND pm_borough.meta_key = 'wsnoise_borough'
            LEFT JOIN {$wpdb->postmeta} pm_noise ON pm_noise.post_id = p.ID AND pm_noise.meta_key = 'wsnoise_has_noise_restriction'
            LEFT JOIN {$wpdb->postmeta} pm_air ON pm_air.post_id = p.ID AND pm_air.meta_key = 'wsnoise_has_air_restriction'
            LEFT JOIN {$wpdb->postmeta} pm_hazmat ON pm_hazmat.post_id = p.ID AND pm_hazmat.meta_key = 'wsnoise_has_hazmat_restriction'
            WHERE p.post_type = '" . WSDistricts_Noise_CPT::SLUG . "'
            AND p.post_status = 'publish'
            GROUP BY pm_borough.meta_value
        " );
        
        return $results;
    }
    
    /**
     * Get noise records with date filtering
     * Получить шумовые записи с фильтрацией по дате
     */
    public static function get_by_date_range( string $start_date, string $end_date ): array {
        $args = [
            'post_type'      => WSDistricts_Noise_CPT::SLUG,
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'wsnoise_effective_date',
                    'value'   => [ $start_date, $end_date ],
                    'type'    => 'DATE',
                    'compare' => 'BETWEEN',
                ],
            ],
        ];
        
        $query = new WP_Query( $args );
        return $query->posts;
    }
    
    /**
     * Get districts with noise restrictions
     * Получить районы с шумовыми ограничениями
     */
    public static function get_districts_with_noise(): array {
        global $wpdb;
        
        $district_ids = $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT p.post_parent
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsnoise_has_noise_restriction'
            WHERE p.post_type = %s 
            AND p.post_status = 'publish'
            AND pm.meta_value = '1'
        ", WSDistricts_Noise_CPT::SLUG ) );
        
        return array_map( 'intval', $district_ids );
    }
    
    /**
     * Get noise restriction summary for dashboard
     * Получить сводку по шумовым ограничениям для дашборда
     */
    public static function get_summary(): array {
        $stats = self::get_stats_by_borough();
        
        $summary = [
            'total_records' => 0,
            'total_noise' => 0,
            'total_air' => 0,
            'total_hazmat' => 0,
            'boroughs' => [],
        ];
        
        foreach ( $stats as $stat ) {
            $summary['total_records'] += (int) $stat->total;
            $summary['total_noise'] += (int) $stat->noise_count;
            $summary['total_air'] += (int) $stat->air_count;
            $summary['total_hazmat'] += (int) $stat->hazmat_count;
            $summary['boroughs'][ $stat->borough ] = [
                'total' => (int) $stat->total,
                'noise' => (int) $stat->noise_count,
                'air' => (int) $stat->air_count,
                'hazmat' => (int) $stat->hazmat_count,
                'noise_percentage' => $stat->total > 0 ? round( $stat->noise_count / $stat->total * 100, 1 ) : 0,
            ];
        }
        
        return $summary;
    }
}