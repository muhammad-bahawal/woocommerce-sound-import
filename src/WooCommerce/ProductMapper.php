<?php
namespace WCErpSync\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes raw ERP product payload fields into standard WooCommerce format.
 */
class ProductMapper {

	/**
	 * Map ERP raw fields to standard format expected by WooConnector.
	 */
	public static function to_wc_format( $raw_data ) {
		$defaults = [
			'external_id'       => '',
			'sku'               => '',
			'name'              => '',
			'brand'             => '',
			'description'       => '',
			'short_description' => '',
			'price'             => 0.00,
			'sale_price'        => '',
			'stock'             => null,
			'stock_status'      => 'instock',
			'category'          => '',
			'categories'        => [],
			'weight'            => 0,
			'dimensions'        => [
				'length' => 0,
				'width'  => 0,
				'height' => 0,
			],
			'attributes'        => [],
			'variations'        => [],
			'image_url'         => '',
			'gallery_images'    => [],
			'ean'               => '',
			'updated_at'        => '',
		];

		// Merge and parse
		$data = wp_parse_args( $raw_data, $defaults );

		if ( empty( $data['description'] ) && ! empty( $raw_data['long_description'] ) ) {
			$data['description'] = $raw_data['long_description'];
		}

		// Custom sanitizations
		$data['sku']               = sanitize_text_field( $data['sku'] );
		$data['name']              = sanitize_text_field( $data['name'] );
		$data['brand']             = sanitize_text_field( $data['brand'] );
		$data['short_description'] = wp_kses_post( $data['short_description'] );
		$data['ean']               = ! empty( $raw_data['ean'] ) ? sanitize_text_field( $raw_data['ean'] ) : ( ! empty( $data['ean'] ) ? sanitize_text_field( $data['ean'] ) : '' );

		// Format 2-column description (Left: Long Description, Right: Specifications)
		$specifications      = ! empty( $raw_data['specifications'] ) && is_array( $raw_data['specifications'] ) ? $raw_data['specifications'] : [];
		$ean                 = $data['ean'];
		$data['description'] = self::build_split_description_html( $data['description'], $specifications, $data['sku'], $ean );

		// Prefix brand to product name if brand exists and name does not already start with brand
		if ( ! empty( $data['brand'] ) && ! empty( $data['name'] ) ) {
			if ( stripos( $data['name'], $data['brand'] ) !== 0 ) {
				$data['name'] = trim( $data['brand'] . ' ' . $data['name'] );
			}
		}

		$data['price'] = floatval( $data['price'] );
		if ( ! empty( $data['sale_price'] ) ) {
			$data['sale_price'] = floatval( $data['sale_price'] );
		}

		// Stock quantity & status assignment
		$stock_val = null;
		if ( isset( $raw_data['stock'] ) && null !== $raw_data['stock'] && '' !== $raw_data['stock'] ) {
			$stock_val = max( 0, intval( $raw_data['stock'] ) );
		} elseif ( isset( $raw_data['stock_quantity'] ) && null !== $raw_data['stock_quantity'] && '' !== $raw_data['stock_quantity'] ) {
			$stock_val = max( 0, intval( $raw_data['stock_quantity'] ) );
		} elseif ( isset( $raw_data['quantity'] ) && null !== $raw_data['quantity'] && '' !== $raw_data['quantity'] ) {
			$stock_val = max( 0, intval( $raw_data['quantity'] ) );
		} elseif ( isset( $raw_data['qty'] ) && null !== $raw_data['qty'] && '' !== $raw_data['qty'] ) {
			$stock_val = max( 0, intval( $raw_data['qty'] ) );
		} elseif ( isset( $data['stock'] ) && null !== $data['stock'] && '' !== $data['stock'] ) {
			$stock_val = max( 0, intval( $data['stock'] ) );
		}

		// Standardize WooCommerce stock_status
		$raw_status = ! empty( $raw_data['stock_status'] ) ? strtolower( trim( (string) $raw_data['stock_status'] ) ) : ( ! empty( $data['stock_status'] ) ? strtolower( trim( (string) $data['stock_status'] ) ) : '' );
		if ( in_array( $raw_status, [ 'on_backorder', 'onbackorder', 'backorder' ], true ) ) {
			$data['stock_status'] = 'onbackorder';
		} elseif ( in_array( $raw_status, [ 'out_of_stock', 'outofstock', 'out of stock' ], true ) || ( null !== $stock_val && 0 === $stock_val ) ) {
			$data['stock_status'] = 'outofstock';
		} else {
			$data['stock_status'] = 'instock';
		}

		// Fallback stock quantity: If API returns null/empty stock for an in-stock product,
		// use the configured default stock quantity (default 10) to ensure stock management is tracked.
		if ( null === $stock_val && 'outofstock' !== $data['stock_status'] ) {
			$settings       = get_option( 'wc_erp_sync_settings', [] );
			$always_manage  = isset( $settings['always_manage_stock'] ) ? $settings['always_manage_stock'] : 'yes';
			$default_qty    = isset( $settings['default_stock_qty'] ) ? intval( $settings['default_stock_qty'] ) : 10;

			if ( 'yes' === $always_manage && $default_qty > 0 ) {
				$stock_val = $default_qty;
			}
		}

		$data['stock'] = $stock_val;

		if ( ! empty( $data['gallery_images'] ) && is_array( $data['gallery_images'] ) ) {
			$clean_gallery = [];
			foreach ( $data['gallery_images'] as $g_url ) {
				if ( is_string( $g_url ) && ! empty( $g_url ) ) {
					$clean_gallery[] = esc_url_raw( $g_url );
				}
			}
			$data['gallery_images'] = $clean_gallery;
		} else {
			$data['gallery_images'] = [];
		}

		return $data;
	}

	/**
	 * Build 2-column responsive layout for WooCommerce Product Description.
	 * Left Column: Long Description (Highlights, Product Details)
	 * Right Column: Specifications Table
	 */
	public static function build_split_description_html( $long_description, $specifications = [], $sku = '', $ean = '' ) {
		$clean_desc = wp_kses_post( trim( $long_description ) );

		$specs_rows_html = '';

		// Add Article Number (SKU) if present
		if ( ! empty( $sku ) ) {
			$specs_rows_html .= sprintf(
				'<tr style="border-bottom: 1px solid rgba(128,128,128,0.15);"><td style="padding: 10px 12px; font-weight: 600;">%s</td><td style="padding: 10px 12px; text-align: right;">%s</td></tr>',
				esc_html__( 'Article number', 'wc-erp-sync' ),
				esc_html( $sku )
			);
		}

		// Add EAN if present
		if ( ! empty( $ean ) ) {
			$specs_rows_html .= sprintf(
				'<tr style="border-bottom: 1px solid rgba(128,128,128,0.15);"><td style="padding: 10px 12px; font-weight: 600;">%s</td><td style="padding: 10px 12px; text-align: right;">%s</td></tr>',
				esc_html__( 'EAN', 'wc-erp-sync' ),
				esc_html( $ean )
			);
		}

		// Add API Specifications
		if ( ! empty( $specifications ) && is_array( $specifications ) ) {
			foreach ( $specifications as $spec ) {
				$name  = is_array( $spec ) && ! empty( $spec['name'] ) ? trim( $spec['name'] ) : '';
				$value = is_array( $spec ) && ! empty( $spec['value'] ) ? trim( $spec['value'] ) : '';

				if ( ! empty( $name ) && ! empty( $value ) ) {
					$specs_rows_html .= sprintf(
						'<tr style="border-bottom: 1px solid rgba(128,128,128,0.15);"><td style="padding: 10px 12px; font-weight: 600;">%s</td><td style="padding: 10px 12px; text-align: right;">%s</td></tr>',
						esc_html( $name ),
						esc_html( $value )
					);
				}
			}
		}

		// If no specs exist, return standard description
		if ( empty( $specs_rows_html ) ) {
			return $clean_desc;
		}

		// Wrap in 2-column flexbox container
		$html  = '<div class="si-product-layout-container" style="display: flex; flex-wrap: wrap; gap: 32px; width: 100%; margin-top: 10px;">';
		$html .= '  <div class="si-layout-left-col" style="flex: 1 1 58%; min-width: 300px;">' . $clean_desc . '</div>';
		$html .= '  <div class="si-layout-right-col" style="flex: 1 1 36%; min-width: 280px; background: rgba(128,128,128,0.06); border: 1px solid rgba(128,128,128,0.18); border-radius: 8px; padding: 20px; align-self: flex-start;">';
		$html .= '    <h3 class="si-specs-heading" style="margin-top: 0; margin-bottom: 16px; font-size: 18px; font-weight: 700; border-bottom: 2px solid #f59e0b; padding-bottom: 8px;">' . esc_html__( 'Specifications', 'wc-erp-sync' ) . '</h3>';
		$html .= '    <table class="si-specs-table" style="width: 100%; border-collapse: collapse; font-size: 14px;"><tbody>' . $specs_rows_html . '</tbody></table>';
		$html .= '  </div>';
		$html .= '</div>';

		return $html;
	}
}
