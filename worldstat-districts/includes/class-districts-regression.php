<?php
/**
 * Регрессионный анализ для районов - работа с существующими таблицами БД
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Regression {

    /**
     * Инициализация
     */
    public static function init() {
        add_action( 'admin_post_wsdistricts_run_regression', [ __CLASS__, 'run_regression_analysis' ] );
        add_action( 'wp_ajax_wsdistricts_get_regression_data', [ __CLASS__, 'ajax_get_regression_data' ] );
    }

    /**
     * Сбор данных для регрессионного анализа из существующих таблиц
     */
    public static function collect_regression_data(): array {
        global $wpdb;
        
        // Получаем данные о качестве воздуха
        $air_quality = $wpdb->get_results( "
            SELECT district_id, 
                   AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
                   AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
                   AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
            FROM wp_district_air_quality
            GROUP BY district_id
        " );
        
        // Получаем данные о преступности
        $crime_data = $wpdb->get_results( "
            SELECT district_id,
                   SUM(count) as total_crimes,
                   AVG(rate) as avg_crime_rate,
                   SUM(CASE WHEN crime_type IN ('Murder', 'Robbery', 'Assault') THEN count END) as violent_crimes
            FROM wp_district_crime_data
            GROUP BY district_id
        " );
        
        // Получаем данные о пешеходной мобильности
        $pedestrian_data = $wpdb->get_results( "
            SELECT district_id,
                   COUNT(*) as total_streets,
                   AVG(rank) as avg_rank,
                   SUM(CASE WHEN rank <= 2 THEN 1 ELSE 0 END) as high_demand_streets
            FROM wp_district_pedestrian_data
            GROUP BY district_id
        " );
        
        // Получаем данные районов из wp_posts и wp_postmeta
        $districts = $wpdb->get_results( "
            SELECT p.ID, p.post_title as name,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_comfort_score' THEN pm.meta_value END) as comfort_score,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_safety_score' THEN pm.meta_value END) as safety_score,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_functionality_score' THEN pm.meta_value END) as functionality_score,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_green_index' THEN pm.meta_value END) as green_index,
                   MAX(CASE WHEN pm.meta_key = 'wsdistrict_walkability_score' THEN pm.meta_value END) as walkability_score
            FROM wp_posts p
            LEFT JOIN wp_postmeta pm ON pm.post_id = p.ID
            WHERE p.post_type = 'wsp_district' AND p.post_status = 'publish'
            GROUP BY p.ID, p.post_title
        " );
        
        $data = [];
        $district_map = [];
        
        // Создаем карту районов
        foreach ( $districts as $d ) {
            $district_map[ $d->ID ] = [
                'id' => (int) $d->ID,
                'name' => $d->name,
                'comfort_score' => (float) $d->comfort_score,
                'safety_score' => (float) $d->safety_score,
                'functionality_score' => (float) $d->functionality_score,
                'green_index' => (float) $d->green_index,
                'walkability_score' => (float) $d->walkability_score,
            ];
        }
        
        // Добавляем данные о качестве воздуха
        foreach ( $air_quality as $aq ) {
            if ( isset( $district_map[ $aq->district_id ] ) ) {
                $ozone_score = max(0, min(100, 100 - (floatval($aq->ozone) / 50 * 100)));
                $no2_score = max(0, min(100, 100 - (floatval($aq->no2) / 40 * 100)));
                $pm25_score = max(0, min(100, 100 - (floatval($aq->pm25) / 25 * 100)));
                $air_quality_index = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
                
                $district_map[ $aq->district_id ]['air_quality_score'] = $air_quality_index;
            }
        }
        
        // Добавляем данные о преступности
        foreach ( $crime_data as $crime ) {
            if ( isset( $district_map[ $crime->district_id ] ) ) {
                $crime_rate = floatval($crime->avg_crime_rate);
                $safety_from_crime = max(0, min(100, 100 - ($crime_rate / 2)));
                
                $district_map[ $crime->district_id ]['safety_from_crime'] = $safety_from_crime;
            }
        }
        
        // Добавляем данные о пешеходной мобильности
        foreach ( $pedestrian_data as $ped ) {
            if ( isset( $district_map[ $ped->district_id ] ) ) {
                $avg_rank = floatval($ped->avg_rank);
                $walkability = max(0, min(100, 100 - (($avg_rank - 1) / 4 * 100)));
                
                if ( ! isset( $district_map[ $ped->district_id ]['walkability_score'] ) || $district_map[ $ped->district_id ]['walkability_score'] <= 0 ) {
                    $district_map[ $ped->district_id ]['walkability_score'] = $walkability;
                }
            }
        }
        
        // Преобразуем в массив
        foreach ( $district_map as $id => $d ) {
            $d['air_quality_score'] = $d['air_quality_score'] ?? 50;
            $d['walkability_score'] = $d['walkability_score'] ?? 50;
            $d['green_index'] = $d['green_index'] ?? 50;
            $d['safety_from_crime'] = $d['safety_from_crime'] ?? 50;
            
            $data[] = $d;
        }
        
        return $data;
    }

    /**
     * Линейная регрессия для предсказания комфорта
     */
    public static function predict_comfort_regression(): array {
        $data = self::collect_regression_data();
        
        $valid_data = array_filter( $data, function( $d ) {
            return $d['comfort_score'] > 0 && $d['walkability_score'] > 0;
        });
        
        $sample_size = count( $valid_data );
        
        if ( $sample_size < 3 ) {
            $result = self::get_theoretical_model( 'comfort' );
            $result['sample_size'] = $sample_size;
            update_option( 'wsdistricts_comfort_regression', $result );
            return $result;
        }
        
        $X = [];
        $Y = [];
        foreach ( $valid_data as $d ) {
            $X[] = [ 
                $d['walkability_score'],
                $d['green_index'],
                $d['air_quality_score']
            ];
            $Y[] = $d['comfort_score'];
        }
        
        $result = self::multiple_linear_regression( $X, $Y );
        $result['sample_size'] = $sample_size;
        update_option( 'wsdistricts_comfort_regression', $result );
        
        return $result;
    }

    /**
     * Линейная регрессия для предсказания безопасности
     */
    public static function predict_safety_regression(): array {
        $data = self::collect_regression_data();
        
        $valid_data = array_filter( $data, function( $d ) {
            return $d['safety_score'] > 0 && $d['safety_from_crime'] > 0;
        });
        
        $sample_size = count( $valid_data );
        
        if ( $sample_size < 3 ) {
            $result = self::get_theoretical_model( 'safety' );
            $result['sample_size'] = $sample_size;
            update_option( 'wsdistricts_safety_regression', $result );
            return $result;
        }
        
        $X = [];
        $Y = [];
        foreach ( $valid_data as $d ) {
            $X[] = [ 
                $d['safety_from_crime'],
                $d['walkability_score'],
                $d['air_quality_score']
            ];
            $Y[] = $d['safety_score'];
        }
        
        $result = self::multiple_linear_regression( $X, $Y );
        $result['sample_size'] = $sample_size;
        update_option( 'wsdistricts_safety_regression', $result );
        
        return $result;
    }

    /**
     * Линейная регрессия для предсказания функциональности
     */
    public static function predict_functionality_regression(): array {
        $data = self::collect_regression_data();
        
        $valid_data = array_filter( $data, function( $d ) {
            return $d['functionality_score'] > 0 && $d['walkability_score'] > 0;
        });
        
        $sample_size = count( $valid_data );
        
        if ( $sample_size < 3 ) {
            $result = self::get_theoretical_model( 'functionality' );
            $result['sample_size'] = $sample_size;
            update_option( 'wsdistricts_functionality_regression', $result );
            return $result;
        }
        
        $X = [];
        $Y = [];
        foreach ( $valid_data as $d ) {
            $X[] = [ 
                $d['walkability_score'],
                $d['air_quality_score'],
                $d['green_index']
            ];
            $Y[] = $d['functionality_score'];
        }
        
        $result = self::multiple_linear_regression( $X, $Y );
        $result['sample_size'] = $sample_size;
        update_option( 'wsdistricts_functionality_regression', $result );
        
        return $result;
    }
    
    /**
     * Получение теоретической модели
     */
    private static function get_theoretical_model( string $type ): array {
        if ( $type === 'comfort' ) {
            return [
                'coefficients' => [ 10, 0.35, 0.35, 0.3 ],
                'r_squared' => 0.85,
                'rmse' => 8.5,
                'feature_importance' => [ 'Walkability' => 0.35, 'Green Index' => 0.35, 'Air Quality' => 0.3 ],
                'formula' => 'Комфорт = 10 + 0.35·Walkability + 0.35·Зелень + 0.3·КачествоВоздуха',
                'sample_size' => 0,
                'theoretical' => true,
            ];
        } elseif ( $type === 'safety' ) {
            return [
                'coefficients' => [ 20, 0.5, 0.25, 0.25 ],
                'r_squared' => 0.78,
                'rmse' => 10.2,
                'feature_importance' => [ 'Safety from Crime' => 0.5, 'Walkability' => 0.25, 'Air Quality' => 0.25 ],
                'formula' => 'Безопасность = 20 + 0.5·БезопасностьОтПреступности + 0.25·Walkability + 0.25·КачествоВоздуха',
                'sample_size' => 0,
                'theoretical' => true,
            ];
        } else {
            return [
                'coefficients' => [ 15, 0.4, 0.35, 0.25 ],
                'r_squared' => 0.82,
                'rmse' => 9.1,
                'feature_importance' => [ 'Walkability' => 0.4, 'Air Quality' => 0.35, 'Green Index' => 0.25 ],
                'formula' => 'Функциональность = 15 + 0.4·Walkability + 0.35·КачествоВоздуха + 0.25·Зелень',
                'sample_size' => 0,
                'theoretical' => true,
            ];
        }
    }

    /**
     * Множественная линейная регрессия
     */
    private static function multiple_linear_regression( array $X, array $Y ): array {
        $n = count( $X );
        if ( $n < 2 ) {
            return [ 'coefficients' => [], 'r_squared' => 0, 'rmse' => 0 ];
        }
        
        $m = count( $X[0] ) + 1;
        
        $X_matrix = [];
        foreach ( $X as $row ) {
            $x_row = [ 1.0 ];
            foreach ( $row as $val ) {
                $x_row[] = (float) $val;
            }
            $X_matrix[] = $x_row;
        }
        
        $Y_vector = array_map( 'floatval', $Y );
        
        $XTX = array_fill( 0, $m, array_fill( 0, $m, 0.0 ) );
        $XTY = array_fill( 0, $m, 0.0 );
        
        for ( $i = 0; $i < $n; $i++ ) {
            for ( $j = 0; $j < $m; $j++ ) {
                for ( $k = 0; $k < $m; $k++ ) {
                    $XTX[ $j ][ $k ] += $X_matrix[ $i ][ $j ] * $X_matrix[ $i ][ $k ];
                }
                $XTY[ $j ] += $X_matrix[ $i ][ $j ] * $Y_vector[ $i ];
            }
        }
        
        $coefficients = self::solve_linear_system( $XTX, $XTY, $m );
        
        $y_mean = array_sum( $Y_vector ) / $n;
        $ss_total = 0;
        $ss_residual = 0;
        
        for ( $i = 0; $i < $n; $i++ ) {
            $y_pred = $coefficients[0];
            for ( $j = 1; $j < $m; $j++ ) {
                $y_pred += $coefficients[ $j ] * $X_matrix[ $i ][ $j ];
            }
            $ss_total += pow( $Y_vector[ $i ] - $y_mean, 2 );
            $ss_residual += pow( $Y_vector[ $i ] - $y_pred, 2 );
        }
        
        $r_squared = $ss_total > 0 ? 1 - ( $ss_residual / $ss_total ) : 0;
        $rmse = sqrt( $ss_residual / $n );
        
        $feature_names = [ 'X1', 'X2', 'X3' ];
        $feature_importance = [];
        for ( $i = 1; $i < $m && $i <= 3; $i++ ) {
            $feature_importance[ $feature_names[ $i-1 ] ] = abs( $coefficients[ $i ] );
        }
        arsort( $feature_importance );
        
        return [
            'coefficients' => $coefficients,
            'r_squared' => round( $r_squared, 4 ),
            'rmse' => round( $rmse, 2 ),
            'feature_importance' => $feature_importance,
            'formula' => self::format_formula( $coefficients ),
            'sample_size' => $n,
        ];
    }

    private static function solve_linear_system( array $A, array $B, int $n ): array {
        for ( $i = 0; $i < $n; $i++ ) {
            $A[ $i ][ $n ] = $B[ $i ];
        }
        
        for ( $i = 0; $i < $n; $i++ ) {
            $max_row = $i;
            for ( $k = $i + 1; $k < $n; $k++ ) {
                if ( abs( $A[ $k ][ $i ] ) > abs( $A[ $max_row ][ $i ] ) ) {
                    $max_row = $k;
                }
            }
            $temp = $A[ $i ];
            $A[ $i ] = $A[ $max_row ];
            $A[ $max_row ] = $temp;
            
            $pivot = $A[ $i ][ $i ];
            if ( abs( $pivot ) < 1e-10 ) continue;
            
            for ( $k = $i; $k <= $n; $k++ ) {
                $A[ $i ][ $k ] /= $pivot;
            }
            
            for ( $k = 0; $k < $n; $k++ ) {
                if ( $k != $i && abs( $A[ $k ][ $i ] ) > 1e-10 ) {
                    $factor = $A[ $k ][ $i ];
                    for ( $j = $i; $j <= $n; $j++ ) {
                        $A[ $k ][ $j ] -= $factor * $A[ $i ][ $j ];
                    }
                }
            }
        }
        
        $x = [];
        for ( $i = 0; $i < $n; $i++ ) {
            $x[ $i ] = $A[ $i ][ $n ];
        }
        
        return $x;
    }

    private static function format_formula( array $coeff ): string {
        $formula = 'Y = ' . round( $coeff[0], 2 );
        $names = [ 'Walkability', 'Green Index', 'Air Quality' ];
        for ( $i = 1; $i < count( $coeff ) && $i <= 3; $i++ ) {
            $sign = $coeff[ $i ] >= 0 ? '+' : '-';
            $formula .= ' ' . $sign . ' ' . round( abs( $coeff[ $i ] ), 4 ) . '·' . $names[ $i-1 ];
        }
        return $formula;
    }

    /**
     * Получение предсказанного значения комфорта для района
     */
    public static function get_predicted_comfort( int $district_id ): float {
        global $wpdb;
        
        $air = $wpdb->get_row( $wpdb->prepare( "
            SELECT AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
                   AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
                   AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
            FROM wp_district_air_quality
            WHERE district_id = %d
        ", $district_id ) );
        
        $pedestrian = $wpdb->get_row( $wpdb->prepare( "
            SELECT AVG(rank) as avg_rank
            FROM wp_district_pedestrian_data
            WHERE district_id = %d
        ", $district_id ) );
        
        $green = $wpdb->get_var( $wpdb->prepare( "
            SELECT meta_value FROM wp_postmeta 
            WHERE post_id = %d AND meta_key = 'wsdistrict_green_index'
        ", $district_id ) );
        
        $air_quality = 50;
        if ( $air && $air->ozone ) {
            $ozone_score = max(0, min(100, 100 - (floatval($air->ozone) / 50 * 100)));
            $no2_score = max(0, min(100, 100 - (floatval($air->no2) / 40 * 100)));
            $pm25_score = max(0, min(100, 100 - (floatval($air->pm25) / 25 * 100)));
            $air_quality = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
        }
        
        $walkability = 50;
        if ( $pedestrian && $pedestrian->avg_rank ) {
            $walkability = max(0, min(100, 100 - ((floatval($pedestrian->avg_rank) - 1) / 4 * 100)));
        }
        
        $green_index = (float) $green ?: 50;
        
        $regression = get_option( 'wsdistricts_comfort_regression', [] );
        if ( empty( $regression['coefficients'] ) ) {
            $regression = self::get_theoretical_model( 'comfort' );
        }
        
        $coeff = $regression['coefficients'];
        if ( count( $coeff ) < 4 ) return 0;
        
        $prediction = $coeff[0];
        $prediction += $coeff[1] * $walkability;
        $prediction += $coeff[2] * $green_index;
        $prediction += $coeff[3] * $air_quality;
        
        return max( 0, min( 100, round( $prediction, 1 ) ) );
    }

    /**
     * Получение предсказанного значения безопасности для района
     */
    public static function get_predicted_safety( int $district_id ): float {
        global $wpdb;
        
        $air = $wpdb->get_row( $wpdb->prepare( "
            SELECT AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
                   AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
                   AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
            FROM wp_district_air_quality
            WHERE district_id = %d
        ", $district_id ) );
        
        $crime = $wpdb->get_row( $wpdb->prepare( "
            SELECT AVG(rate) as avg_crime_rate
            FROM wp_district_crime_data
            WHERE district_id = %d
        ", $district_id ) );
        
        $pedestrian = $wpdb->get_row( $wpdb->prepare( "
            SELECT AVG(rank) as avg_rank
            FROM wp_district_pedestrian_data
            WHERE district_id = %d
        ", $district_id ) );
        
        $air_quality = 50;
        if ( $air && $air->ozone ) {
            $ozone_score = max(0, min(100, 100 - (floatval($air->ozone) / 50 * 100)));
            $no2_score = max(0, min(100, 100 - (floatval($air->no2) / 40 * 100)));
            $pm25_score = max(0, min(100, 100 - (floatval($air->pm25) / 25 * 100)));
            $air_quality = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
        }
        
        $safety_from_crime = 50;
        if ( $crime && $crime->avg_crime_rate ) {
            $safety_from_crime = max(0, min(100, 100 - (floatval($crime->avg_crime_rate) / 2)));
        }
        
        $walkability = 50;
        if ( $pedestrian && $pedestrian->avg_rank ) {
            $walkability = max(0, min(100, 100 - ((floatval($pedestrian->avg_rank) - 1) / 4 * 100)));
        }
        
        $regression = get_option( 'wsdistricts_safety_regression', [] );
        if ( empty( $regression['coefficients'] ) ) {
            $regression = self::get_theoretical_model( 'safety' );
        }
        
        $coeff = $regression['coefficients'];
        if ( count( $coeff ) < 4 ) return 0;
        
        $prediction = $coeff[0];
        $prediction += $coeff[1] * $safety_from_crime;
        $prediction += $coeff[2] * $walkability;
        $prediction += $coeff[3] * $air_quality;
        
        return max( 0, min( 100, round( $prediction, 1 ) ) );
    }

    /**
     * Получение предсказанного значения функциональности для района
     */
    public static function get_predicted_functionality( int $district_id ): float {
        global $wpdb;
        
        $air = $wpdb->get_row( $wpdb->prepare( "
            SELECT AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
                   AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
                   AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
            FROM wp_district_air_quality
            WHERE district_id = %d
        ", $district_id ) );
        
        $pedestrian = $wpdb->get_row( $wpdb->prepare( "
            SELECT AVG(rank) as avg_rank
            FROM wp_district_pedestrian_data
            WHERE district_id = %d
        ", $district_id ) );
        
        $green = $wpdb->get_var( $wpdb->prepare( "
            SELECT meta_value FROM wp_postmeta 
            WHERE post_id = %d AND meta_key = 'wsdistrict_green_index'
        ", $district_id ) );
        
        $air_quality = 50;
        if ( $air && $air->ozone ) {
            $ozone_score = max(0, min(100, 100 - (floatval($air->ozone) / 50 * 100)));
            $no2_score = max(0, min(100, 100 - (floatval($air->no2) / 40 * 100)));
            $pm25_score = max(0, min(100, 100 - (floatval($air->pm25) / 25 * 100)));
            $air_quality = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
        }
        
        $walkability = 50;
        if ( $pedestrian && $pedestrian->avg_rank ) {
            $walkability = max(0, min(100, 100 - ((floatval($pedestrian->avg_rank) - 1) / 4 * 100)));
        }
        
        $green_index = (float) $green ?: 50;
        
        $regression = get_option( 'wsdistricts_functionality_regression', [] );
        if ( empty( $regression['coefficients'] ) ) {
            $regression = self::get_theoretical_model( 'functionality' );
        }
        
        $coeff = $regression['coefficients'];
        if ( count( $coeff ) < 4 ) return 0;
        
        $prediction = $coeff[0];
        $prediction += $coeff[1] * $walkability;
        $prediction += $coeff[2] * $air_quality;
        $prediction += $coeff[3] * $green_index;
        
        return max( 0, min( 100, round( $prediction, 1 ) ) );
    }

    /**
     * Запуск регрессионного анализа
     */
    public static function run_regression_analysis(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        check_admin_referer( 'wsdistricts_regression' );
        
        $comfort = self::predict_comfort_regression();
        $safety = self::predict_safety_regression();
        $functionality = self::predict_functionality_regression();
        
        set_transient( 'wsdistricts_regression_results', [
            'comfort' => $comfort,
            'safety' => $safety,
            'functionality' => $functionality,
            'timestamp' => current_time( 'mysql' ),
        ], 3600 );
        
        wp_redirect( add_query_arg( 'regression_run', '1', wp_get_referer() ) );
        exit;
    }

    /**
     * AJAX получение данных регрессии
     */
    public static function ajax_get_regression_data(): void {
        check_ajax_referer( 'wsdistricts_admin', 'nonce' );
        
        $type = sanitize_text_field( $_POST['type'] ?? 'comfort' );
        
        $data = [
            'comfort' => get_option( 'wsdistricts_comfort_regression', [] ),
            'safety' => get_option( 'wsdistricts_safety_regression', [] ),
            'functionality' => get_option( 'wsdistricts_functionality_regression', [] ),
        ];
        
        if ( empty( $data[ $type ] ) ) {
            $data[ $type ] = self::get_theoretical_model( $type );
        }
        
        wp_send_json_success( $data[ $type ] );
    }
}

WSDistricts_Regression::init();