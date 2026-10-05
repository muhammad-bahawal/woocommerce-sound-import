<?php
namespace WCErpSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Logger\Logger;

/**
 * SoundImports API Client adapter.
 * Connects directly to the live scrape FastAPI service.
 */
class SoundImportsClient extends AbstractClient {

	/**
	 * Test credentials to the scrape server.
	 */
	public function test_connection() {
		$this->load_settings();

		if ( empty( $this->settings['api_url'] ) ) {
			return [
				'success' => false,
				'message' => __( 'API Base URL is empty.', 'wc-erp-sync' ),
			];
		}

		// First attempt endpoint check against health
		$health_check = $this->check_health();
		if ( 'ok' === $health_check['status'] ) {
			return [
				'success' => true,
				'message' => __( 'Success! Connected to SoundImports API. Server health status verified.', 'wc-erp-sync' ),
			];
		}

		// Fallback check against categories endpoint
		$response = $this->request( 'api/categories', 'GET' );

		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'message' => sprintf( __( 'Connection failed: %s', 'wc-erp-sync' ), $response->get_error_message() ),
			];
		}

		return [
			'success' => true,
			'message' => __( 'Success! Connected to SoundImports API. Categories endpoint verified.', 'wc-erp-sync' ),
		];
	}

	/**
	 * Fetch live categories from the endpoint.
	 */
	public function fetch_categories() {
		$this->load_settings();

		Logger::info( 'Fetching live categories from SoundImports API...', 'api_client' );
		$response = $this->request( 'api/categories', 'GET' );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Failed to fetch categories: %s', $response->get_error_message() ), 'api_client' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			Logger::error( 'Invalid JSON returned from categories API.', 'api_client', [ 'body' => substr( $body, 0, 500 ) ] );
			return [];
		}

		Logger::info( sprintf( 'Retrieved %d categories from API.', count( $data ) ), 'api_client' );
		return $data;
	}

	/**
	 * Normalize a raw API product object into internal Sync Engine array format.
	 *
	 * @param array $data Raw product array from API.
	 * @return array Normalized product array.
	 */
	public function normalize_product_payload( $data ) {
		if ( ! is_array( $data ) ) {
			return [];
		}

		$id  = ! empty( $data['id'] ) ? (string) $data['id'] : ( ! empty( $data['external_id'] ) ? (string) $data['external_id'] : '' );
		$sku = ! empty( $data['sku'] ) ? sanitize_text_field( $data['sku'] ) : '';

		// Process image URLs from API response
		$image_url      = ! empty( $data['image_url'] ) ? esc_url_raw( $data['image_url'] ) : '';
		$gallery_images = ! empty( $data['gallery_images'] ) && is_array( $data['gallery_images'] ) ? array_map( 'esc_url_raw', array_filter( $data['gallery_images'] ) ) : [];

		if ( empty( $image_url ) && ! empty( $data['images'] ) && is_array( $data['images'] ) ) {
			usort( $data['images'], function( $a, $b ) {
				$order_a = is_array( $a ) && isset( $a['sort_order'] ) ? intval( $a['sort_order'] ) : 0;
				$order_b = is_array( $b ) && isset( $b['sort_order'] ) ? intval( $b['sort_order'] ) : 0;
				return $order_a <=> $order_b;
			} );

			foreach ( $data['images'] as $img ) {
				$src      = '';
				$is_cover = false;

				if ( is_string( $img ) ) {
					$src = $img;
				} elseif ( is_array( $img ) ) {
					$src      = ! empty( $img['src'] ) ? $img['src'] : ( ! empty( $img['url'] ) ? $img['url'] : '' );
					$is_cover = ! empty( $img['is_cover'] );
				}

				if ( empty( $src ) ) {
					continue;
				}

				if ( strpos( $src, 'http://' ) === 0 || strpos( $src, 'https://' ) === 0 ) {
					$full_url = $src;
				} else {
					$full_url = sprintf( 'https://cdn.webshopapp.com/shops/188510/files/%s/2000x1280x2/image.jpg', $src );
				}

				if ( $is_cover && empty( $image_url ) ) {
					$image_url = $full_url;
				} else {
					$gallery_images[] = $full_url;
				}
			}

			if ( empty( $image_url ) && ! empty( $gallery_images ) ) {
				$image_url = array_shift( $gallery_images );
			}
		}

		// Extract long and short descriptions
		$raw_short = ! empty( $data['short_description'] ) ? $data['short_description'] : ( ! empty( $data['summary'] ) ? $data['summary'] : '' );
		$raw_long  = ! empty( $data['long_description'] ) ? $data['long_description'] : ( ! empty( $data['description'] ) ? $data['description'] : '' );

		if ( ! empty( $raw_long ) ) {
			$description       = $raw_long;
			$short_description = ! empty( $raw_short ) ? $raw_short : '';
		} else {
			$description       = $raw_short;
			$short_description = $raw_short;
		}

		// Category handling
		$category        = '';
		$categories_list = [];
		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$categories_list = $data['categories'];
			$last_cat        = end( $data['categories'] );
			if ( is_array( $last_cat ) ) {
				$category = ! empty( $last_cat['name'] ) ? $last_cat['name'] : ( ! empty( $last_cat['title'] ) ? $last_cat['title'] : '' );
			} elseif ( is_string( $last_cat ) ) {
				$category = $last_cat;
			}
		} elseif ( ! empty( $data['category'] ) ) {
			$category        = is_array( $data['category'] ) ? reset( $data['category'] ) : $data['category'];
			$categories_list = [ $category ];
		}

		// Price handling
		$regular_price = isset( $data['regular_price'] ) ? floatval( $data['regular_price'] ) : ( isset( $data['price'] ) ? floatval( $data['price'] ) : 0.0 );
		$sale_price    = ! empty( $data['sale_price'] ) ? floatval( $data['sale_price'] ) : '';

		// Stock handling. Check all possible stock keys (stock, stock_quantity, quantity, qty)
		$raw_stock = null;
		if ( isset( $data['stock'] ) && null !== $data['stock'] && '' !== $data['stock'] ) {
			$raw_stock = $data['stock'];
		} elseif ( isset( $data['stock_quantity'] ) && null !== $data['stock_quantity'] && '' !== $data['stock_quantity'] ) {
			$raw_stock = $data['stock_quantity'];
		} elseif ( isset( $data['quantity'] ) && null !== $data['quantity'] && '' !== $data['quantity'] ) {
			$raw_stock = $data['quantity'];
		} elseif ( isset( $data['qty'] ) && null !== $data['qty'] && '' !== $data['qty'] ) {
			$raw_stock = $data['qty'];
		}

		$has_exact_stock = ( null !== $raw_stock );
		if ( $has_exact_stock ) {
			$stock = max( 0, intval( $raw_stock ) );
		} elseif ( isset( $data['stock_status'] ) && in_array( strtolower( (string) $data['stock_status'] ), [ 'out_of_stock', 'outofstock', 'out of stock' ], true ) ) {
			$stock = 0;
			$has_exact_stock = true;
		} else {
			$stock = null;
		}

		// Normalize stock status to valid WooCommerce value (instock, outofstock, onbackorder)
		$raw_status = ! empty( $data['stock_status'] ) ? strtolower( trim( (string) $data['stock_status'] ) ) : '';
		if ( in_array( $raw_status, [ 'out_of_stock', 'outofstock', 'out of stock' ], true ) ) {
			$stock_status = 'outofstock';
		} elseif ( in_array( $raw_status, [ 'on_backorder', 'onbackorder', 'backorder' ], true ) ) {
			$stock_status = 'onbackorder';
		} else {
			$stock_status = 'instock';
		}

		return [
			'external_id'       => $id,
			'sku'               => $sku,
			'name'              => isset( $data['title'] ) ? $data['title'] : ( isset( $data['name'] ) ? $data['name'] : '' ),
			'description'       => $description,
			'short_description' => $short_description,
			'price'             => $regular_price,
			'sale_price'        => $sale_price,
			'stock'             => $stock,
			'stock_status'      => $stock_status,
			'has_exact_stock'   => $has_exact_stock,
			'brand'             => isset( $data['brand'] ) ? $data['brand'] : '',
			'category'          => $category,
			'categories'        => $categories_list,
			'ean'               => isset( $data['ean'] ) ? $data['ean'] : '',
			'url'               => isset( $data['url'] ) ? $data['url'] : '',
			'image_url'         => $image_url,
			'gallery_images'    => array_values( array_unique( $gallery_images ) ),
			'specifications'    => ! empty( $data['specifications'] ) && is_array( $data['specifications'] ) ? $data['specifications'] : [],
			'updated_at'        => isset( $data['updated_at'] ) ? $data['updated_at'] : '',
			'raw'               => $data,
		];
	}

	/**
	 * Fetch products paginated list from API.
	 */
	public function fetch_products( $params = [] ) {
		$this->load_settings();

		Logger::info( 'Fetching live products from SoundImports API...', 'api_client' );

		// Prepare URL query parameters
		$query_args = [
			'page'       => isset( $params['page'] ) ? intval( $params['page'] ) : 1,
			'limit'      => isset( $params['limit'] ) ? intval( $params['limit'] ) : 100,
			'sort_order' => isset( $params['sort_order'] ) ? sanitize_text_field( $params['sort_order'] ) : 'desc',
		];

		$endpoint = 'api/products?' . http_build_query( $query_args );
		$response = $this->request( $endpoint, 'GET' );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Failed to fetch products: %s (API URL: %s)', $response->get_error_message(), $this->settings['api_url'] ), 'api_client' );
			return [
				'items'    => [],
				'has_more' => false,
				'error'    => sprintf( '%s (API URL: %s)', $response->get_error_message(), $this->settings['api_url'] ),
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			Logger::error( 'Invalid JSON returned from products API.', 'api_client', [ 'body' => substr( $body, 0, 500 ) ] );
			return [
				'items'    => [],
				'has_more' => false,
				'error'    => __( 'Invalid JSON returned from products API.', 'wc-erp-sync' ),
			];
		}

		$products = [];
		$page     = isset( $params['page'] ) ? intval( $params['page'] ) : 1;
		$limit    = isset( $params['limit'] ) ? intval( $params['limit'] ) : 100;
		$total    = 0;
		$has_more = false;

		if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
			$products = $data['products'];
			$page     = isset( $data['page'] ) ? intval( $data['page'] ) : $page;
			$limit    = isset( $data['limit'] ) ? intval( $data['limit'] ) : $limit;
			$total    = isset( $data['total'] ) ? intval( $data['total'] ) : count( $products );
			$has_more = isset( $data['has_more'] ) ? (bool) $data['has_more'] : ( ( $page * $limit ) < $total );
		} elseif ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$products = $data;
			$total    = count( $products );
			$has_more = count( $products ) >= $limit;
		}

		$normalized_items = [];
		foreach ( $products as $prod ) {
			$norm = $this->normalize_product_payload( $prod );
			if ( ! empty( $norm['sku'] ) || ! empty( $norm['external_id'] ) ) {
				$normalized_items[] = $norm;
			}
		}

		Logger::info( sprintf( 'Retrieved %d normalized products from API (Page %d).', count( $normalized_items ), $page ), 'api_client' );

		return [
			'items'    => $normalized_items,
			'has_more' => $has_more,
		];
	}

	/**
	 * Fetch products by category ID or slug from SoundImports API.
	 * Endpoint: api/category/{category_id_or_slug}/products
	 *
	 * @param string|int $category_id_or_slug Category ID or Slug.
	 * @param array      $params Additional params (page, limit, include_children).
	 * @return array Normalized products response array.
	 */
	public function fetch_products_by_category( $category_id_or_slug, $params = [] ) {
		$this->load_settings();

		$cat_target = sanitize_text_field( $category_id_or_slug );
		$cat_slug   = sanitize_title( $cat_target );
		$page       = isset( $params['page'] ) ? max( 1, intval( $params['page'] ) ) : 1;
		$limit      = isset( $params['limit'] ) ? intval( $params['limit'] ) : 100;
		// Default include_children to true so all category products (including sub-children) are fetched
		$inc_child  = isset( $params['include_children'] ) ? ( filter_var( $params['include_children'], FILTER_VALIDATE_BOOLEAN ) ? 'true' : 'false' ) : 'true';

		Logger::info( sprintf( 'Fetching products for category "%s" (Slug: "%s", Page %d, Limit %d, include_children=%s)...', $cat_target, $cat_slug, $page, $limit, $inc_child ), 'api_client' );

		$query_args = [
			'page'             => $page,
			'limit'            => $limit,
			'include_children' => $inc_child,
		];

		// Try raw target, slugified target, lowercase, and space/hyphen variations across singular and plural endpoints
		$targets = array_unique( array_filter( [
			$cat_target,
			$cat_slug,
			strtolower( $cat_target ),
			str_replace( ' ', '-', strtolower( $cat_target ) ),
			str_replace( '-', ' ', strtolower( $cat_target ) ),
		] ) );
		$endpoints = [];
		foreach ( $targets as $t ) {
			$endpoints[] = 'api/category/' . rawurlencode( $t ) . '/products?' . http_build_query( $query_args );
			$endpoints[] = 'api/categories/' . rawurlencode( $t ) . '/products?' . http_build_query( $query_args );
		}

		$response   = null;
		$valid_data = null;
		$last_error = '';

		foreach ( $endpoints as $endpoint ) {
			$res = $this->request( $endpoint, 'GET' );
			if ( ! is_wp_error( $res ) ) {
				$code = wp_remote_retrieve_response_code( $res );
				if ( 200 === $code ) {
					$body    = wp_remote_retrieve_body( $res );
					$decoded = json_decode( $body, true );
					if ( is_array( $decoded ) && ( isset( $decoded['products'] ) || ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) ) ) {
						$response   = $res;
						$valid_data = $decoded;
						break;
					}
				}
			}
			$last_error = is_wp_error( $res ) ? $res->get_error_message() : ( 'HTTP ' . ( isset( $res ) ? wp_remote_retrieve_response_code( $res ) : 'Error' ) );
		}

		if ( ! $response || null === $valid_data ) {
			Logger::error( sprintf( 'Failed to fetch valid category products for "%s": %s', $cat_target, $last_error ), 'api_client' );
			return [
				'items'    => [],
				'has_more' => false,
				'total'    => 0,
				'error'    => $last_error,
			];
		}

		$data     = $valid_data;
		$products = [];
		$total    = 0;
		$has_more = false;

		if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
			$products = $data['products'];
			$page     = isset( $data['page'] ) ? intval( $data['page'] ) : $page;
			$limit    = isset( $data['limit'] ) ? intval( $data['limit'] ) : $limit;
			$total    = isset( $data['total'] ) ? intval( $data['total'] ) : count( $products );
			$has_more = isset( $data['has_more'] ) ? (bool) $data['has_more'] : ( ( $page * $limit ) < $total );
		} elseif ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$products = $data;
			$total    = count( $products );
			$has_more = count( $products ) >= $limit;
		}

		$normalized_items = [];
		foreach ( $products as $prod ) {
			$norm = $this->normalize_product_payload( $prod );
			if ( ! empty( $norm['sku'] ) || ! empty( $norm['external_id'] ) ) {
				$normalized_items[] = $norm;
			}
		}

		Logger::info( sprintf( 'Retrieved %d normalized category products for "%s" (Total: %d).', count( $normalized_items ), $cat_target, $total ), 'api_client' );

		return [
			'items'    => $normalized_items,
			'has_more' => $has_more,
			'total'    => $total,
			'page'     => $page,
			'limit'    => $limit,
		];
	}

	public function fetch_stock_levels( $external_ids = [] ) {
		$dummy = new DummyClient();
		return $dummy->fetch_stock_levels( $external_ids );
	}

	public function fetch_prices( $external_ids = [] ) {
		$dummy = new DummyClient();
		return $dummy->fetch_prices( $external_ids );
	}

	public function fetch_vendors() {
		$dummy = new DummyClient();
		return $dummy->fetch_vendors();
	}

	public function fetch_brands() {
		$this->load_settings();

		Logger::info( 'Fetching live brands from SoundImports API...', 'api_client' );
		$response = $this->request( 'api/brands', 'GET' );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Failed to fetch brands: %s', $response->get_error_message() ), 'api_client' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			Logger::error( 'Invalid JSON returned from brands API.', 'api_client', [ 'body' => substr( $body, 0, 500 ) ] );
			return [];
		}

		Logger::info( sprintf( 'Retrieved %d brands from API.', count( $data ) ), 'api_client' );
		return $data;
	}

	public function push_order( $local_order_id, $order_data ) {
		$dummy = new DummyClient();
		return $dummy->push_order( $local_order_id, $order_data );
	}

	public function fetch_customer( $external_id ) {
		$dummy = new DummyClient();
		return $dummy->fetch_customer( $external_id );
	}

	/**
	 * Fetch a single product by SKU from the SoundImports Scraper FastAPI endpoint with multi-endpoint fallbacks.
	 * Endpoints tried: api/products/sku/{sku}, api/product/sku/{sku}, api/products?search={sku}
	 */
	public function fetch_product_by_sku( $sku ) {
		$this->load_settings();

		$sku_clean = sanitize_text_field( $sku );
		Logger::info( sprintf( 'Fetching live product by SKU %s from SoundImports API (URL: %s)...', $sku_clean, $this->settings['api_url'] ), 'api_client' );

		$endpoints = [
			'api/product/sku/' . rawurlencode( $sku_clean ),
			'api/products/sku/' . rawurlencode( $sku_clean ),
			'api/products?search=' . rawurlencode( $sku_clean ),
		];

		$last_error = '';

		foreach ( $endpoints as $endpoint ) {
			$response = $this->request( $endpoint, 'GET' );

			if ( is_wp_error( $response ) ) {
				$last_error = sprintf( '%s (HTTP Code: %s)', $response->get_error_message(), $response->get_error_code() );
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			// If search endpoint returned array of products
			if ( isset( $data['products'] ) && is_array( $data['products'] ) && ! empty( $data['products'] ) ) {
				foreach ( $data['products'] as $prod ) {
					if ( isset( $prod['sku'] ) && strcasecmp( trim( $prod['sku'] ), $sku_clean ) === 0 ) {
						Logger::info( sprintf( 'Successfully matched product SKU %s via search endpoint.', $sku_clean ), 'api_client' );
						return $this->normalize_product_payload( $prod );
					}
				}
				Logger::info( sprintf( 'Using first search result for product SKU %s.', $sku_clean ), 'api_client' );
				return $this->normalize_product_payload( $data['products'][0] );
			}

			// Single product payload
			if ( ! empty( $data['sku'] ) || ! empty( $data['title'] ) || ! empty( $data['id'] ) ) {
				$normalized = $this->normalize_product_payload( $data );
				Logger::info( sprintf( 'Successfully fetched product SKU %s via endpoint %s.', $sku_clean, $endpoint ), 'api_client' );
				return $normalized;
			}
		}

		Logger::error( sprintf( 'Failed to fetch product SKU %s after fallback attempts. Last Error: %s (API Base URL: %s)', $sku_clean, $last_error, $this->settings['api_url'] ), 'api_client' );
		return [];
	}

	/**
	 * Fetch IDs of products changed since a given date string.
	 * Endpoint: api/products/changed?since={YYYY-MM-DD}
	 *
	 * @param string $since Date in YYYY-MM-DD format.
	 * @return array { 'since' => string, 'total' => int, 'product_ids' => array }
	 */
	public function fetch_changed_products( $since ) {
		$this->load_settings();

		$since_clean = sanitize_text_field( $since );
		Logger::info( sprintf( 'Fetching changed products since %s from SoundImports API...', $since_clean ), 'api_client' );

		$endpoint = 'api/products/changed?since=' . rawurlencode( $since_clean );
		$response = $this->request( $endpoint, 'GET' );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Failed to fetch changed products since %s: %s', $since_clean, $response->get_error_message() ), 'api_client' );
			return [
				'since'       => $since_clean,
				'total'       => 0,
				'product_ids' => [],
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['product_ids'] ) ) {
			Logger::error( sprintf( 'Invalid JSON returned from changed products API for date %s.', $since_clean ), 'api_client', [ 'body' => substr( $body, 0, 500 ) ] );
			return [
				'since'       => $since_clean,
				'total'       => 0,
				'product_ids' => [],
			];
		}

		$product_ids = is_array( $data['product_ids'] ) ? array_map( 'intval', $data['product_ids'] ) : [];
		$total       = isset( $data['total'] ) ? intval( $data['total'] ) : count( $product_ids );

		Logger::info( sprintf( 'Found %d products changed since %s.', count( $product_ids ), $since_clean ), 'api_client' );

		return [
			'since'       => isset( $data['since'] ) ? $data['since'] : $since_clean,
			'total'       => $total,
			'product_ids' => $product_ids,
		];
	}

	/**
	 * Fetch overall product stats from the SoundImports Scraper FastAPI endpoint.
	 * Endpoint: api/stats
	 *
	 * @return array { 'total_products' => int, 'total_categories' => int, 'total_brands' => int, 'last_sync' => string }
	 */
	public function fetch_stats() {
		$this->load_settings();

		Logger::info( 'Fetching live product stats from SoundImports API...', 'api_client' );

		$response = $this->request( 'api/stats', 'GET' );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Failed to fetch product stats: %s', $response->get_error_message() ), 'api_client' );
			return [
				'total_products'   => 0,
				'total_categories' => 0,
				'total_brands'     => 0,
				'last_sync'        => '',
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			Logger::error( 'Invalid JSON returned from product stats API.', 'api_client', [ 'body' => substr( $body, 0, 500 ) ] );
			return [
				'total_products'   => 0,
				'total_categories' => 0,
				'total_brands'     => 0,
				'last_sync'        => '',
			];
		}

		$stats = [
			'total_products'   => isset( $data['total_products'] ) ? intval( $data['total_products'] ) : 0,
			'total_categories' => isset( $data['total_categories'] ) ? intval( $data['total_categories'] ) : 0,
			'total_brands'     => isset( $data['total_brands'] ) ? intval( $data['total_brands'] ) : 0,
			'last_sync'        => isset( $data['last_sync'] ) ? sanitize_text_field( $data['last_sync'] ) : '',
		];

		Logger::info( sprintf( 'Successfully fetched product stats: %d products, %d categories, %d brands.', $stats['total_products'], $stats['total_categories'], $stats['total_brands'] ), 'api_client' );

		return $stats;
	}

	/**
	 * Trigger remote scraping/sync job on the SoundImports Scraper FastAPI endpoint.
	 * Endpoint: POST api/sync?incremental={true|false}
	 *
	 * @param bool $incremental Whether to perform incremental scrape or full scrape.
	 * @return array { 'job_id' => int, 'status' => string, 'message' => string }
	 */
	public function trigger_sync( $incremental = false ) {
		$this->load_settings();

		$inc_str  = $incremental ? 'true' : 'false';
		Logger::info( sprintf( 'Triggering remote API sync (incremental=%s)...', $inc_str ), 'api_client' );

		$endpoint = 'api/sync?incremental=' . $inc_str;
		$response = $this->request( $endpoint, 'POST', [] );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Failed to trigger remote sync: %s', $response->get_error_message() ), 'api_client' );
			return [
				'job_id'  => 0,
				'status'  => 'failed',
				'message' => $response->get_error_message(),
			];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			Logger::error( 'Invalid JSON returned from trigger sync API.', 'api_client', [ 'body' => substr( $body, 0, 500 ) ] );
			return [
				'job_id'  => 0,
				'status'  => 'error',
				'message' => __( 'Invalid JSON response from server.', 'wc-erp-sync' ),
			];
		}

		$result = [
			'job_id'  => isset( $data['job_id'] ) ? intval( $data['job_id'] ) : 0,
			'status'  => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'unknown',
			'message' => isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '',
		];

		Logger::info( sprintf( 'Remote sync triggered successfully. Job ID: %d, Status: %s', $result['job_id'], $result['status'] ), 'api_client' );

		return $result;
	}

	/**
	 * Check health status of the SoundImports Scraper FastAPI endpoint.
	 * Endpoint: GET health
	 *
	 * @return array { 'status' => string }
	 */
	public function check_health() {
		$this->load_settings();

		Logger::info( 'Checking health status of SoundImports API...', 'api_client' );

		$response = $this->request( 'health', 'GET' );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'Health check failed: %s', $response->get_error_message() ), 'api_client' );
			return [ 'status' => 'error' ];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			Logger::error( 'Invalid JSON returned from health check API.', 'api_client', [ 'body' => substr( $body, 0, 500 ) ] );
			return [ 'status' => 'error' ];
		}

		$status = sanitize_text_field( $data['status'] );
		Logger::info( sprintf( 'Health check status: %s', $status ), 'api_client' );

		return [ 'status' => $status ];
	}
}
