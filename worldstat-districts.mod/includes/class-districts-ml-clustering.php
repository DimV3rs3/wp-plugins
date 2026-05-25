<?php
/**
 * ML Clustering and Classification for Districts
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_ML_Clustering {
    
    /**
     * Get historical data for NYC districts (2010-2023)
     */
    public static function get_historical_data(): array {
        // Исторические данные по Нью-Йорку
        $historical = [
            ['year' => 2010, 'crime_rate' => 28.5, 'population' => 8175133, 'area' => 783800, 'density' => 10430, 'walkability' => 72],
            ['year' => 2011, 'crime_rate' => 27.8, 'population' => 8191029, 'area' => 783800, 'density' => 10450, 'walkability' => 73],
            ['year' => 2012, 'crime_rate' => 26.9, 'population' => 8235876, 'area' => 783800, 'density' => 10510, 'walkability' => 74],
            ['year' => 2013, 'crime_rate' => 25.7, 'population' => 8286738, 'area' => 783800, 'density' => 10570, 'walkability' => 75],
            ['year' => 2014, 'crime_rate' => 24.2, 'population' => 8336697, 'area' => 783800, 'density' => 10640, 'walkability' => 76],
            ['year' => 2015, 'crime_rate' => 23.1, 'population' => 8398748, 'area' => 783800, 'density' => 10720, 'walkability' => 77],
            ['year' => 2016, 'crime_rate' => 22.0, 'population' => 8454538, 'area' => 783800, 'density' => 10790, 'walkability' => 78],
            ['year' => 2017, 'crime_rate' => 20.8, 'population' => 8518542, 'area' => 783800, 'density' => 10870, 'walkability' => 79],
            ['year' => 2018, 'crime_rate' => 19.5, 'population' => 8398748, 'area' => 783800, 'density' => 10720, 'walkability' => 80],
            ['year' => 2019, 'crime_rate' => 18.7, 'population' => 8336817, 'area' => 783800, 'density' => 10640, 'walkability' => 81],
            ['year' => 2020, 'crime_rate' => 21.2, 'population' => 8358972, 'area' => 783800, 'density' => 10670, 'walkability' => 78],
            ['year' => 2021, 'crime_rate' => 22.4, 'population' => 8422360, 'area' => 783800, 'density' => 10750, 'walkability' => 79],
            ['year' => 2022, 'crime_rate' => 21.8, 'population' => 8466489, 'area' => 783800, 'density' => 10800, 'walkability' => 80],
            ['year' => 2023, 'crime_rate' => 20.5, 'population' => 8511978, 'area' => 783800, 'density' => 10860, 'walkability' => 81],
        ];
        
        // Добавляем данные по районам Нью-Йорка
        $boroughs = [
            ['name' => 'Manhattan', 'area' => 5914, 'population' => 1694260, 'density' => 28650, 'crime_rate' => 28.5, 'walkability' => 95, 'green_percentage' => 28],
            ['name' => 'Brooklyn', 'area' => 18340, 'population' => 2736074, 'density' => 14920, 'crime_rate' => 22.3, 'walkability' => 82, 'green_percentage' => 22],
            ['name' => 'Queens', 'area' => 28300, 'population' => 2405464, 'density' => 8500, 'crime_rate' => 18.7, 'walkability' => 75, 'green_percentage' => 25],
            ['name' => 'Bronx', 'area' => 10940, 'population' => 1472654, 'density' => 13460, 'crime_rate' => 31.2, 'walkability' => 68, 'green_percentage' => 20],
            ['name' => 'Staten Island', 'area' => 15150, 'population' => 495747, 'density' => 3270, 'crime_rate' => 12.5, 'walkability' => 55, 'green_percentage' => 35],
        ];
        
        foreach ($boroughs as $borough) {
            $historical[] = array_merge(['year' => 2023], $borough);
        }
        
        return $historical;
    }
    
    /**
     * Get all districts data for ML analysis
     */
    public static function get_all_districts_data(): array {
        global $wpdb;
        
        $districts = $wpdb->get_results("
            SELECT p.ID, p.post_title as name,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_area' THEN pm.meta_value END) as area,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_population' THEN pm.meta_value END) as population,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_density' THEN pm.meta_value END) as density,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_comfort_score' THEN pm.meta_value END) as comfort,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_safety_score' THEN pm.meta_value END) as safety,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_functionality_score' THEN pm.meta_value END) as functionality,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_walkability_score' THEN pm.meta_value END) as walkability,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_crime_level' THEN pm.meta_value END) as crime_level,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_crime_rate' THEN pm.meta_value END) as crime_rate,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_green_percentage' THEN pm.meta_value END) as green_percentage,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_air_quality_class' THEN pm.meta_value END) as air_quality_class
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'wsp_district' AND p.post_status = 'publish'
            GROUP BY p.ID, p.post_title
        ");
        
        $data = [];
        foreach ($districts as $d) {
            $data[] = [
                'id' => (int) $d->ID,
                'name' => $d->name,
                'area' => (float) $d->area ?: 0,
                'population' => (int) $d->population ?: 0,
                'density' => (float) $d->density ?: 0,
                'comfort' => (float) $d->comfort ?: 50,
                'safety' => (float) $d->safety ?: 50,
                'functionality' => (float) $d->functionality ?: 50,
                'walkability' => (float) $d->walkability ?: 50,
                'crime_rate' => (float) $d->crime_rate ?: 15,
                'crime_level' => $d->crime_level ?: 'Medium',
                'green_percentage' => (float) $d->green_percentage ?: 25,
                'air_quality_class' => $d->air_quality_class ?: 'Moderate'
            ];
        }
        
        return $data;
    }
    
    /**
     * AJAX handler for getting all districts data
     */
    public static function ajax_get_all_districts_data() {
        check_ajax_referer('wsdistricts_ml', 'nonce');
        
        if (!current_user_can('read')) {
            wp_send_json_error('Access denied');
        }
        
        $data = self::get_all_districts_data();
        wp_send_json_success($data);
    }
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_wsdistricts_get_all_districts_data', [__CLASS__, 'ajax_get_all_districts_data']);
        add_action('wp_ajax_nopriv_wsdistricts_get_all_districts_data', [__CLASS__, 'ajax_get_all_districts_data']);
    }
}

WSDistricts_ML_Clustering::init();