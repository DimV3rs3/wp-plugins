<?php
/**
 * Zones main page renderer for ADMIN (worldstat-zones page)
 * 
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_Page {
    
    private static $filter_params = [
        'ergonomics' => 'Эргономичность',
        'safety' => 'Безопасность',
        'functionality' => 'Функциональность',
        'controllability' => 'Управляемость',
        'habitability' => 'Обитаемость',
        'masterability' => 'Освояемость',
        'comfort' => 'Комфортность',
        'lighting' => 'Освещенность',
        'temp' => 'Температура',
        'humidity' => 'Влажность',
        'noise_level' => 'Тишина',
        'co2' => 'Качество воздуха',
    ];
    
    public static function render_page(): void {
        $all_zones = WSZ_CPT::get_all_zones();
        
        // Обогащаем метриками
        foreach ( $all_zones as &$z ) {
            $z['noise_normalized'] = max( 0, 100 - min( 100, $z['noise_level'] ) );
            $z['functionality'] = round( ( ( $z['ergonomics'] ?? 0 ) + ( $z['lighting'] ?? 0 ) ) / 2 + min( 20, ( $z['object_count'] ?? 0 ) * 2 ), 1 );
            $z['controllability'] = round( ( ( $z['ergonomics'] ?? 0 ) + ( $z['comfort'] ?? 50 ) ) / 2 + min( 30, ( $z['adjustable_objects'] ?? 0 ) * 10 ), 1 );
            $z['habitability'] = round( ( ( $z['comfort'] ?? 50 ) + ( $z['safety'] ?? 50 ) ) / 2 + min( 20, ( $z['view_quality'] ?? 50 ) / 5 ), 1 );
            $z['masterability'] = round( ( $z['functionality'] + $z['controllability'] ) / 2, 1 );
        }
        
        // Получаем параметры фильтрации из GET
        $filter_param = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : 'ergonomics';
        $sort_order = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'desc';
        $active_category = isset( $_GET['cat'] ) ? sanitize_text_field( $_GET['cat'] ) : 'all';
        
        // Группировка по категориям
        $home_zones = [];
        $office_zones = [];
        foreach ( $all_zones as $zone ) {
            $cat = strtolower( $zone['category'] ?? 'home' );
            if ( $cat === 'офис' || $cat === 'office' ) {
                $office_zones[] = $zone;
            } else {
                $home_zones[] = $zone;
            }
        }
        
        // Выбор по категории
        if ( $active_category === 'home' ) {
            $display_zones = $home_zones;
        } elseif ( $active_category === 'office' ) {
            $display_zones = $office_zones;
        } else {
            $display_zones = $all_zones;
        }
        
        // Сортировка
        usort( $display_zones, function( $a, $b ) use ( $filter_param, $sort_order ) {
            $valA = floatval( $a[ $filter_param ] ?? 0 );
            $valB = floatval( $b[ $filter_param ] ?? 0 );
            return ( $sort_order === 'desc' ) ? ( $valB <=> $valA ) : ( $valA <=> $valB );
        });
        
        $home_count = count( $home_zones );
        $office_count = count( $office_zones );
        $avg_erg = self::calculate_avg( $all_zones, 'ergonomics' );
        
        $room_translations = [
            'bedroom' => 'спальня', 'living room' => 'гостиная', 'kitchen' => 'кухня',
            'bathroom' => 'ванная', 'children' => 'детская', 'office' => 'кабинет',
            'dining room' => 'столовая', 'hallway' => 'прихожая', 'balcony' => 'балкон',
            'corridor' => 'коридор', 'dining' => 'столовая', 'home_office' => 'домашний офис',
            'media' => 'медиа-зона', 'walk_in_closet' => 'гардеробная', 'Другое' => 'другое',
            'workstation' => 'рабочая зона', 'open_space' => 'опенспейс', 'meeting_room' => 'переговорная'
        ];
        
        $room_icons = [
            'спальня' => '🛌', 'гостиная' => '🛋️', 'кухня' => '🍳', 'ванная' => '🚿',
            'детская' => '🧸', 'кабинет' => '📚', 'столовая' => '🍽️', 'прихожая' => '🚪',
            'балкон' => '🌿', 'гардеробная' => '👔', 'коридор' => '🚪', 'медиа-зона' => '📺',
            'домашний офис' => '💻', 'другое' => '🏠'
        ];
        
        $filter_labels = self::$filter_params;
        
        $current_url = self::get_current_page_url();
        $reset_url = remove_query_arg( [ 'filter', 'order', 'cat' ], $current_url );
        $cat_all_url = add_query_arg( [ 'cat' => 'all' ], $current_url );
        $cat_home_url = add_query_arg( [ 'cat' => 'home' ], $current_url );
        $cat_office_url = add_query_arg( [ 'cat' => 'office' ], $current_url );
        
        wp_enqueue_style( 'dashicons' );
        
        ?>
        <div class="wsz-admin-container">
            <style>
                .wsz-admin-container { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 10px 0; }
                .wsz-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
                .wsz-stat { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 16px; padding: 15px; text-align: center; }
                .wsz-stat-number { font-size: 28px; font-weight: bold; }
                .wsz-filters { background: white; padding: 20px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e5e7eb; }
                .wsz-filters-row { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; }
                .wsz-filter-group { flex: 1; min-width: 180px; }
                .wsz-filter-label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #374151; }
                .wsz-filter-select { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; background: white; }
                .wsz-filter-btn { background: #3b82f6; color: white; padding: 10px 24px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; }
                .wsz-reset-btn { background: #f3f4f6; color: #374151; margin-left: 10px; text-decoration: none; display: inline-block; padding: 10px 24px; border-radius: 10px; }
                .wsz-cat-tabs { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
                .wsz-cat-link { padding: 10px 24px; border-radius: 30px; font-size: 15px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
                .wsz-cat-all { background: #f3f4f6; color: #374151; }
                .wsz-cat-all.active { background: #6b7280; color: white; }
                .wsz-cat-home { background: #f3f4f6; color: #374151; }
                .wsz-cat-home.active { background: #3b82f6; color: white; }
                .wsz-cat-office { background: #f3f4f6; color: #374151; }
                .wsz-cat-office.active { background: #8b5cf6; color: white; }
                .wsz-cat-count { background: rgba(0,0,0,0.1); border-radius: 20px; padding: 2px 8px; font-size: 12px; }
                .wsz-sort-info { font-size: 12px; color: #6b7280; margin-bottom: 15px; text-align: right; }
                .wsz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
                .wsz-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #f0f0f0; position: relative; transition: transform 0.2s; }
                .wsz-card:hover { transform: translateY(-4px); }
                .wsz-badge { position: absolute; top: 12px; left: 12px; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; color: white; z-index: 2; }
                .wsz-card-img { height: 180px; overflow: hidden; background: linear-gradient(135deg, #f5f7fa 0%, #e4e7eb 100%); }
                .wsz-card-img img { width: 100%; height: 100%; object-fit: cover; }
                .wsz-card-img-placeholder { display: flex; align-items: center; justify-content: center; height: 100%; font-size: 48px; }
                .wsz-card-content { padding: 15px; }
                .wsz-card-title { font-size: 16px; font-weight: 700; margin: 0 0 8px 0; }
                .wsz-card-title a { color: #1f2937; text-decoration: none; }
                .wsz-card-title a:hover { color: #3b82f6; }
                .wsz-card-details { font-size: 12px; color: #6b7280; margin-bottom: 12px; }
                .wsz-metrics { display: flex; justify-content: space-between; margin: 12px 0; padding: 10px 0; border-top: 1px solid #f0f0f0; border-bottom: 1px solid #f0f0f0; }
                .wsz-metric { text-align: center; flex: 1; }
                .wsz-metric-val { font-size: 16px; font-weight: 700; }
                .wsz-metric-label { font-size: 10px; color: #9ca3af; margin-top: 3px; }
                .wsz-card-btn { display: block; text-align: center; background: #f3f4f6; color: #1f2937; padding: 8px; border-radius: 30px; text-decoration: none; font-size: 12px; font-weight: 500; margin-top: 12px; }
                .wsz-empty { text-align: center; padding: 60px; background: #f9fafb; border-radius: 20px; }
                @media (max-width: 768px) {
                    .wsz-grid { grid-template-columns: 1fr; }
                    .wsz-stats { grid-template-columns: repeat(2, 1fr); }
                    .wsz-filters-row { flex-direction: column; }
                    .wsz-filter-group { width: 100%; }
                }
            </style>
            
            <div class="wsz-stats">
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo count( $all_zones ); ?></div><div>Всего зон</div></div>
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo $home_count; ?></div><div>🏠 Дом</div></div>
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo $office_count; ?></div><div>🏢 Офис</div></div>
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo round( $avg_erg ); ?></div><div>Ср. эргономика</div></div>
            </div>
            
            <div class="wsz-filters">
                <form method="get" action="<?php echo esc_url( $current_url ); ?>">
                    <?php if ( is_admin() ): ?>
                        <input type="hidden" name="page" value="worldstat-zones">
                        <input type="hidden" name="tab" value="zones">
                    <?php endif; ?>
                    <div class="wsz-filters-row">
                        <div class="wsz-filter-group">
                            <label class="wsz-filter-label">📊 Параметр</label>
                            <select name="filter" class="wsz-filter-select">
                                <?php foreach ( $filter_labels as $key => $label ): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filter_param === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wsz-filter-group">
                            <label class="wsz-filter-label">🔼 Сортировка</label>
                            <select name="order" class="wsz-filter-select">
                                <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>По убыванию ↓</option>
                                <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>По возрастанию ↑</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="wsz-filter-btn">Применить</button>
                            <a href="<?php echo esc_url( $reset_url ); ?>" class="wsz-reset-btn">Сбросить</a>
                        </div>
                    </div>
                    <input type="hidden" name="cat" value="<?php echo esc_attr( $active_category ); ?>">
                </form>
            </div>
            
            <div class="wsz-cat-tabs">
                <a href="#" onclick="wsz_filter_category('all', event)" class="wsz-cat-link wsz-cat-all <?php echo $active_category === 'all' ? 'active' : ''; ?>" data-category="all">
                    🌍 Все <span class="wsz-cat-count"><?php echo count( $all_zones ); ?></span>
                </a>
                <a href="#" onclick="wsz_filter_category('home', event)" class="wsz-cat-link wsz-cat-home <?php echo $active_category === 'home' ? 'active' : ''; ?>" data-category="home">
                    🏠 Дом <span class="wsz-cat-count"><?php echo $home_count; ?></span>
                </a>
                <a href="#" onclick="wsz_filter_category('office', event)" class="wsz-cat-link wsz-cat-office <?php echo $active_category === 'office' ? 'active' : ''; ?>" data-category="office">
                    🏢 Офис <span class="wsz-cat-count"><?php echo $office_count; ?></span>
                </a>
            </div>
            
            <script>
            function wsz_filter_category(cat, event) {
                event.preventDefault();
                
                // Обновляем скрытое поле
                document.querySelector('input[name="cat"]').value = cat;
                
                // Обновляем активный класс на кнопках
                document.querySelectorAll('.wsz-cat-tabs .wsz-cat-link').forEach(link => {
                    link.classList.remove('active');
                });
                event.target.closest('a').classList.add('active');
                
                // Фильтруем карточки
                document.querySelectorAll('.wsz-zone-card').forEach(card => {
                    const cardCat = card.getAttribute('data-category') || 'all';
                    if (cat === 'all' || cardCat === cat) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Обновляем URL в адресной строке без перезагрузки
                const url = new URL(window.location);
                url.searchParams.set('cat', cat);
                window.history.replaceState({}, '', url);
                
                // Обновляем счетчик найденных
                const count = Array.from(document.querySelectorAll('.wsz-zone-card')).filter(c => c.style.display !== 'none').length;
                const sortInfo = document.querySelector('.wsz-sort-info');
                if (sortInfo) {
                    sortInfo.innerHTML = sortInfo.innerHTML.replace(/Найдено: \d+/, 'Найдено: ' + count);
                }
            }
            </script>
            
            <?php if ( ! empty( $display_zones ) ): ?>
            <div class="wsz-sort-info">
                📊 Сортировка по <strong><?php echo esc_html( $filter_labels[ $filter_param ] ?? $filter_param ); ?></strong> 
                <?php echo $sort_order === 'desc' ? '↓' : '↑'; ?> | Найдено: <?php echo count( $display_zones ); ?> зон
            </div>
            <?php endif; ?>
            
            <div class="wsz-grid">
                <?php if ( empty( $display_zones ) ): ?>
                    <div class="wsz-empty"><span style="font-size:48px;">📭</span><h3>Нет данных</h3></div>
                <?php else: ?>
                    <?php foreach ( $display_zones as $zone ): 
                        $color = $zone['ergonomics'] >= 75 ? '#10b981' : ( $zone['ergonomics'] >= 60 ? '#3b82f6' : ( $zone['ergonomics'] >= 45 ? '#f59e0b' : '#ef4444' ) );
                        $icon = $room_icons[ strtolower( $zone['room_type'] ) ] ?? '🏠';
                        $room_label = $room_translations[ strtolower( $zone['room_type'] ) ] ?? ucfirst( $zone['room_type'] );
                        $filter_val = round( $zone[ $filter_param ] ?? 0, 1 );
                        $cat_icon = ( strtolower( $zone['category'] ?? 'home' ) === 'office' ) ? '🏢' : '🏠';
                        $zone_category = strtolower( $zone['category'] ?? 'home' ) === 'office' ? 'office' : 'home';
                        $permalink = add_query_arg( 'filter', $filter_param, get_permalink( $zone['id'] ) );
                    ?>
                        <div class="wsz-card wsz-zone-card" data-category="<?php echo esc_attr( $zone_category ); ?>">
                            <div class="wsz-badge" style="background: <?php echo $color; ?>;">
                                📊 <?php echo esc_html( $filter_labels[ $filter_param ] ?? $filter_param ); ?>: <?php echo $filter_val; ?>/100
                            </div>
                            <div class="wsz-card-img">
                                <?php if ( $zone['thumbnail'] ): ?>
                                    <img src="<?php echo esc_url( $zone['thumbnail'] ); ?>" alt="<?php echo esc_attr( $zone['name'] ); ?>">
                                <?php else: ?>
                                    <div class="wsz-card-img-placeholder"><?php echo $icon; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="wsz-card-content">
                                <h3 class="wsz-card-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $zone['name'] ); ?></a></h3>
                                <div class="wsz-card-details"><?php echo $icon; ?> <?php echo $room_label; ?> • <?php echo $cat_icon; ?></div>
                                <div class="wsz-metrics">
                                    <div class="wsz-metric"><div class="wsz-metric-val" style="color:<?php echo $color;?>;"><?php echo round( $zone['ergonomics'] ); ?></div><div class="wsz-metric-label">Эргономика</div></div>
                                    <div class="wsz-metric"><div class="wsz-metric-val" style="color:#f59e0b;"><?php echo round( $zone['lighting'] ); ?></div><div class="wsz-metric-label">Освещение</div></div>
                                    <div class="wsz-metric"><div class="wsz-metric-val" style="color:#8b5cf6;"><?php echo round( max(0,100-$zone['noise_level']) ); ?></div><div class="wsz-metric-label">Тишина</div></div>
                                </div>
                                <a href="<?php echo esc_url( $permalink ); ?>" class="wsz-card-btn">Подробнее →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    private static function get_current_page_url(): string {
        if ( is_admin() ) {
            return admin_url( 'admin.php?page=worldstat-zones&tab=zones' );
        }

        if ( wp_doing_ajax() ) {
            $referer = wp_get_referer();
            if ( $referer && strpos( $referer, 'admin-ajax.php' ) === false ) {
                return esc_url_raw( $referer );
            }
        }

        $scheme = is_ssl() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        return esc_url_raw( set_url_scheme( $scheme . '://' . $host . $request_uri, $scheme ) );
    }

    private static function calculate_avg( $zones, $metric ) {
        $sum = 0;
        $count = 0;
        foreach ( $zones as $zone ) {
            if ( isset( $zone[ $metric ] ) && $zone[ $metric ] > 0 ) {
                $sum += $zone[ $metric ];
                $count++;
            }
        }
        return $count > 0 ? round( $sum / $count, 1 ) : 0;
    }
}