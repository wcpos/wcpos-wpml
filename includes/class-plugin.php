<?php
/**
 * Main plugin class.
 *
 * @package WCPOS\WPML
 */

namespace WCPOS\WPML;

use WCPOS\WooCommercePOS\Services\Settings as WCPOS_Settings;
use WCPOS\WooCommercePOSPro\Services\Stores as WCPOS_Pro_Stores;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Plugin {
	public const STORE_LANGUAGE_META_KEY = '_wcpos_wpml_language';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_rest_product_object_query', array( $this, 'filter_product_query' ), 20, 2 );
		add_filter( 'woocommerce_rest_product_variation_object_query', array( $this, 'filter_product_variation_query' ), 20, 2 );
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_intercept_fast_sync' ), 20, 3 );

		// WCPOS Pro: allow saving language via stores API.
		add_filter( 'woocommerce_pos_store_meta_fields', array( $this, 'extend_store_meta_fields' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'inject_store_language_into_responses' ), 20, 3 );

		// WCPOS Pro: store-edit UI extension.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_store_edit_assets' ), 20 );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wcpos-wpml', false, dirname( plugin_basename( dirname( __DIR__ ) . '/wcpos-wpml.php' ) ) . '/languages' );
	}

	/**
	 * Apply language filtering to products query.
	 *
	 * @param array           $args
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 */
	public function filter_product_query( array $args, WP_REST_Request $request ): array {
		if ( ! $this->is_wpml_supported() ) {
			return $args;
		}

		if ( ! $this->is_wcpos_route( $request ) ) {
			return $args;
		}

		$language = $this->resolve_request_language( $request );
		return $this->apply_language_constraints( $args, $language );
	}

	/**
	 * Apply language filtering to product variation query.
	 *
	 * @param array           $args
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 */
	public function filter_product_variation_query( array $args, WP_REST_Request $request ): array {
		if ( ! $this->is_wpml_supported() ) {
			return $args;
		}

		if ( ! $this->is_wcpos_route( $request ) ) {
			return $args;
		}

		$language = $this->resolve_request_language( $request );
		return $this->apply_language_constraints( $args, $language );
	}

	/**
	 * Intercept WCPOS fast-sync requests and return language-filtered data.
	 *
	 * @param mixed           $result
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 *
	 * @return mixed
	 */
	public function maybe_intercept_fast_sync( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result ) {
			return $result;
		}

		if ( ! $this->is_wpml_supported() ) {
			return $result;
		}

		$context = $this->get_fast_sync_context( $request );
		if ( ! $context ) {
			return $result;
		}

		$language = $this->resolve_request_language( $request );
		if ( '' === $language ) {
			return $result;
		}

		$payload = $this->query_fast_sync_payload( $context, $request, $language );

		$response = rest_ensure_response( $payload );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'X-WP-Total', (string) count( $payload ) );
			$response->header( 'X-WP-TotalPages', '1' );
		}

		return $response;
	}

	/**
	 * Extend WCPOS Pro store meta field mappings.
	 *
	 * @param array $fields
	 *
	 * @return array
	 */
	public function extend_store_meta_fields( array $fields ): array {
		if ( ! $this->is_wpml_supported() ) {
			return $fields;
		}

		$fields['language'] = self::STORE_LANGUAGE_META_KEY;
		return $fields;
	}

	/**
	 * Inject language into WCPOS stores API responses.
	 *
	 * @param mixed           $result
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 *
	 * @return mixed
	 */
	public function inject_store_language_into_responses( $result, $server, WP_REST_Request $request ) {
		if ( ! $this->is_wpml_supported() ) {
			return $result;
		}

		if ( ! ( $result instanceof WP_REST_Response ) ) {
			return $result;
		}

		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/wcpos/v1/stores' ) ) {
			return $result;
		}

		$data = $result->get_data();

		if ( is_array( $data ) && $this->is_list_array( $data ) ) {
			foreach ( $data as $index => $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$data[ $index ]['language'] = $this->resolve_store_language( (int) $item['id'] );
				}
			}
		} elseif ( is_array( $data ) && isset( $data['id'] ) ) {
			$data['language'] = $this->resolve_store_language( (int) $data['id'] );
		}

		$result->set_data( $data );
		return $result;
	}

	/**
	 * Enqueue WCPOS Pro store edit extension script.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_store_edit_assets( string $hook_suffix ): void {
		if ( ! $this->is_wpml_supported() ) {
			return;
		}

		if ( 'admin_page_wcpos-store-edit' !== $hook_suffix ) {
			return;
		}

		if ( ! class_exists( WCPOS_Pro_Stores::class ) ) {
			return;
		}

		$pro_store_edit_handle = 'woocommerce-pos-pro-store-edit';
		if ( ! wp_script_is( $pro_store_edit_handle, 'enqueued' ) ) {
			return;
		}

		$languages = $this->get_wpml_languages_for_js();
		if ( empty( $languages ) ) {
			return;
		}

		$default_language = $this->get_default_language();
		$default_label    = '' !== $default_language ? $default_language : __( 'site default', 'wcpos-wpml' );

		wp_enqueue_script(
			'wcpos-wpml-store-edit',
			plugins_url( 'assets/js/store-language-section.js', dirname( __DIR__ ) . '/wcpos-wpml.php' ),
			array( $pro_store_edit_handle, 'wp-element' ),
			VERSION,
			true
		);

		wp_add_inline_script(
			'wcpos-wpml-store-edit',
			'window.wcposWPMLStoreEdit = ' . wp_json_encode(
				array(
					'defaultLanguage' => $default_language,
					'languages'       => $languages,
					'strings'         => array(
						'sectionLabel'  => __( 'Language', 'wcpos-wpml' ),
						'title'         => __( 'Store language', 'wcpos-wpml' ),
						'description'   => __( 'Choose which WPML language this store should use in WCPOS.', 'wcpos-wpml' ),
						'help'          => __( 'Products in this store are filtered to the selected language. Leave this as default to use your site default language.', 'wcpos-wpml' ),
						'defaultOption' => sprintf(
							/* translators: %s: language code. */
							__( 'Default language (%s)', 'wcpos-wpml' ),
							$default_label
						),
						'noLanguages'   => __( 'No WPML languages found.', 'wcpos-wpml' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Resolve effective language for request.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return string
	 */
	private function resolve_request_language( WP_REST_Request $request ): string {
		$default_language = $this->get_default_language();
		$store_id         = (int) $request->get_param( 'store_id' );

		if ( $store_id > 0 && class_exists( WCPOS_Pro_Stores::class ) ) {
			$authorized = WCPOS_Pro_Stores::instance()->current_user_is_authorized( $store_id );
			if ( $authorized ) {
				$store_language = $this->resolve_store_language( $store_id );
				if ( '' !== $store_language ) {
					return (string) apply_filters( 'wcpos_wpml_resolved_language', $store_language, $request );
				}
			}
		}

		return (string) apply_filters( 'wcpos_wpml_resolved_language', $default_language, $request );
	}

	/**
	 * Resolve store language with fallback to default language.
	 *
	 * @param int $store_id
	 *
	 * @return string
	 */
	private function resolve_store_language( int $store_id ): string {
		$store_language = (string) get_post_meta( $store_id, self::STORE_LANGUAGE_META_KEY, true );
		if ( '' !== $store_language ) {
			return $store_language;
		}

		return $this->get_default_language();
	}

	/**
	 * Resolve WPML default language.
	 *
	 * @return string
	 */
	private function get_default_language(): string {
		$default_language = apply_filters( 'wpml_default_language', null );
		if ( ! is_string( $default_language ) ) {
			$default_language = '';
		}

		return (string) apply_filters( 'wcpos_wpml_default_language', $default_language );
	}

	/**
	 * Check whether WPML is active and supported.
	 *
	 * @return bool
	 */
	private function is_wpml_supported(): bool {
		$default_language = $this->get_default_language();
		$languages        = $this->get_wpml_active_languages();
		$supported        = '' !== $default_language || ! empty( $languages );

		if ( $supported ) {
			$minimum_wpml_version = $this->get_minimum_wpml_version();
			$detected_wpml        = $this->get_detected_wpml_version();
			if ( '' !== $minimum_wpml_version && '' !== $detected_wpml && version_compare( $detected_wpml, $minimum_wpml_version, '<' ) ) {
				$supported = false;
			}

			$minimum_wcml_version = $this->get_minimum_wcml_version();
			$detected_wcml        = $this->get_detected_wcml_version();
			if ( '' !== $minimum_wcml_version && '' !== $detected_wcml && version_compare( $detected_wcml, $minimum_wcml_version, '<' ) ) {
				$supported = false;
			}
		}

		return (bool) apply_filters( 'wcpos_wpml_is_supported', $supported );
	}

	/**
	 * Optional minimum required WPML version.
	 *
	 * @return string
	 */
	private function get_minimum_wpml_version(): string {
		return (string) apply_filters( 'wcpos_wpml_minimum_wpml_version', '' );
	}

	/**
	 * Minimum WCML version for REST lang support.
	 *
	 * @return string
	 */
	private function get_minimum_wcml_version(): string {
		return (string) apply_filters( 'wcpos_wpml_minimum_wcml_version', '4.11.0' );
	}

	/**
	 * Detect WPML version.
	 *
	 * @return string
	 */
	private function get_detected_wpml_version(): string {
		$version = '';
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$version = (string) ICL_SITEPRESS_VERSION;
		} elseif ( defined( 'WPML_VERSION' ) ) {
			$version = (string) WPML_VERSION;
		}

		return (string) apply_filters( 'wcpos_wpml_detected_wpml_version', $version );
	}

	/**
	 * Detect WCML version.
	 *
	 * @return string
	 */
	private function get_detected_wcml_version(): string {
		$version = '';
		if ( defined( 'WCML_VERSION' ) ) {
			$version = (string) WCML_VERSION;
		}

		return (string) apply_filters( 'wcpos_wpml_detected_wcml_version', $version );
	}

	/**
	 * Apply language constraints to WP_Query args.
	 *
	 * @param array  $args
	 * @param string $language
	 *
	 * @return array
	 */
	private function apply_language_constraints( array $args, string $language ): array {
		if ( '' === $language ) {
			return $args;
		}

		$args['lang'] = $language;
		return $args;
	}

	/**
	 * Build context for fast sync routes.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return array|null
	 */
	private function get_fast_sync_context( WP_REST_Request $request ): ?array {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/wcpos/v1/' ) ) {
			return null;
		}

		if ( -1 !== (int) $request->get_param( 'posts_per_page' ) ) {
			return null;
		}

		$fields = $request->get_param( 'fields' );
		if ( is_string( $fields ) ) {
			$fields = array_map( 'trim', explode( ',', $fields ) );
		}
		if ( ! is_array( $fields ) ) {
			return null;
		}

		$fields       = array_values( $fields );
		$fields_index = array_flip( $fields );
		$id_only      = isset( $fields_index['id'] ) && 1 === count( $fields );
		$id_plus_date = isset( $fields_index['id'] ) && isset( $fields_index['date_modified_gmt'] ) && 2 === count( $fields );

		if ( ! $id_only && ! $id_plus_date ) {
			return null;
		}

		$context = array(
			'post_type'            => null,
			'parent_id'            => null,
			'include_modified_gmt' => $id_plus_date,
		);

		if ( '/wcpos/v1/products' === $route ) {
			$context['post_type'] = 'product';
			return $context;
		}

		if ( '/wcpos/v1/products/variations' === $route ) {
			$context['post_type'] = 'product_variation';
			return $context;
		}

		if ( preg_match( '#^/wcpos/v1/products/(\d+)/variations$#', $route, $matches ) ) {
			$context['post_type'] = 'product_variation';
			$context['parent_id'] = (int) $matches[1];
			return $context;
		}

		return null;
	}

	/**
	 * Query fast-sync payload.
	 *
	 * @param array           $context
	 * @param WP_REST_Request $request
	 * @param string          $language
	 *
	 * @return array
	 */
	private function query_fast_sync_payload( array $context, WP_REST_Request $request, string $language ): array {
		$args = array(
			'post_type'              => $context['post_type'],
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);

		if ( ! empty( $context['parent_id'] ) ) {
			$args['post_parent'] = (int) $context['parent_id'];
		}

		$modified_after = $request->get_param( 'modified_after' );
		if ( is_string( $modified_after ) && '' !== $modified_after ) {
			$timestamp = strtotime( $modified_after );
			if ( false !== $timestamp ) {
				$args['date_query'] = array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
					),
				);
			}
		}

		$include = $request->get_param( 'wcpos_include' );
		if ( is_array( $include ) && ! empty( $include ) ) {
			$args['post__in'] = array_map( 'intval', $include );
		}

		$exclude = $request->get_param( 'wcpos_exclude' );
		if ( is_array( $exclude ) && ! empty( $exclude ) ) {
			$args['post__not_in'] = array_map( 'intval', $exclude );
		}

		if ( $this->pos_only_products_enabled() ) {
			$online_only_ids = $this->get_online_only_ids( $context['post_type'] );
			if ( ! empty( $online_only_ids ) ) {
				if ( empty( $args['post__not_in'] ) ) {
					$args['post__not_in'] = $online_only_ids;
				} else {
					$args['post__not_in'] = array_values( array_unique( array_merge( $args['post__not_in'], $online_only_ids ) ) );
				}
			}
		}

		$args  = $this->apply_language_constraints( $args, $language );
		$query = new WP_Query( $args );
		$ids   = array_map( 'intval', $query->posts );

		if ( empty( $ids ) ) {
			return array();
		}

		if ( ! $context['include_modified_gmt'] ) {
			return array_map(
				static function ( int $id ): array {
					return array( 'id' => $id );
				},
				$ids
			);
		}

		$dates = $this->get_modified_dates_by_id( $ids );

		$payload = array();
		foreach ( $ids as $id ) {
			$payload[] = array(
				'id'                => $id,
				'date_modified_gmt' => $this->format_wcpos_modified_gmt( $dates[ $id ] ?? '' ),
			);
		}

		return $payload;
	}

	/**
	 * Fetch modified dates for post IDs.
	 *
	 * @param int[] $ids
	 *
	 * @return array<int,string>
	 */
	private function get_modified_dates_by_id( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$dates = array();
		$posts = get_posts(
			array(
				'post_type'              => 'any',
				'post__in'               => $ids,
				'posts_per_page'         => count( $ids ),
				'orderby'                => 'post__in',
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post ) {
			$dates[ (int) $post->ID ] = (string) $post->post_modified_gmt;
		}

		return $dates;
	}

	/**
	 * Format gmt datetime as WCPOS expects.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	private function format_wcpos_modified_gmt( string $value ): string {
		if ( preg_match( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value ) ) {
			return (string) preg_replace( '/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/', '$1T$2', $value );
		}

		if ( '' !== $value ) {
			return (string) wc_rest_prepare_date_response( $value );
		}

		return '';
	}

	/**
	 * Get WCPOS online-only IDs for products or variations.
	 *
	 * @param string $post_type
	 *
	 * @return int[]
	 */
	private function get_online_only_ids( string $post_type ): array {
		if ( ! class_exists( WCPOS_Settings::class ) ) {
			return array();
		}

		$settings = WCPOS_Settings::instance();
		$data     = array();

		if ( 'product' === $post_type ) {
			$data = $settings->get_online_only_product_visibility_settings();
		}

		if ( 'product_variation' === $post_type ) {
			$data = $settings->get_online_only_variations_visibility_settings();
		}

		$ids = isset( $data['ids'] ) && is_array( $data['ids'] ) ? $data['ids'] : array();
		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Check if POS-only mode is enabled in WCPOS settings.
	 *
	 * @return bool
	 */
	private function pos_only_products_enabled(): bool {
		if ( ! function_exists( 'woocommerce_pos_get_settings' ) ) {
			return false;
		}

		return (bool) woocommerce_pos_get_settings( 'general', 'pos_only_products' );
	}

	/**
	 * True when current request is a WCPOS endpoint.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	private function is_wcpos_route( WP_REST_Request $request ): bool {
		return 0 === strpos( $request->get_route(), '/wcpos/v1/' );
	}

	/**
	 * Get WPML active languages from filter API.
	 *
	 * @return array
	 */
	private function get_wpml_active_languages(): array {
		$languages = apply_filters( 'wpml_active_languages', null, 'skip_missing=0&orderby=code&order=asc' );
		return is_array( $languages ) ? $languages : array();
	}

	/**
	 * Build language options for store edit UI.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function get_wpml_languages_for_js(): array {
		$languages = $this->get_wpml_active_languages();
		if ( empty( $languages ) ) {
			return array();
		}

		$options = array();
		foreach ( $languages as $language_code => $language_data ) {
			if ( ! is_string( $language_code ) || '' === $language_code ) {
				continue;
			}

			$label = $language_code;
			if ( is_array( $language_data ) ) {
				if ( isset( $language_data['native_name'] ) && is_string( $language_data['native_name'] ) && '' !== $language_data['native_name'] ) {
					$label = $language_data['native_name'];
				} elseif ( isset( $language_data['translated_name'] ) && is_string( $language_data['translated_name'] ) && '' !== $language_data['translated_name'] ) {
					$label = $language_data['translated_name'];
				}
			}

			$options[] = array(
				'value' => $language_code,
				'label' => $label,
			);
		}

		return $options;
	}

	/**
	 * Polyfill for PHP 7.4.
	 *
	 * @param mixed $items
	 *
	 * @return bool
	 */
	private function is_list_array( $items ): bool {
		if ( ! is_array( $items ) ) {
			return false;
		}

		$expected_key = 0;
		foreach ( $items as $key => $unused ) {
			if ( $key !== $expected_key ) {
				return false;
			}
			++$expected_key;
		}

		return true;
	}
}
