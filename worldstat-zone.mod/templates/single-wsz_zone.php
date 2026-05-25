<?php
/**
 * Single zone template with Metrics Analysis and ML Graphs
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

// Получаем ID всех зон для навигации
$all_zones_ids = get_posts([
    'post_type' => WSZ_CPT::SLUG,
    'posts_per_page' => -1,
    'fields' => 'ids',
    'post_status' => 'publish',
]);

$current_id = get_the_ID();
$current_index = array_search($current_id, $all_zones_ids);
$prev_id = $current_index > 0 ? $all_zones_ids[$current_index - 1] : null;
$next_id = $current_index < count($all_zones_ids) - 1 ? $all_zones_ids[$current_index + 1] : null;

while ( have_posts() ) : the_post();
    
    $zone_id = get_the_ID();
    $zone = WSZ_CPT::get_product_data( $zone_id );
    
    if ( ! $zone || empty( $zone ) ) {
        echo '<div class="wsp-error">Зона не найдена</div>';
        get_footer();
        return;
    }
    
    // Получаем значения
    $ergonomics = isset( $zone['ergonomics'] ) ? floatval( $zone['ergonomics'] ) : 0;
    $lighting = isset( $zone['lighting'] ) ? floatval( $zone['lighting'] ) : 0;
    $safety = isset( $zone['safety'] ) ? floatval( $zone['safety'] ) : 0;
    $comfort = isset( $zone['comfort'] ) ? floatval( $zone['comfort'] ) : 0;
    $functionality = isset( $zone['functionality'] ) ? floatval( $zone['functionality'] ) : 0;
    $controllability = isset( $zone['controllability'] ) ? floatval( $zone['controllability'] ) : 0;
    $habitability = isset( $zone['habitability'] ) ? floatval( $zone['habitability'] ) : 0;
    $masterability = isset( $zone['masterability'] ) ? floatval( $zone['masterability'] ) : 0;
    $noise = isset( $zone['noise_level'] ) ? floatval( $zone['noise_level'] ) : 0;
    $temp = isset( $zone['temp'] ) ? floatval( $zone['temp'] ) : 0;
    $humidity = isset( $zone['humidity'] ) ? floatval( $zone['humidity'] ) : 0;
    $co2 = isset( $zone['co2'] ) ? floatval( $zone['co2'] ) : 0;
    $area = isset( $zone['area'] ) ? floatval( $zone['area'] ) : 0;
    
    // Нормализованные значения
    $light_norm = min(100, $lighting);
    $noise_norm = max(0, 100 - min(100, $noise));
    $co2_norm = $co2 > 0 ? max(0, 100 - min(2000, $co2) / 20) : 0;
    $temp_norm = isset($zone['temp_normalized']) && $zone['temp_normalized'] > 0 ? $zone['temp_normalized'] : ($temp > 0 ? max(0, (1 - abs($temp - 22) / 15) * 100) : 0);
    $humidity_norm = isset($zone['humidity_normalized']) && $zone['humidity_normalized'] > 0 ? $zone['humidity_normalized'] : ($humidity > 0 ? max(0, (1 - abs($humidity - 45) / 30) * 100) : 0);
    
    // Общая оценка
    $overall_score = 0;
    $metric_count = 0;
    if ($ergonomics > 0) { $overall_score += min(100, $ergonomics); $metric_count++; }
    if ($lighting > 0) { $overall_score += min(100, $lighting); $metric_count++; }
    if ($safety > 0) { $overall_score += min(100, $safety); $metric_count++; }
    if ($comfort > 0) { $overall_score += min(100, $comfort); $metric_count++; }
    $overall_score = $metric_count > 0 ? round( $overall_score / $metric_count, 1 ) : $ergonomics;
    
    $rating_level = 'Нет данных';
    $rating_color = '#6b7280';
    $rating_icon = '📊';
    if ( $overall_score >= 80 ) {
        $rating_level = 'Отлично';
        $rating_color = '#10b981';
        $rating_icon = '🏆';
    } elseif ( $overall_score >= 65 ) {
        $rating_level = 'Хорошо';
        $rating_color = '#3b82f6';
        $rating_icon = '👍';
    } elseif ( $overall_score >= 50 ) {
        $rating_level = 'Средне';
        $rating_color = '#f59e0b';
        $rating_icon = '⚠️';
    } elseif ( $overall_score >= 35 ) {
        $rating_level = 'Ниже среднего';
        $rating_color = '#f97316';
        $rating_icon = '📉';
    } elseif ( $overall_score > 0 ) {
        $rating_level = 'Плохо';
        $rating_color = '#ef4444';
        $rating_icon = '❌';
    }
    
    $has_metrics = ($ergonomics > 0 || $lighting > 0 || $safety > 0 || $comfort > 0 || $noise > 0 || $temp > 0);
    
    // ML графики
    $ml_images = [];
    if (class_exists('WSZ_ML')) {
        $ml_images = WSZ_ML::get_analysis_images();
    }
    
    $filter_param = isset( $_GET['filter_param'] ) ? sanitize_text_field( $_GET['filter_param'] ) : 'ergonomics';
    
    ?>
    
    <div class="wsp-single-container wsz-single">
        
        <!-- Навигация -->
        <div style="background: #f8fafc; padding: 12px 20px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto;">
                <?php if ($prev_id): ?>
                    <a href="<?php echo get_permalink($prev_id); ?>?filter_param=<?php echo esc_attr($filter_param); ?>" style="display: flex; align-items: center; gap: 5px; text-decoration: none; color: #3b82f6;">← Предыдущая зона</a>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>
                <div style="text-align: center; font-size: 14px; color: #666;">Зона <?php echo ($current_index + 1); ?> из <?php echo count($all_zones_ids); ?></div>
                <?php if ($next_id): ?>
                    <a href="<?php echo get_permalink($next_id); ?>?filter_param=<?php echo esc_attr($filter_param); ?>" style="display: flex; align-items: center; gap: 5px; text-decoration: none; color: #3b82f6;">Следующая зона →</a>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Header -->
        <div class="wsz-header" style="background: linear-gradient(135deg, <?php echo $rating_color; ?> 0%, #1a2a3a 100%); color: white; padding: 40px 20px; margin-bottom: 30px;">
            <div class="wsp-container" style="max-width: 1200px; margin: 0 auto;">
                <h1 style="color:white; margin:0;"><?php echo esc_html( get_the_title() ); ?></h1>
                <div style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap;">
                    <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px;"><?php echo $zone['category'] === 'офис' ? '🏢 Офис' : '🏠 Дом'; ?></span>
                    <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px;">🏠 <?php echo esc_html( ucfirst( $zone['room_type'] ?: 'зона' ) ); ?></span>
                    <?php if ($area > 0): ?>
                    <span style="background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 20px;">📐 <?php echo esc_html( $area ); ?> м²</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="wsp-container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">

            <?php do_action( 'wsz_zone_ergo_summary', get_the_ID() ); ?>
            
            <?php if ( $has_metrics ): ?>
            <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); padding: 25px; border-radius: 12px; margin-bottom: 30px;">
                <h2 style="margin-top: 0;">📊 Анализ зоны</h2>
                
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">Общая оценка</div>
                        <div style="font-size: 24px; font-weight: bold; color: <?php echo $rating_color; ?>;"><?php echo $overall_score; ?><span style="font-size: 12px;">/100</span></div>
                        <div style="font-size: 11px; color: <?php echo $rating_color; ?>;"><?php echo $rating_icon; ?> <?php echo $rating_level; ?></div>
                    </div>
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">Эргономика</div>
                        <div style="font-size: 24px; font-weight: bold; color: #10b981;"><?php echo round($ergonomics); ?><span style="font-size: 12px;">/100</span></div>
                    </div>
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">Освещение</div>
                        <div style="font-size: 24px; font-weight: bold; color: #f59e0b;"><?php echo round($light_norm); ?><span style="font-size: 12px;">/100</span></div>
                    </div>
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">Тишина</div>
                        <div style="font-size: 24px; font-weight: bold; color: #8b5cf6;"><?php echo round($noise_norm); ?><span style="font-size: 12px;">/100</span></div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 15px 0;">
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">🔒 Безопасность</div>
                        <div style="font-size: 20px; font-weight: bold; color: #ef4444;"><?php echo round($safety); ?>/100</div>
                    </div>
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">⚙️ Функциональность</div>
                        <div style="font-size: 20px; font-weight: bold; color: #06b6d4;"><?php echo round($functionality); ?>/100</div>
                    </div>
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">🎮 Управляемость</div>
                        <div style="font-size: 20px; font-weight: bold; color: #ec489a;"><?php echo round($controllability); ?>/100</div>
                    </div>
                    <div style="text-align: center; background: white; padding: 12px; border-radius: 10px;">
                        <div style="font-size: 12px; color: #666;">🏠 Обитаемость</div>
                        <div style="font-size: 20px; font-weight: bold; color: #14b8a6;"><?php echo round($habitability); ?>/100</div>
                    </div>
                </div>
                
                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 10px; text-align: center;">
                    <button id="toggleFormula" style="background: #1e40af; color: white; padding: 10px 20px; border-radius: 30px; border: none; cursor: pointer; font-size: 14px;">📊 Показать детальный расчет</button>
                </div>
                
                <div id="formulaSection" style="display: none; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-top: 20px;">
                    <h3 style="margin-top: 0;">📐 Детальный расчет показателей</h3>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                        <div style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px;">
                            <h4 style="margin: 0 0 8px 0; color: #10b981;">🪑 Эргономичность</h4>
                            <div style="font-size: 12px;">
                                Освещение: <?php echo round($lighting); ?> × 0.30 = <?php echo round($lighting * 0.30, 1); ?><br>
                                Тишина: <?php echo round($noise_norm); ?> × 0.25 = <?php echo round($noise_norm * 0.25, 1); ?><br>
                                CO₂: <?php echo round($co2_norm); ?> × 0.20 = <?php echo round($co2_norm * 0.20, 1); ?><br>
                                Температура: <?php echo round($temp_norm); ?> × 0.15 = <?php echo round($temp_norm * 0.15, 1); ?><br>
                                Влажность: <?php echo round($humidity_norm); ?> × 0.10 = <?php echo round($humidity_norm * 0.10, 1); ?><br>
                                <strong>Итого: <?php echo round($ergonomics); ?>/100</strong>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($temp > 0 || $humidity > 0 || $co2 > 0): ?>
                    <h3 style="margin-top: 20px;">🌡️ Параметры среды</h3>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 15px 0;">
                        <div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 12px; color: #666;">🌡️ Температура</div>
                            <div style="font-size: 20px; font-weight: bold;"><?php echo round($temp); ?>°C</div>
                            <div style="font-size: 11px; color: <?php echo $temp_norm >= 80 ? '#10b981' : '#f59e0b'; ?>">Комфорт: <?php echo round($temp_norm); ?>%</div>
                        </div>
                        <div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 12px; color: #666;">💧 Влажность</div>
                            <div style="font-size: 20px; font-weight: bold;"><?php echo round($humidity); ?>%</div>
                            <div style="font-size: 11px; color: <?php echo $humidity_norm >= 80 ? '#10b981' : '#f59e0b'; ?>">Комфорт: <?php echo round($humidity_norm); ?>%</div>
                        </div>
                        <div style="background: #f8fafc; padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 12px; color: #666;">🫧 CO₂</div>
                            <div style="font-size: 20px; font-weight: bold;"><?php echo round($co2); ?> ppm</div>
                            <div style="font-size: 11px; color: <?php echo $co2 <= 600 ? '#10b981' : ($co2 <= 1000 ? '#f59e0b' : '#ef4444'); ?>">Качество: <?php echo round($co2_norm); ?>%</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ( ! empty( $ml_images ) ): ?>
                        <h3 style="margin-top: 30px;">📈 ML-анализ всех зон</h3>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                            <?php foreach ( $ml_images as $label => $url ): ?>
                                <div style="border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden;">
                                    <div style="padding: 8px; background: #f0f9ff; font-weight: bold; text-align: center; font-size: 13px;">
                                        <?php echo esc_html( $label ); ?>
                                    </div>
                                    <img src="<?php echo esc_url($url); ?>" style="width: 100%; display: block; cursor: pointer;" onclick="window.open(this.src, '_blank')">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="background: #fef2f2; padding: 20px; border-radius: 12px; margin-bottom: 30px; text-align: center;">
                <p>📊 Нет данных анализа для этой зоны. Загрузите CSV файл.</p>
            </div>
            <?php endif; ?>
            
            <!-- Характеристики -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
                <div style="background: #fff; padding: 20px; border-radius: 12px; text-align: center;">
                    <?php if ( has_post_thumbnail() ): ?>
                        <?php the_post_thumbnail( 'large', [ 'style' => 'max-width:100%; height:auto; border-radius:8px;' ] ); ?>
                    <?php else: ?>
                        <div style="background: #f3f4f6; padding: 60px; border-radius: 8px; color: #9ca3af;">
                            <span style="font-size: 48px;"><?php echo $zone['category'] === 'офис' ? '🏢' : '🏠'; ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 12px;">
                    <h3 style="margin-top: 0;">📋 Характеристики зоны</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 10px 0; width: 40%;">Категория</td><td style="padding: 10px 0;"><?php echo esc_html( ucfirst( $zone['category'] ?: 'Дом' ) ); ?></td></tr>
                        <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 10px 0;">Тип зоны</td><td style="padding: 10px 0;"><?php echo esc_html( ucfirst( $zone['room_type'] ?: '—' ) ); ?></td></tr>
                        <?php if ($area > 0): ?>
                        <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 10px 0;">Площадь</td><td style="padding: 10px 0;"><?php echo esc_html( $area ); ?> м²</td></tr>
                        <?php endif; ?>
                        <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 10px 0;">Количество объектов</td><td style="padding: 10px 0;"><?php echo $zone['object_count'] ?? 0; ?> шт</td></tr>
                        <tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 10px 0;">Страна</td><td style="padding: 10px 0;"><?php echo esc_html( $zone['country_name'] ?: '—' ); ?></td></tr>
                    </table>
                </div>
            </div>
            
            <!-- Описание из контента поста -->
            <?php 
            $description_content = $zone['description'] ?? '';
            if ( ! empty( $description_content ) ): ?>
                <div style="background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 30px;">
                    <h3 style="margin-top: 0;">📝 Описание</h3>
                    <?php echo nl2br( $description_content ); ?>
                </div>
            <?php endif; ?>
            
            <div style="text-align:center; margin:40px 0;">
                <a href="<?php echo home_url(); ?>" class="button button-primary">← Вернуться на главную</a>
            </div>
            
        </div>
    </div>
    
    <script>
    document.getElementById('toggleFormula')?.addEventListener('click', function() {
        var section = document.getElementById('formulaSection');
        if (section.style.display === 'none') {
            section.style.display = 'block';
            this.textContent = '🔒 Скрыть детальный расчет';
        } else {
            section.style.display = 'none';
            this.textContent = '📊 Показать детальный расчет';
        }
    });
    </script>
    
    <?php
    
endwhile;

get_footer();