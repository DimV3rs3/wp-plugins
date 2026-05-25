<?php
/**
 * Crime Data Importer for NYC Districts
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSCrime_Importer {

    /**
     * Import crime data from CSV
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

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < 5 ) {
                $skipped++;
                continue;
            }
            
            // Extract data (based on NYC crime data format)
            $borough = trim( $row[2] ?? '' ); // Borough column
            $crime_type = trim( $row[3] ?? '' ); // Crime type
            $count = intval( $row[4] ?? 0 ); // Count
            $rate = floatval( $row[5] ?? 0 ); // Rate per capita
            
            if ( empty( $borough ) || empty( $crime_type ) ) {
                $skipped++;
                continue;
            }
            
            // Find district ID by borough name
            $district_id = null;
            foreach ( $district_map as $name => $id ) {
                if ( stripos( $borough, $name ) !== false || stripos( $name, $borough ) !== false ) {
                    $district_id = $id;
                    break;
                }
            }
            
            if ( ! $district_id ) {
                $errors[] = "District not found: $borough";
                $skipped++;
                continue;
            }
            
            // Save to database
            $result = self::save_crime_data( $district_id, $crime_type, $count, $rate );
            
            if ( $result === 'inserted' ) {
                $imported++;
            } elseif ( $result === 'updated' ) {
                $updated++;
            } else {
                $skipped++;
            }
        }
        
        fclose( $handle );
        
        // Run crime analysis
        $analysis = self::run_crime_analysis();
        
        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice( $errors, 0, 20 ),
            'analysis' => $analysis,
        ];
    }
    
    private static function save_crime_data( int $district_id, string $crime_type, int $count, float $rate ): string {
        global $wpdb;
        
        $table = $wpdb->prefix . 'district_crime_data';
        
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE district_id = %d AND crime_type = %s",
            $district_id, $crime_type
        ) );
        
        if ( $existing ) {
            $wpdb->update(
                $table,
                [ 'count' => $count, 'rate' => $rate, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $existing ],
                [ '%d', '%f', '%s' ],
                [ '%d' ]
            );
            return 'updated';
        } else {
            $wpdb->insert(
                $table,
                [
                    'district_id' => $district_id,
                    'crime_type' => $crime_type,
                    'count' => $count,
                    'rate' => $rate,
                    'period' => '2023',
                    'created_at' => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%d', '%f', '%s', '%s' ]
            );
            return 'inserted';
        }
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
     * Run crime data analysis using statistical methods
     */
    public static function run_crime_analysis(): array {
    global $wpdb;
    
    $table_crime = $wpdb->prefix . 'district_crime_data';
    $table_results = $wpdb->prefix . 'district_ml_results';
    
    // 1. Сбор данных
    $crime_stats = $wpdb->get_results("
        SELECT district_id,
               SUM(count) as total_crimes,
               AVG(rate) as avg_crime_rate
        FROM {$table_crime}
        GROUP BY district_id
    ");
    
    if (empty($crime_stats)) {
        return [ 'updated' => 0, 'message' => 'Нет данных о преступности' ];
    }
    
    // 2. Расчет статистики для нормализации
    $all_rates = array_column($crime_stats, 'avg_crime_rate');
    $mean_rate = array_sum($all_rates) / count($all_rates);
    $std_dev = sqrt(array_sum(array_map(function($x) use ($mean_rate) {
        return pow($x - $mean_rate, 2);
    }, $all_rates)) / count($all_rates));
    
    $updated = 0;
    $regression_results = [];
    
    foreach ($crime_stats as $stat) {
        $crime_rate = floatval($stat->avg_crime_rate);
        
        // 3. Z-нормализация (стандартизация)
        $z_score = ($crime_rate - $mean_rate) / $std_dev;
        
        // 4. Линейное преобразование в шкалу 0-100 (регрессионный подход)
        // Чем меньше преступность, тем выше безопасность
        $normalized = ($z_score + 2) / 4; // от 0 до 1
        $safety_score = 100 * (1 - $normalized); // инверсия
        
        // Ограничиваем диапазон
        $safety_score = max(0, min(100, $safety_score));
        
        // 5. Классификация (можно рассматривать как логистическую регрессию)
        if ($safety_score >= 70) {
            $crime_level = 'Low';
            $risk_percent = 100 - $safety_score;
        } elseif ($safety_score >= 40) {
            $crime_level = 'Medium';
            $risk_percent = 100 - $safety_score;
        } else {
            $crime_level = 'High';
            $risk_percent = 100 - $safety_score;
        }
        
        // Сохраняем результаты
        $wpdb->replace($table_results, [
            'district_id' => $stat->district_id,
            'safety_score' => round($safety_score, 1),
            'classification' => $crime_level,
            'updated_at' => current_time('mysql')
        ]);
        
        update_post_meta($stat->district_id, 'wsdistrict_safety_score', round($safety_score, 1));
        update_post_meta($stat->district_id, 'wsdistrict_crime_level', $crime_level);
        update_post_meta($stat->district_id, 'wsdistrict_crime_risk', round($risk_percent, 1));
        
        $regression_results[] = [
            'district_id' => $stat->district_id,
            'crime_rate' => round($crime_rate, 2),
            'z_score' => round($z_score, 2),
            'safety_score' => round($safety_score, 1),
            'risk_level' => $crime_level
        ];
        
        $updated++;
    }
    
    return [
        'updated' => $updated,
        'message' => "Анализ завершен. Обновлено $updated районов.\n" .
                     "Средний уровень преступности: " . round($mean_rate, 2) . " на 1000 чел.\n" .
                     "Метод: Z-нормализация + линейное преобразование (регрессионный подход)",
        'statistics' => [
            'mean_crime_rate' => round($mean_rate, 2),
            'std_deviation' => round($std_dev, 2),
            'regression_results' => $regression_results
        ]
    ];
    }
    public static function delete_all(): int {
        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}district_crime_data" );
        return intval( $deleted );
    }
}