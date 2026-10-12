<?php
namespace WCErpSync\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Abstraction connector. Wrap native WC functions to prevent directDB updates.
 */
class WooConnector {

	/**
	 * Create or Update WooCommerce Product.
	 */
	public static function upsert_product( $data ) {
		$sku = isset( $data['sku'] ) ? sanitize_text_field( $data['sku'] ) : '';
		if ( empty( $sku ) ) {
			return false;
		}

		$product_id = wc_get_product_id_by_sku( $sku );
		$is_new     = ! $product_id;

		// Support variations: default to variable if variation array is populated
		$type = 'simple';
		if ( ! empty( $data['variations'] ) ) {
			$type = 'variable';
		}

		if ( $is_new ) {
			if ( 'variable' === $type ) {
				$product = new \WC_Product_Variable();
			} else {
				$product = new \WC_Product_Simple();
			}
			$product->set_sku( $sku );
		} else {
			$product = wc_get_product( $product_id );
		}

		if ( ! $product ) {
			return false;
		}

		// Ensure WooCommerce global inventory management is enabled
		if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
			update_option( 'woocommerce_manage_stock', 'yes' );
		}

		// Apply fields
		$product->set_name( sanitize_text_field( $data['name'] ) );
		$product->set_description( wp_kses_post( $data['description'] ) );
		if ( isset( $data['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $data['short_description'] ) );
		}
		$product->set_status( 'publish' );

		// Set EAN / GTIN if available
		if ( ! empty( $data['ean'] ) ) {
			$ean_clean = sanitize_text_field( $data['ean'] );
			if ( method_exists( $product, 'set_global_unique_id' ) ) {
				$product->set_global_unique_id( $ean_clean );
			}
		}

		// Set dimensions
		if ( isset( $data['weight'] ) ) {
			$product->set_weight( floatval( $data['weight'] ) );
		}
		if ( isset( $data['dimensions'] ) ) {
			$product->set_length( floatval( $data['dimensions']['length'] ) );
			$product->set_width( floatval( $data['dimensions']['width'] ) );
			$product->set_height( floatval( $data['dimensions']['height'] ) );
		}

		// Ensure WooCommerce global stock management option is enabled
		if ( 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
			update_option( 'woocommerce_manage_stock', 'yes' );
		}

		// Inventory / Stock Quantity & Status handling
		$has_stock_val = isset( $data['stock'] ) && null !== $data['stock'] && '' !== $data['stock'];
		$passed_status = ! empty( $data['stock_status'] ) ? strtolower( trim( (string) $data['stock_status'] ) ) : '';
		$is_backorder  = in_array( $passed_status, [ 'on_backorder', 'onbackorder', 'backorder' ], true );

		if ( $has_stock_val ) {
			$stock_quantity = max( 0, intval( $data['stock'] ) );
			if ( $is_backorder ) {
				$stock_status  = 'onbackorder';
				$backorder_val = 'notify';
			} elseif ( $stock_quantity > 0 ) {
				$stock_status  = 'instock';
				$backorder_val = 'no';
			} else {
				$stock_status  = 'outofstock';
				$backorder_val = 'no';
			}
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_quantity );
			$product->set_stock_status( $stock_status );
			$product->set_backorders( $backorder_val );
		} elseif ( ! empty( $passed_status ) ) {
			if ( $is_backorder ) {
				$status_val    = 'onbackorder';
				$backorder_val = 'notify';
			} elseif ( in_array( $passed_status, [ 'out_of_stock', 'outofstock', 'out of stock' ], true ) ) {
				$status_val    = 'outofstock';
				$backorder_val = 'no';
			} else {
				$status_val    = 'instock';
				$backorder_val = 'no';
			}
			$product->set_manage_stock( false );
			$product->set_stock_status( $status_val );
			$product->set_backorders( $backorder_val );
		}

		if ( 'simple' === $product->get_type() ) {
			// Prices
			if ( isset( $data['price'] ) ) {
				$product->set_regular_price( floatval( $data['price'] ) );
			}
			if ( isset( $data['sale_price'] ) && ! empty( $data['sale_price'] ) ) {
				$product->set_sale_price( floatval( $data['sale_price'] ) );
			}
		}

		// Set categories & hierarchy
		$category_ids = [];
		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$parent_term_id = 0;
			foreach ( $data['categories'] as $cat_item ) {
				$cat_name = '';
				if ( is_array( $cat_item ) ) {
					$cat_name = ! empty( $cat_item['name'] ) ? $cat_item['name'] : ( ! empty( $cat_item['title'] ) ? $cat_item['title'] : '' );
				} elseif ( is_string( $cat_item ) ) {
					$cat_name = $cat_item;
				}
				$cat_name = trim( $cat_name );
				if ( empty( $cat_name ) ) {
					continue;
				}

				$term_id = self::upsert_term( $cat_name, 'product_cat', [ 'parent_id' => $parent_term_id ] );
				if ( $term_id ) {
					$category_ids[] = (int) $term_id;
					$parent_term_id = (int) $term_id; // Set as parent for next child in hierarchy
				}
			}
		} elseif ( ! empty( $data['category'] ) ) {
			$cat_id = self::upsert_term( $data['category'], 'product_cat' );
			if ( $cat_id ) {
				$category_ids[] = (int) $cat_id;
			}
		}

		if ( ! empty( $category_ids ) ) {
			$product->set_category_ids( array_values( array_unique( $category_ids ) ) );
		}

		// Set attributes
		if ( ! empty( $data['attributes'] ) ) {
			$wc_attrs = [];
			foreach ( $data['attributes'] as $attr_name => $attr_value ) {
				$slug     = sanitize_title( $attr_name );
				$taxonomy = wc_attribute_taxonomy_name( $slug );

				// Ensure attribute is registered
				self::upsert_attribute( $slug, $attr_name );
				self::upsert_term( $attr_value, $taxonomy );

				$attribute_object = new \WC_Product_Attribute();
				$attribute_object->set_name( $taxonomy );
				$attribute_object->set_options( [ $attr_value ] );
				$attribute_object->set_visible( true );
				
				if ( 'variable' === $type ) {
					$attribute_object->set_variation( true );
				}

				$wc_attrs[ $taxonomy ] = $attribute_object;
			}
			$product->set_attributes( $wc_attrs );
		}

		// Handle Remote featured image sideloading before primary save
		$featured_att_id = 0;
		if ( ! empty( $data['image_url'] ) ) {
			$featured_att_id = self::sideload_image( $data['image_url'], $product_id ? $product_id : 0 );
			if ( $featured_att_id ) {
				$product->set_image_id( $featured_att_id );
				\WCErpSync\Logger\Logger::info( sprintf( 'Featured image assigned for SKU %s (Attachment ID: %d)', $sku, $featured_att_id ), 'image' );
			}
		}

		// Handle Product Gallery sideloading before primary save (cap to first 3 gallery images to prevent server timeouts)
		if ( ! empty( $data['gallery_images'] ) && is_array( $data['gallery_images'] ) ) {
			$gallery_att_ids = [];
			$gallery_slice   = array_slice( $data['gallery_images'], 0, 3 );
			foreach ( $gallery_slice as $gallery_url ) {
				$att_id = self::sideload_image( $gallery_url, $product_id ? $product_id : 0 );
				if ( $att_id && intval( $att_id ) !== intval( $featured_att_id ) && ! in_array( $att_id, $gallery_att_ids, true ) ) {
					$gallery_att_ids[] = (int) $att_id;
					\WCErpSync\Logger\Logger::info( sprintf( 'Gallery image assigned for SKU %s (Attachment ID: %d)', $sku, $att_id ), 'image' );
				}
			}
			$product->set_gallery_image_ids( $gallery_att_ids );
		}

		// Re-assert stock property prior to save
		if ( $has_stock_val ) {
			$stock_quantity = max( 0, intval( $data['stock'] ) );
			if ( $is_backorder ) {
				$stock_status  = 'onbackorder';
				$backorder_val = 'notify';
			} elseif ( $stock_quantity > 0 ) {
				$stock_status  = 'instock';
				$backorder_val = 'no';
			} else {
				$stock_status  = 'outofstock';
				$backorder_val = 'no';
			}
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_quantity );
			$product->set_stock_status( $stock_status );
			$product->set_backorders( $backorder_val );
		}

		$product_id = $product->save();

		// Post-save: Enforce postmeta, WooCommerce stock tracking, and lookup table synchronization
		if ( $product_id ) {
			if ( $has_stock_val ) {
				$stock_quantity = max( 0, intval( $data['stock'] ) );
				if ( $is_backorder ) {
					$stock_status  = 'onbackorder';
					$backorder_val = 'notify';
				} elseif ( $stock_quantity > 0 ) {
					$stock_status  = 'instock';
					$backorder_val = 'no';
				} else {
					$stock_status  = 'outofstock';
					$backorder_val = 'no';
				}

				update_post_meta( $product_id, '_manage_stock', 'yes' );
				update_post_meta( $product_id, '_stock', $stock_quantity );
				update_post_meta( $product_id, '_stock_status', $stock_status );
				update_post_meta( $product_id, '_backorders', $backorder_val );

				global $wpdb;
				if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
					$wpdb->update(
						$wpdb->wc_product_meta_lookup,
						[
							'stock_quantity' => $stock_quantity,
							'stock_status'   => $stock_status,
						],
						[ 'product_id' => $product_id ]
					);
				}

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $product_id );
				}
				clean_post_cache( $product_id );
			} elseif ( ! empty( $passed_status ) ) {
				if ( $is_backorder ) {
					$status_val    = 'onbackorder';
					$backorder_val = 'notify';
				} elseif ( in_array( $passed_status, [ 'out_of_stock', 'outofstock', 'out of stock' ], true ) ) {
					$status_val    = 'outofstock';
					$backorder_val = 'no';
				} else {
					$status_val    = 'instock';
					$backorder_val = 'no';
				}
				update_post_meta( $product_id, '_manage_stock', 'no' );
				update_post_meta( $product_id, '_stock_status', $status_val );
				update_post_meta( $product_id, '_backorders', $backorder_val );
				delete_post_meta( $product_id, '_stock' );

				global $wpdb;
				if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
					$wpdb->update(
						$wpdb->wc_product_meta_lookup,
						[
							'stock_quantity' => null,
							'stock_status'   => $status_val,
						],
						[ 'product_id' => $product_id ]
					);
				}

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $product_id );
				}
				clean_post_cache( $product_id );
			}

			if ( ! empty( $data['ean'] ) ) {
				$ean_clean = sanitize_text_field( $data['ean'] );
				update_post_meta( $product_id, '_global_unique_id', $ean_clean );
				update_post_meta( $product_id, '_ean', $ean_clean );
				update_post_meta( $product_id, 'ean', $ean_clean );
				update_post_meta( $product_id, '_barcode', $ean_clean );
				update_post_meta( $product_id, '_gtin', $ean_clean );
				update_post_meta( $product_id, '_woodmart_gtin', $ean_clean );
			}
		}

		// Set brand term if provided across all possible brand taxonomies (WoodMart product_brand, PWB pwb-brand, brand, pa_brand)
		if ( ! empty( $data['brand'] ) && $product_id ) {
			$brand_taxonomies = [ 'product_brand', 'pwb-brand', 'brand', 'pa_brand' ];
			foreach ( $brand_taxonomies as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) || strpos( $taxonomy, 'pa_' ) === 0 ) {
					$brand_id = self::upsert_term( $data['brand'], $taxonomy );
					if ( $brand_id ) {
						wp_set_object_terms( $product_id, (int) $brand_id, $taxonomy );
					}
				}
			}
		}

		// Create/Update Variations if Variable
		if ( 'variable' === $type && ! empty( $data['variations'] ) ) {
			foreach ( $data['variations'] as $var_data ) {
				self::upsert_variation( $product_id, $var_data );
			}
		}

		return $product_id;
	}

	/**
	 * Insert or update custom variations.
	 */
	public static function upsert_variation( $parent_id, $data ) {
		$sku = isset( $data['sku'] ) ? sanitize_text_field( $data['sku'] ) : '';
		if ( empty( $sku ) ) {
			return false;
		}

		$variation_id = wc_get_product_id_by_sku( $sku );
		$is_new       = ! $variation_id;

		if ( $is_new ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_sku( $sku );
		} else {
			$variation = new \WC_Product_Variation( $variation_id );
		}

		$variation->set_status( 'publish' );

		if ( isset( $data['price'] ) ) {
			$variation->set_regular_price( floatval( $data['price'] ) );
		}
		if ( isset( $data['sale_price'] ) ) {
			$variation->set_sale_price( floatval( $data['sale_price'] ) );
		}

		$has_var_stock = isset( $data['stock'] ) && null !== $data['stock'] && '' !== $data['stock'];
		if ( $has_var_stock ) {
			$stock_quantity = max( 0, intval( $data['stock'] ) );
			$stock_status   = $stock_quantity > 0 ? 'instock' : 'outofstock';
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $stock_quantity );
			$variation->set_stock_status( $stock_status );
			$variation->set_backorders( 'no' );
		} elseif ( isset( $data['stock_status'] ) && ! empty( $data['stock_status'] ) ) {
			$raw_status = strtolower( trim( (string) $data['stock_status'] ) );
			$status_val = in_array( $raw_status, [ 'out_of_stock', 'outofstock', 'out of stock' ], true ) ? 'outofstock' : 'instock';
			$variation->set_manage_stock( false );
			$variation->set_stock_status( $status_val );
		}

		// Map attributes for variation: e.g. Color => Red, Size => L
		if ( ! empty( $data['attributes'] ) ) {
			$variation_attrs = [];
			foreach ( $data['attributes'] as $attr_name => $attr_value ) {
				$taxonomy = wc_attribute_taxonomy_name( sanitize_title( $attr_name ) );
				$variation_attrs[ $taxonomy ] = sanitize_title( $attr_value );
			}
			$variation->set_attributes( $variation_attrs );
		}

		$var_saved_id = $variation->save();

		if ( $var_saved_id && $has_var_stock ) {
			$stock_quantity = max( 0, intval( $data['stock'] ) );
			$stock_status   = $stock_quantity > 0 ? 'instock' : 'outofstock';

			update_post_meta( $var_saved_id, '_manage_stock', 'yes' );
			update_post_meta( $var_saved_id, '_stock', $stock_quantity );
			update_post_meta( $var_saved_id, '_stock_status', $stock_status );
			update_post_meta( $var_saved_id, '_backorders', 'no' );

			global $wpdb;
			if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
				$wpdb->update(
					$wpdb->wc_product_meta_lookup,
					[
						'stock_quantity' => $stock_quantity,
						'stock_status'   => $stock_status,
					],
					[ 'product_id' => $var_saved_id ]
				);
			}

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $var_saved_id );
				wc_delete_product_transients( $parent_id );
			}
			clean_post_cache( $var_saved_id );
			clean_post_cache( $parent_id );
		}

		return $var_saved_id;
	}

	/**
	 * Safe upsert of custom Taxonomy Terms.
	 */
	public static function upsert_term( $name, $taxonomy, $args = [] ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// If attribute, register dynamically
			if ( strpos( $taxonomy, 'pa_' ) === 0 ) {
				$slug = substr( $taxonomy, 3 );
				self::upsert_attribute( $slug, ucwords( str_replace( '-', ' ', $slug ) ) );
			} else {
				return false;
			}
		}

		$parent_id = 0;
		if ( ! empty( $args['parent_id'] ) ) {
			if ( is_numeric( $args['parent_id'] ) ) {
				$parent_id = intval( $args['parent_id'] );
			} else {
				// Lookup parent ID mapping
				global $wpdb;
				$parent_mapped = $wpdb->get_var( $wpdb->prepare(
					"SELECT local_id FROM {$wpdb->prefix}wc_erp_sync_mappings WHERE entity_type = 'category' AND external_id = %s",
					$args['parent_id']
				) );
				if ( $parent_mapped ) {
					$parent_id = intval( $parent_mapped );
				}
			}
		}

		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term ) {
			if ( $parent_id > 0 && 0 === (int) $term->parent ) {
				wp_update_term( (int) $term->term_id, $taxonomy, [ 'parent' => $parent_id ] );
			}
			return (int) $term->term_id;
		}

		$inserted = wp_insert_term( $name, $taxonomy, [ 'parent' => $parent_id ] );
		if ( is_wp_error( $inserted ) ) {
			return false;
		}

		return (int) $inserted['term_id'];
	}

	/**
	 * Manage WooCommerce Stock update.
	 */
	public static function update_stock( $product_id, $qty ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$stock_quantity = max( 0, intval( $qty ) );
		$stock_status   = $stock_quantity > 0 ? 'instock' : 'outofstock';

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock_quantity );
		$product->set_stock_status( $stock_status );
		$product->set_backorders( 'no' );
		$saved = $product->save();

		update_post_meta( $product_id, '_manage_stock', 'yes' );
		update_post_meta( $product_id, '_stock', $stock_quantity );
		update_post_meta( $product_id, '_stock_status', $stock_status );
		update_post_meta( $product_id, '_backorders', 'no' );
		update_post_meta( $product_id, '_erp_sync_last_stock_update', time() );

		// Touch stock update timestamp in MappingTable
		\WCErpSync\Database\MappingTable::touch_stock_update( $product_id );

		global $wpdb;
		if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
			$wpdb->update(
				$wpdb->wc_product_meta_lookup,
				[
					'stock_quantity' => $stock_quantity,
					'stock_status'   => $stock_status,
				],
				[ 'product_id' => $product_id ]
			);
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );

		return $saved;
	}

	/**
	 * Manage WooCommerce Price update.
	 */
	public static function update_prices( $product_id, $regular, $sale = '' ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$product->set_regular_price( floatval( $regular ) );
		if ( ! empty( $sale ) ) {
			$product->set_sale_price( floatval( $sale ) );
		} else {
			$product->set_sale_price( '' );
		}

		return $product->save();
	}

	/**
	 * Safe upsert of WooCommerce Global Product Attributes.
	 */
	public static function upsert_attribute( $slug, $name ) {
		global $wpdb;

		// Limit size
		$slug = substr( $slug, 0, 28 );

		$attribute_id = wc_attribute_taxonomy_id_by_name( $slug );
		if ( $attribute_id ) {
			return $attribute_id;
		}

		$result = wc_create_attribute( [
			'name'         => $name,
			'slug'         => $slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		] );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		// Flush taxonomies cache
		delete_transient( 'wc_attribute_taxonomies' );

		return $result;
	}

	/**
	 * Register/Upsert Customer.
	 */
	public static function upsert_customer( $data ) {
		$email = sanitize_email( $data['email'] );
		$user  = get_user_by( 'email', $email );

		if ( $user ) {
			$user_id = $user->ID;
		} else {
			$username = sanitize_user( current( explode( '@', $email ) ) );
			// Check user unique
			$base_username = $username;
			$count = 1;
			while ( username_exists( $username ) ) {
				$username = $base_username . $count;
				$count++;
			}

			$password = wp_generate_password();
			$user_id  = wp_create_user( $username, $password, $email );

			if ( is_wp_error( $user_id ) ) {
				return false;
			}

			$user_obj = new \WP_User( $user_id );
			$user_obj->set_role( 'customer' );
		}

		// Update profile details
		if ( isset( $data['first_name'] ) ) {
			update_user_meta( $user_id, 'first_name', sanitize_text_field( $data['first_name'] ) );
			update_user_meta( $user_id, 'billing_first_name', sanitize_text_field( $data['first_name'] ) );
		}
		if ( isset( $data['last_name'] ) ) {
			update_user_meta( $user_id, 'last_name', sanitize_text_field( $data['last_name'] ) );
			update_user_meta( $user_id, 'billing_last_name', sanitize_text_field( $data['last_name'] ) );
		}

		// Billing meta details
		if ( ! empty( $data['billing'] ) ) {
			foreach ( $data['billing'] as $key => $val ) {
				update_user_meta( $user_id, 'billing_' . $key, sanitize_text_field( $val ) );
			}
		}

		return $user_id;
	}

	/**
	 * Update WooCommerce order statuses or metadata.
	 */
	public static function update_order_status( $order_id, $data ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		if ( ! empty( $data['status'] ) ) {
			$order->update_status( $data['status'], sprintf( __( 'Status updated via ERP sync. ERP ID: %s', 'wc-erp-sync' ), $data['external_id'] ) );
		}

		if ( ! empty( $data['tracking_number'] ) ) {
			$order->update_meta_data( '_shipping_tracking_number', sanitize_text_field( $data['tracking_number'] ) );
			$order->add_order_note( sprintf( __( 'Shipping tracking number updated: %s', 'wc-erp-sync' ), $data['tracking_number'] ) );
		}

		return $order->save();
	}

	/**
	 * Downloads and attaches remote images with duplicate detection and audit logging.
	 */
	public static function sideload_image( $image_url, $post_id = 0, $ext_id = null ) {
		if ( empty( $image_url ) || ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			\WCErpSync\Logger\Logger::warning( sprintf( 'Invalid image URL skipped: %s', $image_url ), 'image' );
			return false;
		}

		if ( empty( $ext_id ) ) {
			$ext_id = md5( $image_url );
		}

		// 1. Deduplication Check via MappingTable
		$existing_att_id = \WCErpSync\Database\MappingTable::get_local_id( 'image', $ext_id );
		if ( $existing_att_id && wp_attachment_is_image( $existing_att_id ) ) {
			\WCErpSync\Logger\Logger::info( sprintf( 'Duplicate image skipped (reused existing attachment ID %d for URL: %s)', $existing_att_id, $image_url ), 'image' );
			return (int) $existing_att_id;
		}

		// Extract path parts to build unique filename (prevent generic 'image.jpg' collisions)
		$path_parts  = explode( '/', trim( parse_url( $image_url, PHP_URL_PATH ), '/' ) );
		$files_index = array_search( 'files', $path_parts, true );
		$file_id     = '';

		if ( false !== $files_index && isset( $path_parts[ $files_index + 1 ] ) && is_numeric( $path_parts[ $files_index + 1 ] ) ) {
			$file_id = $path_parts[ $files_index + 1 ];
		}

		$base_name = basename( parse_url( $image_url, PHP_URL_PATH ) );
		if ( empty( $base_name ) || 'image.jpg' === $base_name ) {
			$base_name = 'image.jpg';
		}

		if ( ! empty( $file_id ) ) {
			$unique_filename = $file_id . '_' . $base_name;
		} else {
			$unique_filename = substr( $ext_id, 0, 10 ) . '_' . $base_name;
		}

		// 2. Fallback Deduplication Check in Media Library by unique filename
		if ( ! empty( $unique_filename ) ) {
			global $wpdb;
			$found_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $unique_filename )
			) );

			if ( $found_id && wp_attachment_is_image( $found_id ) ) {
				\WCErpSync\Database\MappingTable::add_mapping( 'image', $ext_id, $found_id, null, 'success' );
				\WCErpSync\Logger\Logger::info( sprintf( 'Duplicate image skipped (reused media library attachment ID %d for image: %s)', $found_id, $image_url ), 'image' );
				return (int) $found_id;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Download file with 15s network timeout
		$tmp = download_url( $image_url, 15 );

		if ( is_wp_error( $tmp ) ) {
			\WCErpSync\Logger\Logger::error( sprintf( 'Image download failed for URL %s: %s', $image_url, $tmp->get_error_message() ), 'image' );
			return false;
		}

		$file_array = [
			'name'     => $unique_filename,
			'tmp_name' => $tmp,
		];

		$desc = "Sideloaded ERP image";

		// Performance boost: temporarily filter out excessive theme thumbnail sizes (e.g. WoodMart 10+ sizes)
		// during bulk sync so image processing is 3-4x faster without PHP timeouts
		$size_filter = function( $sizes ) {
			if ( ! is_array( $sizes ) ) {
				return $sizes;
			}
			$core_needed = [ 'thumbnail', 'medium', 'woocommerce_thumbnail', 'woocommerce_single' ];
			return array_intersect_key( $sizes, array_flip( $core_needed ) );
		};
		add_filter( 'intermediate_image_sizes_advanced', $size_filter, 999 );

		$attachment_id = media_handle_sideload( $file_array, $post_id, $desc );

		remove_filter( 'intermediate_image_sizes_advanced', $size_filter, 999 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $file_array['tmp_name'] );
			\WCErpSync\Logger\Logger::error( sprintf( 'Media handle sideload failed for URL %s: %s', $image_url, $attachment_id->get_error_message() ), 'image' );
			return false;
		}

		\WCErpSync\Database\MappingTable::add_mapping( 'image', $ext_id, $attachment_id, null, 'success' );
		\WCErpSync\Logger\Logger::info( sprintf( 'Successfully downloaded and sideloaded remote image. Local Attachment ID: %d (Filename: %s)', $attachment_id, $unique_filename ), 'image' );

		return (int) $attachment_id;
	}
}
