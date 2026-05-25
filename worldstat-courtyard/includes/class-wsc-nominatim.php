<?php
/**
 * Nominatim: получение административной границы города.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Nominatim {

	const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
	const FALLBACK_RADIUS_KM = 10.0;

	/**
	 * Try to fetch admin polygon for the city; fallback to circle around lat/lng.
	 *
	 * @return array{ geometry:array, source:string, bbox:array{s:float,w:float,n:float,e:float} }
	 */
	public static function get_city_polygon( int $city_id ): array {
		$cached = get_transient( 'wsc_nomi_' . $city_id );
		if ( is_array( $cached ) ) return $cached;

		$lat = (float) get_post_meta( $city_id, 'wscity_lat', true );
		$lng = (float) get_post_meta( $city_id, 'wscity_lng', true );
		$name= get_the_title( $city_id );
		$iso = strtoupper( (string) get_post_meta( $city_id, 'wscity_country_iso2', true ) );

		$result = null;
		if ( $name ) {
			$result = self::nominatim_lookup( $name, $iso );
		}

		if ( ! $result && $lat && $lng ) {
			$geo = self::circle_polygon( $lng, $lat, self::FALLBACK_RADIUS_KM );
			$result = [
				'geometry' => $geo,
				'source'   => 'circle',
				'bbox'     => WSC_Parser::bbox_of( $geo ),
			];
		}

		if ( $result ) set_transient( 'wsc_nomi_' . $city_id, $result, 12 * HOUR_IN_SECONDS );
		return $result ?: [ 'geometry' => null, 'source' => 'none', 'bbox' => [ 's' => 0, 'w' => 0, 'n' => 0, 'e' => 0 ] ];
	}

	private static function nominatim_lookup( string $name, string $iso ): ?array {
		$args = [
			'q' => $name, 'format' => 'json', 'limit' => 1, 'polygon_geojson' => 1,
			'countrycodes' => strtolower( $iso ),
		];
		$url = self::ENDPOINT . '?' . http_build_query( $args );
		$resp = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [ 'User-Agent' => 'WorldStat-Courtyard/' . WSC_VERSION . ' (' . site_url() . ')' ],
		] );
		if ( is_wp_error( $resp ) ) return null;
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body[0]['geojson'] ) ) return null;
		$geo = $body[0]['geojson'];
		if ( ! in_array( $geo['type'] ?? '', [ 'Polygon', 'MultiPolygon' ], true ) ) return null;
		return [
			'geometry' => $geo,
			'source'   => 'nominatim',
			'bbox'     => WSC_Parser::bbox_of( $geo ),
		];
	}

	public static function circle_polygon( float $lng, float $lat, float $radius_km, int $segments = 64 ): array {
		$ring = [];
		$proj = new WSC_LocalProjection( $lng, $lat );
		$r    = $radius_km * 1000;
		for ( $i = 0; $i <= $segments; $i++ ) {
			$ang = ( 2 * M_PI * $i ) / $segments;
			$pt  = $proj->inverse( $r * cos( $ang ), $r * sin( $ang ) );
			$ring[] = $pt;
		}
		return [ 'type' => 'Polygon', 'coordinates' => [ $ring ] ];
	}
}
