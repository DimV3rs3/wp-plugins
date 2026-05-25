<?php
/**
 * MBTiles: чтение SQLite mbtiles-файла из uploads/wsc/tiles, отдача тайлов.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_MBTiles {

	public static function get_default_file(): ?string {
		$src = WSC_Settings::get_tiles_source();
		$file = trim( (string) ( $src['mbtiles_file'] ?? '' ) );
		if ( $file === '' ) return null;
		$path = WSC_Settings::uploads_dir()['basedir'] . '/tiles/' . basename( $file );
		return file_exists( $path ) ? $path : null;
	}

	public static function list_files(): array {
		$dir = WSC_Settings::uploads_dir()['basedir'] . '/tiles';
		if ( ! is_dir( $dir ) ) return [];
		$out = [];
		foreach ( (array) glob( $dir . '/*.mbtiles' ) as $f ) $out[] = basename( $f );
		return $out;
	}

	/**
	 * Read a tile from MBTiles file. Returns binary blob (vector .pbf or raster .png/.jpg).
	 */
	public static function get_tile( string $file_path, int $z, int $x, int $y ): ?string {
		if ( ! class_exists( 'PDO' ) || ! file_exists( $file_path ) ) return null;
		try {
			$pdo = new PDO( 'sqlite:' . $file_path );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			// MBTiles uses TMS scheme: y is flipped.
			$tms_y = ( ( 1 << $z ) - 1 ) - $y;
			$stmt = $pdo->prepare( 'SELECT tile_data FROM tiles WHERE zoom_level=:z AND tile_column=:x AND tile_row=:y' );
			$stmt->execute( [ ':z' => $z, ':x' => $x, ':y' => $tms_y ] );
			$blob = $stmt->fetchColumn();
			return $blob !== false ? (string) $blob : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	public static function get_metadata( string $file_path ): array {
		if ( ! class_exists( 'PDO' ) || ! file_exists( $file_path ) ) return [];
		try {
			$pdo = new PDO( 'sqlite:' . $file_path );
			$rows = $pdo->query( 'SELECT name, value FROM metadata' )->fetchAll( PDO::FETCH_ASSOC );
			$out = [];
			foreach ( (array) $rows as $r ) $out[ $r['name'] ] = $r['value'];
			return $out;
		} catch ( Throwable $e ) {
			return [];
		}
	}
}
