<?php
/**
 * Simplified Air Quality Data Importer
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSAirQuality_Importer {

    public static function prepare( string $tmp_path ): array {
        $upload_dir = wp_upload_dir();
        $dest = $upload_dir['basedir'] . '/wsair-quality-import.csv';

        if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
            if ( ! copy( $tmp_path, $dest ) ) {
                return [ 'error' => 'Failed to save file.' ];
            }
        }

        // Count rows
        $content = file_get_contents( $dest );
        $lines = explode( "\n", $content );
        $total = count( array_filter( $lines, function($line) { return trim($line) != ''; } ) ) - 1; // minus header
        
        return [ 'file' => $dest, 'total' => max(0, $total) ];
    }

    public static function process_batch( string $file_path, int $offset, int $batch_size ): array {
        global $wpdb;
        
        $results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

        // Read all content
        $content = file_get_contents( $file_path );
        $lines = explode( "\n", $content );
        
        // Remove header
        $header = array_shift( $lines );
        
        // Get district map
        $district_map = self::get_district_map();
        $table_air = $wpdb->prefix . 'district_air_quality';
        
        $processed = 0;
        
        foreach ( $lines as $line ) {
            if ( $processed >= $batch_size ) break;
            if ( trim($line) == '' ) continue;
            
            $processed++;
            
            // Parse CSV line (simple split by comma)
            $row = str_getcsv( $line );
            
            if ( count( $row ) < 3 ) {
                $results['skipped']++;
                continue;
            }
            
            // Try to detect columns
            $district_name = '';
            $indicator = '';
            $data_value = 0;
            
            // Check if header has Geo Place Name
            if ( strpos( $header, 'Geo Place Name' ) !== false ) {
                // Find column indices
                $headers = str_getcsv( $header );
                $geo_index = array_search( 'Geo Place Name', $headers );
                $indicator_index = array_search( 'Indicator Name', $headers );
                $value_index = array_search( 'Data Value', $headers );
                
                if ( $geo_index !== false && isset( $row[$geo_index] ) ) {
                    $district_name = trim( $row[$geo_index] );
                }
                if ( $indicator_index !== false && isset( $row[$indicator_index] ) ) {
                    $indicator = trim( $row[$indicator_index] );
                }
                if ( $value_index !== false && isset( $row[$value_index] ) ) {
                    $data_value = floatval( str_replace( ',', '.', $row[$value_index] ) );
                }
            } else {
                // Assume order: District Name, Indicator Name, Data Value
                $district_name = trim( $row[0] ?? '' );
                $indicator = trim( $row[1] ?? '' );
                $data_value = floatval( str_replace( ',', '.', $row[2] ?? '0' ) );
            }
            
            if ( empty( $district_name ) || empty( $indicator ) || $data_value <= 0 ) {
                $results['skipped']++;
                continue;
            }
            
            // Get pollutant type
            $pollutant = self::get_pollutant_type( $indicator );
            if ( ! $pollutant ) {
                $results['skipped']++;
                continue;
            }
            
            // Find district ID:
            // 1) direct district name match,
            // 2) normalize/resolve neighborhood name to NYC borough,
            // 3) then map borough to district.
            $district_id = self::find_district_id( $district_name, $district_map );
            
            if ( ! $district_id ) {
                $results['errors'][] = "District not found: $district_name";
                $results['skipped']++;
                continue;
            }
            
            // Save to database
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table_air} WHERE district_id = %d AND pollutant = %s",
                $district_id, $pollutant
            ) );
            
            if ( $existing ) {
                $wpdb->update( $table_air, [ 'value' => $data_value, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $existing ] );
                $results['updated']++;
            } else {
                $wpdb->insert( $table_air, [
                    'district_id' => $district_id,
                    'pollutant' => $pollutant,
                    'value' => $data_value,
                    'period' => 'Summer 2023',
                    'created_at' => current_time( 'mysql' )
                ] );
                $results['imported']++;
            }
        }
        
        // Run analysis
        if ( $results['imported'] > 0 || $results['updated'] > 0 ) {
            self::run_analysis();
        }
        
        $results['processed'] = $processed;
        return $results;
    }
    
    private static function get_pollutant_type( string $indicator ): ?string {
        $indicator_lower = strtolower( $indicator );
        if ( strpos( $indicator_lower, 'ozone' ) !== false ) return 'ozone';
        if ( strpos( $indicator_lower, 'nitrogen' ) !== false ) return 'no2';
        if ( strpos( $indicator_lower, 'fine particles' ) !== false || strpos( $indicator_lower, 'pm' ) !== false ) return 'pm25';
        return null;
    }

    private static function find_district_id( string $place_name, array $district_map ): ?int {
        $place = trim( $place_name );
        if ( $place === '' ) {
            return null;
        }

        // Direct contains/equality matching against district titles.
        foreach ( $district_map as $name => $id ) {
            if ( stripos( $place, $name ) !== false || stripos( $name, $place ) !== false ) {
                return $id;
            }
        }

        // Normalize NYC names to borough-level districts.
        $borough = self::resolve_borough_name( $place );
        if ( $borough !== null ) {
            foreach ( $district_map as $name => $id ) {
                if ( strcasecmp( $name, $borough ) === 0 ) {
                    return $id;
                }
            }
        }

        return null;
    }

    private static function resolve_borough_name( string $place_name ): ?string {
        $p = mb_strtolower( trim( $place_name ) );
        if ( $p === '' ) {
            return null;
        }

        $rules = [
            'Manhattan' => [ 'manhattan', 'upper east side', 'upper west side', 'harlem', 'chelsea', 'gramercy', 'village', 'wall street' ],
            'Brooklyn' => [ 'brooklyn', 'flatbush', 'canarsie', 'brownsville', 'bushwick', 'bedford', 'williamsburg', 'greenpoint', 'park slope', 'crown heights' ],
            'Queens' => [ 'queens', 'jamaica', 'astoria', 'flushing', 'rockaway', 'rockaways', 'whitestone', 'long island city', 'ridgewood', 'forest hills' ],
            'Bronx' => [ 'bronx', 'fordham', 'mott haven', 'hunts point', 'university heights', 'riverdale', 'pelham', 'throgs neck' ],
            'Staten Island' => [ 'staten island', 'port richmond', 'tottenville', 'st. george', 'st george' ],
        ];

        foreach ( $rules as $borough => $keywords ) {
            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $p, $kw ) !== false ) {
                    return $borough;
                }
            }
        }

        return null;
    }
    
    private static function get_district_map(): array {
        global $wpdb;
        $districts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            WSDistricts_CPT::SLUG
        ) );
        
        $map = [];
        foreach ( $districts as $d ) {
            $map[ $d->post_title ] = (int) $d->ID;
            $map[ strtolower( $d->post_title ) ] = (int) $d->ID;
        }
        return $map;
    }
    
    /**
     * Обновить данные о качестве воздуха для конкретного района
     */
    public static function update_district_air_quality_meta( int $district_id ): array {
        global $wpdb;
        
        $table_air = $wpdb->prefix . 'district_air_quality';
        
        $data = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
                AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
                AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
            FROM {$table_air} 
            WHERE district_id = %d",
            $district_id
        ) );
        
        if ( ! $data || ( $data->ozone === null && $data->no2 === null && $data->pm25 === null ) ) {
            return [ 'updated' => false, 'message' => 'Нет данных для района ID ' . $district_id ];
        }
        
        // Рассчитываем баллы для каждого загрязнителя (чем ниже загрязнение, тем выше балл)
        $ozone_score = $data->ozone !== null ? max(0, min(100, 100 - ($data->ozone / 50 * 100))) : 50;
        $no2_score = $data->no2 !== null ? max(0, min(100, 100 - ($data->no2 / 40 * 100))) : 50;
        $pm25_score = $data->pm25 !== null ? max(0, min(100, 100 - ($data->pm25 / 25 * 100))) : 50;
        
        // Рассчитываем общий балл комфорта
        $comfort = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
        
        // Определяем класс качества воздуха
        if ( $comfort >= 70 ) {
            $air_class = 'Good';
            $air_text = 'Хорошее';
        } elseif ( $comfort >= 50 ) {
            $air_class = 'Moderate';
            $air_text = 'Среднее';
        } else {
            $air_class = 'Poor';
            $air_text = 'Плохое';
        }
        
        // Сохраняем в post meta
        update_post_meta( $district_id, 'wsdistrict_comfort_score', $comfort );
        update_post_meta( $district_id, 'wsdistrict_air_quality_class', $air_class );
        update_post_meta( $district_id, 'wsdistrict_air_quality_text', $air_text );
        
        // Сохраняем отдельные показатели
        update_post_meta( $district_id, 'wsdistrict_air_ozone_score', round($ozone_score, 1) );
        update_post_meta( $district_id, 'wsdistrict_air_no2_score', round($no2_score, 1) );
        update_post_meta( $district_id, 'wsdistrict_air_pm25_score', round($pm25_score, 1) );
        
        return [
            'updated' => true,
            'district_id' => $district_id,
            'comfort' => $comfort,
            'air_class' => $air_class,
            'ozone_score' => round($ozone_score, 1),
            'no2_score' => round($no2_score, 1),
            'pm25_score' => round($pm25_score, 1)
        ];
    }
    
    public static function run_analysis(): array {
        global $wpdb;
        
        $table_air = $wpdb->prefix . 'district_air_quality';
        $table_results = $wpdb->prefix . 'district_ml_results';
        
        // Получаем все районы, для которых есть данные о качестве воздуха
        $districts = $wpdb->get_results( "
            SELECT district_id, 
                   AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
                   AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
                   AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
            FROM {$table_air} 
            GROUP BY district_id
        " );
        
        if ( empty( $districts ) ) {
            return [ 'updated' => 0, 'message' => 'Нет данных о качестве воздуха для анализа' ];
        }
        
        $updated = 0;
        $results = [];
        
        foreach ( $districts as $d ) {
            $ozone = floatval($d->ozone);
            $no2 = floatval($d->no2);
            $pm25 = floatval($d->pm25);
            
            // Рассчитываем баллы
            $ozone_score = max(0, min(100, 100 - ($ozone / 50 * 100)));
            $no2_score = max(0, min(100, 100 - ($no2 / 40 * 100)));
            $pm25_score = max(0, min(100, 100 - ($pm25 / 25 * 100)));
            
            $comfort = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
            
            // Определяем класс качества воздуха
            if ( $comfort >= 70 ) {
                $air_class = 'Good';
            } elseif ( $comfort >= 50 ) {
                $air_class = 'Moderate';
            } else {
                $air_class = 'Poor';
            }
            
            // Сохраняем в таблицу результатов
            $wpdb->replace( $table_results, [
                'district_id' => $d->district_id,
                'comfort_score' => $comfort,
                'classification' => $air_class,
                'updated_at' => current_time( 'mysql' )
            ] );
            
            // Сохраняем в post meta
            update_post_meta( $d->district_id, 'wsdistrict_comfort_score', $comfort );
            update_post_meta( $d->district_id, 'wsdistrict_air_quality_class', $air_class );
            update_post_meta( $d->district_id, 'wsdistrict_air_ozone_score', round($ozone_score, 1) );
            update_post_meta( $d->district_id, 'wsdistrict_air_no2_score', round($no2_score, 1) );
            update_post_meta( $d->district_id, 'wsdistrict_air_pm25_score', round($pm25_score, 1) );
            
            $results[] = [
                'district_id' => $d->district_id,
                'comfort' => $comfort,
                'air_class' => $air_class
            ];
            
            $updated++;
        }
        
        return [
            'updated' => $updated,
            'message' => "Анализ качества воздуха завершен. Обновлено $updated районов.",
            'results' => $results
        ];
    }
    
    /**
     * Получить данные о качестве воздуха для конкретного района
     */
    public static function get_air_quality_for_district( int $district_id ): array {
        global $wpdb;
        
        $table_air = $wpdb->prefix . 'district_air_quality';
        
        $data = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
                AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
                AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
            FROM {$table_air} 
            WHERE district_id = %d",
            $district_id
        ) );
        
        if ( ! $data ) {
            return [
                'has_data' => false,
                'ozone' => null,
                'no2' => null,
                'pm25' => null,
                'comfort_score' => 0,
                'air_class' => 'Нет данных'
            ];
        }
        
        $ozone_score = $data->ozone !== null ? max(0, min(100, 100 - ($data->ozone / 50 * 100))) : 50;
        $no2_score = $data->no2 !== null ? max(0, min(100, 100 - ($data->no2 / 40 * 100))) : 50;
        $pm25_score = $data->pm25 !== null ? max(0, min(100, 100 - ($data->pm25 / 25 * 100))) : 50;
        
        $comfort = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
        
        if ( $comfort >= 70 ) {
            $air_class = 'Good';
            $air_text = 'Хорошее';
        } elseif ( $comfort >= 50 ) {
            $air_class = 'Moderate';
            $air_text = 'Среднее';
        } else {
            $air_class = 'Poor';
            $air_text = 'Плохое';
        }
        
        return [
            'has_data' => true,
            'ozone' => $data->ozone !== null ? round($data->ozone, 2) : null,
            'no2' => $data->no2 !== null ? round($data->no2, 2) : null,
            'pm25' => $data->pm25 !== null ? round($data->pm25, 2) : null,
            'ozone_score' => round($ozone_score, 1),
            'no2_score' => round($no2_score, 1),
            'pm25_score' => round($pm25_score, 1),
            'comfort_score' => $comfort,
            'air_class' => $air_class,
            'air_text' => $air_text
        ];
    }
    
    public static function delete_all(): int {
        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}district_air_quality" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}district_ml_results" );
        
        // Также удаляем meta-поля
        $districts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wsp_district'" );
        foreach ( $districts as $district_id ) {
            delete_post_meta( $district_id, 'wsdistrict_comfort_score' );
            delete_post_meta( $district_id, 'wsdistrict_air_quality_class' );
            delete_post_meta( $district_id, 'wsdistrict_air_ozone_score' );
            delete_post_meta( $district_id, 'wsdistrict_air_no2_score' );
            delete_post_meta( $district_id, 'wsdistrict_air_pm25_score' );
        }
        
        return intval( $deleted );
    }
}