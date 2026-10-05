<?php
namespace WCErpSync\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Database\MappingTable;

/**
 * Maps standard WooCommerce WC_Order instances to external ERP JSON structures.
 */
class OrderMapper {

	/**
	 * Map order items, shipping, addresses and totals to a common ERP array.
	 *
	 * @param \WC_Order $order WC Order Instance.
	 * @return array
	 */
	public static function to_api_format( \WC_Order $order ) {
		$items = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$product      = $item->get_product();
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			// Lookup mapped ERP SKU / ID
			$target_id   = $variation_id ? $variation_id : $product_id;
			$external_id = MappingTable::get_external_id( 'product', $target_id );
			if ( ! $external_id && $variation_id ) {
				$external_id = MappingTable::get_external_id( 'variation', $target_id );
			}

			$items[] = [
				'line_item_id' => $item_id,
				'local_id'     => $target_id,
				'external_id'  => $external_id ? $external_id : '',
				'sku'          => $product ? $product->get_sku() : '',
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'price'        => $item->get_subtotal() / max( 1, $item->get_quantity() ),
				'total'        => $item->get_total(),
				'tax'          => $item->get_total_tax(),
			];
		}

		return [
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'status'           => $order->get_status(),
			'currency'         => $order->get_currency(),
			'subtotal'         => $order->get_subtotal(),
			'total'            => $order->get_total(),
			'discount_total'   => $order->get_discount_total(),
			'shipping_total'   => $order->get_shipping_total(),
			'tax_total'        => $order->get_total_tax(),
			'payment_method'   => $order->get_payment_method(),
			'customer_id'      => $order->get_customer_id(),
			'customer_email'   => $order->get_billing_email(),
			'customer_phone'   => $order->get_billing_phone(),
			
			'billing_address'  => [
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'company'    => $order->get_billing_company(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			],
			
			'shipping_address' => [
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
				'company'    => $order->get_shipping_company(),
				'address_1'  => $order->get_shipping_address_1(),
				'address_2'  => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'postcode'   => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
			],
			
			'line_items'       => $items,
			'created_at'       => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
		];
	}
}
