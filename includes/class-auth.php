<?php
if (!defined('ABSPATH')) exit;

class Buttercups_Dashboard_Auth {
    public static function add_capabilities() {
        // Assign dashboard viewing capability to Admins and the custom Staff role
        $roles = array('administrator', 'staff', 'editor');
        foreach ($roles as $role_slug) {
            $role = get_role($role_slug);
            if ($role) {
                $role->add_cap('view_booking_dashboard');
            }
        }
    }

    /**
     * Standard WordPress login redirect
     */
    public static function handle_login_redirect($redirect_to, $request, $user) {
        return self::calculate_redirect($redirect_to, $user, 'WP_LOGIN');
    }

    /**
     * WooCommerce specific login redirect
     */
    public static function handle_woocommerce_redirect($redirect, $user) {
        return self::calculate_redirect($redirect, $user, 'WOOCOMMERCE');
    }

    /**
     * Prevent other plugins/WooCommerce from blocking wp-admin access for Staff
     */
    public static function allow_staff_admin_access($prevent_access) {
        if (current_user_can('view_booking_dashboard')) {
            error_log("[Buttercups Auth] Explicitly ALLOWING admin access for user with 'view_booking_dashboard' capability.");
            return false; // Do NOT prevent access
        }
        return $prevent_access;
    }

    /**
     * Early trace to catch anyone redirecting staff away from wp-admin
     */
    public static function debug_admin_access_restriction() {
        if (is_admin() && !defined('DOING_AJAX') && current_user_can('view_booking_dashboard')) {
            $uri = $_SERVER['REQUEST_URI'];
            $user = wp_get_current_user();
            
            error_log("[Buttercups Auth Trace] User '{$user->user_login}' in wp-admin. Request: $uri");
            
            // If we are about to be redirected, the 'wp_redirect' filter might catch it.
        }
    }
    
    public static function trace_redirects($location, $status) {
        if (is_admin() && current_user_can('view_booking_dashboard')) {
            $user = wp_get_current_user();
            error_log("[Buttercups Auth Trace] ALERT: Redirect detected in admin! Moving user '{$user->user_login}' to: $location (Status: $status)");
            
            // Backtrace to find the culprit
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            foreach ($bt as $i => $frame) {
                error_log("[Buttercups Auth Trace] Frame #$i: " . ($frame['file'] ?? 'unknown') . " line " . ($frame['line'] ?? 'unknown') . " (func: " . ($frame['function'] ?? 'unknown') . ")");
            }
        }
        return $location;
    }

    /**
     * Centralized logic for redirecting staff users
     */
    private static function calculate_redirect($redirect_to, $user, $context) {
        // Ensure we have a valid user object
        if (!($user instanceof WP_User)) {
            return $redirect_to;
        }

        $username = $user->user_login;
        $roles = (array)$user->roles;
        
        // Debug logging
        error_log("[Buttercups Auth] Login Redirect ($context) for user: $username");
        error_log("[Buttercups Auth] User Roles: " . implode(', ', $roles));

        // Only redirect the 'staff' role
        if (in_array('staff', $roles)) {
            $target = admin_url('admin.php?page=buttercups-dashboard');
            error_log("[Buttercups Auth] Role 'staff' detected. Overriding redirect to: $target");
            return $target;
        }
        
        error_log("[Buttercups Auth] Non-staff user. Allowing default redirect to: $redirect_to");
        return $redirect_to;
    }
}
