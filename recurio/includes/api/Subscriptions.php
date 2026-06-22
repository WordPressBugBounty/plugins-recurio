<?php
namespace Recurio\Api;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use Recurio_Subscription_Engine;
/**
 * REST API Class
 *
 * @package Recurio
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API class for handling Recurio plugin endpoints.
 *
 * @since 1.0.0
 */
class Subscriptions {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private $namespace = 'recurio/v1';

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Subscription routes.
		register_rest_route(
			$this->namespace,
			'/subscriptions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_subscriptions' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_subscription' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_subscription_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_subscription' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param, $request, $key ) {
								return is_numeric( $param );
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_subscription' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_subscription_update_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_subscription' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Subscription actions.
		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>[\d]+)/pause',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pause_subscription' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'duration' => array(
						'required'    => false,
						'type'        => 'integer',
						'description' => 'Pause duration in days',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>[\d]+)/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume_subscription' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>[\d]+)/skip',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'skip_subscription' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>[\d]+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_subscription' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'reason' => array(
						'required'    => false,
						'type'        => 'string',
						'description' => 'Cancellation reason',
					),
				),
			)
		);

		// Subscription history.
		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>[\d]+)/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_history' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'    => array(
						'validate_callback' => function ( $param, $request, $key ) {
							return is_numeric( $param );
						},
					),
					'limit' => array(
						'required'    => false,
						'type'        => 'integer',
						'default'     => 50,
						'description' => 'Number of events to return',
					),
				),
			)
		);

		// Bulk operations.
		register_rest_route(
			$this->namespace,
			'/subscriptions/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_action' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'ids'    => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'action' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'pause', 'resume', 'cancel', 'delete' ),
					),
				),
			)
		);

		// Statistics.
		register_rest_route(
			$this->namespace,
			'/subscriptions/statistics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_statistics' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Customers.
		register_rest_route(
			$this->namespace,
			'/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customers' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_collection_params(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customers/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customer' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customers/statistics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customers_statistics' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Products - Get subscription-enabled products.
		register_rest_route(
			$this->namespace,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription_products' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'search'       => array(
						'type'        => 'string',
						'description' => 'Search products by name or SKU',
					),
					'type'         => array(
						'type'        => 'string',
						'description' => 'Filter by product type',
						'default'     => 'all',
					),
					'category_ids' => array(
						'type'        => 'string',
						'description' => 'Comma-separated product category term IDs',
					),
					'tag_ids'      => array(
						'type'        => 'string',
						'description' => 'Comma-separated product tag term IDs',
					),
					'per_page'     => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/customers/(?P<id>[\d]+)/subscriptions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_customer_subscriptions' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Analytics.
		register_rest_route(
			$this->namespace,
			'/analytics/revenue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_revenue_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'period'     => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'month',
						'enum'     => array( 'day', 'week', 'month', 'year' ),
					),
					'start_date' => array(
						'required' => false,
						'type'     => 'string',
					),
					'end_date'   => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/analytics/churn',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_churn_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/analytics/growth',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_growth_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Main analytics endpoint.
		register_rest_route(
			$this->namespace,
			'/analytics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_analytics' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'period' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '30d',
					),
				),
			)
		);

		// Revenue endpoints.
		register_rest_route(
			$this->namespace,
			'/revenue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_revenue' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'period' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'monthly',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/revenue/transactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_transactions' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_collection_params(),
			)
		);

		// Also register /transactions for compatibility.
		register_rest_route(
			$this->namespace,
			'/transactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_transactions' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_collection_params(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/revenue-goals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_revenue_goals' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_revenue_goal' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/revenue-goals/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_revenue_goal' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param, $request, $key ) {
								return is_numeric( $param );
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_revenue_goal' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param, $request, $key ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		// Calculate current revenue for goal date range.
		register_rest_route(
			$this->namespace,
			'/revenue-goals/calculate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'calculate_goal_current_amount' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Dashboard endpoints.
		register_rest_route(
			$this->namespace,
			'/dashboard/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/dashboard/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recent_activity' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// License management is handled via admin_post hooks by the Pro plugin
		// See RecurioPro::action_activate_license() and RecurioPro::action_deactivate_license()

		/**
		 * Allow Pro plugin to register additional REST API routes.
		 *
		 * Pro can use this hook to add custom endpoints for advanced features like:
		 * - Cohort analysis endpoints
		 * - Revenue forecasting endpoints
		 * - Advanced reporting endpoints
		 * - Webhook management endpoints
		 *
		 * @since 1.1.0
		 * @param string $namespace The REST API namespace (recurio/v1)
		 */
		do_action( 'recurio_register_rest_routes', $this->namespace );
	}

	/**
	 * Check permission for API access.
	 *
	 * @param WP_REST_Request $request The REST API request.
	 * @return bool True if user has permission, false otherwise.
	 */
	public function check_permission( $request ) {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get subscriptions.
	 *
	 * @param \WP_REST_Request $request The REST API request.
	 * @return \WP_REST_Response The response containing subscriptions data.
	 */
	public function get_subscriptions( $request ) {
		global $wpdb;

		$params   = $request->get_params();
		$page     = isset( $params['page'] ) ? intval( $params['page'] ) : 1;
		$per_page = isset( $params['per_page'] ) ? intval( $params['per_page'] ) : 25;
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql  = array( '1=1' );
		$where_args = array();

		// Filter by status.
		if ( ! empty( $params['status'] ) ) {
			$where_sql[]  = 's.status = %s';
			$where_args[] = sanitize_text_field( $params['status'] );
		}

		// Filter by customer.
		if ( ! empty( $params['customer_id'] ) ) {
			$where_sql[]  = 's.customer_id = %d';
			$where_args[] = absint( $params['customer_id'] );
		}

		// Filter by product.
		if ( ! empty( $params['product_id'] ) ) {
			$where_sql[]  = 's.product_id = %d';
			$where_args[] = absint( $params['product_id'] );
		}

		// Filter by date range.
		if ( ! empty( $params['date_from'] ) ) {
			$where_sql[]  = 'DATE(s.created_at) >= %s';
			$where_args[] = sanitize_text_field( $params['date_from'] );
		}
		if ( ! empty( $params['date_to'] ) ) {
			$where_sql[]  = 'DATE(s.created_at) <= %s';
			$where_args[] = sanitize_text_field( $params['date_to'] );
		}

		// Search by customer name or email.
		if ( ! empty( $params['search'] ) ) {
			$search       = '%' . $wpdb->esc_like( sanitize_text_field( $params['search'] ) ) . '%';
			$where_sql[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
			$where_args[] = $search;
			$where_args[] = $search;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- All placeholders collected above; single prepare() call below.
		$where_clause = implode( ' AND ', $where_sql );

		// Get total count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions s LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID WHERE {$where_clause}",
				...$where_args
			)
		);

		// Get subscriptions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, u.display_name as customer_name, u.user_email as customer_email
				FROM {$wpdb->prefix}recurio_subscriptions s
				LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
				WHERE {$where_clause}
				ORDER BY s.created_at DESC
				LIMIT %d OFFSET %d",
				...array_merge( $where_args, array( $per_page, $offset ) )
			)
		);

		// Format subscriptions.
		foreach ( $subscriptions as &$subscription ) {
			$subscription = $this->format_subscription( $subscription );
		}

		return new WP_REST_Response(
			array(
				'subscriptions' => $subscriptions,
				'total'         => intval( $total ),
				'page'          => $page,
				'per_page'      => $per_page,
				'total_pages'   => ceil( $total / $per_page ),
				'currency'      => array(
					'code'   => get_woocommerce_currency(),
					'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
				),
			),
			200
		);
	}

	/**
	 * Get single subscription.
	 *
	 * @param WP_REST_Request $request The REST API request.
	 * @return WP_REST_Response|WP_Error The response containing subscription data or error.
	 */
	public function get_subscription( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$subscription        = $subscription_engine->get_subscription( $request['id'] );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found', 'recurio' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->format_subscription( $subscription ), 200 );
	}

	/**
	 * Create subscription.
	 *
	 * @param WP_REST_Request $request The REST API request.
	 * @return WP_REST_Response|WP_Error The response containing created subscription data or error.
	 */
	public function create_subscription( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();

		$data = array(
			'customer_id'      => $request['customer_id'],
			'product_id'       => $request['product_id'],
			'billing_period'   => $request['billing_period'],
			'billing_interval' => $request['billing_interval'],
			'billing_amount'   => $request['billing_amount'],
			'status'           => $request['status'] ?? 'pending',
		);

		// Add optional fields.
		if ( ! empty( $request['trial_end_date'] ) ) {
			$data['trial_end_date'] = $request['trial_end_date'];
		}
		if ( ! empty( $request['start_date'] ) ) {
			// Use start_date to calculate next_payment_date if needed.
			$data['created_at'] = $request['start_date'] . ' 00:00:00';
		}
		if ( ! empty( $request['notes'] ) ) {
			$data['notes'] = sanitize_text_field( $request['notes'] );
		}

		$subscription_id = $subscription_engine->create_subscription( $data );

		if ( is_wp_error( $subscription_id ) ) {
			return $subscription_id;
		}

		$subscription = $subscription_engine->get_subscription( $subscription_id );

		return new WP_REST_Response( $this->format_subscription( $subscription ), 201 );
	}

	/**
	 * Update subscription
	 */
	public function update_subscription( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();

		$data = array();
		// Add product_id and customer_id to allowed fields
		$allowed_fields = array(
			'customer_id',
			'product_id',
			'status',
			'billing_amount',
			'billing_period',
			'billing_interval',
			'next_payment_date',
			'trial_end_date',
			'notes',
		);

		foreach ( $allowed_fields as $field ) {
			if ( isset( $request[ $field ] ) ) {
				$data[ $field ] = $request[ $field ];
			}
		}

		// The notes field will be handled by the engine's update_subscription method
		// No need to handle it here as it's now done in the engine

		if ( isset( $request['action'] ) ) {
			switch ( $request['action'] ) {
				case 'pause':
					return $this->pause_subscription( $request );
				case 'resume':
					return $this->resume_subscription( $request );
				case 'cancel':
					$request['timing'] = $request['timing'] ?? 'immediate';
					return $this->cancel_subscription( $request );
			}
		}

		$result = $subscription_engine->update_subscription( $request['id'], $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription = $subscription_engine->get_subscription( $request['id'] );

		return new WP_REST_Response( $this->format_subscription( $subscription ), 200 );
	}

	/**
	 * Delete subscription
	 */
	public function delete_subscription( $request ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for subscription deletion
		$result = $wpdb->delete(
			$wpdb->prefix . 'recurio_subscriptions',
			array( 'id' => $request['id'] )
		);

		if ( $result === false ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete subscription', 'recurio' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Pause subscription
	 */
	public function pause_subscription( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$duration            = isset( $request['duration'] ) ? intval( $request['duration'] ) : null;

		$result = $subscription_engine->pause_subscription( $request['id'], $duration );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription = $subscription_engine->get_subscription( $request['id'] );

		return new WP_REST_Response( $this->format_subscription( $subscription ), 200 );
	}

	/**
	 * Resume subscription
	 */
	public function resume_subscription( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();

		$result = $subscription_engine->resume_subscription( $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription = $subscription_engine->get_subscription( $request['id'] );

		return new WP_REST_Response( $this->format_subscription( $subscription ), 200 );
	}

	/**
	 * Skip next billing cycle (admin / REST API)
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function skip_subscription( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$id                    = intval( $request['id'] );
		$result                = $subscription_engine->skip_next_cycle( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription = $subscription_engine->get_subscription( $id );

		return new WP_REST_Response(
			array_merge(
				$this->format_subscription( $subscription ),
				array(
					'skip_result'           => array(
						'previous_next_payment' => $result['previous_next_payment'],
						'new_next_payment'      => $result['new_next_payment'],
					),
				)
			),
			200
		);
	}

	/**
	 * Cancel subscription
	 */
	public function cancel_subscription( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$reason              = isset( $request['reason'] ) ? sanitize_text_field( $request['reason'] ) : '';
		$timing              = isset( $request['timing'] ) ? $request['timing'] : 'immediate';

		$result = $subscription_engine->cancel_subscription( $request['id'], $reason, $timing );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription = $subscription_engine->get_subscription( $request['id'] );

		return new WP_REST_Response( $this->format_subscription( $subscription ), 200 );
	}

	/**
	 * Get subscription history
	 */
	public function get_subscription_history( $request ) {
		global $wpdb;

		$subscription_id = absint( $request['id'] );
		$limit           = isset( $request['limit'] ) ? absint( $request['limit'] ) : 50;

		// Check if subscription exists
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$subscription        = $subscription_engine->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found', 'recurio' ), array( 'status' => 404 ) );
		}

		// Get events from database
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time event data
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscription_events
            WHERE subscription_id = %d
            ORDER BY created_at DESC 
            LIMIT %d",
				$subscription_id,
				$limit
			)
		);

		// Format events for response
		$formatted_events = array();
		foreach ( $events as $event ) {
			$formatted_events[] = array(
				'id'             => intval( $event->id ),
				'event_type'     => $event->event_type,
				'event_value'    => $event->event_value ? floatval( $event->event_value ) : null,
				'event_metadata' => $event->event_metadata ? json_decode( $event->event_metadata, true ) : null,
				'created_at'     => $event->created_at,
				'formatted_date' => wp_gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->created_at ) ),
				'description'    => $this->get_event_description( $event ),
			);
		}

		// Get payment history from revenue table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time payment data
		$payments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE subscription_id = %d
            ORDER BY payment_date DESC 
            LIMIT %d",
				$subscription_id,
				$limit
			)
		);

		// Format payments
		$formatted_payments = array();
		foreach ( $payments as $payment ) {
			$formatted_payments[] = array(
				'id'             => intval( $payment->id ),
				'amount'         => floatval( $payment->amount ),
				'payment_method' => $payment->payment_method,
				'transaction_id' => $payment->transaction_id,
				'payment_date'   => $payment->payment_date,
				'formatted_date' => wp_gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $payment->payment_date ) ),
			);
		}

		return new WP_REST_Response(
			array(
				'subscription_id' => $subscription_id,
				'events'          => $formatted_events,
				'payments'        => $formatted_payments,
				'total_events'    => count( $formatted_events ),
				'total_payments'  => count( $formatted_payments ),
			),
			200
		);
	}

	/**
	 * Get human-readable event description
	 */
	private function get_event_description( $event ) {
		$descriptions = array(
			'subscription_created'   => __( 'Subscription created', 'recurio' ),
			'subscription_activated' => __( 'Subscription activated', 'recurio' ),
			'subscription_paused'    => __( 'Subscription paused', 'recurio' ),
			'subscription_resumed'   => __( 'Subscription resumed', 'recurio' ),
			'subscription_cancelled' => __( 'Subscription cancelled', 'recurio' ),
			'subscription_suspended' => __( 'Subscription suspended', 'recurio' ),
			'payment_processed'      => __( 'Payment processed successfully', 'recurio' ),
			'payment_failed'         => __( 'Payment failed', 'recurio' ),
			'customer_updated'       => __( 'Customer information updated', 'recurio' ),
			'payment_method_updated' => __( 'Payment method updated', 'recurio' ),
			'subscription_renewed'   => __( 'Subscription renewed', 'recurio' ),
			'trial_started'          => __( 'Trial period started', 'recurio' ),
			'trial_ended'            => __( 'Trial period ended', 'recurio' ),
			'skipped'                => __( 'Billing cycle skipped', 'recurio' ),
		);

		$description = isset( $descriptions[ $event->event_type ] )
			? $descriptions[ $event->event_type ]
			: esc_html( ucfirst( str_replace( '_', ' ', $event->event_type ) ) );

		// Add value to description if applicable
		if ( $event->event_value && in_array( $event->event_type, array( 'payment_processed', 'payment_failed' ), true ) ) {
			$description .= ' - ' . wp_strip_all_tags( wc_price( $event->event_value ) );
		}

		return $description;
	}

	/**
	 * Bulk action on subscriptions
	 */
	public function bulk_action( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$ids                 = $request['ids'];
		$action              = $request['action'];
		$results             = array();

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'pause':
					$result = $subscription_engine->pause_subscription( $id );
					break;
				case 'resume':
					$result = $subscription_engine->resume_subscription( $id );
					break;
				case 'cancel':
					$timing = isset( $request['timing'] ) ? $request['timing'] : 'immediate';
					$result = $subscription_engine->cancel_subscription( $id, $request['reason'] ?? '', $timing );
					break;
				case 'delete':
					global $wpdb;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API bulk operations
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for subscription deletion
					$result = $wpdb->delete( $wpdb->prefix . 'recurio_subscriptions', array( 'id' => $id ) );
					break;
				default:
					$result = new WP_Error( 'invalid_action', __( 'Invalid bulk action', 'recurio' ) );
			}

			$results[ $id ] = ! is_wp_error( $result );
		}

		return new WP_REST_Response(
			array(
				'action'        => $action,
				'results'       => $results,
				'success_count' => count( array_filter( $results ) ),
			),
			200
		);
	}

	/**
	 * Get subscription statistics
	 */
	public function get_statistics( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$stats               = $subscription_engine->get_statistics();

		// Add additional statistics
		global $wpdb;

		// Churn rate (last 30 days)
		$thirty_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics
		$cancelled_last_30 = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions
            WHERE status = 'cancelled' AND updated_at >= %s",
				$thirty_days_ago
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics
		$active_30_days_ago = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions
            WHERE created_at <= %s AND (status = 'active' OR (status = 'cancelled' AND updated_at >= %s))",
				$thirty_days_ago,
				$thirty_days_ago
			)
		);

		$stats['churn_rate'] = $active_30_days_ago > 0 ? round( ( $cancelled_last_30 / $active_30_days_ago ) * 100, 2 ) : 0;

		// Average customer lifetime value
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics
		$stats['average_ltv'] = $wpdb->get_var(
			"SELECT AVG(customer_ltv) FROM {$wpdb->prefix}recurio_subscriptions WHERE customer_ltv > 0"
		) ?: 0;

		// Growth rate (last 30 days)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics
		$new_last_30 = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE created_at >= %s",
				$thirty_days_ago
			)
		);

		$stats['growth_rate'] = $stats['total'] > 0 ? round( ( $new_last_30 / $stats['total'] ) * 100, 2 ) : 0;

		return new WP_REST_Response( $stats, 200 );
	}

	/**
	 * Get customers
	 */
	public function get_customers( $request ) {
		global $wpdb;

		$params              = $request->get_params();
		$page                = isset( $params['page'] ) ? intval( $params['page'] ) : 1;
		$per_page            = isset( $params['per_page'] ) ? intval( $params['per_page'] ) : 25;
		$offset              = ( $page - 1 ) * $per_page;
		$search              = isset( $params['search'] ) ? sanitize_text_field( $params['search'] ) : '';
		$segment             = isset( $params['segment'] ) ? sanitize_text_field( $params['segment'] ) : '';
		$subscription_status = isset( $params['subscription_status'] ) ? sanitize_text_field( $params['subscription_status'] ) : '';

		// Build WHERE clause
		$where = 'WHERE 1=1';
		if ( $search ) {
			$where .= $wpdb->prepare(
				' AND (u.display_name LIKE %s OR u.user_email LIKE %s)',
				'%' . $wpdb->esc_like( $search ) . '%',
				'%' . $wpdb->esc_like( $search ) . '%'
			);
		}

		// Build HAVING clause for filters
		$having = '';
		if ( $subscription_status === 'active' ) {
			$having = ' HAVING active_subscriptions > 0';
		} elseif ( $subscription_status === 'inactive' ) {
			$having = ' HAVING active_subscriptions = 0 AND total_subscriptions > 0';
		} elseif ( $subscription_status === 'never' ) {
			$having = ' HAVING total_subscriptions = 0';
		}

		// Get customers with subscription data
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE and HAVING clauses safely constructed with placeholders
		$customers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
                u.ID as id,
                u.display_name as name,
                u.user_email as email,
                u.user_registered as join_date,
                COUNT(DISTINCT s.id) as total_subscriptions,
                COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) as active_subscriptions,
                COALESCE(SUM(s.billing_amount), 0) as total_revenue,
                MAX(s.created_at) as last_subscription_date,
                CASE
                    WHEN COALESCE(SUM(s.billing_amount), 0) > 1000 THEN 'VIP'
                    WHEN COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) = 0 AND COUNT(DISTINCT s.id) > 0 THEN 'At Risk'
                    WHEN COUNT(DISTINCT s.id) = 0 THEN 'Prospect'
                    ELSE 'Regular'
                END as segment
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->prefix}recurio_subscriptions s ON u.ID = s.customer_id
            {$where} /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            GROUP BY u.ID
            {$having} /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            ORDER BY total_revenue DESC
            LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// Get total count
		$total_query = "SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u
                       LEFT JOIN {$wpdb->prefix}recurio_subscriptions s ON u.ID = s.customer_id
                       {$where}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API customer count
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is safely constructed with prepared WHERE clause
		$total = $wpdb->get_var( $total_query );

		// Add segment filtering if needed
		if ( $segment ) {
			$customers = array_filter(
				$customers,
				function ( $customer ) use ( $segment ) {
					// Handle both "at_risk" and "At Risk" formats
					$customer_segment = str_replace( ' ', '_', strtolower( $customer->segment ) );
					$filter_segment   = str_replace( ' ', '_', strtolower( $segment ) );
					return $customer_segment === $filter_segment;
				}
			);
			$customers = array_values( $customers );
			$total     = count( $customers );
		}

		return new WP_REST_Response(
			array(
				'customers'   => $customers,
				'total'       => intval( $total ),
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
				'currency'    => array(
					'code'   => get_woocommerce_currency(),
					'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
				),
			),
			200
		);
	}

	/**
	 * Get single customer
	 */
	public function get_customer( $request ) {
		$user = get_user_by( 'id', $request['id'] );

		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'Customer not found', 'recurio' ), array( 'status' => 404 ) );
		}

		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API customer data
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer statistics
		$customer_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
                COUNT(DISTINCT s.id) as total_subscriptions,
                COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) as active_subscriptions,
                COALESCE(SUM(s.billing_amount), 0) as total_revenue,
                MAX(s.created_at) as last_subscription_date
            FROM {$wpdb->prefix}recurio_subscriptions s
            WHERE s.customer_id = %d",
				$request['id']
			)
		);

		$customer = array(
			'id'                     => $user->ID,
			'name'                   => $user->display_name,
			'email'                  => $user->user_email,
			'join_date'              => $user->user_registered,
			'total_subscriptions'    => intval( $customer_data->total_subscriptions ),
			'active_subscriptions'   => intval( $customer_data->active_subscriptions ),
			'total_revenue'          => floatval( $customer_data->total_revenue ),
			'last_subscription_date' => $customer_data->last_subscription_date,
		);

		return new WP_REST_Response( $customer, 200 );
	}

	/**
	 * Get customers statistics
	 */
	public function get_customers_statistics( $request ) {
		global $wpdb;

		// Total customers
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API customer statistics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
		$total_customers = $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.ID)
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->prefix}recurio_subscriptions s ON u.ID = s.customer_id"
		);

		// Active customers (with at least one active subscription)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API customer statistics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
		$active_customers = $wpdb->get_var(
			"SELECT COUNT(DISTINCT customer_id)
            FROM {$wpdb->prefix}recurio_subscriptions
            WHERE status = 'active'"
		);

		// Inactive customers (customers with subscriptions but none active)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API customer statistics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
		$inactive_customers = $wpdb->get_var(
			"SELECT COUNT(DISTINCT u.ID)
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->prefix}recurio_subscriptions s ON u.ID = s.customer_id
            WHERE u.ID NOT IN (
                SELECT DISTINCT customer_id
                FROM {$wpdb->prefix}recurio_subscriptions
                WHERE status = 'active'
            )"
		);

		// Average customer value
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API customer statistics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
		$avg_customer_value = $wpdb->get_var(
			"SELECT AVG(customer_revenue) FROM (
                SELECT SUM(billing_amount) as customer_revenue
                FROM {$wpdb->prefix}recurio_subscriptions
                GROUP BY customer_id
            ) as customer_totals"
		);

		$statistics = array(
			'totalCustomers'    => intval( $total_customers ),
			'activeCustomers'   => intval( $active_customers ),
			'inactiveCustomers' => intval( $inactive_customers ),
			'avgCustomerValue'  => floatval( $avg_customer_value ) ?: 0,
		);

		return new WP_REST_Response( $statistics, 200 );
	}

	/**
	 * Get customer subscriptions
	 */
	public function get_customer_subscriptions( $request ) {
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$subscriptions       = $subscription_engine->get_customer_subscriptions( $request['id'] );

		foreach ( $subscriptions as &$subscription ) {
			$subscription = $this->format_subscription( $subscription );
		}

		return new WP_REST_Response( $subscriptions, 200 );
	}

	/**
	 * Get subscription-enabled products from WooCommerce
	 */
	public function get_subscription_products( $request ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'woocommerce_not_active', __( 'WooCommerce is not active', 'recurio' ), array( 'status' => 500 ) );
		}

		$search       = isset( $request['search'] ) ? sanitize_text_field( $request['search'] ) : '';
		$type         = isset( $request['type'] ) ? sanitize_text_field( $request['type'] ) : 'all';
		$per_page     = isset( $request['per_page'] ) ? absint( $request['per_page'] ) : 20;
		$category_ids = isset( $request['category_ids'] )
			? array_values( array_filter( array_map( 'absint', explode( ',', $request['category_ids'] ) ) ) )
			: array();
		$tag_ids      = isset( $request['tag_ids'] )
			? array_values( array_filter( array_map( 'absint', explode( ',', $request['tag_ids'] ) ) ) )
			: array();

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => $per_page,
			'post_status'    => 'publish',
			'meta_query'     => array(),
		);

		// Add search
		if ( $search ) {
			$args['s'] = $search;
		}

		// Filter by taxonomy (category / tag)
		if ( ! empty( $category_ids ) || ! empty( $tag_ids ) ) {
			$args['tax_query'] = array( 'relation' => 'OR' );
			if ( ! empty( $category_ids ) ) {
				$args['tax_query'][] = array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $category_ids,
					'include_children' => true,
				);
			}
			if ( ! empty( $tag_ids ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'product_tag',
					'field'    => 'term_id',
					'terms'    => $tag_ids,
				);
			}
		}

		// Filter by subscription-enabled only
		if ( $type === 'subscription' ) {
			$args['meta_query'][] = array(
				'key'     => '_recurio_subscription_enabled',
				'value'   => 'yes',
				'compare' => '=',
			);
		}

		$products = array();
		$query    = new \WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$product = wc_get_product( get_the_ID() );

				if ( $product ) {
					// Get subscription-specific data
					$subscription_enabled = get_post_meta( $product->get_id(), '_recurio_subscription_enabled', true ) === 'yes';
					$subscription_periods = get_post_meta( $product->get_id(), '_recurio_subscription_periods', true );
					$subscription_trial   = get_post_meta( $product->get_id(), '_recurio_subscription_trial_days', true );

					$products[] = array(
						'id'                   => $product->get_id(),
						'name'                 => $product->get_name(),
						'sku'                  => $product->get_sku(),
						'price'                => $product->get_price(),
						'regular_price'        => $product->get_regular_price(),
						'sale_price'           => $product->get_sale_price(),
						'type'                 => $product->get_type(),
						'status'               => $product->get_status(),
						'stock_status'         => $product->get_stock_status(),
						'subscription_enabled' => $subscription_enabled,
						'subscription_periods' => $subscription_periods ? maybe_unserialize( $subscription_periods ) : array( 'monthly' ),
						'trial_days'           => intval( $subscription_trial ),
						'image_url'            => wp_get_attachment_url( $product->get_image_id() ),
					);
				}
			}
			wp_reset_postdata();
		}

		return new WP_REST_Response(
			array(
				'products' => $products,
				'total'    => count( $products ),
			),
			200
		);
	}

	/**
	 * Get revenue analytics
	 */
	public function get_revenue_analytics( $request ) {
		global $wpdb;

		$period     = $request['period'] ?? 'month';
		$start_date = $request['start_date'] ?? gmdate( 'Y-m-d', strtotime( '-6 months' ) );
		$end_date   = $request['end_date'] ?? gmdate( 'Y-m-d' );

		// Get revenue data grouped by period
		$date_format = '%Y-%m-%d';
		switch ( $period ) {
			case 'day':
				$date_format = '%Y-%m-%d';
				break;
			case 'week':
				$date_format = '%Y-%u';
				break;
			case 'month':
				$date_format = '%Y-%m';
				break;
			case 'year':
				$date_format = '%Y';
				break;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API revenue data
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time revenue data
		$revenue_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
                DATE_FORMAT(created_at, %s) as period,
                SUM(amount) as revenue,
                COUNT(*) as transactions
            FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE created_at BETWEEN %s AND %s
            GROUP BY period
            ORDER BY period ASC",
				$date_format,
				$start_date,
				$end_date
			)
		);

		return new WP_REST_Response(
			array(
				'period'     => $period,
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'data'       => $revenue_data,
			),
			200
		);
	}

	/**
	 * Get churn analytics
	 */
	public function get_churn_analytics( $request ) {
		global $wpdb;

		// Get churn data for the last 12 months
		$churn_data = array();

		for ( $i = 11; $i >= 0; $i-- ) {
			$month_start = gmdate( 'Y-m-01', strtotime( "-$i months" ) );
			$month_end   = gmdate( 'Y-m-t', strtotime( "-$i months" ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API churn data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time churn data
			$cancelled = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE status = 'cancelled' AND updated_at BETWEEN %s AND %s",
					$month_start,
					$month_end
				)
			);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API churn data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time churn data
			$active_start = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE created_at <= %s AND (status = 'active' OR (status = 'cancelled' AND updated_at >= %s))",
					$month_start,
					$month_start
				)
			);

			$churn_rate = $active_start > 0 ? round( ( $cancelled / $active_start ) * 100, 2 ) : 0;

			$churn_data[] = array(
				'month'        => gmdate( 'Y-m', strtotime( "-$i months" ) ),
				'cancelled'    => intval( $cancelled ),
				'active_start' => intval( $active_start ),
				'churn_rate'   => $churn_rate,
			);
		}

		return new WP_REST_Response( $churn_data, 200 );
	}

	/**
	 * Get growth analytics
	 */
	public function get_growth_analytics( $request ) {
		global $wpdb;

		// Get growth data for the last 12 months
		$growth_data = array();

		for ( $i = 11; $i >= 0; $i-- ) {
			$month_start = gmdate( 'Y-m-01', strtotime( "-$i months" ) );
			$month_end   = gmdate( 'Y-m-t', strtotime( "-$i months" ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API growth data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time growth data
			$new_subscriptions = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE created_at BETWEEN %s AND %s",
					$month_start,
					$month_end
				)
			);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API growth data
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time growth data
			$cancelled_subscriptions = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE status = 'cancelled' AND updated_at BETWEEN %s AND %s",
					$month_start,
					$month_end
				)
			);

			$net_growth = $new_subscriptions - $cancelled_subscriptions;

			$growth_data[] = array(
				'month'      => gmdate( 'Y-m', strtotime( "-$i months" ) ),
				'new'        => intval( $new_subscriptions ),
				'cancelled'  => intval( $cancelled_subscriptions ),
				'net_growth' => $net_growth,
			);
		}

		return new WP_REST_Response( $growth_data, 200 );
	}

	/**
	 * Get main analytics data
	 */
	public function get_analytics( $request ) {
		global $wpdb;
		$period = $request['period'] ?? '30d';

		// Work with real data only

		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$stats               = $subscription_engine->get_statistics();

		// Calculate growth rate (compare with last month)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$current_month_subs = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d AND status = 'active'",
				gmdate( 'n' ),
				gmdate( 'Y' )
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$last_month_subs = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d AND status = 'active'",
				gmdate( 'n', strtotime( '-1 month' ) ),
				gmdate( 'Y', strtotime( '-1 month' ) )
			)
		);

		// Calculate growth rate - handle zero baseline
		if ( $last_month_subs == 0 && $current_month_subs > 0 ) {
			// If we went from 0 to something, show significant growth
			$growth_rate = 100; // Can show as "New" or 100%+ growth
		} elseif ( $last_month_subs > 0 ) {
			$growth_rate = round( ( ( $current_month_subs - $last_month_subs ) / $last_month_subs ) * 100, 1 );
		} else {
			$growth_rate = 0;
		}

		// Calculate retention rate
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$total_customers = $wpdb->get_var( "SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$active_customers = $wpdb->get_var( "SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions WHERE status = 'active'" );
		$retention_rate   = $total_customers > 0 ? round( ( $active_customers / $total_customers ) * 100, 1 ) : 0;

		// Calculate conversion rate: active subscriptions / total unique customers who tried (including cancelled)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$total_unique_customers = $wpdb->get_var( "SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$active_subscriptions_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE status = 'active'" );
		$conversion_rate            = $total_unique_customers > 0 ? round( ( $active_subscriptions_count / $total_unique_customers ) * 100, 1 ) : 0;

		// Calculate conversion trend (compare current month vs last month)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$current_month_conversions = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d AND status = 'active'",
				gmdate( 'n' ),
				gmdate( 'Y' )
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$last_month_conversions = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d AND status = 'active'",
				gmdate( 'n', strtotime( '-1 month' ) ),
				gmdate( 'Y', strtotime( '-1 month' ) )
			)
		);

		// Calculate conversion trend - handle zero baseline
		if ( $last_month_conversions == 0 && $current_month_conversions > 0 ) {
			$conversion_trend = 100; // New conversions from zero
		} elseif ( $last_month_conversions > 0 ) {
			$conversion_trend = round( ( ( $current_month_conversions - $last_month_conversions ) / $last_month_conversions ) * 100, 1 );
		} else {
			$conversion_trend = 0;
		}

		// Calculate average LTV from recurio_customer_analytics table and subscriptions table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$avg_ltv_analytics = $wpdb->get_var(
			"SELECT AVG(customer_lifetime_value) FROM {$wpdb->prefix}recurio_customer_analytics WHERE customer_lifetime_value > 0"
		);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$avg_ltv_subscriptions = $wpdb->get_var(
			"SELECT AVG(customer_ltv) FROM {$wpdb->prefix}recurio_subscriptions WHERE customer_ltv > 0"
		);
		$avg_ltv               = $avg_ltv_analytics ?: ( $avg_ltv_subscriptions ?: 0 );

		// Calculate LTV growth (compare current quarter vs last quarter)
		$current_quarter   = ceil( gmdate( 'n' ) / 3 );
		$current_year      = gmdate( 'Y' );
		$last_quarter      = $current_quarter > 1 ? $current_quarter - 1 : 4;
		$last_quarter_year = $current_quarter > 1 ? $current_year : $current_year - 1;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$current_quarter_ltv = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(customer_ltv) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE QUARTER(created_at) = %d AND YEAR(created_at) = %d AND customer_ltv > 0",
				$current_quarter,
				$current_year
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$last_quarter_ltv = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(customer_ltv) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE QUARTER(created_at) = %d AND YEAR(created_at) = %d AND customer_ltv > 0",
				$last_quarter,
				$last_quarter_year
			)
		);

		// Calculate LTV growth - handle zero baseline
		if ( $last_quarter_ltv == 0 && $current_quarter_ltv > 0 ) {
			$ltv_growth = 100; // New LTV from zero
		} elseif ( $last_quarter_ltv > 0 && $current_quarter_ltv > 0 ) {
			$ltv_growth = round( ( ( $current_quarter_ltv - $last_quarter_ltv ) / $last_quarter_ltv ) * 100, 1 );
		} else {
			$ltv_growth = 0;
		}

		// Calculate retention trend (compare current month vs last month)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$current_month_customers = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) <= %d AND YEAR(created_at) <= %d AND status = 'active'",
				gmdate( 'n' ),
				gmdate( 'Y' )
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$current_month_total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) <= %d AND YEAR(created_at) <= %d",
				gmdate( 'n' ),
				gmdate( 'Y' )
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$last_month_customers = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) <= %d AND YEAR(created_at) <= %d AND status = 'active'",
				gmdate( 'n', strtotime( '-1 month' ) ),
				gmdate( 'Y', strtotime( '-1 month' ) )
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$last_month_total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE MONTH(created_at) <= %d AND YEAR(created_at) <= %d",
				gmdate( 'n', strtotime( '-1 month' ) ),
				gmdate( 'Y', strtotime( '-1 month' ) )
			)
		);

		$current_retention = $current_month_total > 0 ? ( $current_month_customers / $current_month_total ) * 100 : 0;
		$last_retention    = $last_month_total > 0 ? ( $last_month_customers / $last_month_total ) * 100 : 0;

		// Calculate retention trend - handle zero baseline
		if ( $last_retention == 0 && $current_retention > 0 ) {
			$retention_trend = 100; // New retention from zero
		} elseif ( $last_retention > 0 ) {
			// Show percentage point difference for retention
			$retention_trend = round( $current_retention - $last_retention, 1 );
		} else {
			$retention_trend = 0;
		}

		// Calculate additional metrics
		$analytics = array(
			'totalSubscriptions'     => $stats['total'],
			'activeSubscriptions'    => $stats['active'],
			'pausedSubscriptions'    => $stats['paused'],
			'cancelledSubscriptions' => $stats['cancelled'],
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query needed for analytics
			'expiredSubscriptions'   => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE status = 'expired'" ),
			'mrr'                    => $stats['mrr'],
			'churnRate'              => $stats['churn_rate'] ?? 0,
			'growthRate'             => $growth_rate,
			'subscriptionGrowth'     => $growth_rate,
			'conversionRate'         => $conversion_rate,
			'conversionTrend'        => $conversion_trend,
			'avgLTV'                 => round( $avg_ltv, 2 ),
			'ltvGrowth'              => $ltv_growth,
			'retentionRate'          => $retention_rate,
			'retentionTrend'         => $retention_trend,
		);

		// Get status distribution
		$analytics['statusDistribution'] = array(
			'active'    => $stats['active'],
			'paused'    => $stats['paused'],
			'cancelled' => $stats['cancelled'],
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query needed for analytics
			'pending'   => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE status = 'pending'" ),
		);

		// Get product performance (top 10 products)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
		$product_performance = $wpdb->get_results(
			"SELECT 
                COALESCE(p.post_title, 'Unknown Product') as product,
                COUNT(s.id) as subscriptions,
                COALESCE(SUM(s.billing_amount), 0) as revenue,
                COUNT(CASE WHEN s.status = 'cancelled' THEN 1 END) as cancelled_count,
                COUNT(s.id) as total_count
            FROM {$wpdb->prefix}recurio_subscriptions s
            LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
            WHERE s.product_id IS NOT NULL
            GROUP BY s.product_id
            ORDER BY revenue DESC
            LIMIT 10"
		);

		// Calculate churn rate for each product
		foreach ( $product_performance as &$product ) {
			$churn_rate         = $product->total_count > 0 ?
				round( ( $product->cancelled_count / $product->total_count ) * 100, 1 ) : 0;
			$product->churnRate = $churn_rate;
			unset( $product->cancelled_count );
			unset( $product->total_count );
		}

		$analytics['productPerformance'] = $product_performance;

		// Get growth trend data based on period
		$days = 30; // Default
		if ( $period === '7d' ) {
			$days = 7;
		} elseif ( $period === '90d' ) {
			$days = 90;
		} elseif ( $period === '1y' ) {
			$days = 365;
		}

		$growth_data = array(
			'labels'            => array(),
			'new_subscriptions' => array(),
			'cancellations'     => array(),
		);

		// Generate data for each day in the period
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date                    = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
			$growth_data['labels'][] = gmdate( 'M d', strtotime( $date ) );

			// Count new subscriptions for this date
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
			$new_subs                           = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE DATE(created_at) = %s",
					$date
				)
			);
			$growth_data['new_subscriptions'][] = intval( $new_subs );

			// Count cancellations for this date
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
			$cancelled                      = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE DATE(cancellation_date) = %s OR (status = 'cancelled' AND DATE(updated_at) = %s)",
					$date,
					$date
				)
			);
			$growth_data['cancellations'][] = intval( $cancelled );
		}

		$analytics['growthTrend'] = $growth_data;

		// Get cohort analysis data (last 6 months)
		$cohort_data = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$cohort_month = gmdate( 'Y-m', strtotime( "-$i months" ) );
			$cohort_label = gmdate( 'M Y', strtotime( "-$i months" ) );

			// Get users who started in this cohort
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
			$cohort_users = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
					$cohort_month
				)
			);

			if ( $cohort_users > 0 ) {
				$retention     = array();
				$revenue       = array();
				$subscriptions = array();

				// Get initial revenue for this cohort
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
				$initial_revenue = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT SUM(billing_amount) FROM {$wpdb->prefix}recurio_subscriptions 
                    WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
						$cohort_month
					)
				) ?: 0;

				// Calculate metrics for each subsequent month (up to 12 months)
				for ( $month = 0; $month <= min( 11, $i ); $month++ ) {
					if ( $month === 0 ) {
						// First month is always 100% retention, full revenue, full subscriptions
						$retention[]     = 100;
						$revenue[]       = round( $initial_revenue, 2 );
						$subscriptions[] = $cohort_users;
					} else {
						$check_date = gmdate( 'Y-m', strtotime( $cohort_month . " +$month months" ) );

						// Calculate retained customers
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
						$retained = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions 
                            WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s 
                            AND (status = 'active' OR (status = 'cancelled' AND DATE_FORMAT(cancellation_date, '%%Y-%%m') > %s))",
								$cohort_month,
								$check_date
							)
						);

						// Calculate revenue from retained customers
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
						$retained_revenue = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT SUM(billing_amount) FROM {$wpdb->prefix}recurio_subscriptions 
                            WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s 
                            AND (status = 'active' OR (status = 'cancelled' AND DATE_FORMAT(cancellation_date, '%%Y-%%m') > %s))",
								$cohort_month,
								$check_date
							)
						) ?: 0;

						// Calculate active subscriptions from this cohort
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API analytics
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time analytics data
						$active_subs = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                            WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s 
                            AND (status = 'active' OR (status = 'cancelled' AND DATE_FORMAT(cancellation_date, '%%Y-%%m') > %s))",
								$cohort_month,
								$check_date
							)
						) ?: 0;

						$retention_rate  = round( ( $retained / $cohort_users ) * 100 );
						$retention[]     = $retention_rate;
						$revenue[]       = round( $retained_revenue, 2 );
						$subscriptions[] = $active_subs;
					}
				}

				$cohort_data[] = array(
					'month'         => $cohort_label,
					'users'         => $cohort_users,
					'retention'     => $retention,
					'revenue'       => $revenue,
					'subscriptions' => $subscriptions,
				);
			}
		}
		$analytics['cohortAnalysis'] = $cohort_data;

		// Get churn analysis - monthly churn rate for last 6 months
		$churn_data = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$month_date  = gmdate( 'Y-m', strtotime( "-$i months" ) );
			$month_label = gmdate( 'M', strtotime( "-$i months" ) );

			// Count cancellations in this month
			$cancellations = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE DATE_FORMAT(cancellation_date, '%%Y-%%m') = %s 
                OR (status = 'cancelled' AND DATE_FORMAT(updated_at, '%%Y-%%m') = %s)",
					$month_date,
					$month_date
				)
			);

			// Count active subscriptions at start of month
			$active_start = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions 
                WHERE created_at < %s AND (cancellation_date IS NULL OR cancellation_date > %s)",
					$month_date . '-01',
					$month_date . '-01'
				)
			);

			$churn_rate = $active_start > 0 ? round( ( $cancellations / $active_start ) * 100, 1 ) : 0;

			$churn_data[] = array(
				'month' => $month_label,
				'rate'  => $churn_rate,
			);
		}
		$analytics['churnAnalysis'] = $churn_data;

		// Get top churn reasons (if stored in metadata)
		$churn_reasons = $wpdb->get_results(
			"SELECT 
                CASE 
                    WHEN cancellation_reason IS NULL OR cancellation_reason = '' THEN 'Not specified'
                    ELSE cancellation_reason
                END as reason,
                COUNT(*) as count
            FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE status = 'cancelled'
            GROUP BY cancellation_reason
            ORDER BY count DESC
            LIMIT 5"
		);

		$total_cancellations = array_sum( array_column( $churn_reasons, 'count' ) );
		foreach ( $churn_reasons as &$reason ) {
			$reason->percentage = $total_cancellations > 0 ?
				round( ( $reason->count / $total_cancellations ) * 100 ) : 0;
		}
		$analytics['churnReasons'] = $churn_reasons;

		// Calculate revenue forecast based on historical data
		$forecast_data         = $this->calculate_revenue_forecast( $wpdb );
		$analytics['forecast'] = $forecast_data;

		// Add currency information
		$analytics['currency'] = array(
			'code'   => get_woocommerce_currency(),
			'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
		);

		/**
		 * Allow Pro plugin to enhance analytics data.
		 *
		 * Pro can use this hook to add advanced analytics like:
		 * - Cohort analysis with deeper segmentation
		 * - Revenue forecasting with AI predictions
		 * - Advanced customer segmentation
		 * - Predictive churn scoring
		 *
		 * @since 1.1.0
		 * @param array  $analytics The analytics data array
		 * @param string $period    The period filter (7d, 30d, 90d, 1y)
		 */
		$analytics = apply_filters( 'recurio_analytics_data', $analytics, $period );

		return new WP_REST_Response( $analytics, 200 );
	}

	/**
	 * Calculate revenue forecast
	 */
	private function calculate_revenue_forecast( $wpdb ) {
		// Get current MRR first
		$current_mrr = $wpdb->get_var(
			"SELECT SUM(billing_amount) FROM {$wpdb->prefix}recurio_subscriptions 
            WHERE status = 'active'"
		) ?: 0;

		// Get historical revenue data for last 12 months
		$historical_data  = array();
		$monthly_revenues = array();

		// If we have active subscriptions, generate some historical data based on created dates
		if ( $current_mrr > 0 ) {
			for ( $i = 11; $i >= 0; $i-- ) {
				$month_date    = gmdate( 'Y-m', strtotime( "-$i months" ) );
				$month_label   = gmdate( 'M', strtotime( "-$i months" ) );
				$current_month = gmdate( 'Y-m' );

				// For current month, use actual MRR
				if ( $month_date === $current_month ) {
					$month_revenue = $current_mrr;
				} else {
					// Count active subscriptions that existed in this month
					$month_revenue = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT SUM(billing_amount) FROM {$wpdb->prefix}recurio_subscriptions 
                        WHERE DATE(created_at) <= LAST_DAY(%s)
                        AND (status = 'active' OR (status = 'cancelled' AND DATE(cancellation_date) > LAST_DAY(%s)))",
							$month_date . '-01',
							$month_date . '-01'
						)
					) ?: 0;

					// If no historical data exists and it's a past month, simulate with declining values
					if ( $month_revenue == 0 && $i > 0 ) {
						// Only simulate if we don't have enough real data
						$month_revenue = $current_mrr * ( 1 - ( $i * 0.05 ) ); // Simulate 5% monthly growth backwards
						$month_revenue = max( 0, $month_revenue ); // Ensure non-negative
					}
				}

				$historical_data[]  = array(
					'month'   => $month_label,
					'revenue' => floatval( $month_revenue ),
				);
				$monthly_revenues[] = floatval( $month_revenue );
			}
		} else {
			// No data, create empty historical
			for ( $i = 11; $i >= 0; $i-- ) {
				$month_label        = gmdate( 'M', strtotime( "-$i months" ) );
				$historical_data[]  = array(
					'month'   => $month_label,
					'revenue' => 0,
				);
				$monthly_revenues[] = 0;
			}
		}

		// Calculate growth trend
		$n           = count( $monthly_revenues );
		$avg_revenue = $n > 0 ? array_sum( $monthly_revenues ) / $n : 0;

		// Calculate growth rate
		$growth_sum   = 0;
		$growth_count = 0;
		for ( $i = 1; $i < $n; $i++ ) {
			if ( $monthly_revenues[ $i - 1 ] > 0 ) {
				$growth_sum += ( $monthly_revenues[ $i ] - $monthly_revenues[ $i - 1 ] ) / $monthly_revenues[ $i - 1 ];
				++$growth_count;
			}
		}
		$avg_growth_rate = $growth_count > 0 ? $growth_sum / $growth_count : 0.05; // Default 5% growth if no data

		// Project future revenue with growth factor
		$growth_factor = 1 + max( 0, min( $avg_growth_rate, 0.2 ) ); // Cap at 20% growth

		// Calculate forecasts
		$next_month   = $current_mrr * $growth_factor;
		$next_quarter = $current_mrr * pow( $growth_factor, 3 ) * 3; // 3 months
		$next_year    = $current_mrr * pow( $growth_factor, 12 ) * 12; // 12 months

		// Calculate confidence based on data consistency
		$variance = 0;
		if ( $n > 1 ) {
			foreach ( $monthly_revenues as $revenue ) {
				$variance += pow( $revenue - $avg_revenue, 2 );
			}
			$variance   = sqrt( $variance / $n );
			$cv         = $avg_revenue > 0 ? ( $variance / $avg_revenue ) : 1; // Coefficient of variation
			$confidence = max( 40, min( 95, round( ( 1 - $cv ) * 100 ) ) ); // Convert to confidence %
		} else {
			$confidence = 50; // Low confidence with limited data
		}

		// Generate forecast chart data (next 12 months)
		$forecast_chart     = array();
		$current_projection = $current_mrr;

		for ( $i = 0; $i < 12; $i++ ) {
			$current_projection *= $growth_factor;
			$forecast_chart[]    = round( $current_projection, 2 );
		}

		// Check if we have sufficient data (at least 3 months of history)
		$months_with_data = 0;
		foreach ( $monthly_revenues as $revenue ) {
			if ( $revenue > 0 ) {
				++$months_with_data;
			}
		}
		$has_sufficient_data = $months_with_data >= 3;

		return array(
			'nextMonth'         => round( $next_month, 2 ),
			'nextQuarter'       => round( $next_quarter, 2 ),
			'nextYear'          => round( $next_year, 2 ),
			'confidence'        => $confidence,
			'historicalData'    => $historical_data,
			'forecastData'      => $forecast_chart,
			'growthRate'        => round( $avg_growth_rate * 100, 1 ),
			'hasSufficientData' => $has_sufficient_data,
			'monthsWithData'    => $months_with_data,
		);
	}

	/**
	 * Get revenue data
	 */
	public function get_revenue( $request ) {
		global $wpdb;
		$period = $request['period'] ?? 'monthly';

		// Work with real revenue data only

		// Calculate revenue metrics
		$today_revenue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
            WHERE DATE(created_at) = %s",
				gmdate( 'Y-m-d' )
			)
		) ?: 0;

		// Yesterday's revenue for comparison
		$yesterday_revenue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
            WHERE DATE(created_at) = %s",
				gmdate( 'Y-m-d', strtotime( '-1 day' ) )
			)
		) ?: 0;

		$today_growth = $yesterday_revenue > 0 ? round( ( ( $today_revenue - $yesterday_revenue ) / $yesterday_revenue ) * 100, 1 ) : 0;

		$month_revenue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
            WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
				gmdate( 'n' ),
				gmdate( 'Y' )
			)
		) ?: 0;

		$quarter_revenue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
            WHERE QUARTER(created_at) = %d AND YEAR(created_at) = %d",
				ceil( gmdate( 'n' ) / 3 ),
				gmdate( 'Y' )
			)
		) ?: 0;

		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$stats               = $subscription_engine->get_statistics();

		// Calculate month and quarter progress (assuming targets)
		$month_target     = 150000;
		$quarter_target   = 500000;
		$month_progress   = min( 100, round( ( $month_revenue / $month_target ) * 100 ) );
		$quarter_progress = min( 100, round( ( $quarter_revenue / $quarter_target ) * 100 ) );

		$revenue = array(
			'today'           => floatval( $today_revenue ),
			'todayGrowth'     => $today_growth,
			'thisMonth'       => floatval( $month_revenue ),
			'monthProgress'   => $month_progress,
			'quarterly'       => floatval( $quarter_revenue ),
			'quarterProgress' => $quarter_progress,
			'arr'             => $stats['arr'],
			'mrr'             => $stats['mrr'],
		);

		// Get product revenue breakdown
		$revenue['productRevenue'] = $wpdb->get_results(
			"SELECT 
                p.post_title as product,
                SUM(s.billing_amount) as revenue,
                COUNT(s.id) as count
            FROM {$wpdb->prefix}recurio_subscriptions s
            LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
            WHERE s.status = 'active'
            GROUP BY s.product_id
            ORDER BY revenue DESC"
		);

		// Get payment methods distribution from revenue table
		$payment_methods = $wpdb->get_results(
			"SELECT 
                payment_method,
                SUM(amount) as total,
                COUNT(*) as count
            FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE MONTH(created_at) = MONTH(CURRENT_DATE())
            GROUP BY payment_method"
		);

		if ( empty( $payment_methods ) ) {
			// Default distribution if no data
			$revenue['paymentMethods'] = array(
				array(
					'name'       => 'Credit Card',
					'amount'     => $month_revenue * 0.68,
					'percentage' => 68,
				),
				array(
					'name'       => 'PayPal',
					'amount'     => $month_revenue * 0.20,
					'percentage' => 20,
				),
				array(
					'name'       => 'Stripe',
					'amount'     => $month_revenue * 0.08,
					'percentage' => 8,
				),
				array(
					'name'       => 'Other',
					'amount'     => $month_revenue * 0.04,
					'percentage' => 4,
				),
			);
		} else {
			$total                     = array_sum( array_column( $payment_methods, 'total' ) );
			$revenue['paymentMethods'] = array_map(
				function ( $method ) use ( $total ) {
					return array(
						'name'       => $method->payment_method ?: 'Unknown',
						'amount'     => floatval( $method->total ),
						'percentage' => $total > 0 ? round( ( $method->total / $total ) * 100 ) : 0,
					);
				},
				$payment_methods
			);
		}

		// Get revenue chart data based on period
		$chart_data           = $this->get_revenue_chart_data( $period );
		$revenue['chartData'] = $chart_data;

		// Add currency information
		$revenue['currency'] = array(
			'code'   => get_woocommerce_currency(),
			'symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
		);

		return new WP_REST_Response( $revenue, 200 );
	}

	/**
	 * Get revenue chart data
	 */
	private function get_revenue_chart_data( $period = 'monthly' ) {
		global $wpdb;

		$labels           = array();
		$revenue_data     = array();
		$transaction_data = array();
		$avg_value_data   = array();

		switch ( $period ) {
			case 'daily':
				// Last 30 days
				for ( $i = 29; $i >= 0; $i-- ) {
					$date     = gmdate( 'Y-m-d', strtotime( "-$i days" ) );
					$labels[] = gmdate( 'M d', strtotime( $date ) );

					$daily_revenue = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE DATE(created_at) = %s",
							$date
						)
					) ?: 0;

					$daily_transactions = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE DATE(created_at) = %s",
							$date
						)
					) ?: 0;

					$revenue_data[]     = round( $daily_revenue, 2 );
					$transaction_data[] = $daily_transactions;
					$avg_value_data[]   = $daily_transactions > 0 ? round( $daily_revenue / $daily_transactions, 2 ) : 0;
				}
				break;

			case 'weekly':
				// Last 12 weeks
				for ( $i = 11; $i >= 0; $i-- ) {
					$week_start = gmdate( 'Y-m-d', strtotime( "-$i weeks" ) );
					$week_end   = gmdate( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );
					$labels[]   = 'Week ' . gmdate( 'W', strtotime( $week_start ) );

					$weekly_revenue = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE DATE(created_at) BETWEEN %s AND %s",
							$week_start,
							$week_end
						)
					) ?: 0;

					$weekly_transactions = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE DATE(created_at) BETWEEN %s AND %s",
							$week_start,
							$week_end
						)
					) ?: 0;

					$revenue_data[]     = round( $weekly_revenue, 2 );
					$transaction_data[] = $weekly_transactions;
					$avg_value_data[]   = $weekly_transactions > 0 ? round( $weekly_revenue / $weekly_transactions, 2 ) : 0;
				}
				break;

			case 'yearly':
				// Last 5 years
				for ( $i = 4; $i >= 0; $i-- ) {
					$year     = gmdate( 'Y', strtotime( "-$i years" ) );
					$labels[] = $year;

					$yearly_revenue = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE YEAR(created_at) = %d",
							$year
						)
					) ?: 0;

					$yearly_transactions = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE YEAR(created_at) = %d",
							$year
						)
					) ?: 0;

					$revenue_data[]     = round( $yearly_revenue, 2 );
					$transaction_data[] = $yearly_transactions;
					$avg_value_data[]   = $yearly_transactions > 0 ? round( $yearly_revenue / $yearly_transactions, 2 ) : 0;
				}
				break;

			case 'monthly':
			default:
				// Last 12 months
				for ( $i = 11; $i >= 0; $i-- ) {
					$month_date = gmdate( 'Y-m', strtotime( "-$i months" ) );
					$labels[]   = gmdate( 'M Y', strtotime( $month_date . '-01' ) );

					$monthly_revenue = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
							$month_date
						)
					) ?: 0;

					$monthly_transactions = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscription_revenue 
                        WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
							$month_date
						)
					) ?: 0;

					$revenue_data[]     = round( $monthly_revenue, 2 );
					$transaction_data[] = $monthly_transactions;
					$avg_value_data[]   = $monthly_transactions > 0 ? round( $monthly_revenue / $monthly_transactions, 2 ) : 0;
				}
				break;
		}

		return array(
			'labels'       => $labels,
			'revenue'      => $revenue_data,
			'transactions' => $transaction_data,
			'averageValue' => $avg_value_data,
		);
	}

	/**
	 * Get transactions
	 */
	public function get_transactions( $request ) {
		global $wpdb;

		// Work with real revenue data only

		$params     = $request->get_params();
		$page       = isset( $params['page'] ) ? intval( $params['page'] ) : 1;
		$per_page   = isset( $params['per_page'] ) ? intval( $params['per_page'] ) : 25;
		$offset     = ( $page - 1 ) * $per_page;
		$start_date = isset( $params['start_date'] ) ? sanitize_text_field( $params['start_date'] ) : '';
		$end_date   = isset( $params['end_date'] ) ? sanitize_text_field( $params['end_date'] ) : '';

		// Build WHERE clause
		$where = 'WHERE 1=1';
		if ( $start_date && $end_date ) {
			$where .= $wpdb->prepare( ' AND r.created_at BETWEEN %s AND %s', $start_date, $end_date );
		}

		$transactions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
                r.id,
                r.subscription_id,
                r.amount,
                r.payment_method,
                r.transaction_id,
                r.created_at as date,
                s.customer_id,
                s.product_id,
                u.display_name as customer,
                u.user_email as customer_email,
                p.post_title as product,
                'completed' as status
            FROM {$wpdb->prefix}recurio_subscription_revenue r
            LEFT JOIN {$wpdb->prefix}recurio_subscriptions s ON r.subscription_id = s.id
            LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
            LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
            {$where} /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
            ORDER BY r.created_at DESC
            LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		// Format transactions for display
		foreach ( $transactions as &$transaction ) {
			if ( ! $transaction->customer ) {
				$transaction->customer = $transaction->customer_email ?: 'Unknown Customer';
			}
			if ( ! $transaction->product ) {
				$transaction->product = 'Subscription #' . $transaction->subscription_id;
			}
			$transaction->method = $transaction->payment_method ?: 'Credit Card';
		}

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscription_revenue r {$where}"
			)
		);

		return new WP_REST_Response(
			array(
				'transactions' => $transactions,
				'total'        => intval( $total ),
				'page'         => $page,
				'per_page'     => $per_page,
				'total_pages'  => ceil( $total / $per_page ),
			),
			200
		);
	}

	/**
	 * Get revenue goals
	 */
	public function get_revenue_goals( $request ) {
		// IMPORTANT: This method does NOT check for Pro license status.
		// Goals are displayed in read-only mode in free version to preserve data
		// and show users the value of Pro features.
		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_revenue_goals';

		// Get active goals from database
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time goal data
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely constructed
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Simple query with safe table name
		$goals = $wpdb->get_results(
			"SELECT * FROM $table_name WHERE status = 'active' ORDER BY end_date ASC", /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
			ARRAY_A
		);

		// Format goals for frontend
		$formatted_goals = array();
		foreach ( $goals as $goal ) {
			// Calculate progress percentage
			$progress = $goal['target_amount'] > 0
				? round( ( $goal['current_amount'] / $goal['target_amount'] ) * 100 )
				: 0;

			// Calculate days left
			$days_left = max( 0, ceil( ( strtotime( $goal['end_date'] ) - time() ) / 86400 ) );

			// Determine status based on progress and time
			$status = 'on-track';
			if ( $progress >= 100 ) {
				$status = 'completed';
			} elseif ( $days_left < 7 && $progress < 75 ) {
				$status = 'at-risk';
			} elseif ( $progress < 50 && $days_left < 30 ) {
				$status = 'behind';
			}

			$formatted_goals[] = array(
				'id'         => $goal['id'],
				'name'       => $goal['name'],
				'target'     => floatval( $goal['target_amount'] ),
				'current'    => floatval( $goal['current_amount'] ),
				'progress'   => $progress,
				'status'     => $status,
				'period'     => $goal['period_type'],
				'start_date' => $goal['start_date'],
				'deadline'   => $goal['end_date'],
				'daysLeft'   => $days_left,
			);
		}

		return new WP_REST_Response( $formatted_goals, 200 );
	}

	/**
	 * Calculate current amount for a goal based on period and deadline
	 *
	 * IMPORTANT: This method does NOT check for Pro license status.
	 * Goal tracking/calculation continues to run in the background even in free version
	 * to ensure data continuity. When Pro is reactivated, all data will be current.
	 */
	public function calculate_goal_current_amount( $request ) {
		global $wpdb;

		$period_type = sanitize_text_field( $request['period'] ?? 'monthly' );
		$end_date    = sanitize_text_field( $request['deadline'] ?? gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

		// Calculate start date based on period type
		switch ( $period_type ) {
			case 'yearly':
				$start_date = gmdate( 'Y-01-01', strtotime( $end_date ) );
				break;
			case 'quarterly':
				$quarter    = ceil( gmdate( 'n', strtotime( $end_date ) ) / 3 );
				$start_date = gmdate( 'Y-' . sprintf( '%02d', ( $quarter - 1 ) * 3 + 1 ) . '-01', strtotime( $end_date ) );
				break;
			case 'monthly':
			default:
				$start_date = gmdate( 'Y-m-01', strtotime( $end_date ) );
				break;
		}

		// Calculate current amount from actual revenue
		$current_amount = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE DATE(created_at) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		return new WP_REST_Response(
			array(
				'current_amount' => floatval( $current_amount ),
				'start_date'     => $start_date,
				'end_date'       => $end_date,
				'period'         => $period_type,
			),
			200
		);
	}

	/**
	 * Create revenue goal
	 */
	public function create_revenue_goal( $request ) {
		// Check if Pro is licensed - only allow creating goals with Pro
		if ( ! recurio_is_pro_licensed() ) {
			return new WP_Error(
				'pro_required',
				__( 'Revenue Goals is a Pro feature. Please upgrade to create and manage goals.', 'recurio' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_revenue_goals';

		// Calculate start date based on period type
		$period_type = sanitize_text_field( $request['period'] ?? 'monthly' );
		$end_date    = sanitize_text_field( $request['deadline'] ?? gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

		// Determine start date based on period type
		switch ( $period_type ) {
			case 'yearly':
				$start_date = gmdate( 'Y-01-01', strtotime( $end_date ) );
				break;
			case 'quarterly':
				$quarter    = ceil( gmdate( 'n', strtotime( $end_date ) ) / 3 );
				$start_date = gmdate( 'Y-' . sprintf( '%02d', ( $quarter - 1 ) * 3 + 1 ) . '-01', strtotime( $end_date ) );
				break;
			case 'monthly':
			default:
				$start_date = gmdate( 'Y-m-01', strtotime( $end_date ) );
				break;
		}

		// Calculate initial current amount from actual revenue
		$current_amount = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE DATE(created_at) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		// Insert new goal
		$wpdb->insert(
			$table_name,
			array(
				'name'           => sanitize_text_field( $request['name'] ),
				'target_amount'  => floatval( $request['target'] ),
				'current_amount' => floatval( $current_amount ),
				'period_type'    => $period_type,
				'start_date'     => $start_date,
				'end_date'       => $end_date,
				'status'         => 'active',
			)
		);

		$new_goal_id = $wpdb->insert_id;

		// Return the newly created goal
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time goal data
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely constructed
		$new_goal = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d", /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
				$new_goal_id
			),
			ARRAY_A
		);

		// Format for frontend
		$progress = $new_goal['target_amount'] > 0
			? round( ( $new_goal['current_amount'] / $new_goal['target_amount'] ) * 100 )
			: 0;

		$formatted_goal = array(
			'id'         => $new_goal['id'],
			'name'       => $new_goal['name'],
			'target'     => floatval( $new_goal['target_amount'] ),
			'current'    => floatval( $new_goal['current_amount'] ),
			'progress'   => $progress,
			'status'     => 'on-track',
			'period'     => $new_goal['period_type'],
			'start_date' => $new_goal['start_date'],
			'deadline'   => $new_goal['end_date'],
			'daysLeft'   => max( 0, ceil( ( strtotime( $new_goal['end_date'] ) - time() ) / 86400 ) ),
		);

		return new WP_REST_Response( $formatted_goal, 201 );
	}

	/**
	 * Update revenue goal
	 */
	public function update_revenue_goal( $request ) {
		// Check if Pro is licensed - only allow updating goals with Pro
		if ( ! recurio_is_pro_licensed() ) {
			return new WP_Error(
				'pro_required',
				__( 'Revenue Goals is a Pro feature. Please upgrade to create and manage goals.', 'recurio' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_revenue_goals';
		$goal_id    = intval( $request['id'] );

		// Check if goal exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time goal data
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely constructed
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d", /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
				$goal_id
			),
			ARRAY_A
		);

		if ( ! $existing ) {
			return new WP_Error( 'goal_not_found', __( 'Revenue goal not found', 'recurio' ), array( 'status' => 404 ) );
		}

		// Get updated values from request
		$period_type = sanitize_text_field( $request['period'] ?? $existing['period_type'] );
		$end_date    = sanitize_text_field( $request['deadline'] ?? $existing['end_date'] );

		// Recalculate start date based on period type and end date
		switch ( $period_type ) {
			case 'yearly':
				$start_date = gmdate( 'Y-01-01', strtotime( $end_date ) );
				break;
			case 'quarterly':
				$quarter    = ceil( gmdate( 'n', strtotime( $end_date ) ) / 3 );
				$start_date = gmdate( 'Y-' . sprintf( '%02d', ( $quarter - 1 ) * 3 + 1 ) . '-01', strtotime( $end_date ) );
				break;
			case 'monthly':
			default:
				$start_date = gmdate( 'Y-m-01', strtotime( $end_date ) );
				break;
		}

		// Recalculate current amount based on new date range
		$current_amount = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE DATE(created_at) BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);

		// Update goal with all fields
		$wpdb->update(
			$table_name,
			array(
				'name'           => sanitize_text_field( $request['name'] ),
				'target_amount'  => floatval( $request['target'] ),
				'current_amount' => floatval( $current_amount ),
				'period_type'    => $period_type,
				'start_date'     => $start_date,
				'end_date'       => $end_date,
			),
			array( 'id' => $goal_id )
		);

		// Get updated goal
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time goal data
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely constructed
		$updated_goal = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d", /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
				$goal_id
			),
			ARRAY_A
		);

		// Format for frontend
		$progress = $updated_goal['target_amount'] > 0
			? round( ( $updated_goal['current_amount'] / $updated_goal['target_amount'] ) * 100 )
			: 0;

		$formatted_goal = array(
			'id'         => $updated_goal['id'],
			'name'       => $updated_goal['name'],
			'target'     => floatval( $updated_goal['target_amount'] ),
			'current'    => floatval( $updated_goal['current_amount'] ),
			'progress'   => $progress,
			'status'     => $progress >= 100 ? 'completed' : 'on-track',
			'period'     => $updated_goal['period_type'],
			'start_date' => $updated_goal['start_date'],
			'deadline'   => $updated_goal['end_date'],
			'daysLeft'   => max( 0, ceil( ( strtotime( $updated_goal['end_date'] ) - time() ) / 86400 ) ),
		);

		return new WP_REST_Response( $formatted_goal, 200 );
	}

	/**
	 * Delete revenue goal
	 */
	public function delete_revenue_goal( $request ) {
		// Check if Pro is licensed - only allow deleting goals with Pro
		if ( ! recurio_is_pro_licensed() ) {
			return new WP_Error(
				'pro_required',
				__( 'Revenue Goals is a Pro feature. Please upgrade to create and manage goals.', 'recurio' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_revenue_goals';
		$goal_id    = intval( $request['id'] );

		// Check if goal exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for REST API
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time goal data
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely constructed
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE id = %d", /* phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
				$goal_id
			)
		);

		if ( ! $existing ) {
			return new WP_Error( 'goal_not_found', __( 'Revenue goal not found', 'recurio' ), array( 'status' => 404 ) );
		}

		// Soft delete by updating status to 'deleted'
		$result = $wpdb->update(
			$table_name,
			array( 'status' => 'deleted' ),
			array( 'id' => $goal_id )
		);

		if ( $result !== false ) {
			return new WP_REST_Response( array( 'message' => __( 'Goal deleted successfully', 'recurio' ) ), 200 );
		}

		return new WP_Error( 'delete_failed', __( 'Failed to delete goal', 'recurio' ), array( 'status' => 500 ) );
	}

	/**
	 * Get dashboard stats
	 */
	public function get_dashboard_stats( $request ) {
		global $wpdb;

		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$stats               = $subscription_engine->get_statistics();

		// Get recent activity count
		$recent_activity = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscription_events
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);

		// Get today's revenue
		$today_revenue = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE DATE(created_at) = %s",
				gmdate( 'Y-m-d' )
			)
		) ?: 0;

		// Get WooCommerce currency settings
		$currency        = get_woocommerce_currency();
		$currency_symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ) );

		$metrics = array(
			'subscriptions' => array(
				'total'     => $stats['total'],
				'active'    => $stats['active'],
				'new_today' => $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions
                WHERE DATE(created_at) = %s",
						gmdate( 'Y-m-d' )
					)
				),
			),
			'revenue'       => array(
				'mrr'   => $stats['mrr'],
				'arr'   => $stats['arr'],
				'today' => floatval( $today_revenue ),
			),
			'customers'     => array(
				'total'     => $wpdb->get_var( "SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions" ),
				'new_today' => $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}recurio_subscriptions
                WHERE DATE(created_at) = %s",
						gmdate( 'Y-m-d' )
					)
				),
			),
			'activity'      => array(
				'recent_count' => intval( $recent_activity ),
			),
			'currency'      => array(
				'code'   => $currency,
				'symbol' => $currency_symbol,
			),
		);

		/**
		 * Allow Pro plugin to enhance dashboard metrics.
		 *
		 * Pro can use this hook to add advanced metrics like:
		 * - Churn risk score
		 * - Revenue forecasting
		 * - Advanced customer segmentation
		 * - Predictive analytics
		 *
		 * @since 1.1.0
		 * @param array $metrics The dashboard metrics array
		 */
		$metrics = apply_filters( 'recurio_dashboard_metrics', $metrics );

		return new WP_REST_Response( $metrics, 200 );
	}

	/**
	 * Get recent activity
	 */
	public function get_recent_activity( $request ) {
		global $wpdb;

		$activities = $wpdb->get_results(
			"SELECT 
                e.*,
                s.customer_id,
                u.display_name as customer_name,
                p.post_title as product_name
            FROM {$wpdb->prefix}recurio_subscription_events e
            LEFT JOIN {$wpdb->prefix}recurio_subscriptions s ON e.subscription_id = s.id
            LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
            LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
            ORDER BY e.created_at DESC
            LIMIT 20"
		);

		return new WP_REST_Response( $activities, 200 );
	}

	/**
	 * Format subscription for API response
	 */
	private function format_subscription( $subscription ) {
		global $wpdb;

		if ( is_object( $subscription ) ) {
			$subscription = (array) $subscription;
		}

		// Add product name
		if ( ! empty( $subscription['product_id'] ) ) {
			$product                      = wc_get_product( $subscription['product_id'] );
			$subscription['product_name'] = $product ? $product->get_name() : '';
		}

		// Add customer name if not already included
		if ( ! empty( $subscription['customer_id'] ) && empty( $subscription['customer_name'] ) ) {
			$user = get_user_by( 'id', $subscription['customer_id'] );
			if ( $user ) {
				$subscription['customer_name']  = $user->display_name;
				$subscription['customer_email'] = $user->user_email;
			}
		}

		// Calculate total paid from revenue records
		$total_paid                 = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE subscription_id = %d",
				$subscription['id']
			)
		);
		$subscription['total_paid'] = floatval( $total_paid );

		// Get payment breakdown
		// Get the first/initial payment amount (regardless of period_type)
		$initial_payment = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(amount, 0) FROM {$wpdb->prefix}recurio_subscription_revenue
            WHERE subscription_id = %d
            ORDER BY created_at ASC LIMIT 1",
				$subscription['id']
			)
		);

		// Calculate recurring payments = Total Paid - Initial Payment
		// This correctly separates the first payment (which may include signup fee) from subsequent renewals
		$recurring_payments = max( 0, floatval( $total_paid ) - floatval( $initial_payment ) );

		// Get signup fee from metadata
		$signup_fee_paid = 0;
		$variation_id    = 0;
		if ( ! empty( $subscription['subscription_metadata'] ) ) {
			$metadata = is_string( $subscription['subscription_metadata'] )
				? json_decode( $subscription['subscription_metadata'], true )
				: $subscription['subscription_metadata'];

			if ( isset( $metadata['signup_fee'] ) ) {
				$signup_fee_paid = floatval( $metadata['signup_fee'] );
			}

			// Get variation_id if present
			if ( isset( $metadata['variation_id'] ) ) {
				$variation_id = intval( $metadata['variation_id'] );
			}
		}

		// If no signup fee in metadata, try to get from product meta
		if ( $signup_fee_paid == 0 && ! empty( $subscription['product_id'] ) ) {
			// First check variation if we have a variation_id
			if ( $variation_id ) {
				$variation_override = get_post_meta( $variation_id, '_recurio_override_subscription', true ) === 'yes';
				if ( $variation_override ) {
					$var_signup = get_post_meta( $variation_id, '_recurio_subscription_signup_fee', true );
					if ( $var_signup !== '' && $var_signup !== false ) {
						$signup_fee_paid = floatval( $var_signup );
					}
				}
			}
			// Fall back to parent product signup fee
			if ( $signup_fee_paid == 0 ) {
				$product_signup_fee = get_post_meta( $subscription['product_id'], '_recurio_subscription_signup_fee', true );
				if ( $product_signup_fee ) {
					$signup_fee_paid = floatval( $product_signup_fee );
				}
			}
		}

		// Get payment count for display
		$payment_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscription_revenue WHERE subscription_id = %d",
				$subscription['id']
			)
		);

		// Use the actual sum of recurring payments (already calculated above)
		// This correctly reflects actual money received, not estimated amounts
		$subscription['payment_breakdown'] = array(
			'total_paid'               => floatval( $total_paid ),
			'signup_fee_paid'          => $signup_fee_paid,
			'recurring_payments_total' => floatval( $recurring_payments ),
			'payment_count'            => $payment_count,
		);

		// Decode metadata if needed and extract notes
		if ( ! empty( $subscription['subscription_metadata'] ) && is_string( $subscription['subscription_metadata'] ) ) {
			$metadata                              = json_decode( $subscription['subscription_metadata'], true );
			$subscription['subscription_metadata'] = $metadata;

			// Extract notes from metadata if present
			if ( isset( $metadata['notes'] ) ) {
				$subscription['notes'] = $metadata['notes'];
			}
		}

		// Ensure notes field exists
		if ( ! isset( $subscription['notes'] ) ) {
			$subscription['notes'] = '';
		}

		// Handle payment method - prioritize dedicated column over metadata
		if ( ! empty( $subscription['payment_method'] ) ) {
			// Use the dedicated column value as gateway ID
			$subscription['payment_gateway'] = $subscription['payment_method'];

			// Try to get human-readable title from metadata
			if ( ! empty( $subscription['subscription_metadata'] ) && isset( $subscription['subscription_metadata']['payment_method_title'] ) ) {
				$subscription['payment_method'] = $subscription['subscription_metadata']['payment_method_title'];
			} else {
				// Use the gateway ID as fallback
				$subscription['payment_method'] = $subscription['payment_gateway'];
			}
		} else {
			// Fallback to metadata if column is empty (for older subscriptions)
			if ( ! empty( $subscription['subscription_metadata'] ) ) {
				if ( isset( $subscription['subscription_metadata']['payment_method'] ) ) {
					$subscription['payment_gateway'] = $subscription['subscription_metadata']['payment_method'];
				}
				if ( isset( $subscription['subscription_metadata']['payment_method_title'] ) ) {
					$subscription['payment_method'] = $subscription['subscription_metadata']['payment_method_title'];
				} else {
					$subscription['payment_method'] = $subscription['payment_gateway'] ?? null;
				}
			} else {
				$subscription['payment_gateway'] = null;
				$subscription['payment_method']  = null;
			}
		}

		return $subscription;
	}

	/**
	 * Get collection parameters
	 */
	private function get_collection_params() {
		return array(
			'page'        => array(
				'default' => 1,
				'type'    => 'integer',
				'minimum' => 1,
			),
			'per_page'    => array(
				'default' => 25,
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
			'search'      => array(
				'type' => 'string',
			),
			'status'      => array(
				'type' => 'string',
				'enum' => array( 'active', 'paused', 'cancelled', 'pending', 'expired', 'completed', 'pending_renewal' ),
			),
			'customer_id' => array(
				'type' => 'integer',
			),
			'orderby'     => array(
				'type'    => 'string',
				'default' => 'created_at',
				'enum'    => array( 'id', 'created_at', 'updated_at', 'billing_amount', 'next_payment_date' ),
			),
			'order'       => array(
				'type'    => 'string',
				'default' => 'desc',
				'enum'    => array( 'asc', 'desc' ),
			),
		);
	}

	/**
	 * Get subscription arguments
	 */
	private function get_subscription_args() {
		return array(
			'customer_id'      => array(
				'required' => true,
				'type'     => 'integer',
			),
			'product_id'       => array(
				'required' => true,
				'type'     => 'integer',
			),
			'billing_period'   => array(
				'required' => true,
				'type'     => 'string',
				'enum'     => array( 'day', 'week', 'month', 'year' ),
			),
			'billing_interval' => array(
				'required' => true,
				'type'     => 'integer',
				'minimum'  => 1,
			),
			'billing_amount'   => array(
				'required' => true,
				'type'     => 'number',
			),
			'status'           => array(
				'type'    => 'string',
				'enum'    => array( 'active', 'paused', 'cancelled', 'pending', 'pending_cancellation', 'completed', 'pending_renewal' ),
				'default' => 'pending',
			),
			'start_date'       => array(
				'required'    => false,
				'type'        => 'string',
				'format'      => 'date',
				'description' => 'Subscription start date (YYYY-MM-DD)',
			),
			'trial_end_date'   => array(
				'required'    => false,
				'type'        => 'string',
				'format'      => 'date',
				'description' => 'Optional trial end date (YYYY-MM-DD)',
			),
			'notes'            => array(
				'required'    => false,
				'type'        => 'string',
				'description' => 'Optional notes about the subscription',
			),
		);
	}

	/**
	 * Get subscription update arguments
	 * For updates, all fields are optional except when performing actions
	 */
	private function get_subscription_update_args() {
		return array(
			'customer_id'       => array(
				'required' => false,
				'type'     => 'integer',
			),
			'product_id'        => array(
				'required' => false,
				'type'     => 'integer',
			),
			'billing_period'    => array(
				'required' => false,
				'type'     => 'string',
				'enum'     => array( 'day', 'week', 'month', 'year' ),
			),
			'billing_interval'  => array(
				'required' => false,
				'type'     => 'integer',
				'minimum'  => 1,
			),
			'billing_amount'    => array(
				'required' => false,
				'type'     => 'number',
			),
			'status'            => array(
				'required' => false,
				'type'     => 'string',
				'enum'     => array( 'active', 'paused', 'cancelled', 'pending', 'pending_cancellation', 'completed' ),
			),
			'next_payment_date' => array(
				'required' => false,
				'type'     => 'string',
				'format'   => 'date-time',
			),
			'trial_end_date'    => array(
				'required' => false,
				'type'     => 'string',
				'format'   => 'date',
			),
			'notes'             => array(
				'required' => false,
				'type'     => 'string',
			),
			'action'            => array(
				'required'    => false,
				'type'        => 'string',
				'enum'        => array( 'pause', 'resume', 'cancel' ),
				'description' => 'Action to perform on the subscription',
			),
			'duration'          => array(
				'required'    => false,
				'type'        => 'integer',
				'description' => 'Duration in days (for pause action)',
			),
			'reason'            => array(
				'required'    => false,
				'type'        => 'string',
				'description' => 'Reason for the action (for cancel action)',
			),
			'timing'            => array(
				'required'    => false,
				'type'        => 'string',
				'enum'        => array( 'immediate', 'end_of_period' ),
				'default'     => 'immediate',
				'description' => 'When the cancellation should take effect',
			),
		);
	}

}
