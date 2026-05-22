<?php
/**
 * Полигоны озеленения: Overpass, kind в БД, карта (MapLibre), эргономика, попап двора.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSC_Landcover {
	/**
	 * Поля kind, которые считаются «зелёной эргономикой» (парк, газон, клумба, сельхоз и т.д.).
	 *
	 * @return list<string>
	 */
	public static function green_zone_kinds(): array {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$list = apply_filters(
			'wsc_landcover_green_zone_kinds',
			[
				'park',
				'grass',
				'recreation_ground',
				'playground',
				'garden',
				'nature_reserve',
				'green_space',
				'meadow',
				'orchard',
				'vineyard',
				'farmland',
				'allotments',
				'flowerbed',
				'forest',
				'wood',
				'grassland',
				'scrub',
				'heath',
				'wetland',
				'cemetery',
			]
		);
		$list = array_values( array_unique( array_map( 'strtolower', array_map( 'strval', (array) $list ) ) ) );
		return $memo = $list;
	}

	/**
	 * kind для SQL IN (...) — строки с экранированием.
	 *
	 * @return string 'a','b',...
	 */
	public static function green_zone_sql_in_list(): string {
		return "'" . implode( "','", array_map( 'esc_sql', self::green_zone_kinds() ) ) . "'";
	}

	/**
	 * Match MapLibre: kind => цвет заливки.
	 *
	 * @return array<int, mixed>
	 */
	public static function map_style_fill_match(): array {
		$pairs = apply_filters(
			'wsc_landcover_map_fill_palette_pairs',
			[
				'park',
				'#22c55e',
				'grass',
				'#86efac',
				'recreation_ground',
				'#4ade80',
				'playground',
				'#bef264',
				'garden',
				'#65a30d',
				'nature_reserve',
				'#166534',
				'green_space',
				'#34d399',
				'meadow',
				'#bbf7d0',
				'wood',
				'#14532d',
				'grassland',
				'#a7f3d0',
				'scrub',
				'#6ee7b7',
				'heath',
				'#5eead4',
				'wetland',
				'#2dd4bf',
				'forest',
				'#15803d',
				'cemetery',
				'#9ca3af',
				'flowerbed',
				'#fcd34d',
				'allotments',
				'#84cc16',
				'orchard',
				'#a3e635',
				'vineyard',
				'#d9f99d',
				'farmland',
				'#eab308',
				'pitch',
				'#a3e635',
				'sports_centre',
				'#d1fae5',
				'stadium',
				'#d1fae5',
				/* несмотря ниже — промзона может попасть в landuse-слой: нейтральный хаки. */
				'residential',
				'#e5e7eb',
				'commercial',
				'#e7e5e4',
				'industrial',
				'#d6d3d1',
				'retail',
				'#e7e5e4',
			]
		);
		$m = [ 'match', [ 'get', 'kind' ] ];
		for ( $i = 0; $i + 1 < count( $pairs ); $i += 2 ) {
			$m[] = (string) $pairs[ $i ];
			$m[] = (string) $pairs[ $i + 1 ];
		}
		$m[] = '#dcfce7';
		return $m;
	}

	/**
	 * regex для Overpass: way/relation ["landuse"~"^...$"]
	 */
	public static function overpass_landuse_regex(): string {
		$defaults = implode(
			'|',
			[
				'park',
				'grass',
				'recreation_ground',
				'forest',
				'cemetery',
				'meadow',
				'orchard',
				'vineyard',
				'farmland',
				'allotments',
				'flowerbed',
				'residential',
				'commercial',
				'industrial',
				'retail',
			]
		);
		$rex = (string) apply_filters( 'wsc_overpass_landuse_landcover_regex', '^(' . $defaults . ')$' );
		return self::sanitize_overpass_regex( $rex );
	}

	/** regex leisure на way (полигоны). */
	public static function overpass_leisure_polygon_regex(): string {
		$defaults = implode(
			'|',
			[
				'park',
				'playground',
				'pitch',
				'garden',
				'nature_reserve',
				'green_space',
				'sports_centre',
				'stadium',
			]
		);
		$rex = (string) apply_filters( 'wsc_overpass_leisure_polygon_regex', '^(' . $defaults . ')$' );
		return self::sanitize_overpass_regex( $rex );
	}

	/**
	 * @return list<string>
	 */
	public static function natural_polygon_tag_values(): array {
		return apply_filters(
			'wsc_landcover_natural_polygon_values',
			[ 'wood', 'grassland', 'scrub', 'heath', 'wetland' ]
		);
	}

	/** regex для way/relation ["natural"~"^...$"] без tree. */
	public static function overpass_natural_polygon_regex(): string {
		$body = implode( '|', array_map(
			static function ( string $x ): string {
				return preg_quote( $x, '/' );
			},
			self::natural_polygon_tag_values()
		) );
		$rex = (string) apply_filters( 'wsc_overpass_natural_landcover_regex', '^(' . $body . ')$' );
		return self::sanitize_overpass_regex( $rex );
	}

	private static function sanitize_overpass_regex( string $rex ): string {
		// Overpass использует POSIX ERE, а не PCRE: `(?:...)` (non-capturing group)
		// он не поддерживает и отвечает HTTP 400 «static error: Invalid regular expression».
		// Конвертируем в обычную capturing-группу, которая в Overpass валидна.
		$rex = str_replace( '(?:', '(', $rex );
		return str_replace( [ '"', "'", ';', "\n", "\r" ], '', $rex );
	}

	/**
	 * @param array<string,string> $tags
	 */
	public static function kind_from_landuse_tags( array $tags ): string {
		$leisure = isset( $tags['leisure'] ) ? trim( (string) $tags['leisure'] ) : '';
		if ( $leisure !== '' ) {
			return mb_substr( $leisure, 0, 32 );
		}
		$landuse = isset( $tags['landuse'] ) ? trim( (string) $tags['landuse'] ) : '';
		if ( $landuse !== '' ) {
			return mb_substr( $landuse, 0, 32 );
		}
		$natural = isset( $tags['natural'] ) ? trim( (string) $tags['natural'] ) : '';
		if ( $natural !== '' && $natural !== 'tree' ) {
			return mb_substr( $natural, 0, 32 );
		}
		return 'other';
	}

	/**
	 * Признак «импортировать как landuse-слой» при наличии landuse/leisure или natural-покров.
	 *
	 * @param array<string,string> $tags
	 */
	public static function is_landcover_polygon_entity( array $tags ): bool {
		if ( isset( $tags['building'] ) ) {
			return false;
		}
		if ( isset( $tags['natural'] ) && (string) $tags['natural'] === 'tree' ) {
			return false;
		}
		if ( isset( $tags['landuse'] ) ) {
			return true;
		}
		$liv = (string) ( $tags['leisure'] ?? '' );
		if ( in_array(
			$liv,
			[ 'park', 'playground', 'pitch', 'garden', 'nature_reserve', 'green_space', 'sports_centre', 'stadium' ],
			true
		) ) {
			return true;
		}
		$n = (string) ( $tags['natural'] ?? '' );
		return $n !== '' && in_array( $n, self::natural_polygon_tag_values(), true );
	}
}
