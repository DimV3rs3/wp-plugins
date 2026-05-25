<?php
/**
 * CSV Importer for Districts data
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Importer {

    public function prepare( string $tmp_path ): array {
        $upload_dir = wp_upload_dir();
        $dest = $upload_dir['basedir'] . '/wsdistricts-import.csv';

        if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
            if ( ! copy( $tmp_path, $dest ) ) {
                return [ 'error' => 'Failed to save file.' ];
            }
        }

        $f = fopen( $dest, 'r' );
        fgetcsv( $f );
        $count = 0;
        while ( fgetcsv( $f ) !== false ) $count++;
        fclose( $f );

        return [ 'file' => $dest, 'total' => $count ];
    }

    public function process_batch( string $file_path, int $offset, int $batch_size, bool $update ): array {
        global $wpdb;
        set_time_limit( 300 );

        $results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        $f = fopen( $file_path, 'r' );
        if ( ! $f ) return [ 'error' => 'Could not open file.' ];

        fgetcsv( $f );

        for ( $i = 0; $i < $offset && fgetcsv( $f ) !== false; $i++ ) {}

        $existing = $this->get_existing_districts();
        $now = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', true );
        $uid = get_current_user_id() ?: 1;
        $in_batch = 0;

        while ( $in_batch < $batch_size && ( $row = fgetcsv( $f ) ) !== false ) {
            $in_batch++;

            $district_name = trim( $row[0] ?? '' );
            $city_name = trim( $row[1] ?? '' );
            $country_name = trim( $row[2] ?? '' );
            $country_iso2 = strtoupper( trim( $row[3] ?? '' ) );
            $city_id = (int) ( $row[4] ?? 0 );

            if ( ! $district_name || ! $city_name || ! $country_iso2 ) {
                $results['skipped']++;
                continue;
            }

            if ( ! $city_id ) {
                $city_id = $this->find_city_id( $city_name, $country_iso2 );
                if ( ! $city_id ) {
                    $results['errors'][] = "City not found: $city_name ($country_iso2)";
                    $results['skipped']++;
                    continue;
                }
            }

            $population = (int) str_replace( [ ',', ' ' ], '', trim( $row[7] ?? '0' ) );
            $area = (float) str_replace( [ ',', ' ' ], '', trim( $row[8] ?? '0' ) );
            $density = $area > 0 ? round( $population / $area ) : 0;

            $cache_key = $district_name . '|' . $city_id;
            $existing_id = $existing[ $cache_key ] ?? null;

            if ( $existing_id && $update ) {
                $wpdb->update( $wpdb->posts, [ 'post_modified' => $now, 'post_modified_gmt' => $now_gmt ], [ 'ID' => $existing_id ] );
                update_post_meta( $existing_id, 'wsdistrict_country_iso2', $country_iso2 );
                update_post_meta( $existing_id, 'wsdistrict_country_name', $country_name );
                update_post_meta( $existing_id, 'wsdistrict_city_id', $city_id );
                update_post_meta( $existing_id, 'wsdistrict_city_name', $city_name );
                update_post_meta( $existing_id, 'wsdistrict_lat', (float) $row[5] ?? 0 );
                update_post_meta( $existing_id, 'wsdistrict_lng', (float) $row[6] ?? 0 );
                update_post_meta( $existing_id, 'wsdistrict_population', $population );
                update_post_meta( $existing_id, 'wsdistrict_area', $area );
                update_post_meta( $existing_id, 'wsdistrict_density', $density );
                update_post_meta( $existing_id, 'wsdistrict_established', trim( $row[9] ?? '' ) );
                update_post_meta( $existing_id, 'wsdistrict_postal_code', trim( $row[10] ?? '' ) );
                update_post_meta( $existing_id, 'wsdistrict_website', trim( $row[11] ?? '' ) );
                $results['updated']++;
            } elseif ( ! $existing_id ) {
                $wpdb->insert( $wpdb->posts, [
                    'post_author' => $uid, 'post_date' => $now, 'post_date_gmt' => $now_gmt,
                    'post_title' => $district_name, 'post_status' => 'publish',
                    'post_name' => sanitize_title( $district_name . '-' . $city_id ),
                    'post_modified' => $now, 'post_modified_gmt' => $now_gmt,
                    'post_type' => WSDistricts_CPT::SLUG, 'comment_count' => 0,
                ] );
                $post_id = (int) $wpdb->insert_id;
                if ( $post_id ) {
                    update_post_meta( $post_id, 'wsdistrict_country_iso2', $country_iso2 );
                    update_post_meta( $post_id, 'wsdistrict_country_name', $country_name );
                    update_post_meta( $post_id, 'wsdistrict_city_id', $city_id );
                    update_post_meta( $post_id, 'wsdistrict_city_name', $city_name );
                    update_post_meta( $post_id, 'wsdistrict_lat', (float) $row[5] ?? 0 );
                    update_post_meta( $post_id, 'wsdistrict_lng', (float) $row[6] ?? 0 );
                    update_post_meta( $post_id, 'wsdistrict_population', $population );
                    update_post_meta( $post_id, 'wsdistrict_area', $area );
                    update_post_meta( $post_id, 'wsdistrict_density', $density );
                    update_post_meta( $post_id, 'wsdistrict_established', trim( $row[9] ?? '' ) );
                    update_post_meta( $post_id, 'wsdistrict_postal_code', trim( $row[10] ?? '' ) );
                    update_post_meta( $post_id, 'wsdistrict_website', trim( $row[11] ?? '' ) );
                    $results['imported']++;
                }
            } else {
                $results['skipped']++;
            }
        }

        fclose( $f );
        wp_cache_flush();
        return $results;
    }

    private function find_city_id( string $city_name, string $country_iso2 ): ?int {
        global $wpdb;
        $city_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wscity_country_iso2'
             WHERE p.post_type = 'wsp_city' AND p.post_title LIKE %s AND pm.meta_value = %s LIMIT 1",
            '%' . $wpdb->esc_like( $city_name ) . '%', $country_iso2
        ) );
        return $city_id ? (int) $city_id : null;
    }

    private function get_existing_districts(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value as city_id
             FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s AND pm.meta_key = 'wsdistrict_city_id'",
            WSDistricts_CPT::SLUG
        ) );
        $map = [];
        foreach ( $rows as $r ) {
            $map[ $r->post_title . '|' . $r->city_id ] = (int) $r->ID;
        }
        return $map;
    }

    public static function delete_all(): int {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", WSDistricts_CPT::SLUG ) );
        if ( empty( $ids ) ) return 0;
        $id_list = implode( ',', array_map( 'intval', $ids ) );
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($id_list)" );
        $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($id_list)" );
        return count( $ids );
    }
}