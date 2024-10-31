<?php
/*
Plugin Name: PACK & SEND Live Shipping Rates
Description: This Plugin adds PACK & SEND Live Shipping Rates as a shipping method to your WooCommerce Store
Version: 1.0.0
Author: PACK & SEND
Author URI: https://www.packsend.com.au/
Text Domain: pack-send-live-shipping-rates
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

/*
PACK & SEND Live Shipping Rates is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version. 

GNU General Public License see https://www.gnu.org/licenses/gpl-2.0.html.

PACK & SEND Live Shipping Rates IS PROVIDED "AS IS" AND LICENSOR HEREBY DISCLAIMS ALL WARRANTIES,
WHETHER EXPRESS, IMPLIED, STATUTORY OR OTHERWISE. LICENSOR SPECIFICALLY DISCLAIMS ALL IMPLIED WARRANTIES
OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, TITLE, AND NON-INFRINGEMENT, AND ALL WARRANTIES
ARISING FROM COURSE OF DEALING, USAGE, OR TRADE PRACTICE. LICENSOR MAKES NO WARRANTY OF ANY KIND THAT THE
SOFTWARE, OR ANY PRODUCTS OR RESULTS OF THE USE THEREOF, WILL MEET YOUR OR ANY OTHER PERSON'S REQUIREMENTS,
OPERATE WITHOUT INTERRUPTION, ACHIEVE ANY INTENDED RESULT, BE COMPATIBLE OR WORK WITH ANY SOFTWARE, SYSTEM
OR OTHER SERVICES, OR BE SECURE, ACCURATE, COMPLETE, FREE OF HARMFUL CODE, OR ERROR FREE.
*/


// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Initializes the PACK & SEND Live Shipping Rates plugin
 */
function ps_live_shipping_rates_init()
{
	// Define the main shipping class
	class PS_Live_Shipping_Rates extends WC_Shipping_Method
	{

		const ENABLED_VALUE = 'yes';

		/**
		 * Constructor for the shipping class
		 *
		 * @param int $instance_id
		 */
		public function __construct($instance_id = 0)
		{
			parent::__construct($instance_id);

			$this->id = 'ps_live_shipping_rates';
			$this->instance_id = absint($instance_id);
			$this->title = __('PACK & SEND Live Shipping Rates');
			$this->method_title = __('PACK & SEND Live Shipping Rates');
			$this->method_description = __('Provides shipping rates for PACK & SEND Live integrated marketplaces.');
			$this->enabled = self::ENABLED_VALUE;
			$this->supports = array(
				'settings',
				'shipping-zones',
			);

			$this->init();
		}

		/**
		 * Initialize settings
		 */
		function init()
		{
			// Load the settings API
			$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
			$this->init_settings(); // This is part of the settings API. Loads settings you previously init.

			// Save settings in admin if you have any defined
			add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
		}

		/**
		 * Helper function to get the origin of the shipping rates request
		 *
		 * @return array
		 */
		private function _get_request_origin()
		{
			$store_raw_country = get_option('woocommerce_default_country');
			list($store_country, $store_state) = array_map('trim', explode(":", $store_raw_country));
			$origin = array(
				'postcode' => get_option('woocommerce_store_postcode'),
				'address1' => get_option('woocommerce_store_address'),
				'address2' => get_option('woocommerce_store_address_2'),
				'phone' => '',
				'city' => get_option('woocommerce_store_city'),
				'country' => array(
					'name' => $store_country,
					'code2' => $store_country
				),
				'state' => array(
					'name' => get_option('woocommerce_store_state'),
				)
			);
			return $origin;
		}

		/**
		 * Helper function to get the destination of the shipping rates request
		 *
		 * @param array $packageDestination
		 * @return array
		 */
		private function _get_request_destination($packageDestination)
		{
			$destinationCountry = $packageDestination['country'];
			$destinationState = $packageDestination['state'];
			$destinationPostcode = $packageDestination['postcode'];
			$destinationCity = $packageDestination['city'];
			$destinationAddress1 = $packageDestination['address_1'];
			$destinationAddress2 = $packageDestination['address_2'];
			$destinationCompany = '';

			$customer = WC()->customer;
			if (!empty($customer)) {
				$destinationCompany = $customer->get_shipping_company();
			}

			$destination = array(
				'postcode' => $destinationPostcode,
				'address1' => $destinationAddress1,
				'address2' => $destinationAddress2,
				'phone' => '',
				'city' => $destinationCity,
				'country' => array(
					'name' => $destinationCountry,
					'code2' => $destinationCountry
				),
				'state' => array(
					'name' => $destinationState,
				),
				'company' => $destinationCompany
			);
			return $destination;
		}

		/**
		 * Helper function to get the content items of the shipping rates request
		 *
		 * @param array $packageContents
		 * @return array
		 */
		private function _get_request_content_items($packageContents)
		{
			$weightUnit = get_option('woocommerce_weight_unit');
			$dimensionUnit = get_option('woocommerce_dimension_unit');


			$contentItems = [];
			foreach ($packageContents as $key => $contentItem) {
				$itemData = $contentItem['data'];
				/**
				 * @var WC_Product $itemData
				 */
				$item = array(
					'id' => $key,
					'product_id' => $contentItem['product_id'],
					'variant_id' => $contentItem['variation_id'] ?: null,
					'sku' => $itemData->get_sku(),
					'name' => $itemData->get_name(),
					'price' => $itemData->get_price(),
					'tax' => $contentItem['line_tax'],
					'quantity' => $contentItem['quantity'],
					'weight_unit' => $weightUnit,
					'weight' => $itemData->get_weight(),
					'options' => array(),
					'additional_fields' => array(
						'dimensions_unit' => $dimensionUnit,
						'height' => $itemData->get_height(),
						'width' => $itemData->get_width(),
						'length' => $itemData->get_length()
					)
				);
				$contentItems[] = $item;
			}
			return $contentItems;
		}

		/**
		 * Helper function to construct the shipping rates request
		 *
		 * @param array $packageDestination
		 * @param array $packageContents
		 * @return array
		 */
		private function _get_request($packageDestination, $packageContents)
		{
			$origin = $this->_get_request_origin();
			$destination = $this->_get_request_destination($packageDestination);
			$contentItems = $this->_get_request_content_items($packageContents);

			$currency = get_woocommerce_currency();
			$shippingPackage = array(
				'id' => 1,
				'currency_code' => $currency,
				'origin' => $origin,
				'destination' => $destination,
				'items' => $contentItems
			);

			return ['packages' => [$shippingPackage]];
		}

		/**
		 * Calculate shipping rates based on the provided package
		 *
		 * @param array $package
		 */
		public function calculate_shipping($package = array())
		{
			$this->rates = [];
			if ($this->settings['enabled'] !== self::ENABLED_VALUE) {
				return $this->rates;
			}
			$shippingRatesUrl = get_option('ps_live_shipping_rates_url');
			if (!isset($shippingRatesUrl) || trim($shippingRatesUrl) === "") {
				error_log('Error: Missing shipping rates settings. error code: 10');
				return $this->rates;
			}

			$shippingRatesSecret = get_option('ps_live_shipping_rates_secret');
			if (!isset($shippingRatesSecret) || trim($shippingRatesSecret) === "") {
				error_log('Error: Missing shipping rates settings. error code: 20');
				return $this->rates;
			}

			$packageDestination = $package['destination'];
			if (!isset($packageDestination)) {
				error_log('Error: Unable to get destination info. error code: 100');
				return $this->rates;
			}

			$packageContents = $package['contents'];
			if (!isset($packageContents)) {
				error_log('Error: Unable to get contents info. error code: 200');
				return $this->rates;
			}

			$request = $this->_get_request($packageDestination, $packageContents);
			$requestJson = wp_json_encode($request);
			$hmac = hash_hmac('sha256', $requestJson, $shippingRatesSecret, true);
			$base64_encoded = base64_encode($hmac);
			$requestSignature = 'sha256=' . $base64_encoded;
			$cacheKey = $this->id . '-' . "$requestSignature";
			$cached_rates = WC()->session->get($cacheKey);

			if (!empty($cached_rates)) {
				$cached_rates_validUntil = $cached_rates['validUntil'];
				if (!empty($cached_rates_validUntil) && $cached_rates_validUntil > time()) {
					foreach ($cached_rates['rates'] as $rate) {
						$this->add_rate($rate);
					}
					return $this->rates;
				}
				WC()->session->set($cacheKey, null);
			}

			if (!isset($request)) {
				return $this->rates;
			}

			$response = wp_remote_post(
				$shippingRatesUrl,
				array(
					'sslverify' => true,
					'timeout' => 30,
					'redirection' => 0,
					'compress' => true,
					'body' => $requestJson,
					'headers' => array(
						'Content-Type' => 'application/json',
						'X-PS-LSR-SIGNATURE' => $requestSignature
					),
					'method' => 'POST',
					'data_format' => 'body',
				)
			);

			if (is_wp_error($response) || $response['response']['code'] != 200) {
				$error_message = is_wp_error($response) ? $response->get_error_message() : 'HTTP Error: ' . $response['response']['code'];
				error_log('Error: ' . $error_message);
				return $this->rates;
			}

			$body = wp_remote_retrieve_body($response);
			if (!isset($body)) {
				return $this->rates;
			}
			$data = json_decode($body, true);

			$cached_rates = array(
				// Valid for a number of seconds from now
				'validUntil' => time() + 300,
				'rates' => []
			);
			foreach ($data['packages_rates'] as $packageRateData) {
				foreach ($packageRateData['rates'] as $rateData) {
					$rate = array(
						'id' => $rateData['code'],
						'label' => $rateData['name'],
						'cost' => $rateData['total_cost'],
						'taxes' => array(
							'1' => $rateData['calculated_taxes']
						)
					);
					$cached_rates['rates'][] = $rate;
					$this->add_rate($rate);
				}
			}
			if (!empty($cached_rates)) {
				WC()->session->set($cacheKey, $cached_rates);
			}
			return $this->rates;
		}

		/**
		 * Initialize form fields for the shipping settings
		 */
		public function init_form_fields()
		{
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enabled', 'pack-send-live-shipping-rates'),
					'type' => 'checkbox',
					'label' => __('Enable PACK & SEND Live Shipping Rates', 'pack-send-live-shipping-rates'),
					'default' => self::ENABLED_VALUE,
				)
			);
		}
	}
}
// Hook to initialize the shipping method when WooCommerce is loaded
add_action('woocommerce_shipping_init', 'ps_live_shipping_rates_init');

/**
 * Add PACK & SEND Live Shipping Rates to available shipping methods
 *
 * @param array $methods
 * @return array
 */
function ps_live_shipping_rates_add_woocommerce_shipping_methods($methods)
{
	$methods['ps_live_shipping_rates'] = 'PS_Live_Shipping_Rates';
	return $methods;
}

// Hook to add the shipping method to the available methods
add_filter('woocommerce_shipping_methods', 'ps_live_shipping_rates_add_woocommerce_shipping_methods');

// REST API endpoint for getting and updating plugin settings
function ps_live_shipping_rates_register_get_settings_endpoint()
{
	// Endpoint for getting settings
	register_rest_route(
		'wc/ps_live_shipping_rates/v1',
		'/settings/',
		array(
			'methods' => 'GET',
			'callback' => 'ps_live_shipping_rates_get_setting_callback',
			'permission_callback' => function () {
				if (!current_user_can('manage_options')) {
					return new WP_Error('rest_forbidden', esc_html__('You do not have permission to access this endpoint.'), array('status' => 403));
				}
				return true;
			},
		)
	);
}
/**
 * Callback function for getting plugin settings
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ps_live_shipping_rates_get_setting_callback($request)
{
	$ps_live_shipping_rates_url = get_option('ps_live_shipping_rates_url');
	$ps_live_shipping_rates_secret = get_option('ps_live_shipping_rates_secret');
	if (!empty($ps_live_shipping_rates_url) && !empty($ps_live_shipping_rates_secret)) {
		$response = array(
			'shipping_rates_url' => $ps_live_shipping_rates_url,
			'shipping_rates_secret' => $ps_live_shipping_rates_secret,
		);
		return rest_ensure_response($response, 400);
	} else {
		$response = new WP_REST_Response();
		$response->set_status(404);
		$response->set_data(
			array(
				'error' => 'not_found',
				'message' => 'The requested resource was not found.'
			)
		);
	}
	return rest_ensure_response($response);
}

add_action('rest_api_init', 'ps_live_shipping_rates_register_get_settings_endpoint');

function ps_live_shipping_rates_register_post_settings_endpoint()
{
	// Endpoint for updating settings
	register_rest_route(
		'wc/ps_live_shipping_rates/v1',
		'/settings/',
		array(
			'methods' => 'POST',
			'callback' => 'ps_live_shipping_rates_post_setting_callback',
			'permission_callback' => function () {
				if (!current_user_can('manage_options')) {
					return new WP_Error('rest_forbidden', esc_html__('You do not have permission to access this endpoint.'), array('status' => 403));
				}
				return true;
			},
		)
	);
}

/**
 * Callback function for updating plugin settings
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ps_live_shipping_rates_post_setting_callback($request)
{
	$data = $request->get_json_params();
	$ratesUrl = $data['shipping_rates_url'];
	$secret = $data['shipping_rates_secret'];
	update_option('ps_live_shipping_rates_url', sanitize_text_field($ratesUrl));
	update_option('ps_live_shipping_rates_secret', sanitize_text_field($secret));
	return rest_ensure_response('Settings set successfully.', 200);
}

add_action('rest_api_init', 'ps_live_shipping_rates_register_post_settings_endpoint');
