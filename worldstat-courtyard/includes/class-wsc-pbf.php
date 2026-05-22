<?php
/**
 * PBF wrapper: osmium-tool / osmconvert CLI.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_PBF {

	public static function is_available(): bool {
		return WSC_Buffer::osmium_available();
	}

	/**
	 * WSL-режим: если в настройках указан путь "wsl" (или "wsl /usr/.../osmium"), вызываем
	 * osmium через Windows Subsystem for Linux. Windows-пути транслируем в /mnt/<drive>/...,
	 * иначе shell внутри WSL их не разрешит.
	 */
	private static function is_wsl_mode(): bool {
		$bin = strtolower( trim( WSC_Settings::get_osmium_path() ) );
		return $bin === 'wsl' || strpos( $bin, 'wsl ' ) === 0;
	}

	private static function build_cmd_prefix(): string {
		$bin = trim( WSC_Settings::get_osmium_path() );
		if ( self::is_wsl_mode() ) {
			// "wsl"          → "wsl osmium"
			// "wsl osmium"   → "wsl osmium" (как есть)
			if ( strtolower( $bin ) === 'wsl' ) return 'wsl osmium';
			return $bin; // пользователь сам прописал полный вызов
		}
		return escapeshellcmd( $bin );
	}

	private static function translate_path( string $path ): string {
		if ( ! self::is_wsl_mode() ) return $path;
		if ( preg_match( '#^([A-Za-z]):[\\\\/](.*)$#', $path, $m ) ) {
			return '/mnt/' . strtolower( $m[1] ) . '/' . str_replace( '\\', '/', $m[2] );
		}
		return $path;
	}

	private static function shellarg( string $path ): string {
		// Внутри `wsl ...` аргументы парсит уже Linux-shell, поэтому одинарные кавычки
		// уместнее cmd-стиля; для родного Windows-вызова — escapeshellarg().
		if ( self::is_wsl_mode() ) {
			return "'" . str_replace( "'", "'\\''", self::translate_path( $path ) ) . "'";
		}
		return escapeshellarg( $path );
	}

	/**
	 * Convert a PBF file to Overpass-style JSON elements (subset: building/amenity/shop/etc).
	 *
	 * @return array OSM elements
	 */
	public static function extract_elements( string $pbf_path, ?array $bbox = null ): array {
		if ( ! file_exists( $pbf_path ) ) throw new RuntimeException( 'PBF file not found' );
		if ( ! self::is_available() )    throw new RuntimeException( 'osmium-tool not installed' );

		$bin     = self::build_cmd_prefix();
		$dir     = WSC_Settings::uploads_dir()['basedir'] . '/pbf';
		$tmp_pbf = $dir . '/clip-' . wp_generate_password( 8, false ) . '.osm.pbf';
		$tmp_json= $dir . '/out-' . wp_generate_password( 8, false ) . '.json';

		// Step 1: filter relevant tags (+ земной покров natural для полигонов, как в Overpass).
		$nat_exprs = [ 'nw/natural=tree' ];
		if ( class_exists( 'WSC_Landcover' ) ) {
			foreach ( WSC_Landcover::natural_polygon_tag_values() as $nv ) {
				$tok = strtolower( preg_replace( '/[^a-z_]/', '', (string) $nv ) );
				if ( '' !== $tok ) {
					$nat_exprs[] = 'nw/natural=' . $tok;
				}
			}
		}
		$nat_exprs = array_unique( $nat_exprs );
		$filter_args = implode(
			' ',
			array_merge(
				[ 'building', 'amenity', 'shop', 'office', 'healthcare', 'leisure', 'landuse' ],
				$nat_exprs
			)
		);
		$filter = sprintf( '%s tags-filter %s %s -o %s --overwrite 2>&1',
			$bin, self::shellarg( $pbf_path ), $filter_args, self::shellarg( $tmp_pbf ) );
		@shell_exec( $filter );

		// Step 2: bbox clip if provided.
		if ( $bbox && file_exists( $tmp_pbf ) ) {
			$clipped = $dir . '/clipped-' . wp_generate_password( 8, false ) . '.osm.pbf';
			$bb = sprintf( '%F,%F,%F,%F', $bbox['w'], $bbox['s'], $bbox['e'], $bbox['n'] );
			$clip = sprintf( '%s extract -b %s %s -o %s --overwrite 2>&1',
				$bin, escapeshellarg( $bb ), self::shellarg( $tmp_pbf ), self::shellarg( $clipped ) );
			@shell_exec( $clip );
			if ( file_exists( $clipped ) ) {
				@unlink( $tmp_pbf );
				$tmp_pbf = $clipped;
			}
		}

		// Step 3: export to OSM JSON.
		$export = sprintf( '%s export -f geojsonseq -a type,id,tags %s -o %s --overwrite 2>&1',
			$bin, self::shellarg( $tmp_pbf ), self::shellarg( $tmp_json ) );
		@shell_exec( $export );

		$elements = self::geojsonseq_to_osm_elements( $tmp_json );

		@unlink( $tmp_pbf );
		@unlink( $tmp_json );
		return $elements;
	}

	private static function geojsonseq_to_osm_elements( string $path ): array {
		if ( ! file_exists( $path ) ) return [];
		$out = [];
		$fh  = @fopen( $path, 'r' );
		if ( ! $fh ) return [];
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$line = trim( $line, "\x1e\r\n " ); // GeoJSON-seq RS prefix
			if ( $line === '' ) continue;
			$f = json_decode( $line, true );
			if ( ! is_array( $f ) || empty( $f['geometry'] ) ) continue;
			$props = (array) ( $f['properties'] ?? [] );
			$id    = (int) ( $props['@id'] ?? $props['id'] ?? 0 );
			$type  = (string) ( $props['@type'] ?? $props['type'] ?? 'way' );
			$tags  = $props;
			unset( $tags['@id'], $tags['id'], $tags['@type'], $tags['type'] );

			$g     = $f['geometry'];
			if ( $g['type'] === 'Point' ) {
				$out[] = [
					'type' => 'node', 'id' => $id,
					'lon'  => (float) $g['coordinates'][0],
					'lat'  => (float) $g['coordinates'][1],
					'tags' => $tags,
				];
			} elseif ( $g['type'] === 'Polygon' || $g['type'] === 'LineString' ) {
				$ring = $g['type'] === 'Polygon' ? ( $g['coordinates'][0] ?? [] ) : $g['coordinates'];
				$geom = [];
				foreach ( $ring as $pt ) $geom[] = [ 'lon' => (float) $pt[0], 'lat' => (float) $pt[1] ];
				$out[] = [ 'type' => 'way', 'id' => $id, 'geometry' => $geom, 'tags' => $tags ];
			} elseif ( $g['type'] === 'MultiPolygon' ) {
				$members = [];
				foreach ( $g['coordinates'] as $poly ) {
					$ring = $poly[0] ?? [];
					$geom = [];
					foreach ( $ring as $pt ) $geom[] = [ 'lon' => (float) $pt[0], 'lat' => (float) $pt[1] ];
					$members[] = [ 'type' => 'way', 'role' => 'outer', 'geometry' => $geom ];
				}
				$out[] = [ 'type' => 'relation', 'id' => $id, 'members' => $members, 'tags' => $tags ];
			}
		}
		fclose( $fh );
		return $out;
	}
}
