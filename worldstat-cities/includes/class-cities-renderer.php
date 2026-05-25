<?php
/**
 * Renderer — produces the "Города" tab on country pages.
 *
 * Uses WorldStat_UI components from the platform.
 * Includes Blocks & Roads data visualization (Urban Expansion methodology).
 *
 * @package WorldStatCities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSCities_Renderer {

    /**
     * Metric labels for Blocks & Roads data.
     */
    const BR_LABELS = [
        'road_share'           => 'Доля дорог в застройке (%)',
        'road_width'           => 'Средняя ширина дорог (м)',
        'road_narrow'          => 'Доля дорог <4 м (%)',
        'road_wide'            => 'Доля дорог >16 м (%)',
        'arterial_density'     => 'Плотность артериальных дорог (км/км²)',
        'arterial_distance'    => 'Среднее расстояние до арт. дорог (м)',
        'walk_all'             => 'Пешеходная доступность — все арт. (%)',
        'walk_wide'            => 'Пешеходная доступность — широкие арт. (%)',
        'block_size'           => 'Средний размер квартала (га)',
        'intersect_3way'       => 'Плотность 3-х сторонних перекрёстков (на км²)',
        'intersect_4way'       => 'Плотность 4-х сторонних перекрёстков (на км²)',
        'intersect_4way_share' => 'Доля 4-х сторонних перекрёстков (%)',
        'walkability'          => 'Коэффициент пешеходности',
        'residential'          => 'Доля жилых территорий (%)',
        'laid_out'             => 'Спланировано до застройки (%)',
        'not_laid_out'         => 'Не спланировано до застройки (%)',
        'gridded'              => 'Доля сеточной планировки (%)',
        'informal'             => 'Неформальные территории (%)',
        'formal'               => 'Формальные территории (%)',
        'housing_projects'     => 'Жилищные проекты (%)',
        'plot_informal'        => 'Средний участок — неформальный (м²)',
        'plot_formal'          => 'Средний участок — формальный (м²)',
    ];

    /**
     * Render the "Города" tab on a country page.
     */
    public static function render_country_tab( string $iso2 ): void {
        $cities = WSCities_CPT::get_cities_for_country( $iso2 );

        if ( empty( $cities ) ) {
            echo '<div class="wsp-notice"><p>Нет данных о городах для этой страны.</p></div>';
            return;
        }

        // ── Basic city data ──────────────────────────
        self::render_basic_section( $cities, $iso2 );

        // ── Blocks & Roads data ──────────────────────
        $br_cities = WSCities_CPT::get_cities_with_blocks_roads( $iso2 );
        if ( ! empty( $br_cities ) ) {
            self::render_blocks_roads_section( $br_cities );
        }

        // ── Historical Blocks & Roads ────────────────
        $hist_cities = WSCities_CPT::get_cities_with_blocks_roads_hist( $iso2 );
        if ( ! empty( $hist_cities ) ) {
            self::render_blocks_roads_hist_section( $hist_cities );
        }

        // ── Mini map with city markers ───────────────
        self::render_city_map( $cities, $iso2 );
    }

    /**
     * Basic city data: stats grid, population charts, main data table.
     */
    private static function render_basic_section( array $cities, string $iso2 ): void {
        $count      = count( $cities );
        $total_pop  = 0;
        $largest    = $cities[0];

        foreach ( $cities as $c ) {
            $total_pop  += $c['pop_t3'];
        }

        $country_pop = (int) WorldStat_Data::get( 'core', $iso2, 'population' );
        $urban_pct   = $country_pop > 0 ? round( $total_pop / $country_pop * 100, 1 ) : 0;

        // Match the new city page look in the country tab.
        echo '<div class="container">';
        echo '<div class="city-header">';
        echo '<div class="city-title">';
        echo '<h1>Города страны</h1>';
        echo '<div class="city-location">';
        echo '<span><i class="fas fa-city" style="font-size:0.75rem;"></i> Кол-во городов: ' . esc_html( (string) $count ) . '</span>';
        echo '</div></div>';
        echo '<div class="city-stats-mini">';
        echo '<div class="stat-item"><div class="stat-label">Городское население</div><div class="stat-value">' . esc_html( number_format( $total_pop, 0, '', ' ' ) ) . '</div></div>';
        echo '<div class="stat-item"><div class="stat-label">Крупнейший город</div><div class="stat-value">' . esc_html( $largest['name'] ) . '</div></div>';
        echo '<div class="stat-item"><div class="stat-label">Нас. крупнейшего</div><div class="stat-value">' . esc_html( number_format( (int) $largest['pop_t3'], 0, '', ' ' ) ) . '</div></div>';
        echo '</div></div>';

        echo '<h2 style="font-size:1.5rem; font-weight:600; margin-bottom:1.5rem;">Динамика и сравнение городов</h2>';
        echo '<div class="charts-grid">';

        // Показываем все города (без ограничения Top-N), чтобы графики отражали полный датасет.
        $chart_cities = $cities;
        $chart_population = WorldStat_UI::chart( [
            'type'     => 'bar',
            'title'    => 'Города по населению (T3)',
            'labels'   => array_map( fn( $c ) => $c['name'], $chart_cities ),
            'datasets' => [ [
                'label' => 'Население (T3)',
                'data'  => array_map( fn( $c ) => $c['pop_t3'], $chart_cities ),
                'color' => '#1d4ed8',
            ] ],
            'y_label' => 'Человек',
            'echo'    => false,
        ] );
        echo '<div class="chart-card"><div class="card-header"><i class="fas fa-chart-line"></i><h3>Население городов</h3></div>';
        echo '<div class="card-description">Сравнение городов страны по населению в периоде T3.</div>';
        echo $chart_population;
        echo '</div>';

        // Density chart
        $with_density = array_values( array_filter( $chart_cities, fn( $c ) => self::effective_density( $c ) > 0 ) );
        echo '<div class="chart-card"><div class="card-header"><i class="fas fa-people-arrows"></i><h3>Плотность застройки</h3></div>';
        echo '<div class="card-description">Плотность населения на застроенной территории (чел./га).</div>';
        if ( ! empty( $with_density ) ) {
            $chart_density = WorldStat_UI::chart( [
                'type'     => 'bar',
                'title'    => 'Плотность населения (чел./га)',
                'labels'   => array_map( fn( $c ) => $c['name'], $with_density ),
                'datasets' => [ [
                    'label' => 'Плотность (застр. тер.)',
                    'data'  => array_map( fn( $c ) => self::effective_density( $c ), $with_density ),
                    'color' => '#2563eb',
                ] ],
                'echo'    => false,
            ] );
            echo $chart_density;
        } else {
            echo '<div class="wsp-notice"><p>Для городов этой страны нет достаточных данных, чтобы построить график плотности.</p></div>';
        }
        echo '</div>';

        // Built-up and extent comparison (all cities).
        $chart_built = WorldStat_UI::chart( [
            'type'     => 'bar',
            'title'    => 'Застроенная площадь (га)',
            'labels'   => array_map( fn( $c ) => $c['name'], $chart_cities ),
            'datasets' => [ [
                'label' => 'Built-up Area (T3)',
                'data'  => array_map( fn( $c ) => $c['builtup_t3'], $chart_cities ),
                'color' => '#3b82f6',
            ] ],
            'echo'    => false,
        ] );
        echo '<div class="chart-card"><div class="card-header"><i class="fas fa-draw-polygon"></i><h3>Застроенная площадь</h3></div>';
        echo '<div class="card-description">Сравнение городов по застроенной площади (T3).</div>';
        echo $chart_built;
        echo '</div>';

        $chart_extent = WorldStat_UI::chart( [
            'type'     => 'bar',
            'title'    => 'Городская территория (га)',
            'labels'   => array_map( fn( $c ) => $c['name'], $chart_cities ),
            'datasets' => [ [
                'label' => 'Urban Extent (T3)',
                'data'  => array_map( fn( $c ) => $c['extent_t3'], $chart_cities ),
                'color' => '#60a5fa',
            ] ],
            'echo'    => false,
        ] );
        echo '<div class="chart-card"><div class="card-header"><i class="fas fa-city"></i><h3>Городская территория</h3></div>';
        echo '<div class="card-description">Сравнение городов по Urban Extent (T3).</div>';
        echo $chart_extent;
        echo '</div>';
        echo '</div>';

        $br_cities = WSCities_CPT::get_cities_with_blocks_roads( $iso2 );
        if ( ! empty( $br_cities ) ) {
            $avg_road_share = self::average_br_metric( $br_cities, 'road_share', 'post1990' );
            $avg_road_width = self::average_br_metric( $br_cities, 'road_width', 'post1990' );
            $avg_road_wide  = self::average_br_metric( $br_cities, 'road_wide', 'post1990' );
            $avg_density    = self::average_br_metric( $br_cities, 'arterial_density', 'post1990' );
            $avg_walk_all   = self::average_br_metric( $br_cities, 'walk_all', 'post1990' );
            $avg_block_size = self::average_br_metric( $br_cities, 'block_size', 'post1990' );

            echo '<div class="ergonomics-section">';
            echo '<div class="section-title"><i class="fas fa-clipboard-check"></i><h2>Оценка эргономичности городской среды</h2></div>';
            echo '<div class="ergonomics-grid">';
            self::print_metric_card( 'fa-road', 'Доля дорог', $avg_road_share, '%', 'Среднее по городам (1990–2015)', 0 );
            self::print_metric_card( 'fa-ruler', 'Средняя ширина дорог', $avg_road_width, 'м', 'Среднее по городам (1990–2015)', 1 );
            self::print_metric_card( 'fa-road-circle-check', 'Доля широких дорог', $avg_road_wide, '%', '>16 м, среднее (1990–2015)', 0 );
            self::print_metric_card( 'fa-diagram-project', 'Плотность артерий', $avg_density, 'км/км²', 'Среднее по городам (1990–2015)', 2 );
            self::print_metric_card( 'fa-walking', 'Доступность артерий', $avg_walk_all, '%', 'В шаговой доступности', 0 );
            self::print_metric_card( 'fa-vector-square', 'Средний размер квартала', $avg_block_size, 'га', 'Среднее по городам (1990–2015)', 1 );
            echo '</div>';
            echo '</div>';
        }

        // ── Greenspace metrics cards ───────────────────
        $green_avgs = self::average_green_metrics( $cities );
        echo '<div class="ergonomics-section">';
        echo '<div class="section-title"><i class="fas fa-leaf"></i><h2>Зеленые зоны</h2></div>';
        echo '<div class="ergonomics-grid">';

        if ( ! empty( $green_avgs ) ) {
            $i = 0;
            foreach ( $green_avgs as $key => $item ) {
                if ( $i >= 4 ) break;
                $unit = (string) ( $item['unit'] ?? '' );
                $val  = isset( $item['value'] ) ? (float) $item['value'] : null;
                $dec  = $unit === '%' ? 0 : 2;
                self::print_metric_card(
                    'fa-leaf',
                    self::green_metric_label( $key ),
                    $val,
                    $unit,
                    'Среднее по городам (greenspace)',
                    $dec
                );
                $i++;
            }
        } else {
            // Cards for future mapping (placeholder).
            self::print_metric_card( 'fa-leaf', 'Метрика зелени', null, '', 'Скоро появятся значения после загрузки greenspace.xlsx', 0 );
        }

        // Extra development cards (placeholders).
        self::print_metric_card( 'fa-hourglass-half', 'Доступность зелени', null, '', 'В разработке: построим расчет по вашим данным', 0 );
        self::print_metric_card( 'fa-leaf', 'Индекс городской экологичности', null, '', 'В разработке: формулы/метрики уточняются', 0 );

        echo '</div>';
        echo '</div>';

        // Full data table with links to individual city pages
        $headers = [ 'Город', 'Население (T3)', 'Рост', 'Площадь застр. (га)', 'Городская тер. (га)', 'Плотность', 'Компакт.', 'Класс роста', 'Класс плотности', 'Кластер' ];
        $rows = [];
        $cluster_counts = [];
        $growth_counts = [];
        $density_counts = [];
        foreach ( $cities as $c ) {
            $city_url  = get_permalink( $c['id'] );
            $city_link = '<a href="' . esc_url( $city_url ) . '">' . esc_html( $c['name'] ) . '</a>';
            $growth_class  = self::classify_growth( $c );
            $density_class = self::classify_density( self::effective_density( $c ) );
            $cluster       = self::cluster_label( $c );

            $cluster_counts[ $cluster ] = ( $cluster_counts[ $cluster ] ?? 0 ) + 1;
            $growth_counts[ $growth_class ] = ( $growth_counts[ $growth_class ] ?? 0 ) + 1;
            $density_counts[ $density_class ] = ( $density_counts[ $density_class ] ?? 0 ) + 1;

            $rows[] = [
                $city_link,
                number_format( $c['pop_t3'], 0, '', ' ' ),
                $c['pop_change'],
                number_format( $c['builtup_t3'], 0, '', ' ' ),
                number_format( $c['extent_t3'], 0, '', ' ' ),
                self::effective_density( $c ) > 0 ? round( self::effective_density( $c ) ) : '—',
                $c['cohesion'] > 0 ? number_format( $c['cohesion'], 2 ) : '—',
                $growth_class,
                $density_class,
                $cluster,
            ];
        }

        if ( ! empty( $cluster_counts ) ) {
            echo '<h3 class="wsp-section-title">Классификация и кластеризация городов</h3>';
            WorldStat_UI::chart( [
                'type'     => 'bar',
                'title'    => 'Кластеры по признакам: размер застройки (га) + плотность (чел/га)',
                'labels'   => array_keys( $cluster_counts ),
                'datasets' => [ [
                    'label' => 'Количество городов',
                    'data'  => array_values( $cluster_counts ),
                    'color' => '#1d4ed8',
                ] ],
                'y_label'  => 'Города',
                'height'   => 320,
            ] );

            if ( ! empty( $growth_counts ) ) {
                WorldStat_UI::chart( [
                    'type'     => 'bar',
                    'title'    => 'Классификация по признаку: рост населения (%)',
                    'labels'   => array_keys( $growth_counts ),
                    'datasets' => [ [
                        'label' => 'Количество городов',
                        'data'  => array_values( $growth_counts ),
                        'color' => '#2563eb',
                    ] ],
                    'y_label'  => 'Города',
                    'height'   => 280,
                ] );
            }

            if ( ! empty( $density_counts ) ) {
                WorldStat_UI::chart( [
                    'type'     => 'bar',
                    'title'    => 'Классификация по признаку: плотность населения (чел/га)',
                    'labels'   => array_keys( $density_counts ),
                    'datasets' => [ [
                        'label' => 'Количество городов',
                        'data'  => array_values( $density_counts ),
                        'color' => '#3b82f6',
                    ] ],
                    'y_label'  => 'Города',
                    'height'   => 280,
                ] );
            }
        }

        echo '<div id="wscities-country-cities-table" aria-hidden="true"></div>';
        WorldStat_UI::table( [
            'headers'    => $headers,
            'rows'       => $rows,
            'sortable'   => true,
            'searchable' => true,
            'exportable' => true,
            'allow_html' => true,
        ] );
        echo '</div>';
    }

    private static function average_br_metric( array $cities, string $metric, string $period ): ?float {
        $sum = 0.0;
        $count = 0;
        foreach ( $cities as $city ) {
            $val = self::to_float_or_null( $city['blocks_roads'][ $metric ][ $period ] ?? null );
            if ( $val !== null ) {
                $sum += $val;
                $count++;
            }
        }
        return $count ? round( $sum / $count, 2 ) : null;
    }

    /**
     * Effective density for charts/table:
     * use imported density first, otherwise derive from population/built-up area.
     */
    private static function effective_density( array $city ): float {
        $density = self::to_float_or_null( $city['density'] ?? null );
        $density = $density ?? 0.0;
        if ( $density > 0 ) return $density;

        $pop     = self::to_float_or_null( $city['pop_t3'] ?? null ) ?? 0.0;
        $builtup = self::to_float_or_null( $city['builtup_t3'] ?? null ) ?? 0.0;
        if ( $pop > 0 && $builtup > 0 ) {
            return $pop / $builtup;
        }

        return 0.0;
    }

    /**
     * City growth class using available population fields.
     */
    private static function classify_growth( array $city ): string {
        $pop_t1 = (float) ( $city['pop_t1'] ?? 0 );
        $pop_t3 = (float) ( $city['pop_t3'] ?? 0 );
        $pct = null;

        if ( $pop_t1 > 0 && $pop_t3 > 0 ) {
            $pct = ( ( $pop_t3 - $pop_t1 ) / $pop_t1 ) * 100.0;
        } else {
            $raw = (string) ( $city['pop_change'] ?? '' );
            if ( preg_match( '/-?\d+(?:[.,]\d+)?/', $raw, $m ) ) {
                $pct = (float) str_replace( ',', '.', $m[0] );
            }
        }

        if ( $pct === null ) return 'Нет данных';
        if ( $pct < 0 ) return 'Снижение';
        if ( $pct < 20 ) return 'Стабильный рост';
        if ( $pct < 60 ) return 'Быстрый рост';
        return 'Взрывной рост';
    }

    /**
     * Density class by people per hectare.
     */
    private static function classify_density( float $density ): string {
        if ( $density <= 0 ) return 'Нет данных';
        if ( $density < 50 ) return 'Низкая';
        if ( $density < 150 ) return 'Средняя';
        if ( $density < 300 ) return 'Высокая';
        return 'Сверхвысокая';
    }

    /**
     * Size class by built-up area (ha).
     */
    private static function classify_size( float $builtup_ha ): string {
        if ( $builtup_ha <= 0 ) return 'Нет данных';
        if ( $builtup_ha < 20000 ) return 'Малый';
        if ( $builtup_ha < 80000 ) return 'Средний';
        return 'Крупный';
    }

    /**
     * Simple rule-based cluster name.
     */
    private static function cluster_label( array $city ): string {
        $density_class = self::classify_density( self::effective_density( $city ) );
        $size_class    = self::classify_size( (float) ( $city['builtup_t3'] ?? 0 ) );
        return $size_class . ' / ' . $density_class;
    }

    /**
     * Average greenspace metrics aggregated per country.
     *
     * Expected city meta: `green_metrics` is JSON map:
     *   { metric_key: { value: number, unit: string }, ... }
     */
    private static function average_green_metrics( array $cities ): array {
        $sum   = [];
        $count = [];
        $unit  = [];

        foreach ( $cities as $city ) {
            $raw = $city['green_metrics'] ?? null;
            if ( ! $raw ) continue;

            $data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
            if ( ! is_array( $data ) ) continue;

            foreach ( $data as $key => $item ) {
                if ( ! is_array( $item ) ) continue;
                if ( ! isset( $item['value'] ) ) continue;

                $v = self::to_float_or_null( $item['value'] );
                if ( $v === null ) continue;

                $sum[ $key ]   = ( $sum[ $key ] ?? 0 ) + $v;
                $count[ $key ] = ( $count[ $key ] ?? 0 ) + 1;

                if ( isset( $item['unit'] ) && $item['unit'] ) {
                    $unit[ $key ] = (string) $item['unit'];
                }
            }
        }

        $avg = [];
        foreach ( $sum as $key => $s ) {
            $c = $count[ $key ] ?? 0;
            if ( ! $c ) continue;
            $avg[ $key ] = [
                'value' => round( $s / $c, 2 ),
                'unit'  => $unit[ $key ] ?? '',
            ];
        }

        // Sort desc by value (most relevant first).
        uasort( $avg, fn( $a, $b ) => ( $b['value'] <=> $a['value'] ) );
        return $avg;
    }

    /**
     * Parse numeric values that may contain locale comma decimals.
     */
    private static function to_float_or_null( $value ): ?float {
        if ( $value === null ) return null;
        if ( is_int( $value ) || is_float( $value ) ) return (float) $value;
        if ( ! is_string( $value ) ) return null;

        $v = trim( $value );
        if ( $v === '' ) return null;
        $v = str_replace( [ ' ', '%' ], '', $v );

        if ( strpos( $v, ',' ) !== false && strpos( $v, '.' ) === false ) {
            $v = str_replace( ',', '.', $v );
        } else {
            $v = str_replace( ',', '', $v );
        }

        return is_numeric( $v ) ? (float) $v : null;
    }

    private static function green_metric_label( string $key ): string {
        $label = str_replace( [ '_', '-' ], ' ', $key );
        $label = trim( $label );
        return $label ? mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' ) : $key;
    }

    private static function print_metric_card( string $icon, string $title, ?float $value, string $unit, string $desc, int $decimals = 0 ): void {
        echo '<div class="metric-card">';
        echo '<div class="metric-title"><i class="fas ' . esc_attr( $icon ) . '"></i> ' . esc_html( $title ) . '</div>';
        echo '<div class="metric-value">';
        if ( $value === null ) {
            echo '—';
        } else {
            echo esc_html( number_format( $value, $decimals, '.', ' ' ) );
            echo '<span class="metric-unit">' . esc_html( $unit ) . '</span>';
        }
        echo '</div>';
        echo '<div class="card-description" style="margin-bottom:0;">' . esc_html( $desc ) . '</div>';
        echo '</div>';
    }

    /**
     * Blocks & Roads section — Table 1 data (200 cities, 2 periods).
     */
    private static function render_blocks_roads_section( array $cities ): void {
        echo '<div class="wsp-section-divider"></div>';
        echo '<h3 class="wsp-section-title"><span class="dashicons dashicons-layout"></span> Кварталы и дороги (Urban Layout)</h3>';
        echo '<p class="wsp-section-desc">Данные о дорожной сети, кварталах и планировке территорий по методологии Atlas of Urban Expansion. Два периода: до 1990 и 1990–2015.</p>';

        // ── Key metrics comparison chart (pre-1990 vs post-1990) ──
        $key_metrics = [ 'road_share', 'arterial_density', 'block_size', 'walkability', 'intersect_4way_share' ];

        // Показываем метрики для всех городов (без Top-N), чтобы визуализация отражала полный датасет.
        self::render_br_comparison_chart( $cities, $key_metrics );

        // ── Roads & Walkability chart ──
        self::render_br_roads_chart( $cities );

        // ── Land Use chart ──
        self::render_br_land_use_chart( $cities );

        // ── Full Blocks & Roads data table ──
        self::render_br_table( $cities );
    }

    /**
     * Comparison chart: Pre-1990 vs 1990-2015 for key metrics.
     */
    private static function render_br_comparison_chart( array $cities, array $metrics ): void {
        foreach ( $cities as $city ) {
            $br = $city['blocks_roads'];
            $pre_data  = [];
            $post_data = [];
            $labels    = [];

            foreach ( $metrics as $m ) {
                if ( ! isset( $br[ $m ] ) ) continue;
                $labels[]    = self::BR_LABELS[ $m ] ?? $m;
                $pre_data[]  = $br[ $m ]['pre1990'] ?? 0;
                $post_data[] = $br[ $m ]['post1990'] ?? 0;
            }

            WorldStat_UI::chart( [
                'type'     => 'bar',
                'title'    => $city['name'] . ' — Ключевые метрики планировки',
                'labels'   => $labels,
                'datasets' => [
                    [
                        'label' => 'До 1990',
                        'data'  => $pre_data,
                        'color' => '#1d4ed8',
                    ],
                    [
                        'label' => '1990–2015',
                        'data'  => $post_data,
                        'color' => '#60a5fa',
                    ],
                ],
            ] );
        }
    }

    /**
     * Roads comparison: road width & arterial density across cities.
     */
    private static function render_br_roads_chart( array $cities ): void {
        $labels     = [];
        $width_pre  = [];
        $width_post = [];
        $dens_pre   = [];
        $dens_post  = [];

        foreach ( $cities as $c ) {
            $br = $c['blocks_roads'];
            $labels[]     = $c['name'];
            $width_pre[]  = $br['road_width']['pre1990'] ?? 0;
            $width_post[] = $br['road_width']['post1990'] ?? 0;
            $dens_pre[]   = $br['arterial_density']['pre1990'] ?? 0;
            $dens_post[]  = $br['arterial_density']['post1990'] ?? 0;
        }

        WorldStat_UI::chart( [
            'type'     => 'bar',
            'title'    => 'Средняя ширина дорог (м)',
            'labels'   => $labels,
            'datasets' => [
                [ 'label' => 'До 1990',    'data' => $width_pre,  'color' => '#1d4ed8' ],
                [ 'label' => '1990–2015',   'data' => $width_post, 'color' => '#60a5fa' ],
            ],
        ] );

        WorldStat_UI::chart( [
            'type'     => 'bar',
            'title'    => 'Плотность артериальных дорог (км/км²)',
            'labels'   => $labels,
            'datasets' => [
                [ 'label' => 'До 1990',    'data' => $dens_pre,  'color' => '#1d4ed8' ],
                [ 'label' => '1990–2015',   'data' => $dens_post, 'color' => '#3b82f6' ],
            ],
        ] );
    }

    /**
     * Land Use chart: residential, gridded, informal, formal.
     */
    private static function render_br_land_use_chart( array $cities ): void {
        $labels    = [];
        $informal  = [];
        $formal    = [];
        $gridded   = [];
        $projects  = [];

        foreach ( $cities as $c ) {
            $br = $c['blocks_roads'];
            $labels[]   = $c['name'];
            $informal[] = $br['informal']['post1990'] ?? 0;
            $formal[]   = $br['formal']['post1990'] ?? 0;
            $gridded[]  = $br['gridded']['post1990'] ?? 0;
            $projects[] = $br['housing_projects']['post1990'] ?? 0;
        }

        WorldStat_UI::chart( [
            'type'   => 'bar',
            'title'  => 'Структура жилых территорий (1990–2015)',
            'labels' => $labels,
            'datasets' => [
                [ 'label' => 'Неформальные',     'data' => $informal, 'color' => '#1d4ed8' ],
                [ 'label' => 'Формальные',        'data' => $formal,   'color' => '#2563eb' ],
                [ 'label' => 'Сеточная план.',     'data' => $gridded,  'color' => '#3b82f6' ],
                [ 'label' => 'Жилищные проекты',   'data' => $projects, 'color' => '#60a5fa' ],
            ],
            'stacked' => true,
        ] );
    }

    /**
     * Full Blocks & Roads data table.
     */
    private static function render_br_table( array $cities ): void {
        $headers = [
            'Город',
            'Доля дорог (%)',
            'Ширина дорог (м)',
            'Плотность арт. (км/км²)',
            'Расст. до арт. (м)',
            'Размер кв. (га)',
            'Перекрёстки 4-ст. (%)',
            'Пешеходность',
            'Жилые терр. (%)',
            'Сеточная план. (%)',
            'Неформальные (%)',
        ];

        $rows = [];
        foreach ( $cities as $c ) {
            $br = $c['blocks_roads'];
            $city_url  = get_permalink( $c['id'] );
            $city_link = '<a href="' . esc_url( $city_url ) . '">' . esc_html( $c['name'] ) . '</a>';
            $rows[] = [
                $city_link,
                self::fmt_pair( $br['road_share'] ?? [] ),
                self::fmt_pair( $br['road_width'] ?? [], 1 ),
                self::fmt_pair( $br['arterial_density'] ?? [], 2 ),
                self::fmt_pair( $br['arterial_distance'] ?? [], 0 ),
                self::fmt_pair( $br['block_size'] ?? [], 1 ),
                self::fmt_pair( $br['intersect_4way_share'] ?? [] ),
                self::fmt_pair( $br['walkability'] ?? [], 1 ),
                self::fmt_pair( $br['residential'] ?? [] ),
                self::fmt_pair( $br['gridded'] ?? [] ),
                self::fmt_pair( $br['informal'] ?? [] ),
            ];
        }

        WorldStat_UI::table( [
            'title'      => 'Данные о кварталах и дорогах (до 1990 → 1990–2015)',
            'headers'    => $headers,
            'rows'       => $rows,
            'sortable'   => true,
            'searchable' => true,
            'exportable' => true,
            'allow_html' => true,
        ] );
    }

    /**
     * Format a pre/post pair for display.
     */
    private static function fmt_pair( array $pair, int $decimals = 0 ): string {
        $pre  = $pair['pre1990'] ?? null;
        $post = $pair['post1990'] ?? null;

        if ( $pre === null && $post === null ) return '—';

        $pre_str  = $pre !== null ? number_format( (float) $pre, $decimals, '.', ' ' ) : '—';
        $post_str = $post !== null ? number_format( (float) $post, $decimals, '.', ' ' ) : '—';

        return $pre_str . ' → ' . $post_str;
    }

    /**
     * Historical Blocks & Roads section — Table 2 data (30 cities, 5 periods).
     */
    private static function render_blocks_roads_hist_section( array $cities ): void {
        echo '<div class="wsp-section-divider"></div>';
        echo '<h3 class="wsp-section-title"><span class="dashicons dashicons-clock"></span> Историческая динамика планировки</h3>';
        echo '<p class="wsp-section-desc">Данные о дорожной сети и кварталах за 5 исторических периодов (от начала XX века). 30 городов из глобальной выборки.</p>';

        foreach ( $cities as $city ) {
            $hist = $city['blocks_roads_hist'];
            if ( empty( $hist['periods'] ) ) continue;

            // Build period labels
            $period_labels = [];
            foreach ( $hist['periods'] as $p ) {
                $start = $p['start'] ?? '?';
                $end   = $p['end'] ?? '?';
                $period_labels[] = $start . '–' . $end;
            }

            // Arterial density over time
            $density_vals = $hist['arterial_density'] ?? [];
            $has_data = array_filter( $density_vals, fn( $v ) => $v !== null );

            if ( ! empty( $has_data ) ) {
                WorldStat_UI::chart( [
                    'type'     => 'line',
                    'title'    => $city['name'] . ' — Плотность артериальных дорог (км/км²)',
                    'labels'   => $period_labels,
                    'datasets' => [ [
                        'label' => 'Плотность арт. дорог',
                        'data'  => array_map( fn( $v ) => $v ?? 0, $density_vals ),
                        'color' => '#1d4ed8',
                    ] ],
                ] );
            }

            // Block size over time
            $block_vals = $hist['block_size'] ?? [];
            $has_block  = array_filter( $block_vals, fn( $v ) => $v !== null );

            if ( ! empty( $has_block ) ) {
                WorldStat_UI::chart( [
                    'type'     => 'line',
                    'title'    => $city['name'] . ' — Средний размер квартала (га)',
                    'labels'   => $period_labels,
                    'datasets' => [ [
                        'label' => 'Размер квартала',
                        'data'  => array_map( fn( $v ) => $v ?? 0, $block_vals ),
                        'color' => '#3b82f6',
                    ] ],
                ] );
            }

            // Land use evolution
            $informal_vals = $hist['informal'] ?? [];
            $formal_vals   = $hist['formal'] ?? [];
            $gridded_vals  = $hist['gridded'] ?? [];

            $has_land = array_filter( array_merge( $informal_vals, $formal_vals, $gridded_vals ), fn( $v ) => $v !== null );

            if ( ! empty( $has_land ) ) {
                WorldStat_UI::chart( [
                    'type'     => 'line',
                    'title'    => $city['name'] . ' — Эволюция структуры территорий',
                    'labels'   => $period_labels,
                    'datasets' => [
                        [
                            'label' => 'Неформальные (%)',
                            'data'  => array_map( fn( $v ) => $v ?? 0, $informal_vals ),
                            'color' => '#1d4ed8',
                        ],
                        [
                            'label' => 'Формальные (%)',
                            'data'  => array_map( fn( $v ) => $v ?? 0, $formal_vals ),
                            'color' => '#2563eb',
                        ],
                        [
                            'label' => 'Сеточная (%)',
                            'data'  => array_map( fn( $v ) => $v ?? 0, $gridded_vals ),
                            'color' => '#60a5fa',
                        ],
                    ],
                ] );
            }
        }

        // Historical data table
        self::render_br_hist_table( $cities );
    }

    /**
     * Historical Blocks & Roads table.
     */
    private static function render_br_hist_table( array $cities ): void {
        $headers = [
            'Город', 'Период', 'Плотн. арт. (км/км²)', 'Расст. арт. (м)',
            'Пешеход. (%)', 'Размер кв. (га)', '4-ст. перекр. (%)',
            'Пешеходность', 'Жилые (%)', 'Неформ. (%)', 'Формальные (%)',
        ];

        $rows = [];
        foreach ( $cities as $city ) {
            $hist = $city['blocks_roads_hist'];
            if ( empty( $hist['periods'] ) ) continue;

            $city_url  = get_permalink( $city['id'] );
            $city_link = '<a href="' . esc_url( $city_url ) . '">' . esc_html( $city['name'] ) . '</a>';
            foreach ( $hist['periods'] as $idx => $period ) {
                $rows[] = [
                    $city_link,
                    ( $period['start'] ?? '?' ) . '–' . ( $period['end'] ?? '?' ),
                    self::fmtv( $hist['arterial_density'][ $idx ] ?? null, 2 ),
                    self::fmtv( $hist['arterial_distance'][ $idx ] ?? null, 0 ),
                    self::fmtv( $hist['walk_all'][ $idx ] ?? null ),
                    self::fmtv( $hist['block_size'][ $idx ] ?? null, 1 ),
                    self::fmtv( $hist['intersect_4way_share'][ $idx ] ?? null ),
                    self::fmtv( $hist['walkability'][ $idx ] ?? null, 1 ),
                    self::fmtv( $hist['residential'][ $idx ] ?? null ),
                    self::fmtv( $hist['informal'][ $idx ] ?? null ),
                    self::fmtv( $hist['formal'][ $idx ] ?? null ),
                ];
            }
        }

        WorldStat_UI::table( [
            'title'      => 'Историческая динамика кварталов и дорог',
            'headers'    => $headers,
            'rows'       => $rows,
            'sortable'   => true,
            'searchable' => true,
            'exportable' => true,
            'allow_html' => true,
        ] );
    }

    /**
     * Format a single value (or show dash for null).
     */
    private static function fmtv( $val, int $decimals = 0 ): string {
        if ( $val === null || $val === '' ) return '—';
        return number_format( (float) $val, $decimals, '.', ' ' );
    }

    /**
     * Render city markers map with coordinate grid.
     */
    private static function render_city_map( array $cities, string $iso2 = '' ): void {
        // Find first city with coords for centering
        $center_lat = 0;
        $center_lng = 0;
        foreach ( $cities as $c ) {
            if ( $c['lat'] && $c['lng'] ) {
                $center_lat = $c['lat'];
                $center_lng = $c['lng'];
                break;
            }
        }

        echo '<h3 class="wsp-section-title">Карта городов</h3>';

        WorldStat_UI::map( [
            'type'          => 'markers',
            'lat'           => $center_lat,
            'lng'           => $center_lng,
            'zoom'          => 5,
            'height'        => 450,
            'grid'          => true,
            'grid_interval' => 15,
            'grid_labels'   => true,
            'marker_layers' => [ 'cities' ],
            'country'       => $iso2,
            'layer_control' => true,
            'tile_style'    => 'countries',
        ] );
    }
}
