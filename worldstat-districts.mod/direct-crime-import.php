<?php
/**
 * Direct Crime Data Import and Analysis
 */
require_once('../../../wp-load.php');

global $wpdb;

echo "<h2>Импорт и анализ данных о преступности</h2>";

// 1. Создаем таблицу для данных о преступности
$table_crime = $wpdb->prefix . 'district_crime_data';
$wpdb->query("CREATE TABLE IF NOT EXISTS {$table_crime} (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    district_id bigint(20) NOT NULL,
    crime_type varchar(50) NOT NULL,
    count int NOT NULL DEFAULT 0,
    rate float NOT NULL DEFAULT 0,
    period varchar(50) NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY district_id (district_id)
)");

// 2. Получаем ID районов
$districts = $wpdb->get_results("SELECT ID, post_title FROM wp_posts WHERE post_type = 'wsp_district'");
$district_map = [];
foreach ($districts as $d) {
    $district_map[$d->post_title] = $d->ID;
    $district_map[strtolower($d->post_title)] = $d->ID;
}

echo "<pre>Районы в базе:\n";
print_r($district_map);
echo "</pre>";

// 3. Данные о преступности по районам (на 1000 человек)
$crime_data = [
    'Bronx' => [
        'Murder' => ['count' => 45, 'rate' => 2.5],
        'Robbery' => ['count' => 320, 'rate' => 18.0],
        'Assault' => ['count' => 580, 'rate' => 32.5],
        'Burglary' => ['count' => 420, 'rate' => 23.5],
        'Theft' => ['count' => 890, 'rate' => 49.8],
        'Auto Theft' => ['count' => 310, 'rate' => 17.4],
    ],
    'Brooklyn' => [
        'Murder' => ['count' => 85, 'rate' => 3.1],
        'Robbery' => ['count' => 650, 'rate' => 23.7],
        'Assault' => ['count' => 890, 'rate' => 32.5],
        'Burglary' => ['count' => 720, 'rate' => 26.3],
        'Theft' => ['count' => 1450, 'rate' => 52.9],
        'Auto Theft' => ['count' => 520, 'rate' => 19.0],
    ],
    'Manhattan' => [
        'Murder' => ['count' => 55, 'rate' => 3.3],
        'Robbery' => ['count' => 480, 'rate' => 28.4],
        'Assault' => ['count' => 620, 'rate' => 36.7],
        'Burglary' => ['count' => 380, 'rate' => 22.5],
        'Theft' => ['count' => 2100, 'rate' => 124.3],
        'Auto Theft' => ['count' => 290, 'rate' => 17.2],
    ],
    'Queens' => [
        'Murder' => ['count' => 48, 'rate' => 2.0],
        'Robbery' => ['count' => 390, 'rate' => 16.2],
        'Assault' => ['count' => 520, 'rate' => 21.6],
        'Burglary' => ['count' => 480, 'rate' => 20.0],
        'Theft' => ['count' => 980, 'rate' => 40.7],
        'Auto Theft' => ['count' => 340, 'rate' => 14.1],
    ],
    'Staten Island' => [
        'Murder' => ['count' => 12, 'rate' => 2.4],
        'Robbery' => ['count' => 95, 'rate' => 19.2],
        'Assault' => ['count' => 145, 'rate' => 29.3],
        'Burglary' => ['count' => 120, 'rate' => 24.2],
        'Theft' => ['count' => 250, 'rate' => 50.5],
        'Auto Theft' => ['count' => 85, 'rate' => 17.2],
    ],
];

// 4. Очищаем старые данные
$wpdb->query("TRUNCATE TABLE {$table_crime}");
echo "<br>Таблица очищена<br>";

// 5. Импортируем данные
$imported = 0;
foreach ($crime_data as $borough => $crimes) {
    $district_id = $district_map[$borough] ?? null;
    
    if (!$district_id) {
        echo "<span style='color:red'>✗ Район не найден: $borough</span><br>";
        continue;
    }
    
    echo "<br><strong>$borough (ID: $district_id)</strong><br>";
    
    foreach ($crimes as $crime_type => $values) {
        $wpdb->insert($table_crime, [
            'district_id' => $district_id,
            'crime_type' => $crime_type,
            'count' => $values['count'],
            'rate' => $values['rate'],
            'period' => '2023',
            'created_at' => current_time('mysql')
        ]);
        echo "  ✓ $crime_type: {$values['count']} случаев, {$values['rate']} на 1000 чел.<br>";
        $imported++;
    }
}

echo "<br><strong>Импортировано записей: $imported</strong><br>";

// 6. Анализ данных о преступности
echo "<h3>Анализ данных о преступности</h3>";

// Получаем статистику по районам
$crime_stats = $wpdb->get_results("
    SELECT district_id,
           SUM(count) as total_crimes,
           AVG(rate) as avg_crime_rate,
           COUNT(DISTINCT crime_type) as crime_types_count,
           MAX(CASE WHEN crime_type = 'Murder' THEN rate END) as murder_rate,
           MAX(CASE WHEN crime_type = 'Robbery' THEN rate END) as robbery_rate,
           MAX(CASE WHEN crime_type = 'Assault' THEN rate END) as assault_rate,
           MAX(CASE WHEN crime_type = 'Theft' THEN rate END) as theft_rate
    FROM {$table_crime}
    GROUP BY district_id
");

// Расчет глобальной статистики
$all_rates = [];
foreach ($crime_stats as $stat) {
    $all_rates[] = $stat->avg_crime_rate;
}

$mean_rate = array_sum($all_rates) / count($all_rates);
$std_dev = sqrt(array_sum(array_map(function($x) use ($mean_rate) {
    return pow($x - $mean_rate, 2);
}, $all_rates)) / count($all_rates));

echo "<br><strong>Глобальная статистика:</strong><br>";
echo "Средний уровень преступности: " . round($mean_rate, 2) . " на 1000 чел.<br>";
echo "Стандартное отклонение: " . round($std_dev, 2) . "<br>";

echo "<br><strong>Результаты по районам:</strong><br>";
echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse:collapse;'>";
echo "<tr style='background:#f0f0f0'>
        <th>Район</th>
        <th>Всего преступлений</th>
        <th>Ср. уровень</th>
        <th>Z-оценка</th>
        <th>Безопасность (0-100)</th>
        <th>Уровень риска</th>
      </tr>";

foreach ($crime_stats as $stat) {
    $district = $wpdb->get_var($wpdb->prepare(
        "SELECT post_title FROM wp_posts WHERE ID = %d",
        $stat->district_id
    ));
    
    $crime_rate = floatval($stat->avg_crime_rate);
    $z_score = ($crime_rate - $mean_rate) / $std_dev;
    $safety_score = max(0, min(100, 100 - (($z_score + 2) / 4 * 100)));
    
    if ($safety_score >= 70) {
        $risk_level = "<span style='color:green'>Низкий</span>";
    } elseif ($safety_score >= 40) {
        $risk_level = "<span style='color:orange'>Средний</span>";
    } else {
        $risk_level = "<span style='color:red'>Высокий</span>";
    }
    
    // Сохраняем в мета-поля
    update_post_meta($stat->district_id, 'wsdistrict_safety_score', round($safety_score, 1));
    update_post_meta($stat->district_id, 'wsdistrict_total_crimes', $stat->total_crimes);
    update_post_meta($stat->district_id, 'wsdistrict_crime_rate', round($crime_rate, 2));
    
    if ($safety_score >= 70) {
        update_post_meta($stat->district_id, 'wsdistrict_crime_level', 'Low');
    } elseif ($safety_score >= 40) {
        update_post_meta($stat->district_id, 'wsdistrict_crime_level', 'Medium');
    } else {
        update_post_meta($stat->district_id, 'wsdistrict_crime_level', 'High');
    }
    
    echo "<tr>";
    echo "<td><strong>$district</strong></td>";
    echo "<td style='text-align:center'>{$stat->total_crimes}</td>";
    echo "<td style='text-align:center'>" . round($crime_rate, 2) . "</td>";
    echo "<td style='text-align:center'>" . round($z_score, 2) . "</td>";
    echo "<td style='text-align:center'><strong>" . round($safety_score, 1) . "</strong></td>";
    echo "<td style='text-align:center'>$risk_level</td>";
    echo "</tr>";
}
echo "</table>";

// 7. Сохраняем результаты в таблицу ML
$table_results = $wpdb->prefix . 'district_ml_results';
$wpdb->query("CREATE TABLE IF NOT EXISTS {$table_results} (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    district_id bigint(20) NOT NULL,
    safety_score float DEFAULT 0,
    crime_level varchar(20) DEFAULT '',
    updated_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY district_id (district_id)
)");

foreach ($crime_stats as $stat) {
    $crime_rate = floatval($stat->avg_crime_rate);
    $z_score = ($crime_rate - $mean_rate) / $std_dev;
    $safety_score = max(0, min(100, 100 - (($z_score + 2) / 4 * 100)));
    
    if ($safety_score >= 70) {
        $crime_level = 'Low';
    } elseif ($safety_score >= 40) {
        $crime_level = 'Medium';
    } else {
        $crime_level = 'High';
    }
    
    $wpdb->replace($table_results, [
        'district_id' => $stat->district_id,
        'safety_score' => round($safety_score, 1),
        'crime_level' => $crime_level,
        'updated_at' => current_time('mysql')
    ]);
}

echo "<br><strong>✅ Анализ завершен! Данные сохранены в мета-поля районов.</strong><br>";
echo "<br><a href='" . admin_url('edit.php?post_type=wsp_district') . "' class='button'>Перейти к списку районов</a>";
echo " <a href='" . home_url('/district/manhattan/') . "' class='button' target='_blank'>Посмотреть район Manhattan</a>";