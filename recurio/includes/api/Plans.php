<?php
namespace Recurio\Api;

use WP_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_Query;

/**
 * Plans REST API
 *
 * @package Recurio
 * @since   1.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plans {

	private $namespace = 'recurio/v1';

	/**
	 * Priority values for how a product was linked to a plan.
	 * Higher number = higher priority (wins in conflicts).
	 */
	const LINK_PRIORITY = array(
		'category' => 1,
		'tag'      => 2,
		'direct'   => 3,
	);

	public function register_hooks() {
		// Auto-stamp scoped plans when a product is saved / category changes.
		add_action( 'save_post_product', array( $this, 'maybe_auto_stamp_product' ), 20, 1 );
	}

	public function register_routes() {
		// Collection: list + create.
		register_rest_route(
			$this->namespace,
			'/plans',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_plans' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
						'status'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'type'     => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'search'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_plan' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Single: read, update, delete.
		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_plan' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_plan' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'archive_plan' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Apply plan to an explicit list of product IDs.
		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_to_products' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Apply plan to all products matching its category/tag scope.
		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)/apply-scope',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_scope' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Preview how many products a plan's scope would cover.
		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)/scope-coverage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_scope_coverage' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// List products linked to a plan.
		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_linked_products' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'detach_product' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Per-plan analytics.
		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Duplicate.
		register_rest_route(
			$this->namespace,
			'/plans/(?P<id>[\d]+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate_plan' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Taxonomy search (product_cat / product_tag) for the scope picker UI.
		register_rest_route(
			$this->namespace,
			'/plans/taxonomy-search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_taxonomy' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'taxonomy' => array( 'default' => 'product_cat', 'sanitize_callback' => 'sanitize_text_field' ),
					'search'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	public function check_permission( $request ) {
		return current_user_can( 'manage_woocommerce' );
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	public function get_plans( $request ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'recurio_plans';
		$page     = max( 1, $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = $request->get_param( 'status' );
		$type     = $request->get_param( 'type' );
		$search   = $request->get_param( 'search' );

		$where  = array( '1=1' );
		$values = array();

		if ( $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		} else {
			$where[] = "status != 'archived'";
		}

		if ( $type ) {
			$where[]  = 'type = %s';
			$values[] = $type;
		}

		if ( $search ) {
			$where[]  = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$values
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$values )
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$values
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				? $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge( $values, array( $per_page, $offset ) ) )
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);

		$plans = array_map( array( $this, 'enrich_row' ), $rows );

		return rest_ensure_response(
			array(
				'items'       => $plans,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	public function get_plan( $request ) {
		$plan = $this->fetch_by_id( (int) $request['id'] );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->enrich_row( $plan ) );
	}

	public function create_plan( $request ) {
		global $wpdb;

		$data = $this->extract_plan_data( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['slug']       = $this->unique_slug( $data['name'] );
		$data['created_by'] = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $wpdb->prefix . 'recurio_plans', $data );
		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not save plan.', 'recurio' ), array( 'status' => 500 ) );
		}

		$plan = $this->fetch_by_id( $wpdb->insert_id );
		return rest_ensure_response( $this->enrich_row( $plan ) );
	}

	public function update_plan( $request ) {
		global $wpdb;

		$plan = $this->fetch_by_id( (int) $request['id'] );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}

		// Capture old scope before overwriting so we can detect removals.
		$old_settings = json_decode( $plan['settings'], true ) ?: array();
		$old_cat_ids  = array_map( 'absint', (array) ( $old_settings['scope_category_ids'] ?? array() ) );
		$old_tag_ids  = array_map( 'absint', (array) ( $old_settings['scope_tag_ids'] ?? array() ) );

		$data = $this->extract_plan_data( $request, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'recurio_plans',
			$data,
			array( 'id' => (int) $request['id'] )
		);

		// If scope settings were included in this update, clean up products whose
		// category/tag no longer matches the new scope (e.g. a category was removed).
		if ( isset( $data['settings'] ) ) {
			$new_settings = json_decode( $data['settings'], true ) ?: array();
			$new_cat_ids  = array_map( 'absint', (array) ( $new_settings['scope_category_ids'] ?? array() ) );
			$new_tag_ids  = array_map( 'absint', (array) ( $new_settings['scope_tag_ids'] ?? array() ) );

			$cats_changed = array_diff( $old_cat_ids, $new_cat_ids ) || array_diff( $new_cat_ids, $old_cat_ids );
			$tags_changed = array_diff( $old_tag_ids, $new_tag_ids ) || array_diff( $new_tag_ids, $old_tag_ids );

			if ( $cats_changed || $tags_changed ) {
				$this->cleanup_stale_scope_products( (int) $request['id'], $new_cat_ids, $new_tag_ids );
			}
		}

		if ( filter_var( $request->get_param( 'propagate' ), FILTER_VALIDATE_BOOLEAN ) ) {
			$settings_json = isset( $data['settings'] ) ? $data['settings'] : $plan['settings'];
			$this->propagate_to_linked_products( (int) $request['id'], $settings_json );
		}

		// Handle plan enable/disable: sync _recurio_subscription_enabled on all linked products.
		if ( isset( $data['status'] ) && $data['status'] !== $plan['status'] ) {
			$linked_ids = $this->fetch_linked_product_ids( (int) $request['id'] );
			if ( 'active' === $data['status'] ) {
				// Plan re-enabled — turn subscription back on for all linked products.
				foreach ( $linked_ids as $pid ) {
					update_post_meta( (int) $pid, '_recurio_subscription_enabled', 'yes' );
				}
			} else {
				// Plan disabled (draft) — turn subscription off for all linked products.
				foreach ( $linked_ids as $pid ) {
					update_post_meta( (int) $pid, '_recurio_subscription_enabled', 'no' );
				}
			}
		}

		$plan = $this->fetch_by_id( (int) $request['id'] );
		return rest_ensure_response( $this->enrich_row( $plan ) );
	}

	public function archive_plan( $request ) {
		global $wpdb;

		$plan_id = (int) $request['id'];
		$plan    = $this->fetch_by_id( $plan_id );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}

		// Fully unlink all linked products: remove plan meta + all stamped subscription settings.
		$linked_ids = $this->fetch_linked_product_ids( $plan_id );
		foreach ( $linked_ids as $pid ) {
			$this->clear_product_subscription_meta( $pid );
		}

		// Hard delete the plan row from the database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'recurio_plans',
			array( 'id' => $plan_id ),
			array( '%d' )
		);

		return rest_ensure_response( array( 'success' => true, 'id' => $plan_id ) );
	}

	/**
	 * Apply plan to an explicit array of product IDs (direct link, highest priority).
	 */
	public function apply_to_products( $request ) {
		$plan = $this->fetch_by_id( (int) $request['id'] );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}

		$product_ids = $request->get_param( 'product_ids' );
		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return new WP_Error( 'bad_request', __( 'product_ids must be a non-empty array.', 'recurio' ), array( 'status' => 400 ) );
		}

		$settings = json_decode( $plan['settings'], true ) ?: array();
		$applied  = array();
		$failed   = array();

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$product    = wc_get_product( $product_id );
			if ( ! $product ) {
				$failed[] = $product_id;
				continue;
			}
			$this->stamp_settings_on_product( $product_id, $settings, (int) $request['id'], 'direct' );
			$applied[] = $product_id;
		}

		$this->maybe_activate_plan( (int) $request['id'], $plan['status'], $applied );

		return rest_ensure_response( array( 'applied' => $applied, 'failed' => $failed ) );
	}

	/**
	 * Apply plan to all products matching its category/tag scope settings.
	 * Respects priority: skips products already directly linked to any plan.
	 */
	public function apply_scope( $request ) {
		$plan = $this->fetch_by_id( (int) $request['id'] );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}

		$settings     = json_decode( $plan['settings'], true ) ?: array();
		$category_ids = array_map( 'absint', (array) ( $settings['scope_category_ids'] ?? array() ) );
		$tag_ids      = array_map( 'absint', (array) ( $settings['scope_tag_ids'] ?? array() ) );
		$excluded_ids = array_map( 'absint', (array) ( $settings['scope_exclude_product_ids'] ?? array() ) );

		if ( empty( $category_ids ) && empty( $tag_ids ) ) {
			return new WP_Error( 'no_scope', __( 'Plan has no category or tag scope configured.', 'recurio' ), array( 'status' => 400 ) );
		}

		$plan_id = (int) $request['id'];
		$applied = array();

		// Fully unlink all previously scope-linked (category/tag) products for this plan:
		// remove both the plan link meta AND all stamped subscription settings so the
		// product's edit screen no longer shows subscription enabled after a scope change.
		$stale_ids = $this->fetch_scope_linked_product_ids( $plan_id );
		foreach ( $stale_ids as $pid ) {
			$this->clear_product_subscription_meta( $pid );
		}

		// Apply to category-scoped products (priority 'category').
		if ( ! empty( $category_ids ) ) {
			$product_ids = $this->fetch_products_by_taxonomy( 'product_cat', $category_ids );
			foreach ( $product_ids as $pid ) {
				if ( in_array( $pid, $excluded_ids, true ) ) {
					continue;
				}
				if ( $this->can_stamp( $pid, 'category' ) ) {
					$this->stamp_settings_on_product( $pid, $settings, $plan_id, 'category' );
					$applied[] = $pid;
				}
			}
		}

		// Apply to tag-scoped products (priority 'tag' — overwrites category links only).
		if ( ! empty( $tag_ids ) ) {
			$product_ids = $this->fetch_products_by_taxonomy( 'product_tag', $tag_ids );
			foreach ( $product_ids as $pid ) {
				if ( in_array( $pid, $excluded_ids, true ) ) {
					continue;
				}
				if ( $this->can_stamp( $pid, 'tag' ) ) {
					$this->stamp_settings_on_product( $pid, $settings, $plan_id, 'tag' );
					if ( ! in_array( $pid, $applied, true ) ) {
						$applied[] = $pid;
					}
				}
			}
		}

		$applied = array_unique( $applied );
		$this->maybe_activate_plan( $plan_id, $plan['status'], $applied );

		return rest_ensure_response(
			array(
				'applied'          => $applied,
				'applied_count'    => count( $applied ),
				'category_ids'     => $category_ids,
				'tag_ids'          => $tag_ids,
			)
		);
	}

	/**
	 * Return how many products the plan's current scope would cover, without stamping.
	 */
	public function get_scope_coverage( $request ) {
		$plan = $this->fetch_by_id( (int) $request['id'] );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}

		$settings     = json_decode( $plan['settings'], true ) ?: array();
		$category_ids = array_map( 'absint', (array) ( $settings['scope_category_ids'] ?? array() ) );
		$tag_ids      = array_map( 'absint', (array) ( $settings['scope_tag_ids'] ?? array() ) );

		$cat_products = empty( $category_ids ) ? array() : $this->fetch_products_by_taxonomy( 'product_cat', $category_ids );
		$tag_products = empty( $tag_ids ) ? array() : $this->fetch_products_by_taxonomy( 'product_tag', $tag_ids );

		$all         = array_unique( array_merge( $cat_products, $tag_products ) );
		$category_terms = empty( $category_ids ) ? array() : $this->fetch_term_labels( 'product_cat', $category_ids );
		$tag_terms      = empty( $tag_ids ) ? array() : $this->fetch_term_labels( 'product_tag', $tag_ids );

		return rest_ensure_response(
			array(
				'total_products'   => count( $all ),
				'category_count'   => count( $cat_products ),
				'tag_count'        => count( $tag_products ),
				'categories'       => $category_terms,
				'tags'             => $tag_terms,
			)
		);
	}

	public function get_linked_products( $request ) {
		global $wpdb;

		$plan_id = (int) $request['id'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm1.post_id, pm1.meta_value AS plan_id, pm2.meta_value AS link_type
				FROM {$wpdb->postmeta} pm1
				LEFT JOIN {$wpdb->postmeta} pm2
					ON pm2.post_id = pm1.post_id AND pm2.meta_key = '_recurio_plan_link_type'
				WHERE pm1.meta_key = '_recurio_plan_id' AND pm1.meta_value = %d",
				$plan_id
			),
			ARRAY_A
		);

		$products = array();
		foreach ( $rows as $row ) {
			$pid     = (int) $row['post_id'];
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$products[] = array(
				'id'        => $pid,
				'name'      => $product->get_name(),
				'sku'       => $product->get_sku(),
				'image'     => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
				'edit_url'  => get_edit_post_link( $pid, 'raw' ),
				'link_type' => $row['link_type'] ?: 'direct',
			);
		}

		return rest_ensure_response( $products );
	}

	public function detach_product( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		if ( ! $product_id ) {
			return new WP_Error( 'bad_request', __( 'product_id is required.', 'recurio' ), array( 'status' => 400 ) );
		}
		$this->clear_product_subscription_meta( $product_id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function get_analytics( $request ) {
		global $wpdb;

		$plan_id = (int) $request['id'];
		$plan    = $this->fetch_by_id( $plan_id );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}

		$product_ids = $this->fetch_linked_product_ids( $plan_id );
		if ( empty( $product_ids ) ) {
			return rest_ensure_response( $this->empty_analytics() );
		}

		$subs_table    = $wpdb->prefix . 'recurio_subscriptions';
		$revenue_table = $wpdb->prefix . 'recurio_subscription_revenue';

		$id_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_subscriptions,
					SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_subscriptions,
					SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_subscriptions,
					AVG(customer_ltv) AS avg_ltv,
					AVG(renewal_count) AS avg_renewals
				FROM {$subs_table}
				WHERE product_id IN ({$id_placeholders})",
				...$product_ids
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$revenue_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$revenue_table}
				WHERE subscription_id IN (
					SELECT id FROM {$subs_table} WHERE product_id IN ({$id_placeholders})
				)",
				...$product_ids
			)
		);

		$total = max( 1, (int) $stats['total_subscriptions'] );

		return rest_ensure_response(
			array(
				'total_subscriptions'  => (int) $stats['total_subscriptions'],
				'active_subscriptions' => (int) $stats['active_subscriptions'],
				'churn_rate'           => round( (int) $stats['cancelled_subscriptions'] / $total * 100, 1 ),
				'avg_ltv'              => round( (float) $stats['avg_ltv'], 2 ),
				'avg_renewals'         => round( (float) $stats['avg_renewals'], 1 ),
				'total_revenue'        => round( $revenue_total, 2 ),
				'linked_products'      => count( $product_ids ),
			)
		);
	}

	public function duplicate_plan( $request ) {
		global $wpdb;

		$plan = $this->fetch_by_id( (int) $request['id'] );
		if ( ! $plan ) {
			return new WP_Error( 'not_found', __( 'Plan not found.', 'recurio' ), array( 'status' => 404 ) );
		}

		$new_name = sprintf( '%s (Copy)', $plan['name'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'recurio_plans',
			array(
				'name'        => $new_name,
				'description' => $plan['description'],
				'slug'        => $this->unique_slug( $new_name ),
				'type'        => $plan['type'],
				'status'      => 'draft',
				'settings'    => $plan['settings'],
				'created_by'  => get_current_user_id(),
			)
		);

		$new_id   = (int) $wpdb->insert_id;
		$new_plan = $this->fetch_by_id( $new_id );

		// Copy all product links from the original plan to the new one.
		$settings        = json_decode( $plan['settings'], true ) ?: array();
		$linked_products = $this->fetch_linked_products_with_type( (int) $request['id'] );
		foreach ( $linked_products as $row ) {
			$this->stamp_settings_on_product( (int) $row['post_id'], $settings, $new_id, $row['link_type'] );
		}

		// If any products were linked, activate the new plan.
		if ( ! empty( $linked_products ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'recurio_plans',
				array( 'status' => 'active' ),
				array( 'id' => $new_id )
			);
			$new_plan = $this->fetch_by_id( $new_id );
		}

		return rest_ensure_response( $this->enrich_row( $new_plan ) );
	}

	/**
	 * Search product categories or tags for the scope picker dropdown.
	 */
	public function search_taxonomy( $request ) {
		$taxonomy = $request->get_param( 'taxonomy' );
		$allowed  = array( 'product_cat', 'product_tag' );
		if ( ! in_array( $taxonomy, $allowed, true ) ) {
			return new WP_Error( 'bad_request', __( 'Invalid taxonomy.', 'recurio' ), array( 'status' => 400 ) );
		}

		$search = $request->get_param( 'search' );
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'search'     => $search,
				'hide_empty' => false,
				'number'     => 30,
				'fields'     => 'all',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response( array() );
		}

		$result = array_map( function ( $term ) {
			return array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'slug'  => $term->slug,
				'count' => $term->count,
			);
		}, $terms );

		return rest_ensure_response( $result );
	}

	// -------------------------------------------------------------------------
	// Auto-stamp hook
	// -------------------------------------------------------------------------

	/**
	 * When a product is saved, check if any active plan's category/tag scope
	 * covers it and auto-stamp (respecting priority; never overrides direct links).
	 *
	 * @param int $post_id
	 */
	public function maybe_auto_stamp_product( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		// If product already has a direct link, leave it alone.
		$current_link_type = get_post_meta( $post_id, '_recurio_plan_link_type', true );
		if ( 'direct' === $current_link_type ) {
			return;
		}

		$product_cat_ids = wc_get_product_term_ids( $post_id, 'product_cat' );
		$product_tag_ids = wc_get_product_term_ids( $post_id, 'product_tag' );

		if ( empty( $product_cat_ids ) && empty( $product_tag_ids ) ) {
			return;
		}

		$active_plans    = $this->fetch_scoped_plans();
		$best_plan_id    = null;
		$best_priority   = 0;
		$best_link_type  = 'category';
		$best_settings   = array();

		foreach ( $active_plans as $plan ) {
			$settings        = json_decode( $plan['settings'], true ) ?: array();
			$plan_cat_ids    = array_map( 'absint', (array) ( $settings['scope_category_ids'] ?? array() ) );
			$plan_tag_ids    = array_map( 'absint', (array) ( $settings['scope_tag_ids'] ?? array() ) );
			$plan_excl_ids   = array_map( 'absint', (array) ( $settings['scope_exclude_product_ids'] ?? array() ) );

			// Skip this plan entirely if the product is in its exclusion list.
			if ( in_array( $post_id, $plan_excl_ids, true ) ) {
				continue;
			}

			// Check tag match first (higher priority).
			if ( ! empty( $plan_tag_ids ) && array_intersect( $product_tag_ids, $plan_tag_ids ) ) {
				if ( self::LINK_PRIORITY['tag'] > $best_priority ) {
					$best_priority  = self::LINK_PRIORITY['tag'];
					$best_plan_id   = (int) $plan['id'];
					$best_link_type = 'tag';
					$best_settings  = $settings;
				}
				continue;
			}

			// Check category match.
			if ( ! empty( $plan_cat_ids ) && array_intersect( $product_cat_ids, $plan_cat_ids ) ) {
				if ( self::LINK_PRIORITY['category'] > $best_priority ) {
					$best_priority  = self::LINK_PRIORITY['category'];
					$best_plan_id   = (int) $plan['id'];
					$best_link_type = 'category';
					$best_settings  = $settings;
				}
			}
		}

		if ( $best_plan_id ) {
			$this->stamp_settings_on_product( $post_id, $best_settings, $best_plan_id, $best_link_type );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Remove all plan link meta AND stamped subscription settings from a product.
	 * After this the product's subscription tab will show as disabled.
	 *
	 * @param int $product_id
	 */
	private function clear_product_subscription_meta( $product_id ) {
		// Plan link meta.
		delete_post_meta( $product_id, '_recurio_plan_id' );
		delete_post_meta( $product_id, '_recurio_plan_link_type' );

		// Subscription toggle — disables the subscription tab on the product edit screen.
		delete_post_meta( $product_id, '_recurio_subscription_enabled' );

		// All stamped billing settings.
		$stamped_keys = array(
			'_recurio_subscription_billing_period',
			'_recurio_subscription_billing_interval',
			'_recurio_use_custom_period',
			'_recurio_subscription_trial_days',
			'_recurio_subscription_signup_fee',
			'_recurio_subscription_discount_type',
			'_recurio_subscription_discount_value',
			'_recurio_payment_type',
			'_recurio_max_payments',
			'_recurio_access_timing',
			'_recurio_access_duration_value',
			'_recurio_access_duration_unit',
			'_recurio_subscription_length',
			'_recurio_allow_one_time_purchase',
			'_recurio_include_renewal_shipping',
			'_recurio_plan_pricing_breakdown',
			'_recurio_plan_additional_description',
		);

		foreach ( $stamped_keys as $key ) {
			delete_post_meta( $product_id, $key );
		}
	}

	/**
	 * After a scope change, walk every category/tag-linked product for this plan and
	 * fully unlink any product that no longer falls under the new scope.
	 *
	 * @param int   $plan_id
	 * @param int[] $new_cat_ids  Current category term IDs (may be empty).
	 * @param int[] $new_tag_ids  Current tag term IDs (may be empty).
	 */
	private function cleanup_stale_scope_products( $plan_id, array $new_cat_ids, array $new_tag_ids ) {
		$scope_linked = $this->fetch_scope_linked_product_ids( $plan_id );

		foreach ( $scope_linked as $pid ) {
			$pid             = (int) $pid;
			$product_cat_ids = wc_get_product_term_ids( $pid, 'product_cat' );
			$product_tag_ids = wc_get_product_term_ids( $pid, 'product_tag' );

			$still_in_cat = ! empty( $new_cat_ids ) && ! empty( array_intersect( $product_cat_ids, $new_cat_ids ) );
			$still_in_tag = ! empty( $new_tag_ids ) && ! empty( array_intersect( $product_tag_ids, $new_tag_ids ) );

			// Product no longer covered by any remaining category or tag — fully unlink it.
			if ( ! $still_in_cat && ! $still_in_tag ) {
				$this->clear_product_subscription_meta( $pid );
			}
		}
	}

	private function fetch_by_id( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}recurio_plans WHERE id = %d", $id ),
			ARRAY_A
		);
	}

	/**
	 * Return all product IDs linked to a plan together with their link type.
	 * Returns array of [ 'post_id' => int, 'link_type' => string ] rows.
	 */
	private function fetch_linked_products_with_type( $plan_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm1.post_id,
				        COALESCE( pm2.meta_value, 'direct' ) AS link_type
				FROM {$wpdb->postmeta} pm1
				LEFT JOIN {$wpdb->postmeta} pm2
				    ON pm2.post_id = pm1.post_id
				    AND pm2.meta_key = '_recurio_plan_link_type'
				WHERE pm1.meta_key = '_recurio_plan_id'
				AND pm1.meta_value = %d",
				$plan_id
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Return product IDs linked to a plan via category or tag scope (not direct links).
	 */
	private function fetch_scope_linked_product_ids( $plan_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm1.post_id
				FROM {$wpdb->postmeta} pm1
				INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
				WHERE pm1.meta_key = '_recurio_plan_id'
				AND pm1.meta_value = %d
				AND pm2.meta_key = '_recurio_plan_link_type'
				AND pm2.meta_value IN ('category', 'tag')",
				$plan_id
			),
			ARRAY_A
		);
		return array_column( $rows, 'post_id' );
	}

	private function fetch_linked_product_ids( $plan_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_recurio_plan_id' AND meta_value = %d",
				$plan_id
			),
			ARRAY_A
		);
		return array_column( $rows, 'post_id' );
	}

	/**
	 * Fetch all active plans that have at least one category or tag scope defined.
	 */
	private function fetch_scoped_plans() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, settings FROM {$wpdb->prefix}recurio_plans WHERE status = 'active'",
			ARRAY_A
		);

		return array_filter( $rows, function ( $row ) {
			$s = json_decode( $row['settings'], true ) ?: array();
			return ! empty( $s['scope_category_ids'] ) || ! empty( $s['scope_tag_ids'] );
		} );
	}

	/**
	 * Fetch product IDs belonging to any of the given term IDs in a taxonomy.
	 *
	 * @param string $taxonomy
	 * @param int[]  $term_ids
	 * @return int[]
	 */
	private function fetch_products_by_taxonomy( $taxonomy, array $term_ids ) {
		if ( empty( $term_ids ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => $taxonomy,
						'field'            => 'term_id',
						'terms'            => $term_ids,
						'operator'         => 'IN',
						'include_children' => true,
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Fetch term name + count for a list of term IDs (used in scope coverage preview).
	 */
	private function fetch_term_labels( $taxonomy, array $term_ids ) {
		$labels = array();
		foreach ( $term_ids as $tid ) {
			$term = get_term( $tid, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$labels[] = array( 'id' => $tid, 'name' => $term->name, 'count' => $term->count );
			}
		}
		return $labels;
	}

	/**
	 * Can we stamp this product at the given priority level?
	 * Returns false if an equal or higher priority link already exists (from any plan).
	 */
	private function can_stamp( $product_id, $incoming_link_type ) {
		$current_link_type = get_post_meta( $product_id, '_recurio_plan_link_type', true );
		if ( ! $current_link_type ) {
			return true; // no existing link
		}
		$current_priority = self::LINK_PRIORITY[ $current_link_type ] ?? 0;
		$incoming_priority = self::LINK_PRIORITY[ $incoming_link_type ] ?? 0;
		return $incoming_priority >= $current_priority;
	}

	private function enrich_row( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row['id']         = (int) $row['id'];
		$row['created_by'] = (int) $row['created_by'];
		$settings          = json_decode( $row['settings'], true ) ?: array();

		// Enrich scope fields with term labels for the UI.
		$cat_ids = array_map( 'absint', (array) ( $settings['scope_category_ids'] ?? array() ) );
		$tag_ids = array_map( 'absint', (array) ( $settings['scope_tag_ids'] ?? array() ) );

		$settings['scope_category_ids'] = $cat_ids;
		$settings['scope_tag_ids']      = $tag_ids;
		$settings['scope_categories']   = empty( $cat_ids ) ? array() : $this->fetch_term_labels( 'product_cat', $cat_ids );
		$settings['scope_tags']         = empty( $tag_ids ) ? array() : $this->fetch_term_labels( 'product_tag', $tag_ids );

		// Enrich excluded product IDs with product name for display.
		$exclude_ids = array_map( 'absint', (array) ( $settings['scope_exclude_product_ids'] ?? array() ) );
		$settings['scope_exclude_product_ids']  = $exclude_ids;
		$settings['scope_excluded_products']    = array_values(
			array_filter(
				array_map(
					function ( $pid ) {
						$product = wc_get_product( $pid );
						if ( ! $product ) {
							return null;
						}
						return array( 'id' => $pid, 'name' => $product->get_name() );
					},
					$exclude_ids
				)
			)
		);

		$row['settings']        = $settings;
		$row['linked_products'] = count( $this->fetch_linked_product_ids( $row['id'] ) );
		$row['has_scope']       = ! empty( $cat_ids ) || ! empty( $tag_ids );
		return $row;
	}

	private function extract_plan_data( $request, $partial = false ) {
		$name = sanitize_text_field( $request->get_param( 'name' ) );
		if ( ! $partial && empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Plan name is required.', 'recurio' ), array( 'status' => 400 ) );
		}

		$allowed_types    = array( 'subscribe_save', 'recurring', 'installment' );
		$allowed_statuses = array( 'draft', 'active', 'archived' );

		$type   = sanitize_text_field( $request->get_param( 'type' ) );
		$status = sanitize_text_field( $request->get_param( 'status' ) );

		$data = array();

		if ( $name ) {
			$data['name'] = $name;
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$data['description'] = sanitize_textarea_field( $description );
		}

		if ( $type && in_array( $type, $allowed_types, true ) ) {
			$data['type'] = $type;
		} elseif ( ! $partial ) {
			$data['type'] = 'recurring';
		}

		if ( $status && in_array( $status, $allowed_statuses, true ) ) {
			$data['status'] = $status;
		} elseif ( ! $partial ) {
			$data['status'] = 'draft';
		}

		$raw_settings = $request->get_param( 'settings' );
		if ( $raw_settings !== null ) {
			$settings         = $this->sanitize_settings( is_array( $raw_settings ) ? $raw_settings : array() );
			$data['settings'] = wp_json_encode( $settings );
		} elseif ( ! $partial ) {
			$data['settings'] = wp_json_encode( array() );
		}

		return $data;
	}

	private function sanitize_settings( array $raw ) {
		$scalar_fields = array(
			'billing_period'         => 'sanitize_text_field',
			'billing_interval'       => 'absint',
			'trial_days'             => 'absint',
			'signup_fee'             => 'floatval',
			'signup_fee_type'        => 'sanitize_text_field',
			'discount_type'          => 'sanitize_text_field',
			'discount_value'         => 'floatval',
			'payment_type'           => 'sanitize_text_field',
			'max_payments'           => 'absint',
			'access_timing'          => 'sanitize_text_field',
			'access_duration_value'  => 'absint',
			'access_duration_unit'   => 'sanitize_text_field',
			'subscription_length'    => 'absint',
			'pricing_breakdown'      => 'sanitize_textarea_field',
			'additional_description' => 'sanitize_textarea_field',
		);

		$clean = array();
		foreach ( $scalar_fields as $key => $cb ) {
			if ( isset( $raw[ $key ] ) ) {
				$clean[ $key ] = call_user_func( $cb, $raw[ $key ] );
			}
		}

		// Boolean meta stored as 'yes'/'no' to match WooCommerce product meta convention.
		foreach ( array( 'allow_one_time_purchase', 'use_custom_period', 'include_renewal_shipping' ) as $bool_key ) {
			if ( isset( $raw[ $bool_key ] ) ) {
				$clean[ $bool_key ] = $raw[ $bool_key ] ? 'yes' : 'no';
			}
		}

		// Scope arrays — sanitise each element as a positive integer.
		foreach ( array( 'scope_category_ids', 'scope_tag_ids', 'scope_exclude_product_ids' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				$clean[ $key ] = array_values( array_filter( array_map( 'absint', $raw[ $key ] ) ) );
			}
		}

		return $clean;
	}

	/**
	 * Stamp billing settings onto a product and record how it was linked.
	 *
	 * @param int    $product_id
	 * @param array  $settings
	 * @param int    $plan_id
	 * @param string $link_type  'direct' | 'tag' | 'category'
	 */
	private function stamp_settings_on_product( $product_id, array $settings, $plan_id, $link_type = 'direct' ) {
		update_post_meta( $product_id, '_recurio_plan_id', $plan_id );
		update_post_meta( $product_id, '_recurio_plan_link_type', $link_type );
		update_post_meta( $product_id, '_recurio_subscription_enabled', 'yes' );

		$meta_map = array(
			'billing_period'          => '_recurio_subscription_billing_period',
			'billing_interval'        => '_recurio_subscription_billing_interval',
			'use_custom_period'       => '_recurio_use_custom_period',
			'trial_days'              => '_recurio_subscription_trial_days',
			'signup_fee'              => '_recurio_subscription_signup_fee',
			'discount_type'           => '_recurio_subscription_discount_type',
			'discount_value'          => '_recurio_subscription_discount_value',
			'payment_type'            => '_recurio_payment_type',
			'max_payments'            => '_recurio_max_payments',
			'access_timing'           => '_recurio_access_timing',
			'access_duration_value'   => '_recurio_access_duration_value',
			'access_duration_unit'    => '_recurio_access_duration_unit',
			'subscription_length'     => '_recurio_subscription_length',
			'allow_one_time_purchase'  => '_recurio_allow_one_time_purchase',
			'include_renewal_shipping' => '_recurio_include_renewal_shipping',
			'pricing_breakdown'        => '_recurio_plan_pricing_breakdown',
			'additional_description'   => '_recurio_plan_additional_description',
		);

		foreach ( $meta_map as $setting_key => $meta_key ) {
			if ( isset( $settings[ $setting_key ] ) ) {
				update_post_meta( $product_id, $meta_key, $settings[ $setting_key ] );
			}
		}
	}

	private function propagate_to_linked_products( $plan_id, $settings_json ) {
		global $wpdb;
		// Fetch all linked products with their link types so we preserve them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm1.post_id, pm2.meta_value AS link_type
				FROM {$wpdb->postmeta} pm1
				LEFT JOIN {$wpdb->postmeta} pm2
					ON pm2.post_id = pm1.post_id AND pm2.meta_key = '_recurio_plan_link_type'
				WHERE pm1.meta_key = '_recurio_plan_id' AND pm1.meta_value = %d",
				$plan_id
			),
			ARRAY_A
		);

		$settings = json_decode( $settings_json, true ) ?: array();
		foreach ( $rows as $row ) {
			$link_type = $row['link_type'] ?: 'direct';
			$this->stamp_settings_on_product( (int) $row['post_id'], $settings, $plan_id, $link_type );
		}
	}

	/**
	 * Flip plan to 'active' status when at least one product is linked.
	 */
	private function maybe_activate_plan( $plan_id, $current_status, array $applied ) {
		if ( ! empty( $applied ) && 'active' !== $current_status ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'recurio_plans',
				array( 'status' => 'active' ),
				array( 'id' => $plan_id )
			);
		}
	}

	private function unique_slug( $name ) {
		global $wpdb;

		$base = sanitize_title( $name );
		$slug = $base;
		$i    = 1;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}recurio_plans WHERE slug = %s", $slug )
			);
			if ( ! $exists ) {
				break;
			}
			$slug = $base . '-' . ( ++$i );
		}

		return $slug;
	}

	private function empty_analytics() {
		return array(
			'total_subscriptions'  => 0,
			'active_subscriptions' => 0,
			'churn_rate'           => 0,
			'avg_ltv'              => 0,
			'avg_renewals'         => 0,
			'total_revenue'        => 0,
			'linked_products'      => 0,
		);
	}
}
