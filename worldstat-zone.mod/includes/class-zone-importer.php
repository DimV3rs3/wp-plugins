<?php
/**
 * CSV Importer for Zone data with JSON objects
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_Importer {

    /**
     * Import CSV file with product data (new JSON format)
     */
    public static function import_csv( string $file_path, string $country_iso2, string $country_name ): array {
        global $wpdb;
        
        $result = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        if ( ! file_exists( $file_path ) ) {
            $result['errors'][] = 'File not found';
            return $result;
        }
        
        // Определяем разделитель (точка с запятой для нового формата)
        $content = file_get_contents( $file_path );
        $first_line = strtok( $content, "\n" );
        $delimiter = ';'; // используем ; как разделитель
        
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            $result['errors'][] = 'Cannot open file';
            return $result;
        }
        
        $headers = fgetcsv( $handle, 0, $delimiter );
        if ( ! $headers || ! is_array( $headers ) ) {
            $result['errors'][] = 'Invalid CSV header';
            fclose( $handle );
            return $result;
        }
        
        // Нормализуем заголовки
        $normalized_headers = array_map( 'trim', $headers );
        
        $now = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', true );
        $user_id = get_current_user_id() ?: 1;
        $row_num = 0;
        
        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            $row_num++;
            if ( empty( array_filter( $row ) ) ) {
                continue;
            }
            
            $data = [];
            foreach ( $normalized_headers as $index => $name ) {
                $value = isset( $row[ $index ] ) ? trim( $row[ $index ] ) : '';
                // Если значение в кавычках, удаляем их
                if ( strpos( $value, '"' ) === 0 && substr( $value, -1 ) === '"' ) {
                    $value = substr( $value, 1, -1 );
                }
                // Заменяем двойные кавычки внутри на одинарные для JSON
                $value = str_replace( '""', '"', $value );
                $data[ $name ] = $value;
            }
            
            $zone_name = $data['zone_name'] ?? '';
            if ( empty( $zone_name ) ) {
                $result['skipped']++;
                continue;
            }
            
            // Декодируем JSON объекты
            $objects_json = $data['objects'] ?? '[]';
            $objects = json_decode( $objects_json, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                // Пробуем почистить JSON
                $cleaned = preg_replace('/""/', '"', $objects_json);
                $objects = json_decode( $cleaned, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    $result['errors'][] = "Row {$row_num}: Invalid JSON in objects column";
                    $objects = [];
                }
            }
            
            // Вычисляем метрики на основе данных и объектов
            $metrics = self::calculate_all_metrics( $data, $objects );
            
            // Проверяем существование поста
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s",
                WSZ_CPT::SLUG,
                $zone_name
            ) );
            
            // Формируем описание
            $description = self::generate_description( $data, $objects, $metrics );
            $short_desc = $data['zone_type'] . ' | ' . $metrics['ergonomics'] . '/100 эргономика';
            
            if ( $existing_id ) {
                wp_update_post( [
                    'ID' => $existing_id,
                    'post_content' => $description,
                    'post_excerpt' => $short_desc,
                    'post_modified' => $now,
                    'post_modified_gmt' => $now_gmt,
                ] );
                $post_id = $existing_id;
                $result['updated']++;
            } else {
                $post_id = wp_insert_post( [
                    'post_title' => $zone_name,
                    'post_content' => $description,
                    'post_excerpt' => $short_desc,
                    'post_type' => WSZ_CPT::SLUG,
                    'post_status' => 'publish',
                    'post_author' => $user_id,
                    'post_date' => $now,
                    'post_date_gmt' => $now_gmt,
                ] );
                
                if ( is_wp_error( $post_id ) ) {
                    $result['errors'][] = "Row {$row_num}: " . $post_id->get_error_message();
                    $result['skipped']++;
                    continue;
                }
                $result['imported']++;
            }
            
            // Сохраняем мета-данные
            self::save_zone_meta( $post_id, $data, $objects, $metrics, $country_iso2, $country_name );
        }
        
        fclose( $handle );
        $result['debug'] = "Processed rows: {$row_num}";
        
        return $result;
    }
    
    /**
     * Calculate all metrics based on environment data and objects
     */
    private static function calculate_all_metrics( $data, $objects ): array {
        // Параметры среды
        $lighting_score = self::calculate_lighting_score( $data );
        $noise_score = max(0, 100 - min(100, floatval($data['noise_level_db'] ?? 0)));
        $co2_score = self::calculate_co2_score( floatval($data['co2_ppm'] ?? 400) );
        $temp_score = self::calculate_temperature_score( floatval($data['temp_c'] ?? 22) );
        $humidity_score = self::calculate_humidity_score( floatval($data['humidity_percent'] ?? 45) );
        $airflow_score = min(100, floatval($data['air_flow_m3h'] ?? 0) / 5);
        $natural_light = floatval($data['natural_light_percent'] ?? 0);
        
        // Анализ объектов
        $object_count = count( $objects );
        $ergonomic_objects = 0;
        $adjustable_objects = 0;
        $electric_objects = 0;
        $height_adjustable = 0;
        $total_height = 0;
        $total_width = 0;
        $total_depth = 0;
        
        $material_scores = [
            'дерево' => 85, 'массив' => 90, 'кожа' => 85, 'велюр' => 80,
            'металл' => 75, 'стекло' => 70, 'керамика' => 80, 'камень' => 90,
            'ЛДСП' => 60, 'пластик' => 55, 'сетка' => 75, 'ткань' => 75,
            'алюминий' => 75, 'шерсть' => 80, 'лен' => 70, 'нержавейка' => 75,
            'стеклокерамика' => 75, 'пенополиуретан' => 50
        ];
        
        $total_material_score = 0;
        
        foreach ( $objects as $obj ) {
            if ( ($obj['ergonomic'] ?? 'нет') === 'да' ) $ergonomic_objects++;
            if ( ($obj['adjustable'] ?? 'нет') === 'да' ) $adjustable_objects++;
            if ( ($obj['electric'] ?? 'нет') === 'да' ) $electric_objects++;
            if ( ($obj['height_adjustable'] ?? 'нет') === 'да' ) $height_adjustable++;
            
            $total_height += floatval($obj['height_cm'] ?? 0);
            $total_width += floatval($obj['width_cm'] ?? 0);
            $total_depth += floatval($obj['depth_cm'] ?? 0);
            
            $material = strtolower($obj['material'] ?? '');
            $total_material_score += $material_scores[$material] ?? 65;
        }
        
        $avg_height = $object_count > 0 ? $total_height / $object_count : 0;
        $avg_width = $object_count > 0 ? $total_width / $object_count : 0;
        $avg_depth = $object_count > 0 ? $total_depth / $object_count : 0;
        $avg_material_score = $object_count > 0 ? $total_material_score / $object_count : 65;
        
        // Эргономичность объектов
        $object_ergonomics = $object_count > 0 
            ? (($ergonomic_objects / $object_count) * 50 + 
               ($adjustable_objects / $object_count) * 25 +
               ($height_adjustable / $object_count) * 25)
            : 50;
        
        // ИТОГОВЫЕ МЕТРИКИ
        $ergonomics = round(
            0.30 * $lighting_score +
            0.25 * $noise_score +
            0.20 * $co2_score +
            0.15 * $temp_score +
            0.10 * $object_ergonomics, 1
        );
        
        $safety = round(
            0.25 * (floatval($data['safety_features_score'] ?? 50)) +
            0.20 * (100 - min(100, floatval($data['glare_index'] ?? 0))) +
            0.20 * floatval($data['accessibility_score'] ?? 80) +
            0.20 * floatval($data['cleanability_score'] ?? 80) +
            0.15 * floatval($data['privacy_level'] ?? 50), 1
        );
        
        $functionality = round(
            0.35 * min(100, $object_count * 10) +
            0.35 * min(100, floatval($data['durability_years'] ?? 10) * 10) +
            0.30 * max(0, 100 - (floatval($data['maintenance_frequency_days'] ?? 90) / 3.6)), 1
        );
        
        $controllability = round(
            0.35 * min(100, floatval($data['smart_features_count'] ?? 0) * 20) +
            0.35 * (($adjustable_objects / max(1, $object_count)) * 100) +
            0.30 * (($electric_objects / max(1, $object_count)) * 100), 1
        );
        
        $habitability = round(
            0.30 * $temp_score +
            0.25 * $humidity_score +
            0.20 * floatval($data['view_quality_score'] ?? 50) +
            0.15 * $natural_light +
            0.10 * $airflow_score, 1
        );
        
        $masterability = round(
            0.35 * floatval($data['accessibility_score'] ?? 80) +
            0.35 * ($object_ergonomics) +
            0.30 * (100 - min(100, floatval($data['glare_index'] ?? 0))), 1
        );
        
        $comfort = round(
            0.25 * $temp_score +
            0.20 * $noise_score +
            0.20 * $avg_material_score +
            0.20 * $object_ergonomics +
            0.15 * min(100, $object_count * 10), 1
        );
        
        return [
            'ergonomics' => min(100, max(0, $ergonomics)),
            'safety' => min(100, max(0, $safety)),
            'functionality' => min(100, max(0, $functionality)),
            'controllability' => min(100, max(0, $controllability)),
            'habitability' => min(100, max(0, $habitability)),
            'masterability' => min(100, max(0, $masterability)),
            'comfort' => min(100, max(0, $comfort)),
            'lighting' => $lighting_score,
            'temp_score' => $temp_score,
            'humidity_score' => $humidity_score,
            'co2_score' => $co2_score,
            'noise_score' => $noise_score,
            'object_count' => $object_count,
            'avg_height' => round($avg_height, 1),
            'avg_width' => round($avg_width, 1),
            'avg_depth' => round($avg_depth, 1),
            'avg_material_score' => round($avg_material_score, 1),
            'ergonomic_objects_count' => $ergonomic_objects,
            'adjustable_objects_count' => $adjustable_objects,
        ];
    }
    
    private static function calculate_lighting_score( $data ): float {
        $lighting_power = floatval($data['lighting_power_w'] ?? 0);
        $natural_light = floatval($data['natural_light_percent'] ?? 0);
        $color_temp = floatval($data['color_temperature_k'] ?? 4000);
        
        $power_score = min(100, $lighting_power / 2);
        $color_score = 100 - abs($color_temp - 4000) / 40;
        $color_score = min(100, max(0, $color_score));
        
        return round(0.40 * $power_score + 0.40 * $natural_light + 0.20 * $color_score, 1);
    }
    
    private static function calculate_co2_score( float $co2 ): float {
        if ( $co2 <= 400 ) return 100;
        if ( $co2 >= 2000 ) return 0;
        return round(100 - ($co2 - 400) / 16, 1);
    }
    
    private static function calculate_temperature_score( float $temp ): float {
        $optimal = 22;
        $diff = abs($temp - $optimal);
        if ( $diff <= 2 ) return 100;
        if ( $diff >= 15 ) return 0;
        return round(100 - ($diff - 2) / 13 * 100, 1);
    }
    
    private static function calculate_humidity_score( float $humidity ): float {
        $optimal = 45;
        $diff = abs($humidity - $optimal);
        if ( $diff <= 10 ) return 100;
        if ( $diff >= 40 ) return 0;
        return round(100 - ($diff - 10) / 30 * 100, 1);
    }
    
    private static function generate_description( $data, $objects, $metrics ): string {
        $description = "## 🏠 Зона: {$data['zone_name']}\n\n";
        $description .= "**Тип:** {$data['zone_type']} | **Категория:** {$data['category']}\n";
        $description .= "**Площадь:** {$data['area_sqm']} м² | **Объектов:** {$metrics['object_count']}\n\n";
        
        $description .= "### 📦 Объекты в зоне\n";
        foreach ( $objects as $obj ) {
            $description .= "- **{$obj['name']}** ({$obj['type']}) | ";
            $description .= "{$obj['height_cm']}×{$obj['width_cm']}×{$obj['depth_cm']} см | ";
            $description .= "Материал: {$obj['material']} | Цвет: {$obj['color']}\n";
        }
        $description .= "\n";
        
        $description .= "### 📊 Метрики анализа\n";
        $description .= "| Показатель | Значение | Оценка |\n";
        $description .= "|------------|----------|--------|\n";
        $description .= "| Эргономичность | {$metrics['ergonomics']}/100 | " . self::get_rating_text($metrics['ergonomics']) . " |\n";
        $description .= "| Безопасность | {$metrics['safety']}/100 | " . self::get_rating_text($metrics['safety']) . " |\n";
        $description .= "| Функциональность | {$metrics['functionality']}/100 | " . self::get_rating_text($metrics['functionality']) . " |\n";
        $description .= "| Управляемость | {$metrics['controllability']}/100 | " . self::get_rating_text($metrics['controllability']) . " |\n";
        $description .= "| Обитаемость | {$metrics['habitability']}/100 | " . self::get_rating_text($metrics['habitability']) . " |\n";
        $description .= "| Освояемость | {$metrics['masterability']}/100 | " . self::get_rating_text($metrics['masterability']) . " |\n";
        $description .= "| Комфортность | {$metrics['comfort']}/100 | " . self::get_rating_text($metrics['comfort']) . " |\n\n";
        
        $description .= "### 🌡️ Параметры среды\n";
        $description .= "- Освещение: {$metrics['lighting']}/100\n";
        $description .= "- Температура: {$data['temp_c']}°C → {$metrics['temp_score']}/100\n";
        $description .= "- Влажность: {$data['humidity_percent']}% → {$metrics['humidity_score']}/100\n";
        $description .= "- Уровень шума: {$data['noise_level_db']} дБ → {$metrics['noise_score']}/100\n";
        $description .= "- CO₂: {$data['co2_ppm']} ppm → {$metrics['co2_score']}/100\n\n";
        
        return $description;
    }
    
    private static function get_rating_text( float $score ): string {
        if ( $score >= 80 ) return "🏆 Отлично";
        if ( $score >= 65 ) return "👍 Хорошо";
        if ( $score >= 50 ) return "⚠️ Средне";
        if ( $score >= 35 ) return "📉 Ниже среднего";
        return "❌ Требует улучшения";
    }
    
    private static function save_zone_meta( $post_id, $data, $objects, $metrics, $country_iso2, $country_name ) {
        // Сохраняем объекты как JSON
        update_post_meta( $post_id, 'wsz_objects_json', wp_json_encode( $objects ) );
        
        $meta_fields = [
            // Основная информация
            'wsz_country_iso2' => strtoupper( $country_iso2 ),
            'wsz_country_name' => $country_name,
            'wsz_zone_name' => $data['zone_name'],
            'wsz_category' => $data['category'],
            'wsz_zone_type' => $data['zone_type'],
            'wsz_area' => floatval($data['area_sqm'] ?? 0),
            
            // Параметры среды
            'wsz_temp' => floatval($data['temp_c'] ?? 0),
            'wsz_humidity' => floatval($data['humidity_percent'] ?? 0),
            'wsz_noise_level' => floatval($data['noise_level_db'] ?? 0),
            'wsz_noise_source' => $data['noise_source'] ?? '',
            'wsz_co2' => floatval($data['co2_ppm'] ?? 0),
            'wsz_air_flow' => floatval($data['air_flow_m3h'] ?? 0),
            
            // Освещение
            'wsz_lighting_raw' => floatval($data['lighting_power_w'] ?? 0),
            'wsz_lighting' => $metrics['lighting'],
            'wsz_color_temperature' => floatval($data['color_temperature_k'] ?? 4000),
            'wsz_lighting_type' => $data['lighting_type'] ?? 'нейтральный',
            'wsz_natural_light' => floatval($data['natural_light_percent'] ?? 0),
            
            // Безопасность и доступность
            'wsz_safety_features' => $data['safety_features'] ?? '',
            'wsz_accessibility_score' => floatval($data['accessibility_score'] ?? 80),
            'wsz_cleanability_score' => floatval($data['cleanability_score'] ?? 80),
            'wsz_durability_years' => floatval($data['durability_years'] ?? 10),
            'wsz_maintenance_frequency' => intval($data['maintenance_frequency_days'] ?? 90),
            'wsz_glare_index' => floatval($data['glare_index'] ?? 0),
            'wsz_privacy_level' => floatval($data['privacy_level'] ?? 50),
            'wsz_smart_features_count' => intval($data['smart_features_count'] ?? 0),
            'wsz_view_quality' => floatval($data['view_quality_score'] ?? 50),
            'wsz_energy_efficiency' => $data['energy_efficiency_class'] ?? 'C',
            
            // Основные материалы
            'wsz_primary_material' => $data['primary_material'] ?? '',
            
            // ИТОГОВЫЕ МЕТРИКИ
            'wsz_ergonomics' => $metrics['ergonomics'],
            'wsz_safety' => $metrics['safety'],
            'wsz_functionality' => $metrics['functionality'],
            'wsz_controllability' => $metrics['controllability'],
            'wsz_habitability' => $metrics['habitability'],
            'wsz_masterability' => $metrics['masterability'],
            'wsz_comfort' => $metrics['comfort'],
            
            // Дополнительные метрики для отладки
            'wsz_temp_normalized' => $metrics['temp_score'],
            'wsz_humidity_normalized' => $metrics['humidity_score'],
            'wsz_co2_normalized' => $metrics['co2_score'],
            'wsz_noise_normalized' => $metrics['noise_score'],
            'wsz_object_count' => $metrics['object_count'],
            'wsz_avg_object_height' => $metrics['avg_height'],
            'wsz_avg_object_width' => $metrics['avg_width'],
            'wsz_avg_object_depth' => $metrics['avg_depth'],
            'wsz_ergonomic_objects' => $metrics['ergonomic_objects_count'],
            'wsz_adjustable_objects' => $metrics['adjustable_objects_count'],
        ];
        
        foreach ( $meta_fields as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    /**
     * Delete all products for a country
     */
    public static function delete_products_by_country( string $country_iso2 ): int {
        global $wpdb;
        
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_country_iso2'
             WHERE p.post_type = %s AND pm.meta_value = %s",
            WSZ_CPT::SLUG,
            strtoupper( $country_iso2 )
        ) );
        
        if ( empty( $ids ) ) {
            return 0;
        }
        
        foreach ( $ids as $id ) {
            $thumbnail_id = get_post_thumbnail_id( $id );
            if ( $thumbnail_id ) {
                wp_delete_attachment( $thumbnail_id, true );
            }
            wp_delete_post( $id, true );
        }
        
        return count( $ids );
    }
}