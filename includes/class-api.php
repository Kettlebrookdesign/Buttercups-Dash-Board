<?php
if (!defined('ABSPATH')) exit;

class Buttercups_Dashboard_API {
    public function register_routes() {
        register_rest_route('buttercups/v1', '/summary', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_summary'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route('buttercups/v1', '/slot-detail', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_slot_detail'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route('buttercups/v1', '/search', array(
            'methods' => 'GET',
            'callback' => array($this, 'search'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route('buttercups/v1', '/booking-detail', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_booking_detail'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route('buttercups/v1', '/manual-booking/options', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_manual_booking_options'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route('buttercups/v1', '/manual-booking/availability', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_manual_booking_availability'),
            'permission_callback' => array($this, 'check_permission')
        ));

        register_rest_route('buttercups/v1', '/manual-booking', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_manual_booking'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }

    public function check_permission() {
        return current_user_can('view_booking_dashboard');
    }

    public function get_summary(WP_REST_Request $request) {
        $date = $request->get_param('date') ?: date('Y-m-d');
        $result = Buttercups_Dashboard_DB::get_summary_with_diagnostics($date);
        $raw_data = $result['data'];
        $diagnostics = $result['diagnostics'];
        
        if (empty($raw_data)) {
            return new WP_REST_Response(array(
                'date' => $date,
                'experiences' => array(),
                'status' => 'no_data',
                'debug' => $diagnostics
            ), 200);
        }

        $experiences = array();
        foreach ($raw_data as $row) {
            $experience_id = $row->service_id;
            if (!isset($experiences[$experience_id])) {
                $experiences[$experience_id] = array(
                    'id' => $experience_id,
                    'name' => $row->experience_name,
                    'total_attendees' => 0,
                    'slots' => array()
                );
            }
            
            $experiences[$experience_id]['total_attendees'] += (int)$row->total_attendees;
            
            // Capacity Logic: Return null if neither staff nor service has a max_capacity
            $capacity = $row->staff_capacity ?: ($row->service_capacity ?: null);
            if ($capacity !== null) $capacity = (int)$capacity;

            $experiences[$experience_id]['slots'][] = array(
                'appointment_id' => $row->appointment_id,
                'time' => date('H:i', strtotime($row->start_date)),
                'attendees' => (int)$row->total_attendees,
                'capacity' => $capacity,
                'status' => 'approved'
            );
        }

        // Fetch Tea Summary for the day
        $tea_summary = Buttercups_Dashboard_DB::get_tea_summary($date);

        return new WP_REST_Response(array(
            'date' => $date,
            'experiences' => array_values($experiences),
            'tea_summary' => $tea_summary,
            'debug' => $diagnostics
        ), 200);
    }

    public function get_slot_detail(WP_REST_Request $request) {
        $id = $request->get_param('appointment_id');
        error_log("Buttercups API: get_slot_detail called with appointment_id: " . $id);
        
        if (!$id) return new WP_Error('missing_id', 'Appointment ID required', array('status' => 400));
        
        $bookings = Buttercups_Dashboard_DB::get_slot_detail($id);
        
        // Log results of the DB call
        error_log("Buttercups API: DB returned " . count($bookings) . " bookings for ID: " . $id);
        
        $labels = Buttercups_Dashboard_DB::get_custom_field_definitions();
        
        // Handle empty/null custom_fields
        foreach ($bookings as &$b) {
            $b->attendees = (int)$b->attendees;
            $decoded = $b->custom_fields ? json_decode($b->custom_fields, true) : array();
            
            // Map labels for better frontend parsing
            foreach ($decoded as &$field) {
                if (isset($labels[$field['id']])) {
                    $field['label'] = $labels[$field['id']];
                }
            }
            
            $b->custom_fields = $decoded;
        }

        return new WP_REST_Response($bookings, 200);
    }

    public function search(WP_REST_Request $request) {
        $query = $request->get_param('query');
        $date = $request->get_param('date');
        if (!$query) return new WP_Error('missing_query', 'Search term required', array('status' => 400));

        $results = Buttercups_Dashboard_DB::search_bookings($query, $date);
        
        foreach ($results as &$r) {
            $r->attendees = (int)$r->attendees;
            $r->time = date('H:i', strtotime($r->start_date));
            $r->date = date('Y-m-d', strtotime($r->start_date));
        }

        return new WP_REST_Response(array('results' => $results), 200);
    }

    public function get_booking_detail(WP_REST_Request $request) {
        $id = $request->get_param('id');
        $id_type = gettype($id);
        
        error_log("[REST API] get_booking_detail route triggered.");
        error_log("[REST API] Raw request param 'id': " . print_r($id, true));
        error_log("[REST API] Type of value passed to DB layer: " . $id_type);
        
        $token = (string)$id;
        error_log("[REST API] Final token string: " . $token);

        if (!$token) return new WP_Error('missing_id', 'Booking ID required', array('status' => 400));
        
        $booking = Buttercups_Dashboard_DB::get_booking_detail($token);
        if (!$booking) return new WP_Error('not_found', 'Booking not found', array('status' => 404));

        $labels = Buttercups_Dashboard_DB::get_custom_field_definitions();
        
        $booking->attendees = (int)$booking->attendees;
        $decoded = $booking->custom_fields ? json_decode($booking->custom_fields, true) : array();
        
        foreach ($decoded as &$field) {
            if (isset($labels[$field['id']])) {
                $field['label'] = $labels[$field['id']];
            }
        }
        $booking->custom_fields = $decoded;

        return new WP_REST_Response($booking, 200);
    }

    public function get_manual_booking_options(WP_REST_Request $request) {
        $date = $request->get_param('date');
        if (!$date) {
            return new WP_Error('missing_date', 'The date parameter is required.', array('status' => 400));
        }

        $options = Buttercups_Dashboard_DB::get_manual_booking_options($date);
        return new WP_REST_Response($options, 200);
    }

    public function get_manual_booking_availability(WP_REST_Request $request) {
        $appointment_id = $request->get_param('appointment_id');
        if (!$appointment_id) {
            return new WP_Error('missing_appointment_id', 'The appointment_id parameter is required.', array('status' => 400));
        }

        $availability = Buttercups_Dashboard_DB::get_appointment_availability($appointment_id);
        if (!$availability) {
            return new WP_Error('not_found', 'Appointment slot not found.', array('status' => 404));
        }

        return new WP_REST_Response($availability, 200);
    }

    public function create_manual_booking(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_params();
        }

        // Validation of required fields
        $required = array('appointment_id', 'customer_name', 'customer_phone', 'adults', 'payment_method');
        foreach ($required as $field) {
            if (!isset($params[$field]) || (is_string($params[$field]) && trim($params[$field]) === '')) {
                return new WP_Error('missing_param', 'The parameter ' . $field . ' is required.', array('status' => 400));
            }
        }

        $payment_method = sanitize_text_field($params['payment_method']);
        $valid_methods = array('Pay by card link', 'Pay on arrival', 'Already paid / Voucher');
        if (!in_array($payment_method, $valid_methods)) {
            return new WP_Error('invalid_payment_method', 'Invalid payment method selected.', array('status' => 400));
        }

        if ($payment_method === 'Already paid / Voucher') {
            if (empty($params['payment_note']) || trim($params['payment_note']) === '') {
                return new WP_Error('missing_payment_note', 'Voucher/reference note is required for Already paid / Voucher.', array('status' => 400));
            }
        }

        $result = Buttercups_Dashboard_DB::create_manual_booking($params);
        if (is_wp_error($result)) {
            return $result;
        }

        // Add payment link placeholder details if card link is selected
        if ($payment_method === 'Pay by card link') {
            $result['payment_link_status'] = 'not_configured';
            $result['payment_link_message'] = 'Stripe payment links are not connected yet.';
        }

        return new WP_REST_Response($result, 200);
    }
}
