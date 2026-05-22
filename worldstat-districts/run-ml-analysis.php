<?php
require_once('../../../wp-load.php');

global $wpdb;

$table_air = $wpdb->prefix . 'district_air_quality';
$table_results = $wpdb->prefix . 'district_ml_results';

// Проверяем наличие данных
$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_air}");
echo "Записей в таблице air_quality: $count<br>";

if ($count == 0) {
    echo "Нет данных о качестве воздуха. Сначала импортируйте CSV файл.<br>";
    exit;
}

// Получаем данные по районам
$districts = $wpdb->get_results("
    SELECT district_id, 
           AVG(CASE WHEN pollutant = 'ozone' THEN value END) as ozone,
           AVG(CASE WHEN pollutant = 'no2' THEN value END) as no2,
           AVG(CASE WHEN pollutant = 'pm25' THEN value END) as pm25
    FROM {$table_air}
    GROUP BY district_id
");

echo "Найдено районов с данными: " . count($districts) . "<br>";

$updated = 0;
foreach ($districts as $d) {
    $ozone = floatval($d->ozone);
    $no2 = floatval($d->no2);
    $pm25 = floatval($d->pm25);
    
    echo "Район ID: {$d->district_id}, Ozone: $ozone, NO2: $no2, PM25: $pm25<br>";
    
    // Нормализация (чем ниже загрязнение, тем выше оценка)
    $ozone_score = max(0, min(100, 100 - ($ozone / 50 * 100)));
    $no2_score = max(0, min(100, 100 - ($no2 / 40 * 100)));
    $pm25_score = max(0, min(100, 100 - ($pm25 / 25 * 100)));
    
    $comfort = round( ($ozone_score + $no2_score + $pm25_score) / 3, 1 );
    $safety = round( 100 - ($pm25_score * 0.4), 1 );
    $functionality = round( 50 + ($no2_score * 0.3) + ($ozone_score * 0.2), 1 );
    
    // Определяем класс качества
    $avg_pollution = ($ozone + $no2 + $pm25) / 3;
    if ( $avg_pollution < 20 ) {
        $classification = 'Good';
    } elseif ( $avg_pollution < 35 ) {
        $classification = 'Moderate';
    } else {
        $classification = 'Poor';
    }
    
    // Сохраняем результаты
    $wpdb->replace($table_results, [
        'district_id' => $d->district_id,
        'cluster' => 0,
        'classification' => $classification,
        'comfort_score' => $comfort,
        'safety_score' => $safety,
        'functionality_score' => $functionality,
        'updated_at' => current_time('mysql')
    ]);
    
    // Сохраняем в мета-поля поста
    update_post_meta($d->district_id, 'wsdistrict_comfort_score', $comfort);
    update_post_meta($d->district_id, 'wsdistrict_safety_score', $safety);
    update_post_meta($d->district_id, 'wsdistrict_functionality_score', $functionality);
    update_post_meta($d->district_id, 'wsdistrict_air_quality_class', $classification);
    
    echo "  -> Сохранено: Comfort=$comfort, Safety=$safety, Class=$classification<br>";
    $updated++;
}

echo "<br><strong>Обновлено районов: $updated</strong>";