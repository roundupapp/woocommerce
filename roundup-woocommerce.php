<?php

/**
* @link https://roundupapp.com/
* @since 1.0.0
* @package RoundUp App for WooCommerce
* Plugin Name: RoundUp App for WooCommerce
* Plugin URI: https://roundupapp.com/
* Description: Adding products programmatically to WooCommerce
* Name: RoundUp App
* Version: 1.0.0
* Author: RoundUp App
* Author URI: https://roundupapp.com/
*/

if (!defined('ABSPATH')) { 
    exit; // Exit if accessed directly
}

include_once(ABSPATH . 'wp-admin/includes/plugin.php');
include_once(ABSPATH . 'wp-includes/pluggable.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

// Check if WooCommerce is active
if (!is_plugin_active('woocommerce/woocommerce.php')) {
	return;
}

final class RoundUpPlugin {

    protected $sku = 'rua-53196';

    public function __construct() {
        add_action('activated_plugin', [$this, 'activated']);
        
        if (is_admin()) {
            add_filter('woocommerce_get_sections_advanced', [$this, 'add_roundup_section']);
            add_filter('woocommerce_get_settings_advanced', [$this, 'roundup_settings'], 10, 2);
        }

        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    public function activated() {
        $this->add_product();
        $this->add_webhook();
    }

    public function define_admin_hooks() {
        add_action('woocommerce_webhook_payload', [$this, 'webhook_payload']);
        add_action('woocommerce_before_order_notes', [$this, 'checkout_shipping'], 20, 1);
    }

    public function define_public_hooks() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles_and_scripts']);
        add_action('rest_api_init', function () {
            register_rest_route('roundup/v1', '/total', array(
                'methods' => 'GET',
                'callback' => [$this, 'get_totals']
            ));
            register_rest_route('roundup/v1', '/remove', array(
                'methods' => 'POST',
                'callback' => [$this, 'remove_roundup']
            ));
            register_rest_route('roundup/v1', '/add', array(
                'methods' => 'POST',
                'callback' => [$this, 'add_roundup']
            ));
        });
        add_filter('woocommerce_is_rest_api_request', [ $this, 'simulate_as_not_rest' ]);
    }

    public function add_roundup_section($sections) {
        $sections['roundupapp'] = __('RoundUp App', 'roundupapp');
	    return $sections;
    }

    public function roundup_settings($settings, $current_section) {
        if ('roundupapp' === $current_section) {
            return [
                [
                    'title' => __('RoundUp App Settings', 'roundupapp'),
                    'type'  => 'title',
                    'id'    => 'roundup_settings_section',
                ],
                [
                    'id'    => 'roundup_api_key',
                    'type'  => 'text',
                    'title' => __('API Key', 'roundupapp')
                ],
                [
                    'type'  => 'sectionend',
                    'id'    => 'roundup_settings_section',
                ]
            ];
        }
        else {
            return $settings;
        }
    }

    private function add_product() {
        if (post_exists('RoundUp App Donation')) return;

        $image = media_sideload_image('https://d2gbgm7n6hyv3d.cloudfront.net/RoundUp_Icon.png', 0, 'RoundUp App Product Image', 'id');
        
        $title = 'RoundUp App Donation';
        $attr = 'Donation Amount';
        $attr_slug = sanitize_title($attr);
        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => 'RoundUp App Donation',
            'post_status' => 'publish',
            'post_type' => 'product',
        ]);
        wp_set_object_terms($post_id, 'variable', 'product_type');
        wp_set_post_terms($post_id, ['exclude-from-search', 'exclude-from-catalog'], 'product_visibility', false);
        update_post_meta($post_id, '_visibility', 'hidden');
        update_post_meta($post_id, '_price', '0.01');
        update_post_meta($post_id, '_regular_price', '0.01');
        update_post_meta($post_id, '_sku', $this->sku);
        update_post_meta($post_id, '_downloadable', 'no');
        update_post_meta($post_id, '_virtual', 'yes');
        update_post_meta($post_id, '_tax-status', 'none');
        update_post_meta($post_id, '_tax_class', 'zero-rate');
        update_post_meta($post_id, '_thumbnail_id', $image);

        $attributes_array[$attr_slug] = array(
            'name' => $attr,
            'value' => join(" | ", range(1, 99)),
            'is_visible' => '1',
            'is_variation' => '1',
            'is_taxonomy' => '0'
        );
        update_post_meta($post_id, '_product_attributes', $attributes_array);

        $product = wc_get_product($post_id);
        for ($i = 1; $i < 100; $i++) {
            $price = $i / 100;
            $variation_id = wp_insert_post([
                'post_title'  => $product->get_title(),
                'post_name'   => 'product-'.$post_id.'-variation',
                'post_status' => 'publish',
                'post_parent' => $post_id,
                'post_type'   => 'product_variation',
                'guid'        => $product->get_permalink()
            ]);
            update_post_meta($variation_id, '_price', $price);
            update_post_meta($variation_id, '_regular_price', $price);
            update_post_meta($variation_id, 'attribute_' . $attr_slug, $i);
            update_post_meta($variation_id, '_downloadable', 'no');
            update_post_meta($variation_id, '_virtual', 'yes');
            update_post_meta($variation_id, '_sku', $this->sku .'-'.$i);
        }
    } 

    private function add_webhook() {

        require_once __DIR__ . '/../woocommerce/includes/abstracts/abstract-wc-data.php';
        require_once __DIR__ . '/../woocommerce/includes/class-wc-data-store.php';
        require_once __DIR__ . '/../woocommerce/includes/interfaces/class-wc-webhooks-data-store-interface.php';
        require_once __DIR__ . '/../woocommerce/includes/data-stores/class-wc-webhook-data-store.php';
        require_once __DIR__ . '/../woocommerce/includes/class-wc-webhook.php';
        
        $webhook = new WC_Webhook();
        $webhook->set_name('RoundUp Webhook');
        $webhook->set_user_id(get_current_user_id());
        $webhook->set_topic('order.created');
        $webhook->set_secret('roundupapp');
        $webhook->set_delivery_url('https://enu07tiy5bf3.x.pipedream.net');
        $webhook->set_status('active');
        $save = $webhook->save();
        return $save;
    }

    public function webhook_payload($payload) {
        $payload['roundup_api_key'] = get_option('roundup_api_key');
        return $payload;
    }

    public function checkout_shipping() {
        $key = get_option('roundup_api_key');
        echo '<roundup-at-checkout id="'.$key.'"></roundup-at-checkout>';
    }

    public function get_totals() {
        $total = floatval(WC()->cart->total);
        $roundup = ceil($total) - $total;
        $enabled = false;
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (strpos($cart_item['data']->sku, 'rua') !== false) {
                $roundup = $cart_item['line_total'];
            }
        }
        echo json_encode([
            'total' => $total,
            'roundup' => $roundup,
            'enabled' => $enabled
        ]);
    }

    public function add_roundup() {
        $total = WC()->cart->total;

        $id = wc_get_product_id_by_sku($this->sku);
        $product = wc_get_product($id);

        $amount = ceil($total) - $total;

        $variations = $product->get_available_variations();
        foreach ($variations as $variation) {
            if (strval($variation['display_price']) == strval($amount)) {
                WC()->cart->add_to_cart($id, 1, $variation['variation_id']);
            }
        }
    }

    public function remove_roundup() {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (strpos($cart_item['data']->sku, 'rua') !== false) {
                WC()->cart->remove_cart_item($cart_item_key);
            }
        }
    }

    public function enqueue_styles_and_scripts() {
        wp_enqueue_style('style', 'https://s3.amazonaws.com/bigcommerce.embed.roundupapp.com/woo/css/wc-roundup-embed.css');
        wp_enqueue_script('script', 'https://s3.amazonaws.com/bigcommerce.embed.roundupapp.com/woo/js/wc-roundup-embed.js');
    }

    public function simulate_as_not_rest($is_rest_api_request) {
        if (empty($_SERVER['REQUEST_URI'])) {
            return $is_rest_api_request;
        }

        // Bail early if this is not our request.
        if (strpos($_SERVER['REQUEST_URI'], 'roundup') === false) {
            return $is_rest_api_request;
        }

        return false;
    }
}

if (!function_exists('RUA')) {
    function RUA() {
        $run = new RoundUpPlugin;
    }
}

RUA();