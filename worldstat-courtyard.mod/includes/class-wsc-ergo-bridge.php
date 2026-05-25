<?php
/**
 * Совместимость: алиас WSC_Ergo_Bridge → WSErgo_Courtyard_Bridge (логика в worldstat-ergonomics).
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WSErgo_Courtyard_Bridge', false ) && ! class_exists( 'WSC_Ergo_Bridge', false ) ) {
			class_alias( 'WSErgo_Courtyard_Bridge', 'WSC_Ergo_Bridge' );
		}
	},
	99
);
