<?php
/**
 * Plugin Name: WCPOS WPML Integration
 * Description: WPML language filtering for WCPOS, including fast-sync route coverage and per-store language support in WCPOS Pro.
 * Version: 0.1.0
 * Author: kilbot
 * Text Domain: wcpos-wpml
 */

namespace WCPOS\WPML;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

require_once __DIR__ . '/includes/class-plugin.php';

Plugin::instance();
