<?php
/**
 * Plugin Name: WooCommerce Simple Subscription
 * Plugin URI: https://artchsh.kz/
 * Description: Adds subscription options to WooCommerce products and restricts content access to subscribers.
 * Version: 1.3.2
 * Author: Artyom Chshyogolev
 * Author URI: https://artchsh.kz/
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Add custom subscription fields to WooCommerce products
function wcs_add_subscription_fields() {
    global $woocommerce, $post;
    
    echo '<div class="options_group">';
    
    woocommerce_wp_checkbox([
        'id' => '_wcs_enable_subscription',
        'label' => __('Enable Subscription', 'woocommerce'),
        'description' => __('Enable this product as a subscription.', 'woocommerce'),
    ]);
    
    woocommerce_wp_text_input([
        'id' => '_wcs_subscription_price',
        'label' => __('Subscription Price', 'woocommerce'),
        'type' => 'number',
        'custom_attributes' => [
            'step' => '0.01',
            'min' => '0'
        ],
        'desc_tip' => true,
        'description' => __('Enter the price for subscription.', 'woocommerce')
    ]);
    
    woocommerce_wp_select([
        'id' => '_wcs_billing_interval',
        'label' => __('Billing Cycle', 'woocommerce'),
        'options' => [
            'weekly' => __('Weekly', 'woocommerce'),
            'monthly' => __('Monthly', 'woocommerce'),
            'yearly' => __('Yearly', 'woocommerce')
        ],
        'description' => __('Select how often the customer should be billed.', 'woocommerce')
    ]);
    
    echo '</div>';
}
add_action('woocommerce_product_options_general_product_data', 'wcs_add_subscription_fields');

// Save subscription fields
function wcs_save_subscription_fields($post_id) {
    $enable_subscription = isset($_POST['_wcs_enable_subscription']) ? 'yes' : 'no';
    update_post_meta($post_id, '_wcs_enable_subscription', $enable_subscription);
    
    if (isset($_POST['_wcs_subscription_price'])) {
        update_post_meta($post_id, '_wcs_subscription_price', sanitize_text_field($_POST['_wcs_subscription_price']));
    }
    
    if (isset($_POST['_wcs_billing_interval'])) {
        update_post_meta($post_id, '_wcs_billing_interval', sanitize_text_field($_POST['_wcs_billing_interval']));
    }
}
add_action('woocommerce_process_product_meta', 'wcs_save_subscription_fields');

// Generate checkout button for subscription product
function wcs_checkout_button_shortcode() {
    $args = array(
        'post_type' => 'product',
        'meta_query' => array(
            array(
                'key' => '_wcs_enable_subscription',
                'value' => 'yes',
                'compare' => '='
            )
        )
    );
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $query->the_post();
        $product = wc_get_product(get_the_ID());
        return '<a href="' . esc_url($product->add_to_cart_url()) . '" class="button">Subscribe Now</a>';
    }
    return '';
}
add_shortcode('wcs_checkout_button', 'wcs_checkout_button_shortcode');

// Restrict content for non-subscribers
function wcs_restrict_content() {
    if (!is_user_logged_in()) {
        wp_redirect(wp_login_url());
        exit;
    }
    
    if (is_page('subscription-required') || is_front_page()) {
        return;
    }
    
    $user_id = get_current_user_id();
    $subscriptions = get_user_meta($user_id, 'wcs_active_subscriptions', true);
    if (empty($subscriptions)) {
        wp_redirect(home_url('/subscription-required'));
        exit;
    }
}
add_action('template_redirect', 'wcs_restrict_content');

// Modify user profile to remove address and downloads
function wcs_remove_account_tabs($items) {
    unset($items['edit-address']);
    unset($items['downloads']);
    return $items;
}
add_filter('woocommerce_account_menu_items', 'wcs_remove_account_tabs');

// Add cancel subscription option in user profile
function wcs_add_cancel_subscription_button() {
    $user_id = get_current_user_id();
    $subscriptions = get_user_meta($user_id, 'wcs_active_subscriptions', true);
    
    if (!empty($subscriptions)) {
        echo '<h3>' . __('My Subscriptions', 'woocommerce') . '</h3>';
        foreach ($subscriptions as $order_id) {
            echo '<p>Subscription Order #' . esc_html($order_id) . ' <a href="?cancel_subscription=' . esc_attr($order_id) . '" class="button">Cancel</a></p>';
        }
    }
}
add_action('woocommerce_before_my_account', 'wcs_add_cancel_subscription_button');

// Handle subscription cancellation
function wcs_handle_subscription_cancellation() {
    if (isset($_GET['cancel_subscription']) && is_user_logged_in()) {
        $order_id = intval($_GET['cancel_subscription']);
        $user_id = get_current_user_id();
        $subscriptions = get_user_meta($user_id, 'wcs_active_subscriptions', true);
        
        if (in_array($order_id, $subscriptions)) {
            wc_get_order($order_id)->update_status('cancelled');
            $updated_subscriptions = array_diff($subscriptions, [$order_id]);
            update_user_meta($user_id, 'wcs_active_subscriptions', $updated_subscriptions);
            wp_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
    }
}
add_action('init', 'wcs_handle_subscription_cancellation');
