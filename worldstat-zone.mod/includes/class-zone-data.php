<?php
/**
 * Data provider for Zones extension
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_Data {

    /**
     * Get total number of zones globally
     */
    public static function get_total_zones(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            WSZ_CPT::SLUG
        ) );
    }

    /**
     * Get global average ergonomics score
     */
    public static function get_global_avg_ergonomics(): float {
        global $wpdb;
        
        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(pm.meta_value) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_ergonomics'
             WHERE p.post_type = %s AND p.post_status = 'publish'",
            WSZ_CPT::SLUG
        ) );
        
        return $avg ? round( (float) $avg, 1 ) : 0;
    }

    /**
     * Get global average comfort score
     */
    public static function get_global_avg_comfort(): float {
        global $wpdb;
        
        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(pm.meta_value) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_comfort'
             WHERE p.post_type = %s AND p.post_status = 'publish'",
            WSZ_CPT::SLUG
        ) );
        
        return $avg ? round( (float) $avg, 1 ) : 0;
    }

    /**
     * Get global average safety score
     */
    public static function get_global_avg_safety(): float {
        global $wpdb;
        
        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(pm.meta_value) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_safety'
             WHERE p.post_type = %s AND p.post_status = 'publish'",
            WSZ_CPT::SLUG
        ) );
        
        return $avg ? round( (float) $avg, 1 ) : 0;
    }

    /**
     * Get global map data for zones
     */
    public static function get_global_map_data(): array {
        global $wpdb;
        
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title as name,
                pm_lat.meta_value as lat,
                pm_lng.meta_value as lng,
                pm_ergonomics.meta_value as ergonomics,
                pm_lighting.meta_value as lighting,
                pm_safety.meta_value as safety,
                pm_comfort.meta_value as comfort,
                pm_city.meta_value as city_name,
                pm_country.meta_value as country_name,
                pm_type.meta_value as zone_type
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = 'wsz_lat'
             JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = 'wsz_lng'
             LEFT JOIN {$wpdb->postmeta} pm_ergonomics ON pm_ergonomics.post_id = p.ID AND pm_ergonomics.meta_key = 'wsz_ergonomics'
             LEFT JOIN {$wpdb->postmeta} pm_lighting ON pm_lighting.post_id = p.ID AND pm_lighting.meta_key = 'wsz_lighting'
             LEFT JOIN {$wpdb->postmeta} pm_safety ON pm_safety.post_id = p.ID AND pm_safety.meta_key = 'wsz_safety'
             LEFT JOIN {$wpdb->postmeta} pm_comfort ON pm_comfort.post_id = p.ID AND pm_comfort.meta_key = 'wsz_comfort'
             LEFT JOIN {$wpdb->postmeta} pm_city ON pm_city.post_id = p.ID AND pm_city.meta_key = 'wsz_city_name'
             LEFT JOIN {$wpdb->postmeta} pm_country ON pm_country.post_id = p.ID AND pm_country.meta_key = 'wsz_country_name'
             LEFT JOIN {$wpdb->postmeta} pm_type ON pm_type.post_id = p.ID AND pm_type.meta_key = 'wsz_zone_type'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm_lat.meta_value != '' AND pm_lng.meta_value != ''",
            WSZ_CPT::SLUG
        ) );
        
        $data = [];
        foreach ( $rows as $r ) {
            $types = [
                'bedroom' => 'Спальная',
                'working' => 'Рабочая',
                'kitchen' => 'Кухня',
                'living'  => 'Гостиная',
                'bathroom'=> 'Ванная',
                'other'   => 'Другая'
            ];
            
            $data[] = [
                'id'          => (int) $r->ID,
                'name'        => $r->name,
                'lat'         => (float) $r->lat,
                'lng'         => (float) $r->lng,
                'ergonomics'  => (float) $r->ergonomics,
                'lighting'    => (float) $r->lighting,
                'safety'      => (float) $r->safety,
                'comfort'     => (float) $r->comfort,
                'zone_type'   => $types[ $r->zone_type ] ?? $r->zone_type,
                'city'        => $r->city_name,
                'country'     => $r->country_name,
                'url'         => get_permalink( $r->ID ),
            ];
        }
        
        return $data;
    }

    /**
     * Get number of zones for a city
     */
    public static function get_zones_count_for_city( ?int $city_id ): int {
        if ( ! $city_id ) return 0;
        
        global $wpdb;
        
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_city_id'
             WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = %d",
            WSZ_CPT::SLUG,
            $city_id
        ) );
    }

    /**
     * Get average ergonomics score for city zones
     */
    public static function get_avg_ergonomics_for_city( ?int $city_id ): float {
        if ( ! $city_id ) return 0;
        
        global $wpdb;
        
        $avg = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(pm.meta_value) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_ergonomics'
             JOIN {$wpdb->postmeta} pc ON pc.post_id = p.ID AND pc.meta_key = 'wsz_city_id'
             WHERE p.post_type = %s AND p.post_status = 'publish' AND pc.meta_value = %d",
            WSZ_CPT::SLUG,
            $city_id
        ) );
        
        return $avg ? round( (float) $avg, 1 ) : 0;
    }
    /**
 * Get country name by ISO2
 */
public static function get_country_name_by_iso2( string $iso2 ): string {
    global $wpdb;
    
    $name = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_title FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wscity_country_iso2'
         WHERE p.post_type = 'wsp_country' AND pm.meta_value = %s
         LIMIT 1",
        strtoupper( $iso2 )
    ) );
    
    return $name ?: $iso2;
}

/**
 * Get average ergonomics by country
 */
public static function get_avg_ergonomics_by_country( string $iso2 ): float {
    global $wpdb;
    
    $avg = $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(pm.meta_value) FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_ergonomics'
         JOIN {$wpdb->postmeta} pmc ON pmc.post_id = p.ID AND pmc.meta_key = 'wsz_country_iso2'
         WHERE p.post_type = %s AND p.post_status = 'publish' AND pmc.meta_value = %s",
        WSZ_CPT::SLUG,
        strtoupper( $iso2 )
    ) );
    
    return $avg ? round( (float) $avg, 1 ) : 0;
}

/**
 * Get total countries with zones
 */
public static function get_total_countries_with_zones(): int {
    global $wpdb;
    
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT pm.meta_value) 
         FROM {$wpdb->postmeta} pm
         WHERE pm.meta_key = 'wsz_country_iso2' 
         AND pm.meta_value != ''"
    );
}

/**
 * Get total cities with zones
 */
public static function get_total_cities_with_zones(): int {
    global $wpdb;
    
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT pm.meta_value) 
         FROM {$wpdb->postmeta} pm
         WHERE pm.meta_key = 'wsz_city_name' 
         AND pm.meta_value != ''"
    );
}
}