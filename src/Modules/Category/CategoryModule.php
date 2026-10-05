<?php
namespace WCErpSync\Modules\Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WCErpSync\Modules\BaseModule;
use WCErpSync\WooCommerce\WooConnector;

/**
 * Handles Category Taxonomy Sync.
 */
class CategoryModule extends BaseModule {

	/**
	 * Categories map cache.
	 *
	 * @var array
	 */
	protected $categories_by_id = [];

	/**
	 * Import stack tracker to prevent infinite loops.
	 *
	 * @var array
	 */
	protected $importing_ids = [];

	public function get_entity_name() {
		return 'category';
	}

	public function get_title() {
		return __( 'Categories', 'wc-erp-sync' );
	}

	/**
	 * Main batch processor.
	 */
	public function import_all_categories( $categories ) {
		$this->categories_by_id = [];
		foreach ( $categories as $cat ) {
			// SoundImports API returns numeric id. Map to string external key.
			$id = isset( $cat['id'] ) ? (string) $cat['id'] : '';
			if ( ! empty( $id ) ) {
				$this->categories_by_id[ $id ] = $cat;
			}
		}

		$success_count = 0;
		$this->importing_ids = [];

		foreach ( $categories as $cat ) {
			$id = isset( $cat['id'] ) ? (string) $cat['id'] : '';
			if ( ! empty( $id ) ) {
				$local_id = $this->import_category_recursive( $id );
				if ( $local_id ) {
					$success_count++;
				}
			}
		}

		return $success_count;
	}

	/**
	 * Recursive resolver.
	 */
	protected function import_category_recursive( $ext_id ) {
		$ext_id = (string) $ext_id;

		// Prevent loops
		if ( in_array( $ext_id, $this->importing_ids, true ) ) {
			return false;
		}

		// Check if already mapped
		$local_id = $this->get_local_id( $ext_id );
		if ( $local_id && term_exists( $local_id, 'product_cat' ) ) {
			return $local_id;
		}

		if ( ! isset( $this->categories_by_id[ $ext_id ] ) ) {
			return false;
		}

		$cat_data = $this->categories_by_id[ $ext_id ];
		$this->importing_ids[] = $ext_id;

		$parent_local_id = 0;
		if ( ! empty( $cat_data['parent_id'] ) ) {
			$parent_ext_id   = (string) $cat_data['parent_id'];
			$parent_local_id = $this->import_category_recursive( $parent_ext_id );
		}

		$term_name = sanitize_text_field( $cat_data['name'] );
		$term_slug = isset( $cat_data['slug'] ) ? sanitize_title( $cat_data['slug'] ) : sanitize_title( $term_name );

		$term = get_term_by( 'slug', $term_slug, 'product_cat' );
		if ( $term ) {
			// Update parent if it changed
			if ( intval( $term->parent ) !== intval( $parent_local_id ) ) {
				wp_update_term( $term->term_id, 'product_cat', [ 'parent' => intval( $parent_local_id ) ] );
			}
			$local_id = (int) $term->term_id;
		} else {
			$inserted = wp_insert_term( $term_name, 'product_cat', [
				'slug'   => $term_slug,
				'parent' => intval( $parent_local_id ),
			] );

			if ( ! is_wp_error( $inserted ) ) {
				$local_id = intval( $inserted['term_id'] );
			} else {
				$this->log( 'error', sprintf( 'Failed to create category %s: %s', $term_name, $inserted->get_error_message() ) );
				$local_id = false;
			}
		}

		if ( $local_id ) {
			$this->add_mapping( $ext_id, $local_id, null, 'success' );
			$this->log( 'info', sprintf( 'Imported Category: %s (ERP ID: %s, Local ID: %d, Parent ID: %d)', $term_name, $ext_id, $local_id, $parent_local_id ) );
		}

		// Remove from stack
		$idx = array_search( $ext_id, $this->importing_ids, true );
		if ( $idx !== false ) {
			unset( $this->importing_ids[ $idx ] );
		}

		return $local_id;
	}

	/**
	 * Single queue item import fallback.
	 */
	public function import_item( $raw_data ) {
		$ext_id = '';
		if ( isset( $raw_data['id'] ) ) {
			$ext_id = (string) $raw_data['id'];
		} elseif ( isset( $raw_data['external_id'] ) ) {
			$ext_id = (string) $raw_data['external_id'];
		}

		if ( empty( $ext_id ) || empty( $raw_data['name'] ) ) {
			return false;
		}

		if ( empty( $this->categories_by_id[ $ext_id ] ) ) {
			$this->categories_by_id[ $ext_id ] = [
				'id'        => $ext_id,
				'parent_id' => isset( $raw_data['parent_id'] ) ? $raw_data['parent_id'] : null,
				'name'      => $raw_data['name'],
				'slug'      => isset( $raw_data['slug'] ) ? $raw_data['slug'] : '',
			];
		}

		return $this->import_category_recursive( $ext_id );
	}

	public function export_item( $local_id ) {
		$term = get_term( $local_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		return [
			'external_id' => $this->get_external_id( $local_id ),
			'name'        => $term->name,
			'parent_id'   => $term->parent ? $this->get_external_id( $term->parent ) : null,
		];
	}
}
