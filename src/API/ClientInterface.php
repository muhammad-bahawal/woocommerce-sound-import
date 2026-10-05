<?php
namespace WCErpSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface that any third-party ERP/API Client must implement.
 */
interface ClientInterface {

	/**
	 * Validate connection details to the ERP.
	 *
	 * @return array { 'success' => bool, 'message' => string }
	 */
	public function test_connection();

	/**
	 * Fetch products from the ERP.
	 *
	 * @param array $params Query arguments (e.g., page, limit, updated_since).
	 * @return array Raw product array list and next page info.
	 */
	public function fetch_products( $params = [] );

	/**
	 * Fetch categories from the ERP.
	 *
	 * @return array List of raw category elements.
	 */
	public function fetch_categories();

	/**
	 * Fetch current stock levels.
	 *
	 * @param array $external_ids List of ERP external item IDs.
	 * @return array Map of [ external_id => stock_quantity ].
	 */
	public function fetch_stock_levels( $external_ids = [] );

	/**
	 * Fetch current prices.
	 *
	 * @param array $external_ids List of ERP external item IDs.
	 * @return array Map of [ external_id => [ regular_price => x, sale_price => y ] ].
	 */
	public function fetch_prices( $external_ids = [] );

	/**
	 * Fetch vendors/suppliers.
	 *
	 * @return array List of raw vendor elements.
	 */
	public function fetch_vendors();

	/**
	 * Fetch brands.
	 *
	 * @return array List of raw brand elements.
	 */
	public function fetch_brands();

	/**
	 * Export/Push a WooCommerce order to the ERP.
	 *
	 * @param int   $local_order_id The WC Order ID.
	 * @param array $order_data Prepared order payload.
	 * @return array { 'success' => bool, 'external_id' => string, 'message' => string }
	 */
	public function push_order( $local_order_id, $order_data );

	/**
	 * Fetch a customer record.
	 *
	 * @param string $external_id Customer ERP ID.
	 * @return array Raw customer payload.
	 */
	public function fetch_customer( $external_id );

	/**
	 * Fetch a single product by SKU from ERP.
	 *
	 * @param string $sku Product SKU.
	 * @return array Product detail payload or empty array.
	 */
	public function fetch_product_by_sku( $sku );

	/**
	 * Fetch product IDs updated/changed since a given date.
	 *
	 * @param string $since Date string formatted as YYYY-MM-DD.
	 * @return array { 'since' => string, 'total' => int, 'product_ids' => array }
	 */
	public function fetch_changed_products( $since );

	/**
	 * Fetch product statistics from the ERP.
	 *
	 * @return array { 'total_products' => int, 'total_categories' => int, 'total_brands' => int, 'last_sync' => string }
	 */
	public function fetch_stats();

	/**
	 * Trigger remote scraping/sync job on the ERP backend service.
	 *
	 * @param bool $incremental Whether to perform incremental sync (true) or full scrape (false).
	 * @return array { 'job_id' => int, 'status' => string, 'message' => string }
	 */
	public function trigger_sync( $incremental = false );

	/**
	 * Check health status of the ERP backend API.
	 *
	 * @return array { 'status' => string }
	 */
	public function check_health();
}


