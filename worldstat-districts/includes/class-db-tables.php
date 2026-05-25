<?php
/**
 * Database tables for ML analysis
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_DB_Tables {
    
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Air quality data table
        $table_air = $wpdb->prefix . 'district_air_quality';
        $sql_air = "CREATE TABLE IF NOT EXISTS {$table_air} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            district_id bigint(20) NOT NULL,
            pollutant varchar(20) NOT NULL,
            value float NOT NULL,
            period varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY district_id (district_id)
        ) {$charset_collate};";
        
        // Crime data table
        $table_crime = $wpdb->prefix . 'district_crime_data';
        $sql_crime = "CREATE TABLE IF NOT EXISTS {$table_crime} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            district_id bigint(20) NOT NULL,
            crime_type varchar(50) NOT NULL,
            count int NOT NULL DEFAULT 0,
            rate float NOT NULL DEFAULT 0,
            period varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY district_id (district_id),
            KEY crime_type (crime_type)
        ) {$charset_collate};";
        
        // Pedestrian mobility data table
        $table_pedestrian = $wpdb->prefix . 'district_pedestrian_data';
        $sql_pedestrian = "CREATE TABLE IF NOT EXISTS {$table_pedestrian} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            district_id bigint(20) NOT NULL,
            street_name varchar(255) NOT NULL,
            category varchar(100) NOT NULL,
            rank int NOT NULL DEFAULT 0,
            segment_id varchar(50) DEFAULT '',
            borough varchar(50) NOT NULL,
            nta_code varchar(20) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY district_id (district_id),
            KEY category (category),
            KEY rank (rank)
        ) {$charset_collate};";
        
        // ML results table
        $table_results = $wpdb->prefix . 'district_ml_results';
        $sql_results = "CREATE TABLE IF NOT EXISTS {$table_results} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            district_id bigint(20) NOT NULL,
            cluster int DEFAULT 0,
            classification varchar(50) DEFAULT '',
            comfort_score float DEFAULT 0,
            safety_score float DEFAULT 0,
            functionality_score float DEFAULT 0,
            walkability_score float DEFAULT 0,
            pedestrian_demand_score float DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY district_id (district_id)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_air );
        dbDelta( $sql_crime );
        dbDelta( $sql_pedestrian );
        dbDelta( $sql_results );
    }
    
    public static function drop_tables(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}district_air_quality" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}district_crime_data" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}district_pedestrian_data" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}district_ml_results" );
    }
}