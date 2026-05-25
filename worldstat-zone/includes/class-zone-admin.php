<?php
/**
 * CSV Viewer - Упрощенная версия
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_Admin {

    private $upload_dir;

    public function __construct() {
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/wsz_csv/';
        
        add_action( 'admin_menu', [ $this, 'register_menus' ], 20 );
        add_action( 'admin_init', [ $this, 'handle_upload' ] );
        add_action( 'admin_init', [ $this, 'handle_delete' ] );
        add_action( 'admin_init', [ $this, 'handle_import_to_db' ] );
        
        if ( ! file_exists( $this->upload_dir ) ) {
            wp_mkdir_p( $this->upload_dir );
        }
    }

    public function register_menus(): void {
        add_submenu_page(
            'worldstat',
            'CSV Import',
            'CSV Import',
            'manage_options',
            'worldstat-csv',
            [ $this, 'render_page' ]
        );
    }

    public function handle_upload(): void {
        if ( ! isset( $_POST['wsz_upload'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wsz_upload' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            add_action( 'admin_notices', function() { echo '<div class="error"><p>Ошибка загрузки файла</p></div>'; } );
            return;
        }
        
        $filename = sanitize_file_name( $_FILES['csv_file']['name'] );
        $dest = $this->upload_dir . date('Y-m-d_H-i-s') . '_' . $filename;
        
        if ( move_uploaded_file( $_FILES['csv_file']['tmp_name'], $dest ) ) {
            $line_count = 0;
            if ( ( $handle = fopen( $dest, 'r' ) ) !== false ) {
                while ( fgetcsv( $handle ) !== false ) {
                    $line_count++;
                }
                fclose( $handle );
            }
            
            update_option( 'wsz_file_' . basename( $dest ), [
                'original_name' => $filename,
                'size' => $_FILES['csv_file']['size'],
                'rows' => $line_count - 1,
                'date' => current_time( 'mysql' ),
            ] );
            
            add_action( 'admin_notices', function() { echo '<div class="updated"><p>✅ Файл загружен</p></div>'; } );
        }
    }
    
    public function handle_delete(): void {
        if ( ! isset( $_GET['delete_csv'] ) ) return;
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_csv' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        $file = $this->upload_dir . basename( $_GET['delete_csv'] );
        if ( file_exists( $file ) ) {
            unlink( $file );
            delete_option( 'wsz_file_' . basename( $_GET['delete_csv'] ) );
            add_action( 'admin_notices', function() { echo '<div class="updated"><p>🗑️ Файл удален</p></div>'; } );
        }
    }
    
    public function handle_import_to_db(): void {
        if ( ! isset( $_POST['import_csv'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'import_csv' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        $filename = sanitize_file_name( $_POST['csv_file'] );
        $file_path = $this->upload_dir . $filename;
        
        if ( ! file_exists( $file_path ) ) {
            add_action( 'admin_notices', function() { echo '<div class="error"><p>Файл не найден</p></div>'; } );
            return;
        }
        
        $result = WSZ_Importer::import_csv( $file_path, 'RU', 'Россия' );
        
        add_action( 'admin_notices', function() use ($result) { 
            echo '<div class="updated"><p>✅ Импортировано: ' . $result['imported'] . ' | Обновлено: ' . $result['updated'] . ' | Пропущено: ' . $result['skipped'] . '</p></div>';
            if ( ! empty( $result['errors'] ) ) {
                echo '<div class="error"><p>❌ Ошибок: ' . count( $result['errors'] ) . '</p></div>';
            }
        } );
    }

    public function render_page(): void {
        $files = glob( $this->upload_dir . '*.csv' );
        $view_file = isset( $_GET['view_csv'] ) ? $_GET['view_csv'] : '';
        ?>
        <div class="wrap">
            <h1>📁 CSV Import для зон</h1>
            
            <div class="card" style="padding: 20px; margin-bottom: 20px;">
                <h2>📤 Загрузить CSV файл</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'wsz_upload' ); ?>
                    <input type="file" name="csv_file" accept=".csv" required style="margin: 10px 0;">
                    <button type="submit" name="wsz_upload" class="button button-primary">Загрузить</button>
                </form>
            </div>
            
            <div class="card" style="padding: 20px;">
                <h2>📄 Доступные CSV файлы</h2>
                <?php if ( empty( $files ) ): ?>
                    <p>Нет загруженных файлов</p>
                <?php else: ?>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr><th>Имя файла</th><th>Размер</th><th>Строк</th><th>Действия</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $files as $file ): 
                            $name = basename( $file );
                            $meta = get_option( 'wsz_file_' . $name, [] );
                            $size_display = isset($meta['size']) ? round($meta['size'] / 1024, 2) . ' КБ' : round(filesize($file) / 1024, 2) . ' КБ';
                            $rows = $meta['rows'] ?? 0;
                        ?>
                            <tr>
                                <td><?php echo esc_html( $meta['original_name'] ?? $name ); ?></td>
                                <td><?php echo $size_display; ?></td>
                                <td><?php echo number_format( $rows ); ?></td>
                                <td>
                                    <a href="?page=worldstat-csv&view_csv=<?php echo urlencode( $name ); ?>" class="button">👁️ Просмотр</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Импортировать?')">
                                        <?php wp_nonce_field( 'import_csv' ); ?>
                                        <input type="hidden" name="csv_file" value="<?php echo esc_attr( $name ); ?>">
                                        <button type="submit" name="import_csv" class="button button-primary">📥 Импорт в БД</button>
                                    </form>
                                    <a href="?page=worldstat-csv&delete_csv=<?php echo urlencode( $name ); ?>&_wpnonce=<?php echo wp_create_nonce( 'delete_csv' ); ?>" class="button" style="color:#d63638;" onclick="return confirm('Удалить файл?')">🗑️ Удалить</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <?php if ( $view_file && file_exists( $this->upload_dir . $view_file ) ): 
                $rows = array_map( 'str_getcsv', file( $this->upload_dir . $view_file ) );
                $headers = !empty( $rows ) ? array_shift( $rows ) : [];
            ?>
                <div class="card" style="padding: 10px; margin-top: 10px;">
                    <h2>🔍 Просмотр: <?php echo esc_html( $view_file ); ?></h2>
                    <div style="overflow-x: auto;">
                        <table class="wp-list-table widefat striped">
                            <thead><tr><?php foreach ( $headers as $h ): ?><th><?php echo esc_html( $h ); ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                                <?php foreach ( array_slice( $rows, 0, 20 ) as $row ): ?>
                                    <tr><?php foreach ( $row as $cell ): ?><td><?php echo esc_html( mb_substr( $cell, 0, 80 ) ); ?></td><?php endforeach; ?></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <a href="?page=worldstat-csv" class="button">← Назад</a>
                </div>
            <?php endif; ?>
        </div>
        <style>.card { background: #fff; border: 1px solid #ccd0d4; border-radius: 20px; }</style>
        <?php
    }
}