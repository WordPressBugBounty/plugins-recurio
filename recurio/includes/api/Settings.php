<?php
namespace Recurio\Api;

use WP_REST_Server;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API
 *
 * Handles plugin settings and payment gateway configuration.
 *
 * Routes:
 *   GET|PUT  /recurio/v1/settings
 *   GET      /recurio/v1/payment-gateways
 *
 * @package Recurio
 * @since   1.2.0
 */
class Settings {

	/** REST namespace. */
	private string $namespace = 'recurio/v1';

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	public function register_routes(): void {

		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/payment-gateways',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payment_gateways' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /settings
	 */
	public function get_settings( $request ) {
		$woo_currency        = get_woocommerce_currency();
		$woo_currency_symbol = html_entity_decode( get_woocommerce_currency_symbol( $woo_currency ) );
		$wp_timezone         = wp_timezone_string();

		$default_settings = array(
			'general'      => array(
				'enableSubscriptions'        => true,
				'currency'                   => $woo_currency,
				'currencySymbol'             => $woo_currency_symbol,
				'dateFormat'                 => get_option( 'date_format', 'Y-m-d' ),
				'timezone'                   => $wp_timezone,
				'enableCustomerPortal'       => true,
				'portalLocation'             => 'standalone',
				'myAccountEndpoint'          => 'subscriptions',
				'myAccountLabel'             => 'Subscriptions',
				'subscriptionButtonText'     => 'Subscribe Now',
				'enableEarlyRenewal'         => true,
				'enable_skip_billing_cycle'  => false,
				'enableSwitching'            => true,
				'allowDowngrades'            => true,
				'switchProration'            => 'prorate',
				'enableAI'                   => false,
				'debugMode'                  => false,
			),
			'billing'      => array(
				'periods'               => array( 'monthly', 'yearly' ),
				'trialLength'           => 14,
				'trialUnit'             => 'days',
				'gracePeriod'           => 3,
				'dunningAttempts'       => 3,
				'dunningInterval'       => 3,
				'enableProration'       => true,
				'autoRenewal'           => true,
				'allowedPaymentMethods' => array(
					'cod'    => false,
					'bacs'   => false,
					'cheque' => false,
					'stripe' => true,
					'paypal' => true,
				),
			),
			'emails'       => array(
				'fromName'                => get_bloginfo( 'name' ),
				'fromEmail'               => get_option( 'admin_email' ),
				'replyTo'                 => get_option( 'admin_email' ),
				'sendWelcome'             => true,
				'sendReceipt'             => true,
				'sendFailedPayment'       => true,
				'sendRenewalReminder'     => true,
				'reminderDays'            => 7,
				'sendCancellation'        => true,
				'sendSubscriptionPaused'  => true,
				'sendSubscriptionResumed' => true,
				'subscriptionSkipped'     => true,
				'sendSubscriptionExpired' => true,
				'sendTrialReminder'       => true,
				'headerImageId'           => 0,
				'headerHtml'              => '',
				'footerHtml'              => '',
				'customTemplates'         => array(),
			),
			'integrations' => array(
				'stripe'    => array(
					'enabled'       => false,
					'publicKey'     => '',
					'secretKey'     => '',
					'webhookSecret' => '',
				),
				'paypal'    => array(
					'enabled'      => false,
					'clientId'     => '',
					'clientSecret' => '',
					'sandbox'      => false,
				),
				'mailchimp' => array(
					'enabled' => false,
					'apiKey'  => '',
					'listId'  => '',
				),
			),
			'advanced'     => array(
				'apiRateLimit'   => 100,
				'cacheDuration'  => 300,
				'batchSize'      => 100,
				'webhookTimeout' => 30,
				'dataRetention'  => 365,
			),
			'cancellationFlow' => array(
				'enabled'                 => false,
				'offer_type'              => 'pause',
				'percent_off'             => 10,
				'fixed_amount'            => 5,
				'coupon_expiry_days'      => 14,
				'prevention_step_enabled' => true,
				'free_product_ids'        => array(),
				'survey_reasons'          => array(
					array( 'value' => 'too_expensive', 'label' => 'Too expensive' ),
					array( 'value' => 'not_using',     'label' => 'Not using it enough' ),
					array( 'value' => 'alternative',   'label' => 'Found an alternative' ),
					array( 'value' => 'pausing',       'label' => 'Just pausing for a while' ),
					array( 'value' => 'other',         'label' => 'Other' ),
				),
				'portal_copy'             => array(
					'title'                        => 'Before you go',
					'benefits_lead'                => 'Your subscription includes ongoing access and renewal protection.',
					'step_survey'                  => 'Tell us why you\'re leaving',
					'step_prevention'              => 'Need a break instead?',
					'prevention_lead'              => 'Pause billing or skip your next payment — no discount required.',
					'step_offer'                   => 'We have an option for you',
					'step_confirm'                 => 'Confirm cancellation',
					'keep_subscription'            => 'Keep my subscription',
					'continue'                     => 'Continue',
					'continue_to_offer'            => 'Continue to next step',
					'back'                         => 'Back',
					'pause_instead'                => 'Pause subscription',
					'skip_next_payment_btn'        => 'Skip next payment',
					'cancel_anyway'                => 'Continue to cancel',
					'i_will_use_discount'          => 'Got it — I\'ll keep my subscription',
					'accept_free_offer_btn'        => 'Accept offer',
					'choose_product_label'         => 'Choose your gift',
					'free_price_label'             => 'FREE',
					'final_confirm_hint'           => 'This will cancel your subscription according to the timing you selected.',
					'outcome_paused'               => 'Your subscription is paused.',
					'outcome_skipped_payment'      => 'Your next payment was skipped.',
					'outcome_kept_coupon'          => 'Thanks for staying — your discount is ready to use.',
					'outcome_kept_free_product'    => 'Thanks for staying — your complimentary item code is %s.',
					'outcome_cancelling'           => 'Processing your cancellation…',
					'pause_offer_body'             => 'You can pause your subscription instead of cancelling. You can resume anytime from this portal.',
					'pause_offer_after_prevention' => 'If you still want to leave, you can continue — or review a discount below if you change your mind.',
					'percent_offer_body'           => 'Stay subscribed with a one-time discount on your renewal.',
					'fixed_offer_body'             => 'Stay subscribed with a fixed discount on your next renewal.',
					'free_product_offer_body'      => 'Pick a complimentary product on us — available only because you stayed.',
					'coupon_use_hint'              => 'Use code %s at checkout on your next renewal order.',
				),
			),
		);

		$settings = get_option( 'recurio_settings', $default_settings );
		$settings = wp_parse_args( $settings, $default_settings );

		if ( is_array( $default_settings['general'] ) ) {
			$settings['general'] = wp_parse_args(
				is_array( $settings['general'] ) ? $settings['general'] : array(),
				$default_settings['general']
			);
		}

		if ( is_array( $default_settings['emails'] ) ) {
			$settings['emails'] = wp_parse_args(
				is_array( $settings['emails'] ) ? $settings['emails'] : array(),
				$default_settings['emails']
			);
		}

		if ( is_array( $default_settings['cancellationFlow'] ) ) {
			$settings['cancellationFlow'] = wp_parse_args(
				is_array( $settings['cancellationFlow'] ) ? $settings['cancellationFlow'] : array(),
				$default_settings['cancellationFlow']
			);

			if ( is_array( $default_settings['cancellationFlow']['portal_copy'] ) ) {
				$pc_existing = is_array( $settings['cancellationFlow']['portal_copy'] )
					? $settings['cancellationFlow']['portal_copy']
					: array();
				$settings['cancellationFlow']['portal_copy'] = wp_parse_args(
					$pc_existing,
					$default_settings['cancellationFlow']['portal_copy']
				);
			}
		}

		$preview_url = '';
		if ( ! empty( $settings['emails']['headerImageId'] ) ) {
			$hid = absint( $settings['emails']['headerImageId'] );
			if ( $hid && wp_attachment_is_image( $hid ) ) {
				$img_url = wp_get_attachment_image_url( $hid, 'medium' );
				if ( $img_url ) {
					$preview_url = $img_url;
				}
			}
		}
		$settings['emailHeaderImagePreviewUrl'] = $preview_url;

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * PUT|PATCH /settings
	 */
	public function update_settings( $request ) {
		$incoming = $request->get_json_params();

		if ( ! is_array( $incoming ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid settings payload.', 'recurio' ),
				),
				400
			);
		}

		$old_settings = get_option( 'recurio_settings', array() );

		// Merge deeply for `emails` so partial SPA payloads never wipe `customTemplates`
		// or boolean toggles that exist only in the database.
		$settings = $incoming;
		if ( isset( $incoming['emails'] ) && is_array( $incoming['emails'] ) ) {
			$base_emails        = isset( $old_settings['emails'] ) && is_array( $old_settings['emails'] ) ? $old_settings['emails'] : array();
			$settings['emails'] = array_replace_recursive( $base_emails, $incoming['emails'] );
		} elseif ( isset( $old_settings['emails'] ) && is_array( $old_settings['emails'] ) ) {
			$settings['emails'] = $old_settings['emails'];
		}

		// Ephemeral key must never be persisted.
		unset( $settings['emailHeaderImagePreviewUrl'] );

		// Handle cache clearing action.
		if ( isset( $settings['action'] ) && $settings['action'] === 'clear_cache' ) {
			wp_cache_flush();
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Cache cleared successfully', 'recurio' ),
				),
				200
			);
		}

		// Pro-only: email header image & custom HTML templates.
		if ( isset( $settings['emails'] ) && is_array( $settings['emails'] ) ) {
			$is_pro = function_exists( 'recurio_is_pro_licensed' ) && recurio_is_pro_licensed();

			if ( ! $is_pro ) {
				if ( isset( $old_settings['emails']['headerImageId'] ) && absint( $old_settings['emails']['headerImageId'] ) > 0 ) {
					$settings['emails']['headerImageId'] = absint( $old_settings['emails']['headerImageId'] );
				} else {
					unset( $settings['emails']['headerImageId'] );
				}
				if ( isset( $old_settings['emails']['customTemplates'] ) && is_array( $old_settings['emails']['customTemplates'] ) ) {
					$settings['emails']['customTemplates'] = $old_settings['emails']['customTemplates'];
				} else {
					unset( $settings['emails']['customTemplates'] );
				}
			} else {
				if ( isset( $settings['emails']['headerImageId'] ) ) {
					$hid = absint( $settings['emails']['headerImageId'] );
					$settings['emails']['headerImageId'] = ( $hid && wp_attachment_is_image( $hid ) ) ? $hid : 0;
				}
				if ( isset( $settings['emails']['customTemplates'] ) ) {
					$settings['emails']['customTemplates'] = recurio_email_sanitize_custom_templates_input( $settings['emails']['customTemplates'] );
				}
			}
		}

		// Sanitize general settings fields.
		if ( isset( $settings['general'] ) && is_array( $settings['general'] ) ) {
			$allowed_locations = array( 'myaccount', 'shortcode' );
			if ( isset( $settings['general']['portalLocation'] ) ) {
				$settings['general']['portalLocation'] = in_array( $settings['general']['portalLocation'], $allowed_locations, true )
					? $settings['general']['portalLocation']
					: 'myaccount';
			}
			if ( isset( $settings['general']['myAccountEndpoint'] ) ) {
				$settings['general']['myAccountEndpoint'] = sanitize_key( $settings['general']['myAccountEndpoint'] );
			}
			if ( isset( $settings['general']['myAccountLabel'] ) ) {
				$settings['general']['myAccountLabel'] = sanitize_text_field( $settings['general']['myAccountLabel'] );
			}
		}

		// Sanitize billing settings fields.
		if ( isset( $settings['billing'] ) && is_array( $settings['billing'] ) ) {
			foreach ( array( 'batchSize', 'maxRetries', 'retryInterval', 'dataRetention' ) as $int_key ) {
				if ( isset( $settings['billing'][ $int_key ] ) ) {
					$settings['billing'][ $int_key ] = absint( $settings['billing'][ $int_key ] );
				}
			}
		}

		// Sanitize notification/email from-address fields.
		if ( isset( $settings['emails'] ) && is_array( $settings['emails'] ) ) {
			if ( isset( $settings['emails']['fromName'] ) ) {
				$settings['emails']['fromName'] = sanitize_text_field( $settings['emails']['fromName'] );
			}
			if ( isset( $settings['emails']['fromEmail'] ) ) {
				$settings['emails']['fromEmail'] = sanitize_email( $settings['emails']['fromEmail'] );
			}
			// Always sanitize HTML branding fields regardless of Pro status.
			if ( isset( $settings['emails']['headerHtml'] ) ) {
				$settings['emails']['headerHtml'] = wp_kses_post( $settings['emails']['headerHtml'] );
			}
			if ( isset( $settings['emails']['footerHtml'] ) ) {
				$settings['emails']['footerHtml'] = wp_kses_post( $settings['emails']['footerHtml'] );
			}
		}

		// Sanitize cancellation flow portal copy strings.
		if ( isset( $settings['cancellationFlow']['portal_copy'] ) && is_array( $settings['cancellationFlow']['portal_copy'] ) ) {
			foreach ( $settings['cancellationFlow']['portal_copy'] as $key => $value ) {
				$settings['cancellationFlow']['portal_copy'][ sanitize_key( $key ) ] = sanitize_text_field( $value );
			}
		}

		// Ensure default values for My Account settings if empty.
		if ( isset( $settings['general']['portalLocation'] ) && $settings['general']['portalLocation'] === 'myaccount' ) {
			if ( empty( $settings['general']['myAccountEndpoint'] ) ) {
				$settings['general']['myAccountEndpoint'] = 'subscriptions';
			}
			if ( empty( $settings['general']['myAccountLabel'] ) ) {
				$settings['general']['myAccountLabel'] = 'Subscriptions';
			}
		}

		update_option( 'recurio_settings', $settings );

		// Flush rewrite rules if portal location, endpoint, or label changed.
		$location_changed = isset( $settings['general']['portalLocation'], $old_settings['general']['portalLocation'] ) &&
			$settings['general']['portalLocation'] !== $old_settings['general']['portalLocation'];

		$endpoint_changed = isset( $settings['general']['myAccountEndpoint'], $old_settings['general']['myAccountEndpoint'] ) &&
			$settings['general']['myAccountEndpoint'] !== $old_settings['general']['myAccountEndpoint'];

		$label_changed = isset( $settings['general']['myAccountLabel'], $old_settings['general']['myAccountLabel'] ) &&
			$settings['general']['myAccountLabel'] !== $old_settings['general']['myAccountLabel'];

		if ( $location_changed || $endpoint_changed || $label_changed ) {
			if ( $settings['general']['portalLocation'] === 'myaccount' ) {
				$endpoint = ! empty( $settings['general']['myAccountEndpoint'] )
					? $settings['general']['myAccountEndpoint']
					: 'subscriptions';
				add_rewrite_endpoint( $endpoint, EP_ROOT | EP_PAGES );
			}

			update_option( 'recurio_flush_rewrite_rules', true );
			flush_rewrite_rules();
			delete_transient( 'wc_account_menu_items' );
			wp_cache_flush();
		}

		// Set flush flag when switching to My Account mode for the first time.
		if (
			isset( $settings['general']['portalLocation'] ) &&
			$settings['general']['portalLocation'] === 'myaccount' &&
			( ! isset( $old_settings['general']['portalLocation'] ) || $old_settings['general']['portalLocation'] !== 'myaccount' )
		) {
			update_option( 'recurio_flush_rewrite_rules', true );
		}

		$message = __( 'Settings saved successfully', 'recurio' );
		if ( ( $endpoint_changed || $label_changed ) && $settings['general']['portalLocation'] === 'myaccount' ) {
			$message = __( 'Settings saved successfully. Please refresh your My Account page to see the changes.', 'recurio' );
		}

		$preview_url = '';
		if ( ! empty( $settings['emails']['headerImageId'] ) ) {
			$hid = absint( $settings['emails']['headerImageId'] );
			if ( $hid && wp_attachment_is_image( $hid ) ) {
				$img_url = wp_get_attachment_image_url( $hid, 'medium' );
				if ( $img_url ) {
					$preview_url = $img_url;
				}
			}
		}
		$settings['emailHeaderImagePreviewUrl'] = $preview_url;

		return new WP_REST_Response(
			array(
				'success'  => true,
				'settings' => $settings,
				'message'  => $message,
			),
			200
		);
	}

	/**
	 * GET /payment-gateways
	 */
	public function get_payment_gateways( $request ) {
		if ( ! function_exists( 'WC' ) ) {
			return new WP_REST_Response( array( 'gateways' => array() ), 200 );
		}

		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$gateways           = array();

		$online_subscription_gateways = array( 'stripe', 'stripe_cc', 'stripe_sepa', 'paypal', 'ppec_paypal', 'square' );
		$offline_methods              = array( 'cod', 'bacs', 'cheque' );
		$subscription_features        = array( 'subscriptions', 'tokenization', 'subscription_payment_method_change' );

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			$supports_subscriptions = false;

			if ( isset( $gateway->supports ) && is_array( $gateway->supports ) ) {
				foreach ( $subscription_features as $feature ) {
					if ( in_array( $feature, $gateway->supports, true ) ) {
						$supports_subscriptions = true;
						break;
					}
				}
			}

			if ( in_array( $gateway_id, $online_subscription_gateways, true ) ) {
				$supports_subscriptions = true;
			}

			$gateways[ $gateway_id ] = array(
				'id'                     => $gateway_id,
				'title'                  => $gateway->get_title(),
				'description'            => $gateway->get_method_description(),
				'enabled'                => $gateway->enabled === 'yes',
				'supports_subscriptions' => $supports_subscriptions,
				'is_offline'             => in_array( $gateway_id, $offline_methods, true ),
			);
		}

		return new WP_REST_Response( array( 'gateways' => $gateways ), 200 );
	}

	// -------------------------------------------------------------------------
	// Permission
	// -------------------------------------------------------------------------

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}
}
