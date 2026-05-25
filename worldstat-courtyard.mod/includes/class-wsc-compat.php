<?php
/**
 * Compat: aliases для существующих в worldstat-ergonomics проверок class_exists().
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WSOSM_Writer' ) ) {
	class_alias( 'WSC_Writer', 'WSOSM_Writer' );
}
if ( ! class_exists( 'WSOSM_Jobs_Import' ) ) {
	class_alias( 'WSC_Jobs_Import', 'WSOSM_Jobs_Import' );
}
