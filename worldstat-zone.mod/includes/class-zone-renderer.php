<?php
/**
 * Renderer for Zones extension - ФИНАЛЬНАЯ ВЕРСИЯ БЕЗ AJAX
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_Renderer {
    
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

    public static function render_country_tab( string $iso2 ): void {
        $products = WSZ_CPT::get_products_by_country( $iso2 );
        
        // Обогащаем продукты метриками
        foreach ( $products as &$p ) {
            $p['noise_normalized'] = max( 0, 100 - min( 100, $p['noise_level'] ) );
            $p['functionality'] = round( ( ( $p['ergonomics'] ?? 0 ) + ( $p['lighting'] ?? 0 ) ) / 2 + min( 20, ( $p['object_count'] ?? 0 ) * 2 ), 1 );
            $p['controllability'] = round( ( ( $p['ergonomics'] ?? 0 ) + ( $p['comfort'] ?? 50 ) ) / 2 + min( 30, ( $p['adjustable_objects'] ?? 0 ) * 10 ), 1 );
            $p['habitability'] = round( ( ( $p['comfort'] ?? 50 ) + ( $p['safety'] ?? 50 ) ) / 2 + min( 20, ( $p['view_quality'] ?? 50 ) / 5 ), 1 );
            $p['masterability'] = round( ( $p['functionality'] + $p['controllability'] ) / 2, 1 );
        }
        
        // Получаем параметры фильтрации из GET
        $filter_param = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : 'ergonomics';
        $sort_order = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'desc';
        $active_category = isset( $_GET['cat'] ) ? sanitize_text_field( $_GET['cat'] ) : 'all';
        
        // Группировка по категориям
        $home_products = [];
        $office_products = [];
        foreach ( $products as $product ) {
            $cat = strtolower( $product['category'] ?? 'home' );
            if ( $cat === 'офис' || $cat === 'office' ) {
                $office_products[] = $product;
            } else {
                $home_products[] = $product;
            }
        }
        
        // Выбор по категории
        if ( $active_category === 'home' ) {
            $display_products = $home_products;
        } elseif ( $active_category === 'office' ) {
            $display_products = $office_products;
        } else {
            $display_products = $products;
        }
        
        // Сортировка
        usort( $display_products, function( $a, $b ) use ( $filter_param, $sort_order ) {
            $valA = floatval( $a[ $filter_param ] ?? 0 );
            $valB = floatval( $b[ $filter_param ] ?? 0 );
            return ( $sort_order === 'desc' ) ? ( $valB <=> $valA ) : ( $valA <=> $valB );
        });
        
        $home_count = count( $home_products );
        $office_count = count( $office_products );
        
        // Средние по стране
        $country_avg_ergonomics = self::calculate_avg( $products, 'ergonomics' );
        
        // Переводы
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
            'домашний офис' => '💻', 'другое' => '🏠', 'рабочая зона' => '💻', 'опенспейс' => '🏢',
            'переговорная' => '📢'
        ];
        
        $filter_labels = self::$filter_params;
        
        // ТЕКУЩИЙ URL - ПРАВИЛЬНО БЕЗ AJAX
       // БАЗОВЫЙ URL - берём текущий путь и добавляем iso2
$current_url = remove_query_arg( ['filter', 'order'] );
$current_url = add_query_arg( 'iso2', $iso2, $current_url );
        
        ?>
        <div class="wsz-country-tab">
            <style>
                .wsz-country-tab { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 10px 0; }
                .wsz-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
                .wsz-stat { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 16px; padding: 15px; text-align: center; }
                .wsz-stat-number { font-size: 28px; font-weight: bold; }
                .wsz-stat-label { font-size: 12px; color: #666; margin-top: 5px; }
                .wsz-filters { background: white; padding: 20px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #e5e7eb; }
                .wsz-filters-row { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; }
                .wsz-filter-group { flex: 1; min-width: 180px; }
                .wsz-filter-label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px; }
                .wsz-filter-select { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; background: white; cursor: pointer; }
                .wsz-filter-btn { background: #3b82f6; color: white; padding: 10px 24px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
                .wsz-filter-btn:hover { background: #2563eb; }
                .wsz-reset-btn { background: #f3f4f6; color: #374151; padding: 10px 24px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; margin-left: 10px; text-decoration: none; display: inline-block; }
                .wsz-cat-tabs { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; }
                .wsz-cat-link { padding: 10px 24px; border-radius: 30px; font-size: 15px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
                .wsz-cat-all { background: #f3f4f6; color: #374151; }
                .wsz-cat-all.active { background: #6b7280; color: white; }
                .wsz-cat-home { background: #f3f4f6; color: #374151; }
                .wsz-cat-home.active { background: #3b82f6; color: white; }
                .wsz-cat-office { background: #f3f4f6; color: #374151; }
                .wsz-cat-office.active { background: #8b5cf6; color: white; }
                .wsz-cat-count { background: rgba(0,0,0,0.1); border-radius: 20px; padding: 2px 8px; font-size: 12px; }
                .wsz-products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-top: 5px; }
                .wsz-product-card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #f0f0f0; position: relative; transition: transform 0.2s; }
                .wsz-product-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.12); }
                .wsz-badge { position: absolute; top: 12px; left: 12px; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; color: white; z-index: 2; }
                .wsz-product-image { height: 180px; overflow: hidden; background: linear-gradient(135deg, #f5f7fa 0%, #e4e7eb 100%); }
                .wsz-product-image img { width: 100%; height: 100%; object-fit: cover; }
                .wsz-product-content { padding: 15px; }
                .wsz-product-name { font-size: 16px; font-weight: 700; margin: 0 0 8px 0; }
                .wsz-product-name a { color: #1f2937; text-decoration: none; }
                .wsz-product-name a:hover { color: #3b82f6; }
                .wsz-product-details { font-size: 12px; color: #6b7280; margin-bottom: 12px; }
                .wsz-metrics { display: flex; justify-content: space-between; margin: 12px 0; padding: 10px 0; border-top: 1px solid #f0f0f0; border-bottom: 1px solid #f0f0f0; }
                .wsz-metric { text-align: center; flex: 1; }
                .wsz-metric-value { font-size: 16px; font-weight: 700; }
                .wsz-metric-label { font-size: 10px; color: #9ca3af; margin-top: 3px; }
                .wsz-view-btn { display: block; text-align: center; background: #f3f4f6; color: #1f2937; padding: 8px; border-radius: 30px; text-decoration: none; font-size: 12px; font-weight: 500; margin-top: 12px; }
                .wsz-view-btn:hover { background: #e5e7eb; }
                .wsz-empty { text-align: center; padding: 50px 20px; background: #f9fafb; border-radius: 20px; }
                .wsz-sort-info { font-size: 12px; color: #6b7280; margin-bottom: 15px; text-align: right; }
                @media (max-width: 768px) {
                    .wsz-products-grid { grid-template-columns: 1fr; }
                    .wsz-stats { grid-template-columns: repeat(2, 1fr); }
                    .wsz-filters-row { flex-direction: column; }
                    .wsz-filter-group { width: 100%; }
                }
            </style>
            
            <div class="wsz-stats">
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo count( $products ); ?></div><div class="wsz-stat-label">Всего зон</div></div>
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo $home_count; ?></div><div class="wsz-stat-label">🏠 Дом</div></div>
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo $office_count; ?></div><div class="wsz-stat-label">🏢 Офис</div></div>
                <div class="wsz-stat"><div class="wsz-stat-number"><?php echo round( $country_avg_ergonomics ); ?></div><div class="wsz-stat-label">Ср. эргономика</div></div>
            </div>
            
            <div class="wsz-filters">
    <div class="wsz-filters-row">
        <div class="wsz-filter-group">
            <label class="wsz-filter-label">📊 Параметр</label>
            <select id="wsz-filter-select" name="filter" class="wsz-filter-select">
                <?php foreach ( self::$filter_params as $key => $label ): ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php echo $filter_param === $key ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wsz-filter-group">
            <label class="wsz-filter-label">🔼 Сортировка</label>
            <select id="wsz-order-select" name="order" class="wsz-filter-select">
                <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>По убыванию ↓</option>
                <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>По возрастанию ↑</option>
            </select>
        </div>
        <div>
            <a href="#" onclick="wsz_apply_filters(); return false;" class="wsz-filter-btn">Применить</a>
            <a href="#" onclick="wsz_reset_filters(); return false;" class="wsz-reset-btn">Сбросить</a>
        </div>
    </div>
    <input type="hidden" name="cat" value="<?php echo esc_attr( $active_category ); ?>">
</div>
            
            <div class="wsz-cat-tabs">
                <a href="#" onclick="wsz_filter_category('all', event)" class="wsz-cat-link wsz-cat-all <?php echo $active_category === 'all' ? 'active' : ''; ?>" data-category="all">
                    🌍 Все <span class="wsz-cat-count"><?php echo count( $products ); ?></span>
                </a>
                <a href="#" onclick="wsz_filter_category('home', event)" class="wsz-cat-link wsz-cat-home <?php echo $active_category === 'home' ? 'active' : ''; ?>" data-category="home">
                    🏠 Дом <span class="wsz-cat-count"><?php echo $home_count; ?></span>
                </a>
                <a href="#" onclick="wsz_filter_category('office', event)" class="wsz-cat-link wsz-cat-office <?php echo $active_category === 'office' ? 'active' : ''; ?>" data-category="office">
                    🏢 Офис <span class="wsz-cat-count"><?php echo $office_count; ?></span>
                </a>
            </div>
            
            <script>
// Функция для фильтрации по категориям
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
    document.querySelectorAll('.wsz-product-card').forEach(card => {
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
    const count = Array.from(document.querySelectorAll('.wsz-product-card')).filter(c => c.style.display !== 'none').length;
    const sortInfo = document.querySelector('.wsz-sort-info');
    if (sortInfo) {
        sortInfo.innerHTML = sortInfo.innerHTML.replace(/Найдено: \d+/, 'Найдено: ' + count);
    }
}

// Функция для применения фильтров
function wsz_apply_filters() {
    const filterValue = document.getElementById('wsz-filter-select').value;
    const orderValue = document.getElementById('wsz-order-select').value;
    const cards = Array.from(document.querySelectorAll('.wsz-product-card'));
    
    // Получаем значения для сортировки из data-атрибутов карточек
    cards.forEach(card => {
        // Пытаемся получить значение из разных источников
        let metricValue = 0;
        
        // 1. Пробуем из data-атрибута с нужной метрикой
        if (card.getAttribute('data-' + filterValue)) {
            metricValue = parseFloat(card.getAttribute('data-' + filterValue)) || 0;
        }
        // 2. Пробуем из числового значения в бейдже (но это значение текущей метрики, не подходит)
        // 3. Пробуем из текста внутри карточки
        else {
            // Ищем все значения в метриках карточки
            const metrics = card.querySelectorAll('.wsz-metric-value');
            metrics.forEach(metric => {
                const value = parseFloat(metric.innerText);
                if (!isNaN(value)) {
                    // Если не нашли, берем эргономику как fallback
                    if (metricValue === 0) metricValue = value;
                }
            });
        }
        
        card.setAttribute('data-sort-value', metricValue);
    });
    
    // Нужно получить правильные значения для выбранной метрики
    // Для этого пересоздадим карточки с правильными бейджами
    
    // Получаем все карточки и обновляем их бейджи
    cards.forEach(card => {
        // Находим значение для выбранной метрики
        let metricValue = 0;
        
        // Ищем во всех метриках карточки
        const metricElements = card.querySelectorAll('.wsz-metric');
        metricElements.forEach(metric => {
            const label = metric.querySelector('.wsz-metric-label')?.innerText || '';
            const value = parseFloat(metric.querySelector('.wsz-metric-value')?.innerText) || 0;
            
            // Сопоставляем метрики
            if ((filterValue === 'ergonomics' && label === 'Эргономика') ||
                (filterValue === 'lighting' && label === 'Освещение') ||
                (filterValue === 'noise_level' && label === 'Тишина')) {
                metricValue = value;
            }
        });
        
        // Если не нашли по меткам, пробуем другие метрики
        if (metricValue === 0) {
            if (filterValue === 'comfort') metricValue = 50; // fallback
            else if (filterValue === 'safety') metricValue = 50;
            else metricValue = parseFloat(card.querySelector('.wsz-metric-value')?.innerText) || 0;
        }
        
        card.setAttribute('data-sort-value', metricValue);
        
        // Обновляем бейдж
        const badge = card.querySelector('.wsz-badge');
        if (badge) {
            const filterLabel = document.querySelector('#wsz-filter-select option:checked')?.text || filterValue;
            badge.innerHTML = `📊 ${filterLabel}: ${metricValue}/100`;
            // Обновляем цвет бейджа
            const color = metricValue >= 75 ? '#10b981' : (metricValue >= 60 ? '#3b82f6' : (metricValue >= 45 ? '#f59e0b' : '#ef4444'));
            badge.style.background = color;
        }
    });
    
    // Сортировка
    cards.sort((a, b) => {
        let valA = parseFloat(a.getAttribute('data-sort-value')) || 0;
        let valB = parseFloat(b.getAttribute('data-sort-value')) || 0;
        return orderValue === 'desc' ? valB - valA : valA - valB;
    });
    
    // Переставляем карточки
    const grid = document.querySelector('.wsz-products-grid');
    cards.forEach(card => grid.appendChild(card));
    
    // Обновляем URL
    const url = new URL(window.location);
    url.searchParams.set('filter', filterValue);
    url.searchParams.set('order', orderValue);
    window.history.replaceState({}, '', url);
    
    // Обновляем текст сортировки
    const filterLabel = document.querySelector('#wsz-filter-select option:checked').text;
    const sortInfo = document.querySelector('.wsz-sort-info');
    if (sortInfo) {
        sortInfo.innerHTML = sortInfo.innerHTML.replace(/Сортировка по <strong>.*?<\/strong>/, `Сортировка по <strong>${filterLabel}</strong>`).replace(/[↓↑]/, orderValue === 'desc' ? '↓' : '↑');
    }
}

// Функция сброса фильтров
function wsz_reset_filters() {
    document.getElementById('wsz-filter-select').value = 'ergonomics';
    document.getElementById('wsz-order-select').value = 'desc';
    wsz_apply_filters();
}

// Назначаем обработчики после загрузки страницы
document.addEventListener('DOMContentLoaded', function() {
    const applyBtn = document.getElementById('wsz-apply-filters');
    const resetBtn = document.getElementById('wsz-reset-filters');
    if (applyBtn) applyBtn.addEventListener('click', wsz_apply_filters);
    if (resetBtn) resetBtn.addEventListener('click', wsz_reset_filters);
});
</script>
            
            <?php if ( ! empty( $display_products ) ): ?>
            <div class="wsz-sort-info">
                📊 Сортировка по <strong><?php echo esc_html( $filter_labels[ $filter_param ] ?? $filter_param ); ?></strong> 
                <?php echo $sort_order === 'desc' ? '↓' : '↑'; ?> | Найдено: <?php echo count( $display_products ); ?> зон
            </div>
            <?php endif; ?>
            
            <div class="wsz-products-grid">
                <?php if ( empty( $display_products ) ): ?>
                    <div class="wsz-empty"><span style="font-size:48px;">📭</span><h3>Нет данных</h3></div>
                <?php else: ?>
                    <?php foreach ( $display_products as $product ): 
                        $color = $product['ergonomics'] >= 75 ? '#10b981' : ( $product['ergonomics'] >= 60 ? '#3b82f6' : ( $product['ergonomics'] >= 45 ? '#f59e0b' : '#ef4444' ) );
                        $icon = $room_icons[ strtolower( $product['room_type'] ) ] ?? '🏠';
                        $room_label = $room_translations[ strtolower( $product['room_type'] ) ] ?? ucfirst( $product['room_type'] );
                        $filter_val = round( $product[ $filter_param ] ?? 0, 1 );
                        $cat_icon = ( strtolower( $product['category'] ?? 'home' ) === 'office' ) ? '🏢' : '🏠';
                        $product_category = strtolower( $product['category'] ?? 'home' ) === 'office' ? 'office' : 'home';
                        $permalink = add_query_arg( 'filter', $filter_param, get_permalink( $product['id'] ) );
                    ?>
                        <div class="wsz-product-card" data-category="<?php echo esc_attr( $product_category ); ?>">
                            <div class="wsz-badge" style="background: <?php echo $color; ?>;">
                                📊 <?php echo esc_html( $filter_labels[ $filter_param ] ?? $filter_param ); ?>: <?php echo $filter_val; ?>/100
                            </div>
                            <div class="wsz-product-image">
                                <?php if ( $product['thumbnail'] ): ?>
                                    <img src="<?php echo esc_url( $product['thumbnail'] ); ?>" alt="<?php echo esc_attr( $product['name'] ); ?>">
                                <?php else: ?>
                                    <div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:48px;"><?php echo $icon; ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="wsz-product-content">
                                <h3 class="wsz-product-name"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product['name'] ); ?></a></h3>
                                <div class="wsz-product-details"><?php echo $icon; ?> <?php echo $room_label; ?> • <?php echo $cat_icon; ?></div>
                                <div class="wsz-metrics">
                                    <div class="wsz-metric"><div class="wsz-metric-value" style="color:<?php echo $color;?>;"><?php echo round( $product['ergonomics'] ); ?></div><div class="wsz-metric-label">Эргономика</div></div>
                                    <div class="wsz-metric"><div class="wsz-metric-value" style="color:#f59e0b;"><?php echo round( $product['lighting'] ); ?></div><div class="wsz-metric-label">Освещение</div></div>
                                    <div class="wsz-metric"><div class="wsz-metric-value" style="color:#8b5cf6;"><?php echo round( max(0,100-$product['noise_level']) ); ?></div><div class="wsz-metric-label">Тишина</div></div>
                                </div>
                                <a href="<?php echo esc_url( $permalink ); ?>" class="wsz-view-btn">Подробнее →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    private static function calculate_avg( $products, $metric ) {
        $sum = 0;
        $count = 0;
        foreach ( $products as $product ) {
            if ( isset( $product[ $metric ] ) && $product[ $metric ] > 0 ) {
                $sum += $product[ $metric ];
                $count++;
            }
        }
        return $count > 0 ? round( $sum / $count, 1 ) : 0;
    }

    public static function render_city_tab( int $city_id ): void {
        global $wpdb;
        
        $products = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title as name
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_city_id'
             WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = %d",
            WSZ_CPT::SLUG,
            $city_id
        ) );
        
        if ( empty( $products ) ) {
            echo '<div class="wsp-notice"><p>📭 Нет данных для этого города.</p></div>';
            return;
        }
        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
            <?php foreach ( $products as $product ): ?>
                <div style="background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="height: 180px; background: #f3f4f6; display: flex; align-items: center; justify-content: center;">
                        <?php if ( has_post_thumbnail( $product->ID ) ): ?>
                            <?php echo get_the_post_thumbnail( $product->ID, 'medium', ['style' => 'width:100%; height:100%; object-fit:cover;'] ); ?>
                        <?php else: ?>
                            <span style="font-size: 48px;">🛏️</span>
                        <?php endif; ?>
                    </div>
                    <div style="padding: 15px;">
                        <h3 style="margin: 0 0 10px 0; font-size: 16px;">
                            <a href="<?php echo get_permalink( $product->ID ); ?>" style="text-decoration: none;"><?php echo esc_html( $product->name ); ?></a>
                        </h3>
                        <a href="<?php echo get_permalink( $product->ID ); ?>" class="button button-small">Подробнее</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function get_current_page_url(): string {
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
}