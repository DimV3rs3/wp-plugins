<?php
require_once('../../../wp-load.php');

global $wpdb;

echo "<h2>Тест импорта пешеходных данных</h2>";

// 1. Проверяем районы в базе
$districts = $wpdb->get_results("SELECT ID, post_title FROM wp_posts WHERE post_type = 'wsp_district'");
echo "<h3>Районы в базе:</h3>";
foreach ($districts as $d) {
    echo "ID: {$d->ID} - '{$d->post_title}'<br>";
}

// 2. Создаем таблицу если не существует
$table = $wpdb->prefix . 'district_pedestrian_data';
$wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    district_id bigint(20) NOT NULL,
    street_name varchar(255) NOT NULL,
    category varchar(100) NOT NULL,
    rank int NOT NULL DEFAULT 0,
    segment_id varchar(50) DEFAULT '',
    borough varchar(50) NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
)");

// 3. Читаем CSV файл
$csv_file = __DIR__ . '/pedetion.csv';
if (!file_exists($csv_file)) {
    die("Файл не найден: $csv_file");
}

$handle = fopen($csv_file, 'r');
$headers = fgetcsv($handle);

echo "<h3>Импорт данных:</h3>";

$imported = 0;
while (($row = fgetcsv($handle)) !== false) {
    $borough = trim($row[0]);
    $street = trim($row[1]);
    $rank = intval($row[2]);
    $category = trim($row[3]);
    
    echo "<br>Обработка: Borough='$borough', Street='$street', Rank=$rank<br>";
    
    // Находим ID района
    $district_id = null;
    foreach ($districts as $d) {
        if (strcasecmp($d->post_title, $borough) === 0) {
            $district_id = $d->ID;
            echo "  -> Найден район: {$d->post_title} (ID: $district_id)<br>";
            break;
        }
    }
    
    if (!$district_id) {
        echo "  -> <span style='color:red'>ОШИБКА: Район '$borough' не найден!</span><br>";
        continue;
    }
    
    // Вставляем данные
    $result = $wpdb->insert($table, [
        'district_id' => $district_id,
        'street_name' => $street,
        'category' => $category,
        'rank' => $rank,
        'segment_id' => uniqid(),
        'borough' => $borough,
        'created_at' => current_time('mysql')
    ]);
    
    if ($result) {
        echo "  -> <span style='color:green'>УСПЕШНО: Данные вставлены</span><br>";
        $imported++;
    } else {
        echo "  -> <span style='color:red'>ОШИБКА: " . $wpdb->last_error . "</span><br>";
    }
}

fclose($handle);

echo "<h3>Итог: Импортировано $imported записей</h3>";

// 4. Проверяем результат
$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
echo "<h3>Всего записей в таблице: $count</h3>";

$records = $wpdb->get_results("SELECT * FROM {$table}");
if ($records) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>District ID</th><th>Street</th><th>Rank</th><th>Category</th></tr>";
    foreach ($records as $r) {
        echo "<tr>";
        echo "<td>{$r->id}</td>";
        echo "<td>{$r->district_id}</td>";
        echo "<td>{$r->street_name}</td>";
        echo "<td>{$r->rank}</td>";
        echo "<td>{$r->category}</td>";
        echo "</tr>";
    }
    echo "</table>";
}