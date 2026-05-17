<?php
/**
 * Pro-only customizable HTML email templates (subject + body placeholders).
 *
 * @package Recurio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template keys that may have custom subject/body stored under recurio_settings.emails.customTemplates.
 *
 * @return string[]
 */
function recurio_email_custom_template_allowed_keys() {
	return array(
		// Core (free plugin sends).
		'subscription_created',
		'subscription_activated',
		'subscription_paused',
		'subscription_resumed',
		'subscription_skipped',
		'subscription_cancelled',
		'subscription_expired',
		'payment_successful',
		'payment_failed',
		'trial_ending',
		'renewal_reminder',
		// Pro automation (keys match Recurio_Pro_Email_Templates slugs).
		'dunning-series-1',
		'dunning-series-2',
		'dunning-series-3',
		'trial-ending-3-days',
		'trial-ending-1-day',
		'pause-reminder-7-days',
		'pause-reminder-14-days',
		'pause-reminder-30-days',
		'win-back-7-days',
		'win-back-30-days',
		'win-back-90-days',
		'expiration-warning-30-days',
		'expiration-warning-14-days',
		'expiration-warning-7-days',
		'card-expiring-30-days',
		'card-expiring-7-days',
		'anniversary',
	);
}

/**
 * Keys whose values are URLs for placeholder replacement.
 *
 * @return string[]
 */
function recurio_email_custom_template_url_keys() {
	return array(
		'manage_url',
		'reactivate_url',
		'renew_url',
		'update_payment_url',
		'invoice_url',
		'resume_url',
		'feedback_url',
		'update_card_url',
	);
}

/**
 * Keys allowed to contain minimal HTML (e.g. formatted prices).
 *
 * @return string[]
 */
function recurio_email_custom_template_html_fragment_keys() {
	return array(
		'billing_amount',
		'payment_amount',
		'skipped_amount',
		'loyalty_reward',
		'special_offer',
	);
}

/**
 * Escape one placeholder value for subject or HTML body context.
 *
 * @param string $key   Variable key.
 * @param mixed  $value Raw value.
 * @param string $context 'subject' or 'body'.
 * @return string
 */
function recurio_email_escape_placeholder_value( $key, $value, $context ) {
	if ( ! is_scalar( $value ) && null !== $value ) {
		return '';
	}
	$str = (string) $value;

	if ( in_array( $key, recurio_email_custom_template_url_keys(), true ) ) {
		return esc_url( $str );
	}

	if ( 'subject' === $context ) {
		return sanitize_text_field( $str );
	}

	if ( in_array( $key, recurio_email_custom_template_html_fragment_keys(), true ) ) {
		return wp_kses_post( $str );
	}

	return esc_html( $str );
}

/**
 * Replace {{key}} placeholders in a string.
 *
 * @param string               $template  Raw template with placeholders.
 * @param array<string, mixed> $variables Engine-provided variables.
 * @param string               $context   'subject' or 'body'.
 * @return string
 */
function recurio_email_render_placeholder_string( $template, $variables, $context ) {
	if ( $template === '' || ! is_array( $variables ) ) {
		return $template;
	}

	foreach ( $variables as $key => $value ) {
		if ( ! is_string( $key ) || $key === '' ) {
			continue;
		}
		$replacement = recurio_email_escape_placeholder_value( $key, $value, $context );
		$template    = str_replace( '{{' . $key . '}}', $replacement, $template );
	}

	return $template;
}

/**
 * Apply saved custom subject/body for any allowed template key.
 * Pro gating is enforced at the sending level for automation templates;
 * core template customisation is available to all plans.
 *
 * @param string               $template_key      Allowed template slug.
 * @param string               $default_subject   Subject if no custom subject.
 * @param string               $default_body_html Body HTML if no custom body.
 * @param array<string, mixed> $variables         Template variables.
 * @return array{subject:string,body:string}
 */
function recurio_email_finalize_template( $template_key, $default_subject, $default_body_html, $variables ) {
	$subject = $default_subject;
	$body    = $default_body_html;

	if ( ! in_array( $template_key, recurio_email_custom_template_allowed_keys(), true ) ) {
		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	$settings    = get_option( 'recurio_settings', array() );
	$custom_all  = isset( $settings['emails']['customTemplates'] ) && is_array( $settings['emails']['customTemplates'] )
		? $settings['emails']['customTemplates']
		: array();
	$custom      = isset( $custom_all[ $template_key ] ) && is_array( $custom_all[ $template_key ] )
		? $custom_all[ $template_key ]
		: array();
	$sub_custom  = isset( $custom['subject'] ) ? (string) $custom['subject'] : '';
	$body_custom = isset( $custom['body'] ) ? (string) $custom['body'] : '';

	if ( $sub_custom !== '' ) {
		$subject = recurio_email_render_placeholder_string( $sub_custom, $variables, 'subject' );
		$subject = sanitize_text_field( wp_strip_all_tags( $subject ) );
	}

	if ( $body_custom !== '' ) {
		$body = recurio_email_render_placeholder_string( $body_custom, $variables, 'body' );
		$body = wp_kses_post( $body );
	}

	return array(
		'subject' => $subject,
		'body'    => $body,
	);
}

/**
 * Sanitize customTemplates payload from REST (whitelist keys only).
 *
 * @param mixed $input Raw input.
 * @return array<string, array{subject:string,body:string}>
 */
function recurio_email_sanitize_custom_templates_input( $input ) {
	$allowed = recurio_email_custom_template_allowed_keys();
	$out     = array();

	if ( ! is_array( $input ) ) {
		return $out;
	}

	foreach ( $allowed as $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
			continue;
		}
		$row            = $input[ $key ];
		$out[ $key ]    = array(
			'subject' => isset( $row['subject'] ) ? sanitize_text_field( $row['subject'] ) : '',
			'body'    => isset( $row['body'] ) ? wp_kses_post( $row['body'] ) : '',
		);
	}

	return $out;
}
