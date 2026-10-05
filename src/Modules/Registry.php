<?php
namespace WCErpSync\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry containing all active sync modules.
 */
class Registry {

	/**
	 * Registry Instance.
	 *
	 * @var Registry
	 */
	private static $instance = null;

	/**
	 * Map of registered modules.
	 *
	 * @var BaseModule[]
	 */
	private $modules = [];

	/**
	 * Get registry instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for Singleton pattern.
	 */
	private function __construct() {}

	/**
	 * Initialize default modules.
	 */
	public function init() {
		// Instantiate and register default modules
		$this->register_module( new Product\ProductModule() );
		$this->register_module( new Category\CategoryModule() );
		$this->register_module( new Stock\StockModule() );
		$this->register_module( new Price\PriceModule() );
		$this->register_module( new Vendor\VendorModule() );
		$this->register_module( new Brand\BrandModule() );
		$this->register_module( new Image\ImageModule() );
		$this->register_module( new Variation\VariationModule() );
		$this->register_module( new Attribute\AttributeModule() );
		$this->register_module( new Customer\CustomerModule() );
		$this->register_module( new Order\OrderModule() );

		// Hook for external plugins to register custom modules
		do_action( 'wc_erp_sync_register_modules', $this );
	}

	/**
	 * Register a sync module.
	 */
	public function register_module( BaseModule $module ) {
		$this->modules[ $module->get_entity_name() ] = $module;
	}

	/**
	 * Get all registered modules.
	 *
	 * @return BaseModule[]
	 */
	public function get_modules() {
		return $this->modules;
	}

	/**
	 * Get a specific registered module.
	 *
	 * @param string $entity_name Entity name key.
	 * @return BaseModule|null
	 */
	public function get_module( $entity_name ) {
		return isset( $this->modules[ $entity_name ] ) ? $this->modules[ $entity_name ] : null;
	}
}
