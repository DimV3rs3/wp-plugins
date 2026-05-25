<?php
/**
 * Plugin Name:       WorldStat — Room Zones PRO MOD to worldstat-ergonomics
 * Plugin URI:        https://worldstatistics.dev/extensions/zone
 * Description:       Анализ зон помещений: комфортность, безопасность, эргономика.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            World Statistics Team
 * License:           GPL v2 or later
 * Text Domain:       worldstat-zone
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSZ_VERSION', '2.0.0' );
define( 'WSZ_FILE',    __FILE__ );
define( 'WSZ_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WSZ_URL',     plugin_dir_url( __FILE__ ) );
define( 'WSZ_CSV_DIR', WSZ_DIR . 'csv_files/' );

/* ── Создаем папку при активации ───────────────────────── */
register_activation_hook( __FILE__, 'wsz_create_folders' );
function wsz_create_folders() {
    if ( ! file_exists( WSZ_CSV_DIR ) ) {
        wp_mkdir_p( WSZ_CSV_DIR );
    }
    
    $htaccess = WSZ_CSV_DIR . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Deny from all\n" );
    }
    
    $index = WSZ_CSV_DIR . 'index.php';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, "<?php\n// Silence is golden\n" );
    }
}

/* ── Проверка наличия платформы ────────────────────────── */
if ( ! class_exists( 'WorldStat_Core' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WorldStat Room Zones PRO</strong> requires <strong>World Statistics Platform</strong>.</p></div>';
    } );
    return;
}

/* ── Подключение файлов ─────────────────────────────────── */
require_once WSZ_DIR . 'includes/class-zone-cpt.php';
require_once WSZ_DIR . 'includes/class-zone-importer.php';
require_once WSZ_DIR . 'includes/class-zone-data.php';
require_once WSZ_DIR . 'includes/class-zone-renderer.php';
require_once WSZ_DIR . 'includes/class-zone-admin.php';
require_once WSZ_DIR . 'includes/class-zone-metrics-calculator.php';
require_once WSZ_DIR . 'includes/class-zone-ml.php';
require_once WSZ_DIR . 'includes/class-zone-page.php';

/* ── Шорткод для фронтенда ─────────────────────────────── */
add_shortcode( 'wsz_zones_page', 'wsz_render_frontend_page' );
function wsz_render_frontend_page( $atts ) {
    wp_enqueue_style( 'dashicons' );
    ob_start();
    WSZ_Page::render_page();
    return ob_get_clean();
}

/* ── Регистрация расширения на платформе ───────────────── */
add_action( 'worldstat_init', function () {

    WorldStat_Extensions::register( [
        'id'                => 'zones',
        'name'              => 'Room Zones PRO',
        'version'           => WSZ_VERSION,
        'author'            => 'World Statistics Team',
        'description'       => 'Анализ зон помещений: комфортность, безопасность, эргономика.',
        'icon'              => 'dashicons-admin-home',
        'requires_platform' => '1.0.0',
    ] );

    WorldStat_Extensions::add_data_provider( 'zones', [
        'metrics' => [
            'zones_total' => [
                'label'       => 'Всего зон',
                'type'        => 'integer',
                'unit'        => '',
                'description' => 'Общее количество зон в базе',
                'callback'    => [ 'WSZ_Data', 'get_total_zones' ],
            ],
            'avg_ergonomics_global' => [
                'label'       => 'Средняя эргономика',
                'type'        => 'number',
                'unit'        => '/100',
                'description' => 'Средний индекс эргономики по всем зонам',
                'callback'    => [ 'WSZ_Data', 'get_global_avg_ergonomics' ],
            ],
        ],
    ] );

    WorldStat_Extensions::add_country_tab( 'zones', [
        'title'    => 'Зоны',
        'icon'     => 'dashicons-admin-home',
        'callback' => [ 'WSZ_Renderer', 'render_country_tab' ],
        'priority' => 20,
    ] );

    if ( method_exists( 'WorldStat_Extensions', 'add_city_tab' ) ) {
        WorldStat_Extensions::add_city_tab( 'zones', [
            'title'    => 'Зоны',
            'icon'     => 'dashicons-admin-home',
            'callback' => [ 'WSZ_Renderer', 'render_city_tab' ],
            'priority' => 20,
        ] );
    }

    WorldStat_Extensions::add_map_layer( 'zones', [
        'label'         => 'Зоны мира',
        'type'          => 'markers',
        'color_scale'   => [ '#10b981', '#ef4444' ],
        'data_callback' => [ 'WSZ_Data', 'get_global_map_data' ],
    ] );

} );

/* ── Инициализация CPT и админки ───────────────────────── */
new WSZ_CPT();
if ( is_admin() ) {
    new WSZ_Admin();
}

/* ── Шаблон для одиночной записи зоны ───────────────────── */
add_filter( 'worldstat_single_template', function ( string $template, string $post_type ): string {
    if ( $post_type === WSZ_CPT::SLUG ) {
        $path = WSZ_DIR . 'templates/single-wsz_zone.php';
        if ( file_exists( $path ) ) return $path;
    }
    return $template;
}, 10, 2 );

/* ── Регистрация типа как страницы платформы ────────────── */
add_filter( 'worldstat_extension_post_types', function ( array $types ): array {
    $types[] = WSZ_CPT::SLUG;
    return $types;
} );

/* ── ДОБАВЛЯЕМ ОДИН ПУНКТ В WORLD STATISTICS (с вкладками внутри) ── */
add_action( 'admin_menu', 'wsz_add_menu_to_worldstat', 30 );
add_action( 'admin_post_wsz_run_ml', [ 'WSZ_ML', 'handle_ml_run' ] );
function wsz_add_menu_to_worldstat() {
    add_submenu_page(
        'worldstat',
        'Зоны',
        '🏠 Зоны',
        'manage_options',
        'worldstat-zones',
        'wsz_render_main_page'
    );
}

function wsz_render_main_page() {
    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'zones';
    ?>
    <div class="wrap">
        <h1>WorldStat Zones</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=worldstat-zones&tab=zones" class="nav-tab <?php echo $active_tab === 'zones' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-admin-home"></span> Зоны
            </a>
            <a href="?page=worldstat-zones&tab=datasets" class="nav-tab <?php echo $active_tab === 'datasets' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-database"></span> Датасеты
            </a>
            <a href="?page=worldstat-zones&tab=upload" class="nav-tab <?php echo $active_tab === 'upload' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-upload"></span> Загрузка CSV
            </a>
            <a href="?page=worldstat-zones&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-admin-settings"></span> Настройки
            </a>
            <a href="?page=worldstat-zones&tab=analysis" class="nav-tab <?php echo $active_tab === 'analysis' ? 'nav-tab-active' : ''; ?>">
                <span class="dashicons dashicons-chart-bar"></span> ML-анализ
            </a>
        </h2>
        
        <div class="tab-content">
            <?php
            switch ( $active_tab ) {
                case 'zones':
                    WSZ_Page::render_page();
                    break;
                case 'datasets':
                    wsz_render_datasets_tab();
                    break;
                case 'upload':
                    $admin = new WSZ_Admin();
                    $admin->render_page();
                    break;
                case 'analysis':
                    WSZ_ML::render_page();
                    break;
                case 'settings':
                    wsz_render_settings_tab();
                    break;
                default:
                    WSZ_Page::render_page();
                    break;
            }
            ?>
        </div>
    </div>
    
    <style>
        .nav-tab-wrapper {
            border-bottom: 1px solid #c3c4c7;
            margin-bottom: 20px;
            padding: 0;
        }
        .nav-tab {
            padding: 10px 20px;
            font-size: 14px;
            text-decoration: none;
        }
        .nav-tab .dashicons {
            vertical-align: middle;
            margin-right: 5px;
        }
        .tab-content {
            background: #fff;
            padding: 20px;
            border: 1px solid #c3c4c7;
            border-top: none;
            border-radius: 0 0 8px 8px;
        }
    </style>
    <?php
}

function wsz_render_datasets_tab() {
    global $wpdb;
    
    $datasets = $wpdb->get_results( $wpdb->prepare(
        "SELECT COUNT(*) as total, pm.meta_value as country_iso2
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_country_iso2'
         WHERE p.post_type = %s AND p.post_status = 'publish'
         GROUP BY pm.meta_value
         ORDER BY total DESC",
        WSZ_CPT::SLUG
    ) );
    ?>
    <?php
    if ( class_exists( 'WSErgo_Extension_Notices' ) ) {
        WSErgo_Extension_Notices::render_moved_notice( 'zone', 'WorldStat Zones — Датасеты' );
    }
    ?>
    <h2>📊 Доступные датасеты</h2>
    <?php if ( empty( $datasets ) ): ?>
        <div class="notice notice-info">
            <p>Нет загруженных датасетов. Перейдите на вкладку "Загрузка CSV" чтобы добавить данные.</p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr><th>Страна</th><th>Количество зон</th><th>Средняя эргономика</th><th>Действия</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $datasets as $dataset ): 
                    $country_name = WSZ_Data::get_country_name_by_iso2( $dataset->country_iso2 );
                    $avg_ergonomics = WSZ_Data::get_avg_ergonomics_by_country( $dataset->country_iso2 );
                ?>
                    <tr>
                        <td><?php echo esc_html( $country_name ?: $dataset->country_iso2 ); ?></td>
                        <td><?php echo number_format( $dataset->total ); ?></td>
                        <td><?php echo round( $avg_ergonomics, 1 ); ?> / 100</span></td>
                        <td>
                            <button class="button" onclick="wszExportDataset('<?php echo esc_js( $dataset->country_iso2 ); ?>')">📥 Экспорт</button>
                            <button class="button" style="color:#d63638;" onclick="wszDeleteDataset('<?php echo esc_js( $dataset->country_iso2 ); ?>')">🗑️ Удалить</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <script>
    function wszExportDataset(iso2) {
        window.location.href = '<?php echo admin_url( 'admin-post.php?action=wsz_export_dataset&_wpnonce=' . wp_create_nonce( 'wsz_export' ) ); ?>&country_iso2=' + iso2;
    }
    function wszDeleteDataset(iso2) {
        if (confirm('Удалить все данные для этой страны?')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo admin_url( 'admin-post.php' ); ?>';
            form.innerHTML = '<input type="hidden" name="action" value="wsz_delete_country">' +
                           '<input type="hidden" name="country_iso2" value="' + iso2 + '">' +
                           '<input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce( 'wsz_delete_country' ); ?>">';
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
    <?php
}

function wsz_render_settings_tab() {
    ?>
    <h2>⚙️ Настройки</h2>
    <form method="post" action="options.php">
        <?php settings_fields( 'wsz_settings' ); ?>
        <table class="form-table">
            <tr><th>Отображать на карте</th><td><label><input type="checkbox" name="wsz_show_on_map" value="1" <?php checked( get_option( 'wsz_show_on_map', 1 ), 1 ); ?>> Показывать зоны на карте</label></td></tr>
            <tr><th>Зон на странице</th><td><input type="number" name="wsz_zones_per_page" value="<?php echo esc_attr( get_option( 'wsz_zones_per_page', 12 ) ); ?>" min="6" max="48"></td></tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <?php
}

/* ── Экспорт датасета ───────────────────────────────────── */
add_action( 'admin_post_wsz_export_dataset', 'wsz_handle_export_dataset' );
function wsz_handle_export_dataset() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied' );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'wsz_export' ) ) wp_die( 'Invalid nonce' );
    
    $country_iso2 = sanitize_text_field( $_GET['country_iso2'] );
    $zones = WSZ_CPT::get_products_by_country( $country_iso2 );
    
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="zones_' . $country_iso2 . '_' . date('Y-m-d') . '.csv"' );
    
    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, [ 'ID', 'zone_name', 'category', 'zone_type', 'area_sqm', 'temp', 'humidity', 'noise', 'light', 'co2', 'furniture_type', 'furniture_style', 'furniture_material', 'ergonomics', 'country_name', 'country_iso2' ] );
    
    foreach ( $zones as $zone ) {
        fputcsv( $output, [
            $zone['id'],
            $zone['name'],
            $zone['category'],
            $zone['room_type'],
            $zone['area'],
            $zone['temp'],
            $zone['humidity'],
            $zone['noise_level'],
            $zone['lighting'],
            $zone['co2'],
            $zone['furniture_type'],
            $zone['furniture_style'],
            $zone['furniture_material'],
            $zone['ergonomics'],
            $zone['country_name'],
            $zone['country_iso2'],
        ] );
    }
    
    fclose( $output );
    exit;
}

/* ── Обработка импорта CSV ─────────────────────────────────── */
add_action( 'admin_post_wsz_import_csv', 'wsz_handle_csv_import' );
add_action( 'admin_post_wsz_delete_country', 'wsz_handle_delete_country' );

function wsz_handle_csv_import() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Доступ запрещен' );
    
    $country_iso2 = isset( $_POST['country_iso2'] ) ? sanitize_text_field( $_POST['country_iso2'] ) : 'RU';
    $country_name = isset( $_POST['country_name'] ) ? sanitize_text_field( $_POST['country_name'] ) : 'Россия';
    $nonce = isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : '';
    
    if ( ! wp_verify_nonce( $nonce, 'wsz_import_' . $country_iso2 ) ) wp_die( 'Неверный nonce' );
    if ( empty( $_FILES['csv_file'] ) ) wp_die( 'Ошибка загрузки файла' );
    
    $result = WSZ_Importer::import_csv( $_FILES['csv_file']['tmp_name'], $country_iso2, $country_name );
    
    wp_redirect( add_query_arg( 'imported', $result['imported'], wp_get_referer() ) );
    exit;
}

function wsz_handle_delete_country() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Доступ запрещен' );
    
    $country_iso2 = isset( $_POST['country_iso2'] ) ? sanitize_text_field( $_POST['country_iso2'] ) : '';
    $nonce = isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : '';
    
    if ( ! wp_verify_nonce( $nonce, 'wsz_delete_country' ) ) wp_die( 'Неверный nonce' );
    
    WSZ_Importer::delete_products_by_country( $country_iso2 );
    
    wp_redirect( add_query_arg( 'deleted', '1', wp_get_referer() ) );
    exit;
}

/* ── Регистрация настроек ─────────────────────────────────── */
add_action( 'admin_init', 'wsz_register_settings' );
function wsz_register_settings() {
    register_setting( 'wsz_settings', 'wsz_show_on_map' );
    register_setting( 'wsz_settings', 'wsz_zones_per_page' );
    register_setting( 'wsz_settings', 'wsz_color_scheme' );
}
/* ── БЛОКИРУЕМ AJAX НА ФРОНТЕНДЕ ───────────────────────── */
add_action('init', function() {
    if (!is_admin() && isset($_GET['wsp_country'])) {
        // Отключаем все AJAX действия, которые могут вызываться
        remove_all_actions('wp_ajax_wsz_filter_zones');
        remove_all_actions('wp_ajax_nopriv_wsz_filter_zones');
        
        // Блокируем прямой доступ к admin-ajax.php
        if (strpos($_SERVER['REQUEST_URI'], 'admin-ajax.php') !== false) {
            wp_die('AJAX disabled on this page', 'Forbidden', ['response' => 403]);
        }
    }
});
/* ── Chunked upload handler ──────────────────────────────── */
add_action( 'wp_ajax_wsz_upload_chunk', function() {
    @ini_set('memory_limit', '1024M');
    @ini_set('max_execution_time', 3600);
    
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wsz_chunk_upload' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Access denied' );
    }
    
    $upload_dir = wp_upload_dir()['basedir'] . '/wsz_csv/';
    if ( ! file_exists( $upload_dir ) ) {
        wp_mkdir_p( $upload_dir );
    }
    
    $chunk_index = (int) ($_POST['chunk_index'] ?? 0);
    $total_chunks = (int) ($_POST['total_chunks'] ?? 1);
    $original_name = sanitize_file_name( $_POST['original_name'] ?? 'file.csv' );
    $chunk_data = base64_decode( $_POST['chunk_data'] ?? '' );
    
    if ( empty( $chunk_data ) ) {
        wp_send_json_error( 'Empty chunk data' );
    }
    
    $temp_file = $upload_dir . 'temp_' . md5( $original_name ) . '.part';
    file_put_contents( $temp_file, $chunk_data, FILE_APPEND );
    
    if ( $chunk_index == $total_chunks - 1 ) {
        $final_name = date('Y-m-d_H-i-s') . '_' . $original_name;
        $final_path = $upload_dir . $final_name;
        rename( $temp_file, $final_path );
        
        wp_send_json_success( [ 'finished' => true, 'filename' => $final_name ] );
    } else {
        wp_send_json_success( [ 'finished' => false ] );
    }
} );