<?php
/**
 * Single City page template.
 *
 * Displays comprehensive data about an individual city.
 *
 * @package WorldStatCities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$post_id = get_the_ID();
$meta    = [];
foreach ( WSCities_CPT::META_FIELDS as $key => $type ) {
    $raw = get_post_meta( $post_id, $key, true );
    $short = str_replace( 'wscity_', '', $key );
    $meta[ $short ] = $raw;
}

$city_name    = get_the_title();
$country_name = $meta['country_name'] ?? '';
$iso2         = strtoupper( $meta['country_iso2'] ?? '' );
$lat          = (float) ( $meta['lat'] ?? 0 );
$lng          = (float) ( $meta['lng'] ?? 0 );

// Population data
$pop_t1 = (int) ( $meta['pop_t1'] ?? 0 );
$pop_t2 = (int) ( $meta['pop_t2'] ?? 0 );
$pop_t3 = (int) ( $meta['pop_t3'] ?? 0 );
$pop_change = $meta['pop_change'] ?? '';

// Area data
$builtup_t1 = (float) ( $meta['builtup_t1'] ?? 0 );
$builtup_t2 = (float) ( $meta['builtup_t2'] ?? 0 );
$builtup_t3 = (float) ( $meta['builtup_t3'] ?? 0 );
$extent_t1  = (float) ( $meta['extent_t1'] ?? 0 );
$extent_t2  = (float) ( $meta['extent_t2'] ?? 0 );
$extent_t3  = (float) ( $meta['extent_t3'] ?? 0 );

// Density
$density_builtup = (float) ( $meta['density_builtup'] ?? 0 );
$density_extent  = (float) ( $meta['density_extent'] ?? 0 );

// Fragmentation
$saturation = (float) ( $meta['saturation'] ?? 0 );
$openness   = (float) ( $meta['openness'] ?? 0 );
$proximity  = (float) ( $meta['proximity'] ?? 0 );
$cohesion   = (float) ( $meta['cohesion'] ?? 0 );

// Dates
$date_t1 = $meta['date_t1'] ?? '';
$date_t2 = $meta['date_t2'] ?? '';
$date_t3 = $meta['date_t3'] ?? '';

// Blocks & Roads
$br = WSCities_CPT::get_blocks_roads( $post_id );
$br_hist = WSCities_CPT::get_blocks_roads_hist( $post_id );

// Greenspace metrics (JSON map: key => {value, unit})
$green_metrics_raw = (string) ( $meta['green_metrics'] ?? '' );
$green_metrics = $green_metrics_raw ? json_decode( $green_metrics_raw, true ) : [];
if ( ! is_array( $green_metrics ) ) $green_metrics = [];

// Population history (JSON map: year => pop)
$pop_hist_raw = (string) ( $meta['pop_history'] ?? '' );
$pop_history = $pop_hist_raw ? json_decode( $pop_hist_raw, true ) : [];
if ( ! is_array( $pop_history ) ) $pop_history = [];

$labelize_key = function ( string $key ): string {
    $k = str_replace( [ '_', '-' ], ' ', $key );
    $k = trim( preg_replace( '/\s+/', ' ', $k ) );
    return $k ? mb_convert_case( $k, MB_CASE_TITLE, 'UTF-8' ) : $key;
};

// Human-readable labels for generic Greenspace metric names.
$greenspace_label_map = [
    'green metric 0'  => 'Базовый показатель озеленения',
    'green metric 7'  => 'Озеленение: метрика 7',
    'green metric 8'  => 'Озеленение: метрика 8',
    'green metric 9'  => 'Озеленение: метрика 9',
    'green metric 10' => 'Озеленение: метрика 10',
    'green metric 12' => 'Озеленение: метрика 12',
    'green metric 13' => 'Озеленение: метрика 13',
    'green metric 14' => 'Озеленение: метрика 14',
    'green metric 16' => 'Озеленение: метрика 16',
    'green metric 17' => 'Озеленение: метрика 17',
    'green metric 18' => 'Озеленение: метрика 18',
    'green metric 19' => 'Озеленение: метрика 19',
    'green metric 20' => 'Озеленение: метрика 20',
    'green metric 22' => 'Озеленение: метрика 22',
    'green metric 23' => 'Озеленение: метрика 23',
    'green metric 24' => 'Озеленение: метрика 24',
    'green metric 26' => 'Озеленение: метрика 26',
];
$resolve_greenspace_label = function ( string $key, string $label ) use ( $greenspace_label_map ): string {
    $normalized = strtolower( trim( $label ) );
    if ( isset( $greenspace_label_map[ $normalized ] ) ) {
        return $greenspace_label_map[ $normalized ];
    }
    if ( preg_match( '/^green metric\s+(\d+)$/i', $label, $m ) ) {
        return 'Озеленение: метрика ' . $m[1];
    }

    $k = strtolower( trim( $key ) );
    $patterns = [
        '/^annual_avg_(\d{4})$/'         => 'Среднее озеленение (NDVI), %s',
        '/^peak_ndvi_(\d{4})$/'          => 'Пиковое озеленение (NDVI), %s',
        '/^annual_weight_avg_(\d{4})$/'  => 'Взвешенное среднее озеленение, %s',
        '/^peak_weight_(\d{4})$/'        => 'Взвешенный пик озеленения, %s',
        '/^indicator_(\d{4})$/'          => 'Класс озеленения, %s',
    ];
    foreach ( $patterns as $re => $tpl ) {
        if ( preg_match( $re, $k, $m ) ) {
            return sprintf( $tpl, $m[1] );
        }
    }

    // Fallback: make key human-readable.
    $fallback = str_replace( [ '_', '-' ], ' ', $k );
    $fallback = trim( preg_replace( '/\s+/', ' ', $fallback ) );
    return $fallback ? mb_convert_case( $fallback, MB_CASE_TITLE, 'UTF-8' ) : $label;
};

// Helpers to read greenspace by key.
$gs_get = function ( string $key ) use ( $green_metrics ) {
    $k = strtolower( $key );
    $item = $green_metrics[ $k ] ?? null;
    if ( ! is_array( $item ) || ! isset( $item['value'] ) ) return null;
    return is_numeric( $item['value'] ) ? (float) $item['value'] : null;
};
$pct = function ( ?float $from, ?float $to ): ?float {
    if ( $from === null || $to === null || $from == 0.0 ) return null;
    return ( ( $to - $from ) / $from ) * 100.0;
};

// Build per-metric greenspace chart cards.
$greenspace_chart_metrics = [];
if ( ! empty( $green_metrics ) ) {
    foreach ( $green_metrics as $k => $item ) {
        if ( ! is_array( $item ) || ! isset( $item['value'] ) || ! is_numeric( $item['value'] ) ) continue;
        $key_norm = strtolower( trim( (string) $k ) );
        // Skip service columns accidentally imported as metrics.
        if ( in_array( $key_norm, [ 'column1', 'id', 'rank' ], true ) ) continue;

        $raw_label = isset( $item['label'] ) && $item['label'] ? (string) $item['label'] : $labelize_key( (string) $k );
        $greenspace_chart_metrics[] = [
            'id'    => sanitize_title( (string) $k ),
            'label' => $resolve_greenspace_label( (string) $k, $raw_label ),
            'value' => (float) $item['value'],
            'unit'  => (string) ( $item['unit'] ?? '' ),
        ];
    }
    usort( $greenspace_chart_metrics, fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
}

// Greenspace key years present in source.
$gs_years = [ 2010, 2015, 2020, 2021 ];
$annual = [];
$peak   = [];
foreach ( $gs_years as $y ) {
    $annual[] = $gs_get( 'annual_avg_' . $y );
    $peak[]   = $gs_get( 'peak_ndvi_' . $y );
}

// Calculated cards: greenspace + GHS/WUP.
$annual_2010 = $gs_get( 'annual_avg_2010' );
$annual_2021 = $gs_get( 'annual_avg_2021' );
$peak_2010   = $gs_get( 'peak_ndvi_2010' );
$peak_2021   = $gs_get( 'peak_ndvi_2021' );

$annual_delta_pct = $pct( $annual_2010, $annual_2021 );
$peak_delta_pct   = $pct( $peak_2010, $peak_2021 );

$ghs_years = array_keys( $pop_history );
$ghs_years = array_filter( $ghs_years, fn( $y ) => preg_match( '/^\d{4}$/', (string) $y ) );
sort( $ghs_years, SORT_NUMERIC );
$ghs_vals = array_map( fn( $y ) => (int) str_replace( [ ',', ' ' ], '', (string) ( $pop_history[ $y ] ?? 0 ) ), $ghs_years );

$ghs_first_year = $ghs_years ? (int) reset( $ghs_years ) : null;
$ghs_last_year  = $ghs_years ? (int) end( $ghs_years ) : null;
$ghs_first_pop  = $ghs_vals ? (int) reset( $ghs_vals ) : null;
$ghs_last_pop   = $ghs_vals ? (int) end( $ghs_vals ) : null;
$ghs_growth_pct = ( $ghs_first_pop && $ghs_last_pop ) ? ( ( ( $ghs_last_pop - $ghs_first_pop ) / $ghs_first_pop ) * 100.0 ) : null;

$ghs_cagr = null;
if ( $ghs_first_pop && $ghs_last_pop && $ghs_first_year && $ghs_last_year && $ghs_last_year > $ghs_first_year ) {
    $n = (float) ( $ghs_last_year - $ghs_first_year );
    $ghs_cagr = ( pow( (float) $ghs_last_pop / (float) $ghs_first_pop, 1.0 / $n ) - 1.0 ) * 100.0;
}

$calc_cards = [];
if ( $annual_2021 !== null ) {
    $calc_cards[] = [
        'icon'  => 'fa-leaf',
        'title' => 'Средний уровень озеленения (2010→2021)',
        'value' => number_format( $annual_2021, 4, '.', ' ' ),
        'desc'  => $annual_delta_pct !== null
            ? 'Изменение за период: ' . ( $annual_delta_pct >= 0 ? '+' : '' ) . number_format( $annual_delta_pct, 1, '.', ' ' ) . '%'
            : 'Динамика за период недоступна',
    ];
}
if ( $peak_2021 !== null ) {
    $calc_cards[] = [
        'icon'  => 'fa-seedling',
        'title' => 'Пиковый индекс озеленения NDVI (2010→2021)',
        'value' => number_format( $peak_2021, 4, '.', ' ' ),
        'desc'  => $peak_delta_pct !== null
            ? 'Изменение за период: ' . ( $peak_delta_pct >= 0 ? '+' : '' ) . number_format( $peak_delta_pct, 1, '.', ' ' ) . '%'
            : 'Динамика за период недоступна',
    ];
}
if ( $ghs_first_year && $ghs_last_year && $ghs_growth_pct !== null ) {
    $calc_cards[] = [
        'icon'  => 'fa-users',
        'title' => 'Рост населения по историческим данным',
        'value' => $ghs_first_year . '→' . $ghs_last_year,
        'desc'  => 'Изменение за период: ' . ( $ghs_growth_pct >= 0 ? '+' : '' ) . number_format( $ghs_growth_pct, 1, '.', ' ' ) . '%',
    ];
}
if ( $ghs_cagr !== null ) {
    $calc_cards[] = [
        'icon'  => 'fa-chart-line',
        'title' => 'Среднегодовой темп роста населения',
        'value' => number_format( $ghs_cagr, 2, '.', ' ' ) . '%',
        'desc'  => 'Рассчитано по историческому ряду GHS/WUP',
    ];
}

// Доп. расчёты по основным трём срезам (T1→T3), если есть данные.
$pop_growth_pct = $pct( (float) $pop_t1, (float) $pop_t3 );
$built_growth_pct = $pct( $builtup_t1, $builtup_t3 );
$extent_growth_pct = $pct( $extent_t1, $extent_t3 );

// Link to country page
$country_post = null;
if ( $iso2 && class_exists( 'WorldStat_Country_CPT' ) ) {
    $country_post = WorldStat_Country_CPT::get_by_code( $iso2 );
}

$country_cities_url = $iso2 ? WSCities_CPT::get_country_tab_url( $iso2, 'cities' ) : '';
$country_ergo_url     = $iso2 ? WSCities_CPT::get_country_tab_url( $iso2, 'ergonomics', 'cities' ) : '';
$has_ergo_tab         = $country_ergo_url && class_exists( 'WSErgo_Renderer' );

// Prepare data for the transferred layout.
$region_name = (string) ( $meta['region'] ?? '' );
$br = is_array( $br ) ? $br : [];

/**
 * Blocks & Roads Table 2 (история): если для метрики нет Table 1, подставляем
 * первый и последний период с числом — в те же ключи pre1990/post1990 для тренда и карточек.
 * В Table 2 нет: road_share, road_width, road_wide.
 */
$br_hist_arr = is_array( $br_hist ) ? $br_hist : [];
if ( ! empty( $br_hist_arr['periods'] ) && is_array( $br_hist_arr['periods'] ) ) {
    $hist_metric_keys = [ 'arterial_density', 'walk_all', 'block_size' ];
    foreach ( $hist_metric_keys as $hm ) {
        if ( empty( $br_hist_arr[ $hm ] ) || ! is_array( $br_hist_arr[ $hm ] ) ) {
            continue;
        }
        $existing = $br[ $hm ] ?? [];
        $t1_pre  = $to_float_or_null( $existing['pre1990'] ?? null );
        $t1_post = $to_float_or_null( $existing['post1990'] ?? null );
        $has_t1  = ( $t1_pre !== null ) || ( $t1_post !== null );
        if ( $has_t1 ) {
            continue;
        }
        $vals = $br_hist_arr[ $hm ];
        $first   = null;
        $last    = null;
        $first_i = null;
        $last_i  = null;
        foreach ( $vals as $i => $v ) {
            $fv = $to_float_or_null( $v );
            if ( $fv === null ) {
                continue;
            }
            if ( $first === null ) {
                $first   = $fv;
                $first_i = (int) $i;
            }
            $last   = $fv;
            $last_i = (int) $i;
        }
        if ( $first === null && $last === null ) {
            continue;
        }
        if ( $last === null ) {
            $last   = $first;
            $last_i = $first_i;
        }
        if ( $first === null ) {
            $first   = $last;
            $first_i = $last_i;
        }
        $period_label = function ( ?int $idx ) use ( $br_hist_arr ): string {
            if ( $idx === null || ! isset( $br_hist_arr['periods'][ $idx ] ) ) {
                return '';
            }
            $p = $br_hist_arr['periods'][ $idx ];
            $s = isset( $p['start'] ) ? (string) $p['start'] : '';
            $e = isset( $p['end'] ) ? (string) $p['end'] : '';
            if ( $s !== '' && $e !== '' ) {
                return $s . '–' . $e;
            }
            return $s !== '' ? $s : ( $e !== '' ? $e : (string) $idx );
        };
        $br[ $hm ] = [
            'pre1990'  => $first,
            'post1990' => $last,
            '_br_hist' => [
                'period_pre'  => $period_label( $first_i ),
                'period_post' => $period_label( $last_i ),
            ],
        ];
    }
}

$chart_label_1 = $date_t1 ?: 'T1';
$chart_label_2 = $date_t2 ?: 'T2';
$chart_label_3 = $date_t3 ?: 'T3';

// Density by period can be derived from population / built-up area (ha).
$density_1 = $builtup_t1 > 0 ? ( (float) $pop_t1 / (float) $builtup_t1 ) : 0;
$density_2 = $builtup_t2 > 0 ? ( (float) $pop_t2 / (float) $builtup_t2 ) : 0;
$density_3 = $builtup_t3 > 0 ? ( (float) $pop_t3 / (float) $builtup_t3 ) : $density_builtup;

$format_population_short = function ( int $pop ): string {
    if ( $pop >= 1000000 ) return round( $pop / 1000000, 2 ) . ' млн';
    if ( $pop >= 1000 ) return round( $pop / 1000, 1 ) . ' тыс.';
    return (string) $pop;
};

$format_int = function ( $v ): string {
    if ( $v === null || $v === '' ) return '—';
    return number_format( (int) $v, 0, '', ' ' );
};

$format_float = function ( $v, int $decimals = 1 ): string {
    if ( $v === null || $v === '' ) return '—';
    return number_format( (float) $v, $decimals, '.', ' ' );
};

$to_float_or_null = function ( $value ): ?float {
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
};

$fmt_trend = function ( $pre, $post ): array {
    if ( $pre === null || $post === null ) return [ 'text' => '—', 'class' => 'neutral' ];
    $pre_f = (float) $pre;
    $post_f = (float) $post;
    if ( $pre_f == 0.0 ) return [ 'text' => '—', 'class' => 'neutral' ];
    $delta = ( ( $post_f - $pre_f ) / $pre_f ) * 100;
    if ( abs( $delta ) < 0.0000001 ) {
        return [ 'text' => '0%', 'class' => 'neutral' ];
    }
    $abs = (int) round( abs( $delta ), 0 );
    $text = ( $delta >= 0 ? '+' : '-' ) . $abs . '%';
    $class = $delta < 0 ? 'warning' : '';
    return [ 'text' => $text, 'class' => $class ];
};

$road_share_pre = ( $br['road_share'] ?? [] )['pre1990'] ?? null;
$road_share_post = ( $br['road_share'] ?? [] )['post1990'] ?? null;
$road_width_pre = ( $br['road_width'] ?? [] )['pre1990'] ?? null;
$road_width_post = ( $br['road_width'] ?? [] )['post1990'] ?? null;
$road_wide_pre = ( $br['road_wide'] ?? [] )['pre1990'] ?? null;
$road_wide_post = ( $br['road_wide'] ?? [] )['post1990'] ?? null;
$arterial_density_pre = ( $br['arterial_density'] ?? [] )['pre1990'] ?? null;
$arterial_density_post = ( $br['arterial_density'] ?? [] )['post1990'] ?? null;
$walk_all_pre = ( $br['walk_all'] ?? [] )['pre1990'] ?? null;
$walk_all_post = ( $br['walk_all'] ?? [] )['post1990'] ?? null;
$block_size_pre = ( $br['block_size'] ?? [] )['pre1990'] ?? null;
$block_size_post = ( $br['block_size'] ?? [] )['post1990'] ?? null;

/**
 * Значение метрики Blocks & Roads: сначала post1990, иначе pre1990 (если post отсутствует).
 */
$br_pick = function ( string $key ) use ( $br, $to_float_or_null ): array {
    $metric = $br[ $key ] ?? null;
    if ( ! is_array( $metric ) ) {
        return [ 'val' => null, 'note' => '' ];
    }
    $hist_meta = ( isset( $metric['_br_hist'] ) && is_array( $metric['_br_hist'] ) ) ? $metric['_br_hist'] : null;
    $pre  = $to_float_or_null( $metric['pre1990'] ?? null );
    $post = $to_float_or_null( $metric['post1990'] ?? null );
    if ( $post !== null ) {
        if ( $hist_meta ) {
            $pp = $hist_meta['period_post'] ?? '';
            $note = $pp !== '' ? ( 'последний период: ' . $pp . ' (табл. 2)' ) : 'табл. 2 (история)';
        } else {
            $note = '1990–2015';
        }
        return [ 'val' => $post, 'note' => $note ];
    }
    if ( $pre !== null ) {
        if ( $hist_meta ) {
            $pp = $hist_meta['period_pre'] ?? '';
            $note = $pp !== '' ? ( 'период: ' . $pp . ' (табл. 2)' ) : 'табл. 2 (история)';
        } else {
            $note = 'до 1990 (нет post1990)';
        }
        return [ 'val' => $pre, 'note' => $note ];
    }
    return [ 'val' => null, 'note' => '' ];
};

$calc_pop_growth = $pct( (float) $pop_t1, (float) $pop_t3 );
$calc_built_growth = $pct( $builtup_t1, $builtup_t3 );
$calc_extent_growth = $pct( $extent_t1, $extent_t3 );
$calc_density_growth = $pct( $density_1 > 0 ? $density_1 : null, $density_3 > 0 ? $density_3 : null );

$fill_t1 = ( $extent_t1 > 0 && $builtup_t1 > 0 ) ? ( $builtup_t1 / $extent_t1 ) * 100.0 : null;
$fill_t3 = ( $extent_t3 > 0 && $builtup_t3 > 0 ) ? ( $builtup_t3 / $extent_t3 ) * 100.0 : null;
$fill_change = $pct( $fill_t1, $fill_t3 );

$ergo_cards = [
    [
        'icon' => 'fa-users',
        'title' => 'Рост населения (T1→T3)',
        'value' => $calc_pop_growth !== null ? ( ( $calc_pop_growth >= 0 ? '+' : '' ) . number_format( $calc_pop_growth, 1, '.', ' ' ) ) : 'Нет данных',
        'unit'  => $calc_pop_growth !== null ? '%' : '',
        'desc'  => 'Расчёт: (население T3 − T1) / T1',
        'trend' => $fmt_trend( $pop_t1 > 0 ? $pop_t1 : null, $pop_t3 > 0 ? $pop_t3 : null ),
    ],
    [
        'icon' => 'fa-draw-polygon',
        'title' => 'Рост застроенной площади (T1→T3)',
        'value' => $calc_built_growth !== null ? ( ( $calc_built_growth >= 0 ? '+' : '' ) . number_format( $calc_built_growth, 1, '.', ' ' ) ) : 'Нет данных',
        'unit'  => $calc_built_growth !== null ? '%' : '',
        'desc'  => 'Расчёт: (застроенная площадь T3 − T1) / T1',
        'trend' => $fmt_trend( $builtup_t1 > 0 ? $builtup_t1 : null, $builtup_t3 > 0 ? $builtup_t3 : null ),
    ],
    [
        'icon' => 'fa-city',
        'title' => 'Рост городской территории (T1→T3)',
        'value' => $calc_extent_growth !== null ? ( ( $calc_extent_growth >= 0 ? '+' : '' ) . number_format( $calc_extent_growth, 1, '.', ' ' ) ) : 'Нет данных',
        'unit'  => $calc_extent_growth !== null ? '%' : '',
        'desc'  => 'Расчёт: (городская территория T3 − T1) / T1',
        'trend' => $fmt_trend( $extent_t1 > 0 ? $extent_t1 : null, $extent_t3 > 0 ? $extent_t3 : null ),
    ],
    [
        'icon' => 'fa-people-arrows',
        'title' => 'Изменение плотности (T1→T3)',
        'value' => $calc_density_growth !== null ? ( ( $calc_density_growth >= 0 ? '+' : '' ) . number_format( $calc_density_growth, 1, '.', ' ' ) ) : 'Нет данных',
        'unit'  => $calc_density_growth !== null ? '%' : '',
        'desc'  => 'Расчёт по плотности населения на застроенной территории',
        'trend' => $fmt_trend( $density_1 > 0 ? $density_1 : null, $density_3 > 0 ? $density_3 : null ),
    ],
    [
        'icon' => 'fa-chart-pie',
        'title' => 'Доля застройки в городской территории (T3)',
        'value' => $fill_t3 !== null ? number_format( $fill_t3, 1, '.', ' ' ) : 'Нет данных',
        'unit'  => $fill_t3 !== null ? '%' : '',
        'desc'  => 'Расчёт: застроенная площадь T3 / городская территория T3',
        'trend' => $fmt_trend( $fill_t1, $fill_t3 ),
    ],
    [
        'icon' => 'fa-object-group',
        'title' => 'Компактность (Cohesion)',
        'value' => $cohesion > 0 ? number_format( $cohesion, 2, '.', ' ' ) : 'Нет данных',
        'unit'  => '',
        'desc'  => 'Индекс компактности городской формы (из исходного импорта)',
        'trend' => [ 'text' => $cohesion > 0 ? 'актуальное значение T3' : '—', 'class' => 'neutral' ],
    ],
];

$ergo_by_title = [];
foreach ( $ergo_cards as $c ) {
    if ( ! empty( $c['title'] ) ) {
        $ergo_by_title[ $c['title'] ] = $c;
    }
}

$stat_hint_pop = '';
if ( $pop_t1 > 0 && $pop_t3 > 0 && $pop_growth_pct !== null ) {
    $stat_hint_pop = ( $pop_growth_pct >= 0 ? '+' : '' ) . number_format( $pop_growth_pct, 1, '.', ' ' ) . '% за период ' . $chart_label_1 . '→' . $chart_label_3;
}

$stat_hint_built = '';
if ( $builtup_t1 > 0 && $builtup_t3 > 0 && $built_growth_pct !== null ) {
    $stat_hint_built = ( $built_growth_pct >= 0 ? '+' : '' ) . number_format( $built_growth_pct, 1, '.', ' ' ) . '% за период ' . $chart_label_1 . '→' . $chart_label_3;
}

$stat_hint_density = '';
if ( $pop_t3 > 0 && $builtup_t3 > 0 ) {
    $stat_hint_density = 'Расчёт: население ' . $chart_label_3 . ' / застройка ' . $chart_label_3;
} elseif ( $density_builtup > 0 ) {
    $stat_hint_density = 'Значение из импорта (density_builtup)';
}

// Enqueue example layout assets for the city page.
wp_enqueue_style(
    'wscities-city-inter',
    'https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap',
    [],
    null
);
wp_enqueue_style(
    'wscities-city-fontawesome',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
    [],
    null
);
wp_enqueue_style(
    'wscities-city-css',
    WSCITIES_URL . 'assets/css/city-page.css',
    [],
    WSCITIES_VERSION
);

// Chart.js local (platform asset) for offline support.
$chartjs_deps = [];
if ( defined( 'WSP_ASSETS_URL' ) ) {
    wp_enqueue_script(
        'chartjs',
        WSP_ASSETS_URL . 'vendor/chartjs/chart.umd.min.js',
        [],
        '4.4',
        true
    );
    $chartjs_deps = [ 'chartjs' ];
}

wp_enqueue_script(
    'wscities-city-page',
    WSCITIES_URL . 'assets/js/city-page.js',
    $chartjs_deps,
    WSCITIES_VERSION,
    true
);

$chart_payload = [
    'labels'       => [ $chart_label_1, $chart_label_2, $chart_label_3 ],
    'population'   => [ (int) $pop_t1, (int) $pop_t2, (int) $pop_t3 ],
    'builtArea'    => [ (float) $builtup_t1, (float) $builtup_t2, (float) $builtup_t3 ],
    'urbanExtent'  => [ (float) $extent_t1, (float) $extent_t2, (float) $extent_t3 ],
    'density'      => [ (float) $density_1, (float) $density_2, (float) $density_3 ],
    'fragmentationLabels' => [ 'Saturation', 'Openness', 'Proximity', 'Cohesion' ],
    'fragmentationValues' => [ (float) $saturation, (float) $openness, (float) $proximity, (float) $cohesion ],
];

wp_localize_script( 'wscities-city-page', 'wscitiesCityCharts', $chart_payload );

$has_districts_subpage = class_exists( 'WSDistricts_Renderer' ) && class_exists( 'WSDistricts_CPT' );

do_action( 'worldstat_before_city', $post_id, $meta );
?>

<div class="wsp-country-page wsp-city-page">

    <div class="container">
        <?php if ( $country_cities_url || $has_ergo_tab ) : ?>
            <nav class="wsp-city-nav" aria-label="Навигация по стране">
                <?php if ( $country_cities_url ) : ?>
                    <a class="wsp-city-nav-btn" href="<?php echo esc_url( $country_cities_url ); ?>">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i>
                        <?php
                        echo esc_html(
                            $country_name
                                ? sprintf( 'Все города: %s', $country_name )
                                : 'К таблице городов страны'
                        );
                        ?>
                    </a>
                <?php endif; ?>
                <?php if ( $has_ergo_tab ) : ?>
                    <a class="wsp-city-nav-btn wsp-city-nav-btn--ergo" href="<?php echo esc_url( $country_ergo_url ); ?>">
                        <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                        Эргономичность городов
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

        <!-- Шапка города -->
        <div class="city-header">
            <div class="city-title">
                <h1><?php echo esc_html( $city_name ); ?></h1>
                <div class="city-location">
                    <?php if ( $country_name ) : ?>
                        <span><i class="fas fa-globe" style="font-size:0.75rem;"></i> <?php echo esc_html( $country_name ); ?></span>
                    <?php endif; ?>
                    <?php if ( $region_name ) : ?>
                        <span><i class="fas fa-map-pin" style="font-size:0.75rem;"></i> <?php echo esc_html( $region_name ); ?></span>
                    <?php endif; ?>
                    <?php if ( $date_t1 || $date_t3 ) : ?>
                        <span><i class="fas fa-calendar-alt" style="font-size:0.75rem;"></i>
                            Данные:
                            <?php echo esc_html( $date_t1 ?: $chart_label_1 ); ?>
                            – <?php echo esc_html( $date_t3 ?: $chart_label_3 ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="city-stats-mini">
                <div class="stat-item">
                    <div class="stat-label">Население (<?php echo esc_html( $chart_label_3 ); ?>)</div>
                    <div class="stat-value"><?php echo esc_html( $format_population_short( (int) $pop_t3 ) ); ?></div>
                    <?php if ( $stat_hint_pop ) : ?>
                        <div class="stat-hint"><?php echo esc_html( $stat_hint_pop ); ?></div>
                    <?php endif; ?>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Застроенная площадь</div>
                    <div class="stat-value">
                        <?php echo $builtup_t3 > 0 ? esc_html( $format_int( $builtup_t3 ) ) : '—'; ?> га
                    </div>
                    <?php if ( $stat_hint_built ) : ?>
                        <div class="stat-hint"><?php echo esc_html( $stat_hint_built ); ?></div>
                    <?php endif; ?>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Плотность застройки</div>
                    <div class="stat-value">
                        <?php echo $density_3 > 0 ? esc_html( round( $density_3 ) ) : '—'; ?> чел/га
                    </div>
                    <?php if ( $stat_hint_density ) : ?>
                        <div class="stat-hint"><?php echo esc_html( $stat_hint_density ); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ( $has_districts_subpage ) : ?>
            <style>
                .wsp-city-page .wsp-city-subtabs .wsp-tab-nav {
                    display: flex !important;
                    align-items: center !important;
                    gap: 24px !important;
                    border-bottom: 1px solid #e5e7eb !important;
                    margin-bottom: 16px !important;
                }
                .wsp-city-page .wsp-city-subtabs .wsp-tab-btn {
                    appearance: none !important;
                    border: 0 !important;
                    background: transparent !important;
                    color: #6b7280 !important;
                    font-size: 24px !important;
                    font-weight: 500 !important;
                    line-height: 1.2 !important;
                    padding: 0 0 10px !important;
                    cursor: pointer !important;
                    border-bottom: 2px solid transparent !important;
                }
                .wsp-city-page .wsp-city-subtabs .wsp-tab-btn.wsp-tab-active {
                    color: #2563eb !important;
                    border-bottom-color: #2563eb !important;
                }
            </style>
            <div class="wsp-tabs wsp-city-subtabs" style="margin-bottom:1.5rem;">
                <nav class="wsp-tab-nav" role="tablist" aria-label="Подвкладки страницы города">
                    <button class="wsp-tab-btn wsp-tab-active" data-tab="overview" role="tab" aria-selected="true">Обзор города</button>
                    <button class="wsp-tab-btn" data-tab="districts" role="tab" aria-selected="false">Районы</button>
                </nav>
                <div class="wsp-tab-panels">
                    <section class="wsp-tab-panel wsp-tab-panel-active" data-tab="overview">
        <?php endif; ?>

        <!-- Блок с графиками -->
        <h2 style="font-size:1.5rem; font-weight:600; margin-bottom:1.5rem;">Динамика роста</h2>

        <div class="charts-grid">
            <!-- Карточка 1 -->
            <div class="chart-card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i>
                    <h3>Рост населения</h3>
                </div>
                <div class="card-description">
                    Изменение численности населения городской агломерации за три временных среза.
                </div>
                <div class="chart-container">
                    <canvas id="chartPopulation"></canvas>
                </div>
            </div>

            <!-- Карточка 2 -->
            <div class="chart-card">
                <div class="card-header">
                    <i class="fas fa-draw-polygon"></i>
                    <h3>Застроенная площадь</h3>
                </div>
                <div class="card-description">
                    Общая застроенная площадь в гектарах за три временных среза.
                </div>
                <div class="chart-container">
                    <canvas id="chartBuiltArea"></canvas>
                </div>
            </div>

            <!-- Карточка 3 -->
            <div class="chart-card">
                <div class="card-header">
                    <i class="fas fa-city"></i>
                    <h3>Городская территория</h3>
                </div>
                <div class="card-description">
                    Изменение общей площади городской территории (Urban Extent) за три периода.
                </div>
                <div class="chart-container">
                    <canvas id="chartUrbanExtent"></canvas>
                </div>
            </div>

            <!-- Карточка 4 -->
            <div class="chart-card">
                <div class="card-header">
                    <i class="fas fa-people-arrows"></i>
                    <h3>Плотность застройки</h3>
                </div>
                <div class="card-description">
                    Изменение плотности населения на застроенной территории (чел./га).
                </div>
                <div class="chart-container">
                    <canvas id="chartDensity"></canvas>
                </div>
            </div>

        </div>

        <!-- Оценка эргономичности городской среды -->
        <div class="ergonomics-section">
            <div class="section-title">
                <i class="fas fa-clipboard-check"></i>
                <h2>Оценка эргономичности городской среды</h2>
            </div>

            <div class="ergonomics-grid">
                <?php foreach ( $ergo_cards as $card ) : ?>
                    <div class="metric-card">
                        <div class="metric-title"><i class="fas <?php echo esc_attr( $card['icon'] ); ?>"></i> <?php echo esc_html( $card['title'] ); ?></div>
                        <div class="metric-value">
                            <?php echo esc_html( (string) $card['value'] ); ?>
                            <?php if ( ! empty( $card['unit'] ) ) : ?>
                                <span class="metric-unit"><?php echo esc_html( (string) $card['unit'] ); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $card['desc'] ) ) : ?>
                            <div class="card-description" style="margin-bottom:0;"><?php echo esc_html( (string) $card['desc'] ); ?></div>
                        <?php endif; ?>
                        <?php if ( ! empty( $card['trend'] ) && is_array( $card['trend'] ) ) : ?>
                            <div class="metric-trend <?php echo esc_attr( (string) ( $card['trend']['class'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $card['trend']['text'] ?? '—' ) ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ( $calc_cards as $card ) : ?>
                    <div class="metric-card">
                        <div class="metric-title"><i class="fas <?php echo esc_attr( $card['icon'] ); ?>"></i> <?php echo esc_html( $card['title'] ); ?></div>
                        <div class="metric-value"><?php echo esc_html( $card['value'] ); ?></div>
                        <?php if ( ! empty( $card['desc'] ) ) : ?>
                            <div class="card-description" style="margin-bottom:0;"><?php echo esc_html( $card['desc'] ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ergonomics-description">
                <p>
                    <strong>Интегральная оценка:</strong>
                    <?php
                    $e_pop = $ergo_by_title['Рост населения (T1→T3)'] ?? null;
                    $e_bui = $ergo_by_title['Рост застроенной площади (T1→T3)'] ?? null;
                    $e_den = $ergo_by_title['Изменение плотности (T1→T3)'] ?? null;
                    $e_fill = $ergo_by_title['Доля застройки в Urban Extent (T3)'] ?? null;
                    ?>
                    По данным динамики видно, что население изменилось на
                    <strong><?php echo $e_pop && $e_pop['value'] !== 'Нет данных' ? esc_html( (string) $e_pop['value'] . ( $e_pop['unit'] ? (string) $e_pop['unit'] : '' ) ) : 'нет данных'; ?></strong>,
                    застроенная площадь — на
                    <strong><?php echo $e_bui && $e_bui['value'] !== 'Нет данных' ? esc_html( (string) $e_bui['value'] . ( $e_bui['unit'] ? (string) $e_bui['unit'] : '' ) ) : 'нет данных'; ?></strong>,
                    плотность — на
                    <strong><?php echo $e_den && $e_den['value'] !== 'Нет данных' ? esc_html( (string) $e_den['value'] . ( $e_den['unit'] ? (string) $e_den['unit'] : '' ) ) : 'нет данных'; ?></strong>.
                    Текущая доля застройки в пределах Urban Extent:
                    <strong><?php echo $e_fill && $e_fill['value'] !== 'Нет данных' ? esc_html( (string) $e_fill['value'] . ( $e_fill['unit'] ? (string) $e_fill['unit'] : '' ) ) : 'нет данных'; ?></strong>.
                </p>
                <p>
                    <strong>Изменения по историческим срезам T1→T3:</strong>
                    <?php
                    $pick_trend = function ( $row ): array {
                        if ( is_array( $row ) && isset( $row['trend'] ) && is_array( $row['trend'] ) ) {
                            return $row['trend'];
                        }
                        return [ 'text' => '—', 'class' => 'neutral' ];
                    };
                    $t_pop = $pick_trend( $e_pop );
                    $t_bui = $pick_trend( $e_bui );
                    $t_den = $pick_trend( $e_den );
                    ?>
                    население <?php echo esc_html( (string) $t_pop['text'] ); ?>,
                    застроенная площадь <?php echo esc_html( (string) $t_bui['text'] ); ?>,
                    плотность <?php echo esc_html( (string) $t_den['text'] ); ?>.
                </p>
            </div>
        </div>
        <!-- Карточки в разработке -->
        <div class="ergonomics-section">
            <div class="section-title">
                <i class="fas fa-flask"></i>
                <h2>Карточки в разработке</h2>
            </div>
            <div class="ergonomics-grid">
                <div class="metric-card">
                    <div class="metric-title"><i class="fas fa-route"></i> Индекс транспортной связности</div>
                    <div class="metric-value">—</div>
                    <div class="card-description" style="margin-bottom:0;">План: объединить показатели сети дорог, плотности и доступности.</div>
                </div>
                <div class="metric-card">
                    <div class="metric-title"><i class="fas fa-tree-city"></i> Индекс экологической устойчивости</div>
                    <div class="metric-value">—</div>
                    <div class="card-description" style="margin-bottom:0;">План: связать greenspace, плотность и динамику urban extent.</div>
                </div>
                <div class="metric-card">
                    <div class="metric-title"><i class="fas fa-house-chimney"></i> Индекс компактности застройки</div>
                    <div class="metric-value">—</div>
                    <div class="card-description" style="margin-bottom:0;">План: расчёт по cohesion, saturation, openness и fill-rate.</div>
                </div>
                <div class="metric-card">
                    <div class="metric-title"><i class="fas fa-arrow-up-right-dots"></i> Индекс сбалансированного роста</div>
                    <div class="metric-value">—</div>
                    <div class="card-description" style="margin-bottom:0;">План: сравнение темпов роста населения и застроенной площади.</div>
                </div>
            </div>
        </div>
        <!-- GHS/WUP history -->
        <h2 style="font-size:1.5rem; font-weight:600; margin:2rem 0 1rem;">История населения (GHS/WUP)</h2>
        <?php
        if ( ! empty( $ghs_years ) ) {
            WorldStat_UI::chart( [
                'type'     => 'line',
                'title'    => 'Население по годам (GHS/WUP)',
                'labels'   => array_map( 'strval', $ghs_years ),
                'datasets' => [ [ 'label' => 'Население', 'data' => $ghs_vals, 'color' => '#1d4ed8' ] ],
                'y_label'  => 'Человек',
                'height'   => 280,
            ] );
        } else {
            echo '<div class="wsp-notice"><p>Данные GHS/WUP не импортированы для этого города.</p></div>';
        }
        ?>

        <!-- Greenspace (all metrics charts) -->
        <h2 style="font-size:1.5rem; font-weight:600; margin:2rem 0 1rem;">Greenspace — все метрики</h2>
        <?php
        if ( ! empty( $greenspace_chart_metrics ) ) {
            // One combined chart with logarithmic transform, so all bars stay visible
            // even when one metric has a much larger absolute value.
            $combined_items = $greenspace_chart_metrics;
            $combined_vals = array_map( function ( $it ) {
                $v = max( 0.0, (float) $it['value'] );
                return round( log10( $v + 1.0 ), 6 );
            }, $combined_items );

            echo '<div class="ergonomics-section">';
            WorldStat_UI::chart( [
                'type'     => 'bar',
                'title'    => 'Greenspace — объединенный график метрик',
                'labels'   => array_map( fn( $it ) => $it['label'], $combined_items ),
                'datasets' => [ [
                    'label' => 'Нормализованное значение (log10(value + 1))',
                    'data'  => $combined_vals,
                    'color' => '#22c55e',
                ] ],
                'y_label'  => 'Нормализованная шкала',
                'height'   => 360,
            ] );
            echo '</div>';
            echo '<p class="card-description" style="margin-top:8px;">Для сравнимости метрик используется нормализация: log10(value + 1).</p>';
        } else {
            echo '<div class="wsp-notice"><p>Данные greenspace.csv не импортированы для этого города.</p></div>';
        }
        ?>
        <?php if ( $has_districts_subpage ) : ?>
                    </section>
                    <section class="wsp-tab-panel" data-tab="districts">
                        <?php WSDistricts_Renderer::render_city_districts_list( $post_id ); ?>
                    </section>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div><!-- .wsp-city-page -->

<?php
do_action( 'worldstat_after_city', $post_id, $meta );
get_footer();
