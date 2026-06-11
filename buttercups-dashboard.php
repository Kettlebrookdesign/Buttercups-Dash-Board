<?php
/*
Plugin Name: Buttercups Staff Booking Dashboard
Description: Staff-only booking operations dashboard for Bookly.
Version: 1.0.0
Author: Kettlebrookdesign
License: GPL2
*/

if (!defined('ABSPATH')) exit;

class Buttercups_Dashboard {
    public function __construct() {
        require_once plugin_dir_path(__FILE__) . 'includes/class-auth.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-db.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-api.php';

        register_activation_hook(__FILE__, array('Buttercups_Dashboard_Auth', 'add_capabilities'));
        
        add_action('rest_api_init', array(new Buttercups_Dashboard_API(), 'register_routes'));
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_assets'));
        
        // Redirect staff to dashboard after login (WP and WooCommerce)
        add_filter('login_redirect', array('Buttercups_Dashboard_Auth', 'handle_login_redirect'), 10, 3);
        add_filter('woocommerce_login_redirect', array('Buttercups_Dashboard_Auth', 'handle_woocommerce_redirect'), 10, 2);

        // Access overrides
        add_filter('woocommerce_prevent_admin_access', array('Buttercups_Dashboard_Auth', 'allow_staff_admin_access'), 10, 1);
        
        // Trace and debug admin-area redirects
        add_action('admin_init', array('Buttercups_Dashboard_Auth', 'debug_admin_access_restriction'), 1);
        add_filter('wp_redirect', array('Buttercups_Dashboard_Auth', 'trace_redirects'), 10, 2);
    }

    public function add_menu_page() {
        add_menu_page(
            'Booking Dashboard',
            'Booking Dash',
            'view_booking_dashboard',
            'buttercups-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-calendar-alt',
            26
        );
    }

    public function enqueue_dashboard_assets($hook) {
        if ($hook !== 'toplevel_page_buttercups-dashboard') {
            return;
        }

        $js_path = plugin_dir_path(__FILE__) . 'build/index.js';
        $css_path = plugin_dir_path(__FILE__) . 'build/style.css';
        
        $js_ver = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        $css_ver = file_exists($css_path) ? filemtime($css_path) : '1.0.0';

        wp_enqueue_style('buttercups-dashboard-css', plugin_dir_url(__FILE__) . 'build/style.css', array(), $css_ver);
        wp_enqueue_script('buttercups-dashboard-js', plugin_dir_url(__FILE__) . 'build/index.js', array(), $js_ver, true);

        wp_localize_script('buttercups-dashboard-js', 'bcDashboard', array(
            'root' => esc_url_raw(rest_url('buttercups/v1')),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }

    public function render_dashboard() {
        if (!current_user_can('view_booking_dashboard')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'buttercups-dashboard'));
        }
        echo '<div id="buttercups-dashboard-root"></div>';
    }
}

new Buttercups_Dashboard();
