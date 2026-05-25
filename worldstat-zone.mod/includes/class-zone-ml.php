<?php
/**
 * ML analysis integration for WorldStat Zones
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_ML {
    
    public static function get_output_dir(): string {
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'wsz_ml/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    public static function get_output_url(): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['baseurl'] ) . 'wsz_ml/';
    }

    public static function get_dataset_path(): string {
        return self::get_output_dir() . 'wsz_ml_dataset.csv';
    }

    public static function export_ml_dataset(): string {
        $dataset_path = self::get_dataset_path();
        $zones = WSZ_CPT::get_all_zones();

        $headers = [
            'id',
            'zone_name',
            'category',
            'zone_type',
            'area_sqm',
            'temp',
            'humidity',
            'noise',
            'light',
            'co2',
            'furniture_type',
            'furniture_style',
            'furniture_material',
            'ergonomics',
            'country_name',
            'country_iso2',
        ];

        $handle = fopen( $dataset_path, 'w' );
        if ( ! $handle ) {
            return $dataset_path;
        }

        fputcsv( $handle, $headers );

        foreach ( $zones as $zone ) {
            fputcsv( $handle, [
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

        fclose( $handle );
        return $dataset_path;
    }

    public static function get_node_command(): ?string {
        if ( ! function_exists( 'shell_exec' ) ) {
            return null;
        }

        $commands = [ 'node', 'node.exe' ];
        foreach ( $commands as $command ) {
            $where = strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN'
                ? shell_exec( "where {$command} 2>NUL" )
                : shell_exec( "command -v {$command} 2>/dev/null" );
            if ( $where ) {
                $path = strtok( trim( $where ), "\r\n" );
                if ( $path ) {
                    return $path;
                }
            }
        }

        return null;
    }

    public static function run_analysis(): array {
        $node = self::get_node_command();
        if ( ! $node ) {
            return [
                'success' => false,
                'error'   => 'Node.js не найден на сервере. Установите Node.js.',
            ];
        }

        $dataset = self::export_ml_dataset();
        $output_dir = self::get_output_dir();
        $script = WSZ_DIR . 'js/ml_analysis.js';

        if ( ! file_exists( $script ) ) {
            return [
                'success' => false,
                'error'   => 'ML-скрипт не найден: ' . $script,
            ];
        }

        $cmd = escapeshellarg( $node ) . ' ' . escapeshellarg( $script ) .
               ' --input ' . escapeshellarg( $dataset ) .
               ' --out-dir ' . escapeshellarg( $output_dir );

        $output = [];
        $return = 0;
        exec( $cmd . ' 2>&1', $output, $return );
        $result_json = $output_dir . 'analysis_result.json';

        if ( $return !== 0 || ! file_exists( $result_json ) ) {
            return [
                'success' => false,
                'error'   => implode( "\n", $output ),
            ];
        }

        $text = file_get_contents( $result_json );
        $data = json_decode( $text, true );
        if ( ! is_array( $data ) ) {
            return [
                'success' => false,
                'error'   => 'Неверный результат анализа: ' . $text,
            ];
        }

        return array_merge( [ 'success' => true ], $data );
    }

    public static function handle_ml_run(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Доступ запрещен' );
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wsz_run_ml' ) ) {
            wp_die( 'Неверный nonce' );
        }

        $result = self::run_analysis();
        $args = [ 'tab' => 'analysis' ];
        if ( ! $result['success'] ) {
            $args['ml_status'] = 'error';
            $args['ml_message'] = urlencode( $result['error'] );
        } else {
            $args['ml_status'] = 'success';
        }

        wp_redirect( add_query_arg( $args, wp_get_referer() ) );
        exit;
    }

    public static function get_analysis_images(): array {
        $base_url = self::get_output_url();
        $files = [
            'elbow' => 'elbow.svg',
            'clusters' => 'clusters.svg',
            'feature_importance' => 'feature_importance.svg',
            'classification_accuracy' => 'classification_accuracy.svg',
        ];

        $result = [];
        foreach ( $files as $key => $filename ) {
            if ( file_exists( self::get_output_dir() . $filename ) ) {
                $result[ $key ] = $base_url . $filename;
            }
        }

        return $result;
    }

    public static function render_page(): void {
        $images = self::get_analysis_images();
        $dataset_path = self::get_dataset_path();
        $dataset_exists = file_exists( $dataset_path );
        $status = isset( $_GET['ml_status'] ) ? sanitize_text_field( $_GET['ml_status'] ) : '';
        $message = isset( $_GET['ml_message'] ) ? urldecode( sanitize_text_field( $_GET['ml_message'] ) ) : '';
        ?>
        <div class="wrap">
            <h1>ML-анализ зон</h1>
            <?php if ( $status === 'success' ): ?>
                <div class="notice notice-success"><p>✅ Анализ завершён успешно.</p></div>
            <?php elseif ( $status === 'error' ): ?>
                <div class="notice notice-error"><p>❌ Ошибка анализа: <?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>

            <div class="card" style="padding: 20px; margin-bottom: 20px;">
                <h2>📦 Данные для анализа</h2>
                <p>Будет использован один файл данных из базы данных зон. Он экспортируется автоматически.</p>
                <p><strong>Путь файла:</strong> <?php echo esc_html( $dataset_path ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wsz_run_ml' ); ?>
                    <input type="hidden" name="action" value="wsz_run_ml">
                    <button type="submit" class="button button-primary">Запустить ML-анализ</button>
                </form>
            </div>

            <?php if ( ! empty( $images ) ): ?>
                <div class="card" style="padding: 20px; margin-bottom: 20px;">
                    <h2>📈 Результаты анализа</h2>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit,minmax(320px,1fr)); gap:20px;">
                        <?php foreach ( $images as $label => $url ): ?>
                            <div style="border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; background:#fff;">
                                <div style="padding:12px; font-weight:700; text-transform:capitalize; background:#f8fafc;">
                                    <?php 
                                        $label_text = [
                                            'elbow' => 'Метод локтя',
                                            'clusters' => 'Кластеры зон',
                                            'feature_importance' => 'Важность признаков',
                                            'classification_accuracy' => 'Точность классификации'
                                        ][$label] ?? str_replace('_', ' ', $label);
                                        echo esc_html( $label_text );
                                    ?>
                                </div>
                                <img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( $label ); ?>" style="width:100%; display:block;">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( ! $dataset_exists ): ?>
                <div class="notice notice-warning"><p>Файл данных для анализа ещё не создан. Запустите анализ, чтобы экспортировать данные и построить графики.</p></div>
            <?php endif; ?>
        </div>
        <?php
    }
}