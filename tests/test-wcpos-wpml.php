<?php

class Test_WCPOS_WPML extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );

		if ( ! post_type_exists( 'product' ) ) {
			register_post_type(
				'product',
				array(
					'public' => true,
				)
			);
		}

		if ( ! post_type_exists( 'product_variation' ) ) {
			register_post_type(
				'product_variation',
				array(
					'public' => false,
				)
			);
		}

		if ( ! post_type_exists( 'wcpos_store_test' ) ) {
			register_post_type(
				'wcpos_store_test',
				array(
					'public' => false,
				)
			);
		}

		$this->register_wpml_mocks();
	}

	public function tearDown(): void {
		remove_all_filters( 'wpml_default_language' );
		remove_all_filters( 'wpml_current_language' );
		remove_all_filters( 'wpml_active_languages' );

		remove_all_filters( 'wcpos_wpml_default_language' );
		remove_all_filters( 'wcpos_wpml_is_supported' );
		remove_all_filters( 'wcpos_wpml_minimum_wpml_version' );
		remove_all_filters( 'wcpos_wpml_minimum_wcml_version' );
		remove_all_filters( 'wcpos_wpml_detected_wpml_version' );
		remove_all_filters( 'wcpos_wpml_detected_wcml_version' );

		remove_all_filters( 'posts_where' );
		remove_all_filters( 'posts_pre_query' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_product_query_adds_lang_for_wcpos_route(): void {
		$args    = array();
		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );

		$filtered = apply_filters( 'woocommerce_rest_product_object_query', $args, $request );
		$this->assertArrayHasKey( 'lang', $filtered );
		$this->assertSame( 'en', $filtered['lang'] );
		$this->assertArrayNotHasKey( 'meta_query', $filtered );
	}

	public function test_product_query_does_not_add_lang_for_non_wcpos_route(): void {
		$args    = array();
		$request = new WP_REST_Request( 'GET', '/wc/v3/products' );

		$filtered = apply_filters( 'woocommerce_rest_product_object_query', $args, $request );
		$this->assertArrayNotHasKey( 'lang', $filtered );
	}

	public function test_variation_query_adds_lang_for_wcpos_route(): void {
		$args    = array();
		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products/variations' );

		$filtered = apply_filters( 'woocommerce_rest_product_variation_object_query', $args, $request );
		$this->assertArrayHasKey( 'lang', $filtered );
		$this->assertSame( 'en', $filtered['lang'] );
	}

	public function test_fast_sync_products_returns_default_language_only(): void {
		$english_id = $this->create_product( 'English Product', 'en' );
		$french_id  = $this->create_product( 'French Product', 'fr' );

		$this->add_lang_mock_to_posts_pre_query();

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertContains( $english_id, $ids );
		$this->assertNotContains( $french_id, $ids );
	}

	public function test_fast_sync_variations_route_returns_language_only(): void {
		$parent_id = $this->create_product( 'Parent Product', 'en' );
		$en_id     = $this->create_variation( $parent_id, 'EN Variation', 'en' );
		$fr_id     = $this->create_variation( $parent_id, 'FR Variation', 'fr' );

		$this->add_lang_mock_to_posts_pre_query();

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertContains( $en_id, $ids );
		$this->assertNotContains( $fr_id, $ids );
	}

	public function test_fast_sync_child_variations_route_respects_parent_and_language(): void {
		$parent_a_id = $this->create_product( 'Parent A', 'en' );
		$parent_b_id = $this->create_product( 'Parent B', 'en' );

		$target_id    = $this->create_variation( $parent_a_id, 'Parent A EN', 'en' );
		$other_lang   = $this->create_variation( $parent_a_id, 'Parent A FR', 'fr' );
		$other_parent = $this->create_variation( $parent_b_id, 'Parent B EN', 'en' );

		$this->add_lang_mock_to_posts_pre_query();

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products/' . $parent_a_id . '/variations' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id' ) );

		$response = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertContains( $target_id, $ids );
		$this->assertNotContains( $other_lang, $ids );
		$this->assertNotContains( $other_parent, $ids );
	}

	public function test_fast_sync_not_intercepted_for_non_fast_sync_fields(): void {
		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id', 'name' ) );

		$response = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request );
		$this->assertNull( $response );
	}

	public function test_fast_sync_with_modified_date_field_returns_date(): void {
		$english_id = $this->create_product( 'English Product Date', 'en' );

		$this->add_lang_mock_to_posts_pre_query();

		$request = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$request->set_param( 'posts_per_page', -1 );
		$request->set_param( 'fields', array( 'id', 'date_modified_gmt' ) );

		$response = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$this->assertNotEmpty( $data );
		$this->assertSame( $english_id, (int) $data[0]['id'] );
		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $data[0]['date_modified_gmt'] );
	}

	public function test_store_meta_fields_include_language(): void {
		$fields = apply_filters( 'woocommerce_pos_store_meta_fields', array() );
		$this->assertArrayHasKey( 'language', $fields );
		$this->assertSame( '_wcpos_wpml_language', $fields['language'] );
	}

	public function test_store_response_includes_language_meta_for_single_item(): void {
		$store_id = wp_insert_post(
			array(
				'post_type'   => 'wcpos_store_test',
				'post_status' => 'publish',
				'post_title'  => 'Test Store',
			)
		);
		$this->assertGreaterThan( 0, $store_id );
		update_post_meta( $store_id, '_wcpos_wpml_language', 'fr' );

		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/stores/' . $store_id );
		$response = new WP_REST_Response(
			array(
				'id' => $store_id,
			)
		);

		$result = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$data   = $result->get_data();

		$this->assertSame( 'fr', $data['language'] );
	}

	public function test_store_response_collection_includes_language_and_default_fallback(): void {
		$fr_store_id      = wp_insert_post(
			array(
				'post_type'   => 'wcpos_store_test',
				'post_status' => 'publish',
				'post_title'  => 'French Store',
			)
		);
		$default_store_id = wp_insert_post(
			array(
				'post_type'   => 'wcpos_store_test',
				'post_status' => 'publish',
				'post_title'  => 'Default Store',
			)
		);

		update_post_meta( $fr_store_id, '_wcpos_wpml_language', 'fr' );

		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/stores' );
		$response = new WP_REST_Response(
			array(
				array( 'id' => $fr_store_id ),
				array( 'id' => $default_store_id ),
			)
		);

		$result = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$data   = $result->get_data();

		$this->assertSame( 'fr', $data[0]['language'] );
		$this->assertSame( 'en', $data[1]['language'] );
	}

	public function test_wpml_guard_disables_query_and_store_fields(): void {
		add_filter( 'wcpos_wpml_is_supported', '__return_false' );

		$args     = array();
		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$filtered = apply_filters( 'woocommerce_rest_product_object_query', $args, $request );

		$this->assertArrayNotHasKey( 'lang', $filtered );

		$fields = apply_filters( 'woocommerce_pos_store_meta_fields', array() );
		$this->assertArrayNotHasKey( 'language', $fields );
	}

	public function test_wcml_minimum_version_gate_can_disable_integration(): void {
		add_filter(
			'wcpos_wpml_minimum_wcml_version',
			static function () {
				return '4.11.0';
			}
		);

		add_filter(
			'wcpos_wpml_detected_wcml_version',
			static function () {
				return '4.10.9';
			}
		);

		$args     = array();
		$request  = new WP_REST_Request( 'GET', '/wcpos/v1/products' );
		$filtered = apply_filters( 'woocommerce_rest_product_object_query', $args, $request );

		$this->assertArrayNotHasKey( 'lang', $filtered );
	}

	private function create_product( string $title, string $language = '' ): int {
		$product_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		$this->assertGreaterThan( 0, $product_id );

		if ( '' !== $language ) {
			update_post_meta( $product_id, '_test_lang', $language );
		}

		return (int) $product_id;
	}

	private function create_variation( int $parent_id, string $title, string $language = '' ): int {
		$variation_id = wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'post_title'  => $title,
			)
		);

		$this->assertGreaterThan( 0, $variation_id );

		if ( '' !== $language ) {
			update_post_meta( $variation_id, '_test_lang', $language );
		}

		return (int) $variation_id;
	}

	private function register_wpml_mocks(): void {
		add_filter(
			'wpml_default_language',
			static function () {
				return 'en';
			}
		);

		add_filter(
			'wpml_current_language',
			static function () {
				return 'en';
			}
		);

		add_filter(
			'wpml_active_languages',
			static function () {
				return array(
					'en' => array(
						'code'        => 'en',
						'native_name' => 'English',
					),
					'fr' => array(
						'code'        => 'fr',
						'native_name' => 'Français',
					),
				);
			},
			10,
			2
		);
	}

	private function add_lang_mock_to_posts_pre_query(): void {
		add_filter(
			'posts_pre_query',
			static function ( $posts, $query ) {
				static $inside_callback = false;

				if ( $inside_callback ) {
					return $posts;
				}

				if ( ! in_array( $query->get( 'post_type' ), array( 'product', 'product_variation' ), true ) ) {
					return $posts;
				}

				$inside_callback = true;

				$lang      = (string) $query->get( 'lang' );
				$post_ids  = $query->get( 'post__in' );
				$post_ids  = is_array( $post_ids ) && ! empty( $post_ids ) ? array_map( 'intval', $post_ids ) : null;
				$post_type = $query->get( 'post_type' );

				$args = array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				);

				if ( ! empty( $post_ids ) ) {
					$args['post__in'] = $post_ids;
				}

				$post_parent = (int) $query->get( 'post_parent' );
				if ( $post_parent > 0 ) {
					$args['post_parent'] = $post_parent;
				}

				$raw_ids = get_posts( $args );
				$ids     = array();

				foreach ( $raw_ids as $id ) {
					$post_lang = (string) get_post_meta( (int) $id, '_test_lang', true );
					if ( '' === $lang || $post_lang === $lang ) {
						$ids[] = (int) $id;
					}
				}

				$inside_callback = false;
				return $ids;
			},
			20,
			2
		);
	}
}
