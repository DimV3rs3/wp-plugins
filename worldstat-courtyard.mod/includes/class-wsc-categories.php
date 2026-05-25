<?php
/**
 * Categories: 8 категорий + классификатор тегов OSM.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Categories {

	const OPT_RULES = 'wsc_categories_map';

	const RESIDENTIAL   = 'residential';
	const COMMERCIAL    = 'commercial';
	const EDUCATION     = 'education';
	const HEALTHCARE    = 'healthcare';
	const SPORT_LEISURE = 'sport_leisure';
	const OFFICE        = 'office';
	const INDUSTRIAL    = 'industrial';
	const OTHER         = 'other';

	/* Инфраструктурные мини-категории (POI-узлы для эргономичности здания). */
	const INFRA_SAFETY  = 'infra_safety';
	const INFRA_COMFORT = 'infra_comfort';
	const INFRA_FUNC    = 'infra_func';

	public static function all(): array {
		return [
			self::RESIDENTIAL   => 'Жилые',
			self::COMMERCIAL    => 'Торговые',
			self::EDUCATION     => 'Образование',
			self::HEALTHCARE    => 'Медицина',
			self::SPORT_LEISURE => 'Спорт и досуг',
			self::OFFICE        => 'Офисы',
			self::INDUSTRIAL    => 'Индустриальные',
			self::OTHER         => 'Прочее',
			self::INFRA_SAFETY  => 'Инфра: безопасность',
			self::INFRA_COMFORT => 'Инфра: комфорт',
			self::INFRA_FUNC    => 'Инфра: функции',
		];
	}

	public static function colors(): array {
		return [
			self::RESIDENTIAL   => '#0ea5e9',
			self::COMMERCIAL    => '#f59e0b',
			self::EDUCATION     => '#8b5cf6',
			self::HEALTHCARE    => '#ef4444',
			self::SPORT_LEISURE => '#22c55e',
			self::OFFICE        => '#64748b',
			self::INDUSTRIAL    => '#6b7280',
			self::OTHER         => '#94a3b8',
			self::INFRA_SAFETY  => '#dc2626',
			self::INFRA_COMFORT => '#10b981',
			self::INFRA_FUNC    => '#3b82f6',
		];
	}

	/**
	 * Default tag → category rules.
	 */
	public static function default_rules(): array {
		return [
			'building' => [
				'residential' => self::RESIDENTIAL, 'apartments' => self::RESIDENTIAL, 'house' => self::RESIDENTIAL,
				'detached' => self::RESIDENTIAL, 'terrace' => self::RESIDENTIAL, 'dormitory' => self::RESIDENTIAL,
				'bungalow' => self::RESIDENTIAL, 'semidetached_house' => self::RESIDENTIAL,
				'commercial' => self::COMMERCIAL, 'retail' => self::COMMERCIAL, 'supermarket' => self::COMMERCIAL,
				'kiosk' => self::COMMERCIAL, 'mall' => self::COMMERCIAL,
				'school' => self::EDUCATION, 'kindergarten' => self::EDUCATION, 'university' => self::EDUCATION, 'college' => self::EDUCATION,
				'hospital' => self::HEALTHCARE, 'clinic' => self::HEALTHCARE,
				'sports_hall' => self::SPORT_LEISURE, 'sports_centre' => self::SPORT_LEISURE, 'stadium' => self::SPORT_LEISURE,
				'office' => self::OFFICE, 'government' => self::OFFICE,
				'industrial' => self::INDUSTRIAL, 'warehouse' => self::INDUSTRIAL, 'factory' => self::INDUSTRIAL,
				'manufacture' => self::INDUSTRIAL,
			],
			'amenity' => [
				'school' => self::EDUCATION, 'kindergarten' => self::EDUCATION, 'university' => self::EDUCATION, 'college' => self::EDUCATION,
				'library' => self::EDUCATION,
				'hospital' => self::HEALTHCARE, 'clinic' => self::HEALTHCARE, 'doctors' => self::HEALTHCARE,
				'pharmacy' => self::HEALTHCARE, 'dentist' => self::HEALTHCARE,
				'restaurant' => self::COMMERCIAL, 'cafe' => self::COMMERCIAL, 'fast_food' => self::COMMERCIAL,
				'bank' => self::COMMERCIAL, 'marketplace' => self::COMMERCIAL,
				'cinema' => self::SPORT_LEISURE, 'theatre' => self::SPORT_LEISURE,
				'community_centre' => self::SPORT_LEISURE,
				/* Инфра-узлы для эргономичности здания. */
				'fire_hydrant' => self::INFRA_SAFETY,
				'waste_basket' => self::INFRA_SAFETY,
				'recycling'    => self::INFRA_SAFETY,
				'waste_disposal' => self::INFRA_SAFETY,
				'parking'      => self::INFRA_SAFETY,
				'bench'           => self::INFRA_COMFORT,
				'drinking_water'  => self::INFRA_COMFORT,
				'fountain'        => self::INFRA_COMFORT,
				'bicycle_parking' => self::INFRA_FUNC,
				'charging_station' => self::INFRA_FUNC,
			],
			'highway' => [
				'street_lamp' => self::INFRA_SAFETY,
			],
			'emergency' => [
				'fire_hydrant' => self::INFRA_SAFETY,
			],
			'shop' => [
				'*' => self::COMMERCIAL,
			],
			'office' => [
				'*' => self::OFFICE,
			],
			'leisure' => [
				'sports_centre' => self::SPORT_LEISURE, 'fitness_centre' => self::SPORT_LEISURE,
				'pitch' => self::SPORT_LEISURE, 'stadium' => self::SPORT_LEISURE,
				'park' => self::SPORT_LEISURE, 'playground' => self::SPORT_LEISURE,
			],
			'industrial' => [ '*' => self::INDUSTRIAL ],
			'man_made'   => [ 'works' => self::INDUSTRIAL ],
		];
	}

	public static function get_rules(): array {
		$stored = get_option( self::OPT_RULES, null );
		if ( is_array( $stored ) && ! empty( $stored ) ) return $stored;
		return self::default_rules();
	}

	public static function set_rules( array $rules ): void {
		update_option( self::OPT_RULES, $rules );
	}

	/**
	 * Categorize an OSM feature by its tags.
	 *
	 * @param array<string,string> $tags
	 */
	public static function categorize( array $tags ): string {
		$rules = self::get_rules();
		foreach ( $rules as $tag_key => $values ) {
			if ( ! isset( $tags[ $tag_key ] ) ) continue;
			$val = $tags[ $tag_key ];
			if ( isset( $values[ $val ] ) ) return (string) $values[ $val ];
			if ( isset( $values['*'] ) )    return (string) $values['*'];
		}
		// Special: unmarked building → residential is wrong; default to other.
		return self::OTHER;
	}

	/**
	 * Determine entity_type: building / poi / landuse / tree.
	 */
	public static function entity_type( array $tags, string $geom_type ): string {
		if ( isset( $tags['building'] ) ) return 'building';
		if ( isset( $tags['natural'] ) && $tags['natural'] === 'tree' ) return 'tree';
		if ( class_exists( 'WSC_Landcover' ) && WSC_Landcover::is_landcover_polygon_entity( $tags ) ) {
			return 'landuse';
		}
		if ( ! class_exists( 'WSC_Landcover' )
			&& ( isset( $tags['landuse'] ) || in_array( $tags['leisure'] ?? '', [ 'park', 'playground', 'pitch', 'garden' ], true ) ) ) {
			return 'landuse';
		}
		if ( isset( $tags['amenity'] ) || isset( $tags['shop'] ) || isset( $tags['office'] ) || isset( $tags['healthcare'] ) ) {
			return 'poi';
		}
		return 'poi';
	}
}
