<?php
namespace WCErpSync\Modules\Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;
use WCErpSync\WooCommerce\ProductMapper;
use WCErpSync\Database\MappingTable;
use WCErpSync\API\ClientFactory;

/**
 * Handles Product Sync processes.
 */
class ProductModule extends BaseModule {

	/**
	 * Get entity key.
	 */
	public function get_entity_name() {
		return 'product';
	}

	/**
	 * Get label.
	 */
	public function get_title() {
		return __( 'Products', 'wc-erp-sync' );
	}

	/**
	 * Process importing product item.
	 */
	public function import_item( $raw_data ) {
		if ( empty( $raw_data['external_id'] ) ) {
			$this->log( 'error', 'Import failed: Product record missing external_id.' );
			return false;
		}

		$external_id = (string) $raw_data['external_id'];
		$sku         = isset( $raw_data['sku'] ) ? sanitize_text_field( $raw_data['sku'] ) : '';

		// 1. Resolve SKU from local mapping if missing
		if ( empty( $sku ) ) {
			$local_id = MappingTable::get_local_id( 'product', $external_id );
			if ( $local_id ) {
				$existing_product = wc_get_product( $local_id );
				if ( $existing_product ) {
					$sku = sanitize_text_field( $existing_product->get_sku() );
					if ( ! empty( $sku ) ) {
						$raw_data['sku'] = $sku;
					}
				}
			}

			// If not found in mapping table, check if product exists in WooCommerce by meta _erp_external_id
			if ( empty( $sku ) ) {
				$meta_prods = get_posts( [
					'post_type'      => [ 'product', 'product_variation' ],
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => [
						[
							'key'   => '_erp_external_id',
							'value' => $external_id,
						],
					],
				] );
				if ( ! empty( $meta_prods ) ) {
					$existing_product = wc_get_product( $meta_prods[0] );
					if ( $existing_product ) {
						$sku = sanitize_text_field( $existing_product->get_sku() );
						if ( ! empty( $sku ) ) {
							$raw_data['sku'] = $sku;
							$this->add_mapping( $external_id, $meta_prods[0] );
						}
					}
				}
			}
		}

		// 2. If SKU is still missing and payload has no details, fetch product directly by external ID
		if ( empty( $sku ) && empty( $raw_data['title'] ) && empty( $raw_data['name'] ) ) {
			$client = ClientFactory::get_client();
			if ( method_exists( $client, 'fetch_product_by_id' ) ) {
				$by_id = $client->fetch_product_by_id( $external_id );
				if ( ! empty( $by_id ) && is_array( $by_id ) ) {
					foreach ( $by_id as $k => $v ) {
						if ( ! empty( $v ) || ! isset( $raw_data[ $k ] ) ) {
							$raw_data[ $k ] = $v;
						}
					}
					$sku = isset( $raw_data['sku'] ) ? sanitize_text_field( $raw_data['sku'] ) : '';
				}
			}
		}

		// 3. If still completely missing SKU and product name, log warning and skip (prevents 450+ false errors)
		if ( empty( $sku ) && empty( $raw_data['title'] ) && empty( $raw_data['name'] ) ) {
			$this->log( 'warning', sprintf( 'Skipping Product ERP ID %s: Product record could not be resolved from API or local store.', $external_id ) );
			return false;
		}

		// Assign fallback SKU if name is present but SKU was omitted
		if ( empty( $sku ) ) {
			$sku             = 'SI-' . $external_id;
			$raw_data['sku'] = $sku;
		}

		// Performance check: If raw_data already has title/name and content, it came from
		// categories or products list endpoint and does not need expensive individual SKU queries.
		$has_title          = ! empty( $raw_data['title'] ) || ! empty( $raw_data['name'] );
		$has_content_detail = ! empty( $raw_data['image_url'] ) || ! empty( $raw_data['gallery_images'] ) || ! empty( $raw_data['description'] ) || ! empty( $raw_data['long_description'] );
		$has_exact_stock    = ! empty( $raw_data['has_exact_stock'] ) || ( ! array_key_exists( 'has_exact_stock', $raw_data ) && isset( $raw_data['stock'] ) && null !== $raw_data['stock'] && '' !== $raw_data['stock'] );
		$is_detail_payload  = $has_title && ( $has_content_detail || isset( $raw_data['price'] ) || isset( $raw_data['regular_price'] ) );

		if ( ! $is_detail_payload && ! empty( $sku ) ) {
			// Check if existing product mapping exists and hash (updated_at) matches
			$existing_mapping = MappingTable::get_mapping( 'product', $external_id );
			$updated_at       = isset( $raw_data['updated_at'] ) ? $raw_data['updated_at'] : '';
			$current_hash     = ! empty( $updated_at ) ? md5( $updated_at ) : '';

			if ( $has_exact_stock && $existing_mapping && ! empty( $current_hash ) && $existing_mapping['hash'] === $current_hash ) {
				$local_id = intval( $existing_mapping['local_id'] );
				$existing_product = wc_get_product( $local_id );
				$api_stock        = max( 0, intval( $raw_data['stock'] ) );
				$expected_status  = $api_stock > 0 ? 'instock' : 'outofstock';

				// Do not let the content hash bypass an inventory correction. Stock can
				// change independently of updated_at, and older imports may have stored
				// only the status without enabling quantity management.
				$stock_is_current = $existing_product
					&& $existing_product->get_manage_stock()
					&& intval( $existing_product->get_stock_quantity() ) === $api_stock
					&& $existing_product->get_stock_status() === $expected_status;

				if ( $stock_is_current ) {
					$this->log( 'info', sprintf( 'Skipping detailed API fetch: Product SKU %s (ID %d) is already up-to-date.', $sku, $local_id ) );
					return $local_id;
				}
			}

			// Fetch full product detail from API endpoint (/api/product/sku/{sku})
			$client      = ClientFactory::get_client();
			$full_detail = $client->fetch_product_by_sku( $sku );

			if ( ! empty( $full_detail ) && is_array( $full_detail ) ) {
				foreach ( $full_detail as $k => $v ) {
					// Don't overwrite non-empty images or descriptions with empty ones from SKU endpoint
					if ( ( 'image_url' === $k || 'gallery_images' === $k ) && empty( $v ) && ! empty( $raw_data[ $k ] ) ) {
						continue;
					}
					if ( 'stock' === $k && null === $v && isset( $raw_data['stock'] ) && null !== $raw_data['stock'] ) {
						continue;
					}
					$raw_data[ $k ] = $v;
				}
			}
		}

		// Normalize fields via ProductMapper
		$formatted_data = ProductMapper::to_wc_format( $raw_data );

		$this->log( 'info', sprintf( 'Importing Product SKU: %s (ERP ID: %s)', $formatted_data['sku'], $external_id ) );

		// Call WooCommerce Connector to create/update
		$local_id = WooConnector::upsert_product( $formatted_data );

		if ( $local_id ) {
			$hash_source = ! empty( $raw_data['updated_at'] ) ? $raw_data['updated_at'] : wp_json_encode( $formatted_data );
			$hash        = md5( $hash_source );
			$this->add_mapping( $external_id, $local_id, $hash, 'success' );
			$this->log( 'info', sprintf( 'Successfully synced Product. Local ID: %d', $local_id ) );
			return $local_id;
		}

		$this->log( 'error', sprintf( 'Failed to upsert Product SKU: %s in WooCommerce.', $formatted_data['sku'] ) );
		return false;
	}

	/**
	 * Process exporting product item.
	 */
	public function export_item( $local_id ) {
		$external_id = $this->get_external_id( $local_id );
		$product     = wc_get_product( $local_id );

		if ( ! $product ) {
			$this->log( 'error', sprintf( 'Export failed: Product %d not found.', $local_id ) );
			return false;
		}

		$this->log( 'info', sprintf( 'Exporting Product: %s (Local ID: %d)', $product->get_sku(), $local_id ) );

		// Placeholder for ERP push
		$payload = [
			'external_id' => $external_id ? $external_id : '',
			'sku'         => $product->get_sku(),
			'name'        => $product->get_name(),
			'price'       => $product->get_regular_price(),
			'stock'       => $product->get_stock_quantity(),
		];

		return $payload;
	}

	/**
	 * Fetch a product by SKU from the active API client and import it into WooCommerce.
	 *
	 * @param string $sku Product SKU.
	 * @return int|bool Local WC Product ID or false on failure.
	 */
	public function import_product_by_sku( $sku ) {
		$client = ClientFactory::get_client();
		$data   = $client->fetch_product_by_sku( $sku );

		if ( empty( $data ) ) {
			$this->log( 'error', sprintf( 'Failed to fetch product data for SKU: %s from API client.', $sku ) );
			return false;
		}

		return $this->import_item( $data );
	}
}
