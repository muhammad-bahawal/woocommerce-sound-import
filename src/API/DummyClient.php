<?php
namespace WCErpSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Concrete client implementation that serves mock records.
 * Enables the sync engine, dashboard tables, logs, mapping tools,
 * and order exports to be tested immediately.
 */
class DummyClient extends AbstractClient {

	/**
	 * Test the api settings connection.
	 */
	public function test_connection() {
		$this->load_settings();

		if ( empty( $this->settings['api_url'] ) ) {
			return [
				'success' => false,
				'message' => __( 'Connection failed. API Base URL is empty.', 'wc-erp-sync' ),
			];
		}

		return [
			'success' => true,
			'message' => __( 'Success! Connected to ERP Server (Simulated). Response Time: 120ms.', 'wc-erp-sync' ),
		];
	}

	/**
	 * Simulate fetching raw product records.
	 */
	public function fetch_products( $params = [] ) {
		$page  = isset( $params['page'] ) ? intval( $params['page'] ) : 1;
		$limit = isset( $params['limit'] ) ? intval( $params['limit'] ) : 10;

		// We will allow up to 3 pages for demonstration
		if ( $page > 3 ) {
			return [
				'items'     => [],
				'has_more'  => false,
			];
		}

		$products = [];
		$start_index = ( $page - 1 ) * $limit + 1;

		$categories = [ 'Electronics', 'Home & Living', 'Clothing', 'Sports Gear' ];
		$brands     = [ 'LogiTech', 'FitLife', 'ThreadCo', 'ActivePlus' ];
		$vendors    = [ 'Apex Distributors', 'Summit Wholesalers', 'Direct Logistics' ];

		for ( $i = 0; $i < $limit; $i++ ) {
			$id_num = $start_index + $i;
			$sku    = 'SKU-DUMMY-' . str_pad( $id_num, 4, '0', STR_PAD_LEFT );
			$ext_id = 'ERP-PROD-' . $id_num;

			$products[] = [
				'external_id' => $ext_id,
				'sku'         => $sku,
				'name'        => 'Simulated ERP Product #' . $id_num,
				'description' => 'This is a description for simulated product #' . $id_num . ' pulled dynamically from the ERP API client.',
				'price'       => 19.99 + ( $id_num * 5 ),
				'sale_price'  => ( $id_num % 3 === 0 ) ? ( 14.99 + ( $id_num * 4 ) ) : null,
				'stock'       => rand( 5, 120 ),
				'category'    => $categories[ $id_num % count( $categories ) ],
				'brand'       => $brands[ $id_num % count( $brands ) ],
				'vendor'      => $vendors[ $id_num % count( $vendors ) ],
				'weight'      => 0.5 + ( $id_num * 0.1 ),
				'dimensions'  => [
					'length' => 10 + $id_num,
					'width'  => 8 + $id_num,
					'height' => 5 + $id_num,
				],
				'attributes' => [
					'Color' => [ 'Red', 'Blue', 'Black' ][ $id_num % 3 ],
					'Size'  => [ 'S', 'M', 'L', 'XL' ][ $id_num % 4 ],
				],
				'variations' => [
					[
						'external_id' => $ext_id . '-VAR-A',
						'sku'         => $sku . '-A',
						'price'       => 21.99 + ( $id_num * 5 ),
						'stock'       => rand( 2, 50 ),
						'attributes'  => [ 'Color' => 'Red', 'Size' => 'M' ]
					],
					[
						'external_id' => $ext_id . '-VAR-B',
						'sku'         => $sku . '-B',
						'price'       => 22.99 + ( $id_num * 5 ),
						'stock'       => rand( 0, 10 ),
						'attributes'  => [ 'Color' => 'Blue', 'Size' => 'L' ]
					]
				],
				'image_url'   => 'https://picsum.photos/id/' . ( $id_num + 10 ) . '/600/600',
			];
		}

		return [
			'items'    => $products,
			'has_more' => $page < 3,
		];
	}

	/**
	 * Simulate category tree.
	 */
	public function fetch_categories() {
		return [
			[ 'external_id' => 'ERP-CAT-1', 'name' => 'Electronics', 'parent_id' => null ],
			[ 'external_id' => 'ERP-CAT-2', 'name' => 'Computers', 'parent_id' => 'ERP-CAT-1' ],
			[ 'external_id' => 'ERP-CAT-3', 'name' => 'Home & Living', 'parent_id' => null ],
			[ 'external_id' => 'ERP-CAT-4', 'name' => 'Clothing', 'parent_id' => null ],
			[ 'external_id' => 'ERP-CAT-5', 'name' => 'Sports Gear', 'parent_id' => null ],
		];
	}

	/**
	 * Simulate stock updates.
	 */
	public function fetch_stock_levels( $external_ids = [] ) {
		$map = [];
		foreach ( $external_ids as $id ) {
			$map[ $id ] = rand( 0, 200 );
		}
		return $map;
	}

	/**
	 * Simulate price updates.
	 */
	public function fetch_prices( $external_ids = [] ) {
		$map = [];
		foreach ( $external_ids as $id ) {
			$base = rand( 15, 500 );
			$map[ $id ] = [
				'regular_price' => $base,
				'sale_price'    => ( rand( 1, 10 ) > 7 ) ? $base * 0.8 : null,
			];
		}
		return $map;
	}

	/**
	 * Simulate vendor profiles.
	 */
	public function fetch_vendors() {
		return [
			[ 'external_id' => 'ERP-VND-1', 'name' => 'Apex Distributors', 'contact' => 'sales@apex.example.com' ],
			[ 'external_id' => 'ERP-VND-2', 'name' => 'Summit Wholesalers', 'contact' => 'orders@summit.example.com' ],
			[ 'external_id' => 'ERP-VND-3', 'name' => 'Direct Logistics', 'contact' => 'support@directlog.example.com' ],
		];
	}

	/**
	 * Simulate brand profiles.
	 */
	public function fetch_brands() {
		return [
			[ 'external_id' => 'ERP-BRD-1', 'name' => 'LogiTech' ],
			[ 'external_id' => 'ERP-BRD-2', 'name' => 'FitLife' ],
			[ 'external_id' => 'ERP-BRD-3', 'name' => 'ThreadCo' ],
			[ 'external_id' => 'ERP-BRD-4', 'name' => 'ActivePlus' ],
		];
	}

	/**
	 * Simulate exporting order to ERP.
	 */
	public function push_order( $local_order_id, $order_data ) {
		// Mock latency
		usleep( 200000 ); // 200ms sleep

		return [
			'success'     => true,
			'external_id' => 'ERP-ORD-' . rand( 10000, 99999 ),
			'message'     => __( 'Order exported successfully to simulated ERP endpoint.', 'wc-erp-sync' ),
		];
	}

	/**
	 * Simulate customer retrieval.
	 */
	public function fetch_customer( $external_id ) {
		return [
			'external_id' => $external_id,
			'first_name'  => 'John',
			'last_name'   => 'Doe',
			'email'       => 'john.doe@example.com',
			'billing'     => [
				'company'  => 'SaaS Enterprise',
				'address'  => '123 Tech Lane',
				'city'     => 'San Jose',
				'state'    => 'CA',
				'postcode' => '95112',
				'country'  => 'US',
			]
		];
	}

	/**
	 * Simulate fetching a product by SKU.
	 */
	public function fetch_product_by_sku( $sku ) {
		$sku_clean = sanitize_text_field( $sku );

		return [
			'external_id' => 'ERP-PROD-SKU-' . md5( $sku_clean ),
			'sku'         => $sku_clean,
			'name'        => 'Simulated Product (' . $sku_clean . ')',
			'description' => 'This is a simulated product description for SKU ' . $sku_clean,
			'price'       => 36.45,
			'sale_price'  => null,
			'stock'       => 10,
			'brand'       => 'Simulated Brand',
			'category'    => 'Electronics',
			'ean'         => '8785256848157',
			'url'         => sanitize_title( $sku_clean ) . '.html',
			'image_url'   => 'https://picsum.photos/id/10/600/600',
			'raw'         => [
				'id'    => 267,
				'sku'   => $sku_clean,
				'title' => 'Simulated Product (' . $sku_clean . ')',
			],
		];
	}

	/**
	 * Simulate fetching product IDs updated/changed since a given date.
	 */
	public function fetch_changed_products( $since ) {
		$since_clean = sanitize_text_field( $since );
		$mock_ids    = [ 790, 832, 690, 262, 251 ];

		return [
			'since'       => $since_clean,
			'total'       => count( $mock_ids ),
			'product_ids' => $mock_ids,
		];
	}

	/**
	 * Simulate fetching product statistics.
	 *
	 * @return array { 'total_products' => int, 'total_categories' => int, 'total_brands' => int, 'last_sync' => string }
	 */
	public function fetch_stats() {
		return [
			'total_products'   => 4213,
			'total_categories' => 171,
			'total_brands'     => 82,
			'last_sync'        => '2026-07-14T15:48:45.643161',
		];
	}

	/**
	 * Simulate triggering remote sync job.
	 *
	 * @param bool $incremental
	 * @return array { 'job_id' => int, 'status' => string, 'message' => string }
	 */
	public function trigger_sync( $incremental = false ) {
		$job_id = rand( 1, 100 );
		$type   = $incremental ? 'Incremental' : 'Full';

		return [
			'job_id'  => $job_id,
			'status'  => 'running',
			'message' => sprintf( '%s scrape started (job #%d)', $type, $job_id ),
		];
	}

	/**
	 * Simulate checking health status.
	 *
	 * @return array { 'status' => string }
	 */
	public function check_health() {
		return [
			'status' => 'ok',
		];
	}
}


