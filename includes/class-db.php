<?php
if (!defined('ABSPATH')) exit;

class Buttercups_Dashboard_DB {
    private static $tables_cache = null;
    private static $extras_cache = array();

    private static function get_tables() {
        if (self::$tables_cache !== null) return self::$tables_cache;

        global $wpdb;
        $prefix = $wpdb->prefix;
        
        $candidates = array(
            'appointments' => $prefix . 'bookly_appointments',
            'customer_appointments' => $prefix . 'bookly_customer_appointments',
            'services' => $prefix . 'bookly_services',
            'customers' => $prefix . 'bookly_customers',
            'staff_services' => $prefix . 'bookly_staff_services',
            'custom_fields' => $prefix . 'bookly_custom_fields',
            'payments' => $prefix . 'bookly_payments',
            'service_extras' => $prefix . 'bookly_service_extras'
        );

        $found = array();
        $missing = array();

        foreach ($candidates as $key => $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $found[$key] = $table;
            } else {
                $missing[] = $table;
            }
        }

        self::$tables_cache = array('found' => $found, 'missing' => $missing);
        return self::$tables_cache;
    }

    public static function get_booking_detail($token) {
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        
        global $wpdb;
        $table_ca = $found['customer_appointments'];
        $table_c = $found['customers'];
        $table_a = $found['appointments'];
        $table_s = $found['services'];
        $table_p = isset($found['payments']) ? $found['payments'] : null;

        error_log("Buttercups DB Trace: get_booking_detail received token: " . $token);

        // STEP 1 -- Token Lookup ONLY (no joins)
        $sql_step1 = $wpdb->prepare("SELECT * FROM $table_ca WHERE token = %s", $token);
        $ca_row = $wpdb->get_row($sql_step1);

        if (!$ca_row) {
            error_log("[booking-detail] TOKEN RECEIVED: " . $token);
            error_log("[booking-detail] TABLE USED: " . $table_ca);
            error_log("[booking-detail] SQL QUERY: " . $sql_step1);
            if ($wpdb->last_error) {
                error_log("[booking-detail] DB ERROR: " . $wpdb->last_error);
            }
            return null;
        }

        // STEP 2 -- Fetch related data separately
        $result = new stdClass();
        $result->reference = $ca_row->id;
        $result->booking_token = $ca_row->token;
        $result->created_at = isset($ca_row->created_at) ? $ca_row->created_at : (isset($ca_row->created) ? $ca_row->created : null);
        $result->internal_note = $ca_row->notes;
        $result->attendees = $ca_row->number_of_persons;
        $result->custom_fields = $ca_row->custom_fields;
        $result->appointment_id = $ca_row->appointment_id;
        $result->resolved_extras = self::resolve_extras(isset($ca_row->extras) ? $ca_row->extras : null);

        $result->customer_name = null;
        $result->customer_phone = null;
        $result->customer_email = null;
        $result->start_date = null;
        $result->experience = null;
        $result->capacity = null;
        $result->payment_method = null;
        $result->payment_status = null;
        $result->payment_amount = null;

        // Fetch Customer
        if (!empty($ca_row->customer_id) && $table_c) {
            $customer = $wpdb->get_row($wpdb->prepare("SELECT full_name as customer_name, phone as customer_phone, email as customer_email FROM $table_c WHERE id = %d", $ca_row->customer_id));
            if ($customer) {
                $result->customer_name = $customer->customer_name;
                $result->customer_phone = $customer->customer_phone;
                $result->customer_email = $customer->customer_email;
            }
        }

        // Fetch Appointment and Service
        if (!empty($ca_row->appointment_id) && $table_a) {
            $appointment = $wpdb->get_row($wpdb->prepare("SELECT start_date, service_id FROM $table_a WHERE id = %d", $ca_row->appointment_id));
            if ($appointment) {
                $result->start_date = $appointment->start_date;
                
                // Fetch Service
                if (!empty($appointment->service_id) && $table_s) {
                    $service = $wpdb->get_row($wpdb->prepare("SELECT title as experience, capacity_max as capacity FROM $table_s WHERE id = %d", $appointment->service_id));
                    if ($service) {
                        $result->experience = $service->experience;
                        $result->capacity = $service->capacity;
                    }
                }
            }
        }

        // Fetch Payment
        if (!empty($ca_row->payment_id) && $table_p) {
            $payment = $wpdb->get_row($wpdb->prepare("SELECT type as payment_method, status as payment_status, total as payment_amount FROM $table_p WHERE id = %d", $ca_row->payment_id));
            if ($payment) {
                $result->payment_method = $payment->payment_method;
                $result->payment_status = $payment->payment_status;
                $result->payment_amount = $payment->payment_amount;
            }
        }

        return $result;
    }

    public static function get_custom_field_definitions() {
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        if (!isset($found['custom_fields'])) return array();
        
        global $wpdb;
        $table = $found['custom_fields'];
        $results = $wpdb->get_results("SELECT id, label FROM $table", 'OBJECT');
        $mapped = array();
        if ($results) {
            foreach ($results as $r) {
                $mapped[$r->id] = $r->label;
            }
        }
        return $mapped;
    }

    private static function has_ca_status_column($table_ca) {
        global $wpdb;
        if (!$table_ca) return false;
        $column = $wpdb->get_results("SHOW COLUMNS FROM `$table_ca` LIKE 'status'");
        return !empty($column);
    }

    public static function get_summary_with_diagnostics($date) {
        global $wpdb;
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        
        $diagnostics = array(
            'detected_tables' => $found,
            'missing_tables' => $tables_info['missing'],
            'row_counts' => array(),
            'query_status' => 'init'
        );

        $required = array('appointments', 'customer_appointments', 'services');
        foreach ($required as $req) {
            if (!isset($found[$req])) {
                $diagnostics['query_status'] = 'tables_missing';
                return array('data' => array(), 'diagnostics' => $diagnostics);
            }
        }

        $table_a = $found['appointments'];
        $table_ca = $found['customer_appointments'];
        $table_s = $found['services'];

        $diagnostics['row_counts']['appointments_total'] = $wpdb->get_var("SELECT COUNT(*) FROM $table_a");
        $diagnostics['row_counts']['appointments_date'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_a WHERE DATE(start_date) = %s", $date));
        
        if ($diagnostics['row_counts']['appointments_date'] == 0 || $diagnostics['row_counts']['appointments_date'] === null) {
            $diagnostics['query_status'] = 'no_appointments_for_date';
            return array('data' => array(), 'diagnostics' => $diagnostics);
        }

        $has_ca_status = self::has_ca_status_column($table_ca);
        $ca_status_check = $has_ca_status ? "AND (ca.status IS NULL OR ca.status IN ('approved', 'pending', 'done'))" : "";

        $query = $wpdb->prepare("
            SELECT 
                s.id as service_id, 
                s.title as experience_name, 
                a.id as appointment_id,
                a.start_date, 
                SUM(COALESCE(NULLIF(ca.number_of_persons, 0), 1)) as total_attendees,
                NULL as staff_capacity,
                s.capacity_max as service_capacity
            FROM $table_a a
            JOIN $table_s s ON a.service_id = s.id
            LEFT JOIN $table_ca ca ON a.id = ca.appointment_id
            WHERE DATE(a.start_date) = %s
              AND (ca.id IS NULL OR (ca.status IS NULL OR ca.status IN ('approved', 'pending', 'done')))
            GROUP BY a.id, s.id, s.title, a.start_date, s.capacity_max
        ", $date);

        $results = $wpdb->get_results($query);
        return array('data' => $results, 'diagnostics' => $diagnostics);
    }

    public static function get_summary($date) {
        $result = self::get_summary_with_diagnostics($date);
        return $result['data'];
    }

    public static function get_slot_detail($appointment_ids) {
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        if (!isset($found['customer_appointments']) || !isset($found['customers'])) return array();
        
        global $wpdb;
        $table_ca = $found['customer_appointments'];
        $table_c = $found['customers'];

        $has_ca_status = self::has_ca_status_column($table_ca);
        $status_col = $has_ca_status ? "ca.status" : "'approved' as status";
        $status_check = $has_ca_status ? "AND (ca.status IS NULL OR ca.status IN ('approved', 'pending', 'done'))" : "";

        // Ensure we have an array of integers
        if (!is_array($appointment_ids)) {
            $appointment_ids = array_map('intval', explode(',', $appointment_ids));
        } else {
            $appointment_ids = array_map('intval', $appointment_ids);
        }

        if (empty($appointment_ids)) return array();

        $ids_string = implode(',', array_filter($appointment_ids));
        
        error_log("Buttercups DB Trace: get_slot_detail for IDs: " . $ids_string);

        $query = "
            SELECT 
                c.full_name as customer_name,
                ca.id as reference,
                ca.token as booking_token,
                ca.notes as internal_note,
                COALESCE(NULLIF(ca.number_of_persons, 0), 1) as attendees,
                $status_col,
                ca.custom_fields,
                ca.extras
            FROM $table_ca ca
            LEFT JOIN $table_c c ON ca.customer_id = c.id
            WHERE ca.appointment_id IN ($ids_string)
              $status_check
        ";

        $results = $wpdb->get_results($query);
        
        foreach ($results as &$row) {
            $row->resolved_extras = self::resolve_extras($row->extras);
            unset($row->extras);
        }

        error_log("Buttercups DB Trace: get_slot_detail returns " . count($results) . " rows");
        if (count($results) > 0) {
            error_log("Buttercups DB Trace: Sample row: " . print_r($results[0], true));
        }

        return $results;
    }

    public static function search_bookings($query_str, $date = null) {
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        if (!isset($found['customer_appointments']) || !isset($found['customers']) || !isset($found['appointments']) || !isset($found['services'])) return array();

        global $wpdb;
        $table_ca = $found['customer_appointments'];
        $table_c = $found['customers'];
        $table_a = $found['appointments'];
        $table_s = $found['services'];

        $search_term = '%' . $wpdb->esc_like($query_str) . '%';
        
        $sql = "
            SELECT 
                c.full_name as customer_name,
                ca.id as reference,
                ca.token as booking_token,
                a.id as appointment_id,
                a.start_date,
                s.title as experience,
                s.capacity_max as capacity,
                COALESCE(NULLIF(ca.number_of_persons, 0), 1) as attendees,
                ca.extras
            FROM $table_ca ca
            JOIN $table_c c ON ca.customer_id = c.id
            JOIN $table_a a ON ca.appointment_id = a.id
            JOIN $table_s s ON a.service_id = s.id
            WHERE (c.full_name LIKE %s OR ca.token LIKE %s OR CAST(ca.id AS CHAR) LIKE %s)
        ";
        
        $params = [$search_term, $search_term, $search_term];

        if ($date) {
            $sql = $sql . " AND DATE(a.start_date) = %s";
            $params[] = $date;
        }

        $sql = $sql . " ORDER BY a.start_date DESC LIMIT 50";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        
        foreach ($results as &$r) {
            $r->resolved_extras = self::resolve_extras($r->extras);
            unset($r->extras);
            $r->attendees = (int)$r->attendees;
        }

        return $results;
    }

    public static function resolve_extras($extras_json) {
        if (empty($extras_json) || $extras_json === '[]' || $extras_json === '{}') return array();
        
        $extras_data = json_decode($extras_json, true);
        if (!is_array($extras_data)) return array();

        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        $table_se = isset($found['service_extras']) ? $found['service_extras'] : null;

        $resolved = array();
        global $wpdb;

        foreach ($extras_data as $extra_id => $quantity) {
            $extra_id_int = (int)$extra_id;
            $quantity_int = (int)$quantity;
            
            if (!isset(self::$extras_cache[$extra_id_int])) {
                $title = "Extra #$extra_id_int";
                if ($table_se) {
                    $extra_info = $wpdb->get_row($wpdb->prepare("SELECT title FROM $table_se WHERE id = %d", $extra_id_int));
                    if ($extra_info) {
                        $title = $extra_info->title;
                    }
                }
                self::$extras_cache[$extra_id_int] = $title;
            }

            $resolved[] = array(
                'id' => $extra_id_int,
                'title' => self::$extras_cache[$extra_id_int],
                'quantity' => $quantity_int
            );
        }
        return $resolved;
    }

    public static function get_tea_summary($date) {
        global $wpdb;
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        
        if (!isset($found['appointments']) || !isset($found['customer_appointments']) || !isset($found['customers']) || !isset($found['services'])) {
            return array('total_teas' => 0, 'tea_breakdown' => array(), 'tea_notes' => array());
        }

        $table_a = $found['appointments'];
        $table_ca = $found['customer_appointments'];
        $table_c = $found['customers'];
        $table_s = $found['services'];

        // Fetch bookings for the date with customer and service details
        $query = $wpdb->prepare("
            SELECT 
                ca.extras, 
                ca.notes, 
                ca.token, 
                c.full_name as customer_name, 
                a.start_date, 
                s.title as service_name
            FROM $table_ca ca
            JOIN $table_a a ON ca.appointment_id = a.id
            JOIN $table_c c ON ca.customer_id = c.id
            JOIN $table_s s ON a.service_id = s.id
            WHERE DATE(a.start_date) = %s
              AND (ca.status IS NULL OR ca.status IN ('approved', 'pending', 'done'))
        ", $date);

        $results = $wpdb->get_results($query);
        
        $total_teas = 0;
        $breakdown_map = array();
        $tea_notes = array();

        foreach ($results as $row) {
            $resolved = self::resolve_extras($row->extras);
            $is_tea_booking = false;
            
            foreach ($resolved as $extra) {
                if (stripos($extra['title'], 'Afternoon Tea') !== false) {
                    $is_tea_booking = true;
                    
                    // Normalize the title for grouping
                    $raw_title = $extra['title'];
                    $normalized = trim($raw_title);
                    $normalized = rtrim($normalized, ',. ');
                    $normalized = preg_replace('/\s+/', ' ', $normalized);
                    
                    $group_key = strtolower($normalized);
                    
                    $total_teas += $extra['quantity'];
                    if (!isset($breakdown_map[$group_key])) {
                        $breakdown_map[$group_key] = array(
                            'title' => $normalized,
                            'quantity' => 0
                        );
                    }
                    $breakdown_map[$group_key]['quantity'] += $extra['quantity'];
                }
            }
            
            // If it's a tea booking and has a non-empty note, add to notes list
            if ($is_tea_booking && !empty(trim($row->notes))) {
                $tea_notes[] = array(
                    'booking_token' => $row->token,
                    'customer_name' => $row->customer_name,
                    'time' => date('H:i', strtotime($row->start_date)),
                    'service_name' => $row->service_name,
                    'note' => trim($row->notes)
                );
            }
        }

        $tea_breakdown = array();
        foreach ($breakdown_map as $item) {
            $tea_breakdown[] = array('title' => $item['title'], 'quantity' => (int)$item['quantity']);
        }

        usort($tea_breakdown, function($a, $b) {
            return $b['quantity'] - $a['quantity'];
        });

        // Sort notes by time
        usort($tea_notes, function($a, $b) {
            return strcmp($a['time'], $b['time']);
        });

        return array(
            'total_teas' => (int)$total_teas,
            'tea_breakdown' => $tea_breakdown,
            'tea_notes' => $tea_notes
        );
    }

    public static function get_manual_booking_options($date) {
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        $table_a = $found['appointments'];
        $table_s = $found['services'];
        $table_ca = $found['customer_appointments'];
        $table_ss = isset($found['staff_services']) ? $found['staff_services'] : null;
        $table_se = isset($found['service_extras']) ? $found['service_extras'] : null;

        global $wpdb;

        $ss_join = $table_ss ? "LEFT JOIN $table_ss ss ON a.service_id = ss.service_id AND a.staff_id = ss.staff_id" : "";
        $ss_select = $table_ss ? ", ss.capacity_max as staff_capacity" : ", NULL as staff_capacity";

        $query = $wpdb->prepare("
            SELECT 
                a.id as appointment_id,
                a.service_id,
                a.staff_id,
                a.start_date,
                s.title as service_title,
                s.price as service_price,
                s.capacity_max as service_capacity
                $ss_select
            FROM $table_a a
            JOIN $table_s s ON a.service_id = s.id
            $ss_join
            WHERE DATE(a.start_date) = %s
            ORDER BY a.start_date ASC
        ", $date);

        $appointments = $wpdb->get_results($query);

        $active_attendees = array();
        if (!empty($appointments)) {
            $appointment_ids = array_map(function($a) { return (int)$a->appointment_id; }, $appointments);
            $ids_string = implode(',', $appointment_ids);

            $ca_query = "
                SELECT appointment_id, SUM(COALESCE(NULLIF(number_of_persons, 0), 1)) as total_booked
                FROM $table_ca
                WHERE appointment_id IN ($ids_string)
                  AND (status IS NULL OR status IN ('approved', 'pending', 'done'))
                GROUP BY appointment_id
            ";
            $ca_results = $wpdb->get_results($ca_query);
            foreach ($ca_results as $row) {
                $active_attendees[(int)$row->appointment_id] = (int)$row->total_booked;
            }
        }

        $service_extras = array();
        if (!empty($appointments) && $table_se) {
            $service_ids = array_unique(array_map(function($a) { return (int)$a->service_id; }, $appointments));
            $s_ids_string = implode(',', $service_ids);

            $se_query = "
                SELECT id, service_id, title, price, min_quantity, max_quantity
                FROM $table_se
                WHERE service_id IN ($s_ids_string)
                ORDER BY title ASC
            ";
            $se_results = $wpdb->get_results($se_query);
            foreach ($se_results as $row) {
                $s_id = (int)$row->service_id;
                if (!isset($service_extras[$s_id])) {
                    $service_extras[$s_id] = array();
                }
                $service_extras[$s_id][] = array(
                    'id' => (int)$row->id,
                    'title' => $row->title,
                    'price' => (float)$row->price,
                    'min_quantity' => (int)$row->min_quantity,
                    'max_quantity' => (int)$row->max_quantity
                );
            }
        }

        $experiences = array();
        foreach ($appointments as $app) {
            $s_id = (int)$app->service_id;

            $capacity = !empty($app->staff_capacity) ? (int)$app->staff_capacity : (!empty($app->service_capacity) ? (int)$app->service_capacity : 0);
            $booked = isset($active_attendees[(int)$app->appointment_id]) ? $active_attendees[(int)$app->appointment_id] : 0;
            $remaining = max(0, $capacity - $booked);

            if (!isset($experiences[$s_id])) {
                $experiences[$s_id] = array(
                    'id' => $s_id,
                    'title' => $app->service_title,
                    'price' => (float)$app->service_price,
                    'extras' => isset($service_extras[$s_id]) ? $service_extras[$s_id] : array(),
                    'slots' => array()
                );
            }

            $experiences[$s_id]['slots'][] = array(
                'appointment_id' => (int)$app->appointment_id,
                'time' => date('H:i', strtotime($app->start_date)),
                'capacity' => $capacity,
                'active_attendees' => $booked,
                'remaining_spaces' => $remaining,
                'price' => (float)$app->service_price
            );
        }

        return array_values($experiences);
    }

    public static function get_appointment_availability($appointment_id) {
        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        $table_a = $found['appointments'];
        $table_s = $found['services'];
        $table_ca = $found['customer_appointments'];
        $table_ss = isset($found['staff_services']) ? $found['staff_services'] : null;

        global $wpdb;

        $ss_join = $table_ss ? "LEFT JOIN $table_ss ss ON a.service_id = ss.service_id AND a.staff_id = ss.staff_id" : "";
        $ss_select = $table_ss ? ", ss.capacity_max as staff_capacity" : ", NULL as staff_capacity";

        $app = $wpdb->get_row($wpdb->prepare("
            SELECT 
                a.id as appointment_id,
                a.service_id,
                a.start_date,
                s.title as service_title,
                s.capacity_max as service_capacity
                $ss_select
            FROM $table_a a
            JOIN $table_s s ON a.service_id = s.id
            $ss_join
            WHERE a.id = %d
        ", $appointment_id));

        if (!$app) return null;

        $capacity = !empty($app->staff_capacity) ? (int)$app->staff_capacity : (!empty($app->service_capacity) ? (int)$app->service_capacity : 0);

        $booked = (int)$wpdb->get_var($wpdb->prepare("
            SELECT SUM(COALESCE(NULLIF(number_of_persons, 0), 1))
            FROM $table_ca
            WHERE appointment_id = %d
              AND (status IS NULL OR status IN ('approved', 'pending', 'done'))
        ", $appointment_id));

        return array(
            'appointment_id' => (int)$app->appointment_id,
            'service_title' => $app->service_title,
            'appointment_time' => date('H:i', strtotime($app->start_date)),
            'capacity' => $capacity,
            'active_booked_attendees' => $booked,
            'remaining_spaces' => max(0, $capacity - $booked)
        );
    }

    public static function create_manual_booking($data) {
        global $wpdb;

        $tables_info = self::get_tables();
        $found = $tables_info['found'];
        $table_a = $found['appointments'];
        $table_s = $found['services'];
        $table_ca = $found['customer_appointments'];
        $table_c = $found['customers'];
        $table_ss = isset($found['staff_services']) ? $found['staff_services'] : null;
        $table_se = isset($found['service_extras']) ? $found['service_extras'] : null;

        $appointment_id = (int)$data['appointment_id'];
        $customer_name  = sanitize_text_field($data['customer_name']);
        $customer_phone = sanitize_text_field($data['customer_phone']);
        $customer_email = !empty($data['customer_email']) ? sanitize_email($data['customer_email']) : '';
        $adults         = max(1, (int)$data['adults']);
        $under_14       = max(0, (int)$data['under_14']);
        $requested_attendees = $adults + $under_14;

        $extras_data    = isset($data['extras']) ? $data['extras'] : array();
        $internal_note  = sanitize_textarea_field($data['internal_note']);
        $payment_method = sanitize_text_field($data['payment_method']);
        $payment_note   = isset($data['payment_note']) ? sanitize_text_field($data['payment_note']) : '';

        // 1. Start Database Transaction
        $wpdb->query('START TRANSACTION');

        // 2. Lock appointment row using FOR UPDATE to prevent race conditions
        $app = $wpdb->get_row($wpdb->prepare("
            SELECT a.id, a.service_id, s.price as service_price, s.capacity_max as service_capacity
            FROM $table_a a
            JOIN $table_s s ON a.service_id = s.id
            WHERE a.id = %d
            FOR UPDATE
        ", $appointment_id));

        if (!$app) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('invalid_appointment', 'The selected appointment slot was not found.', array('status' => 404));
        }

        $service_id = (int)$app->service_id;
        $service_price = (float)$app->service_price;

        // 3. Resolve Capacity Max
        $staff_capacity = null;
        if ($table_ss) {
            $staff_id = $wpdb->get_var($wpdb->prepare("SELECT staff_id FROM $table_a WHERE id = %d", $appointment_id));
            if ($staff_id) {
                $staff_capacity = $wpdb->get_var($wpdb->prepare("
                    SELECT capacity_max 
                    FROM $table_ss 
                    WHERE service_id = %d AND staff_id = %d
                ", $service_id, $staff_id));
            }
        }

        $capacity = !empty($staff_capacity) ? (int)$staff_capacity : (!empty($app->service_capacity) ? (int)$app->service_capacity : 0);

        // 4. Sum current active attendees
        $active_attendees = (int)$wpdb->get_var($wpdb->prepare("
            SELECT SUM(COALESCE(NULLIF(number_of_persons, 0), 1))
            FROM $table_ca
            WHERE appointment_id = %d
              AND (status IS NULL OR status IN ('approved', 'pending', 'done'))
        ", $appointment_id));

        // Check Capacity
        if ($active_attendees + $requested_attendees > $capacity) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('over_capacity', 'Exceeded remaining capacity! The selected slot only has ' . max(0, $capacity - $active_attendees) . ' space(s) left.', array('status' => 400));
        }

        // 5. Validate Extras server-side (including min_quantity check and service check)
        $extras_total = 0.0;
        $filtered_extras = array();

        // Fetch all service extras for this service from the database
        $all_service_extras = array();
        if ($table_se) {
            $all_service_extras = $wpdb->get_results($wpdb->prepare("
                SELECT id, min_quantity, max_quantity, price
                FROM $table_se
                WHERE service_id = %d
            ", $service_id));
        }

        // Map submitted extras for easy lookup
        $submitted_extras_map = array();
        if (!empty($extras_data) && is_array($extras_data)) {
            foreach ($extras_data as $extra_id => $qty) {
                $submitted_extras_map[(int)$extra_id] = (int)$qty;
            }
        }

        // Enforce min_quantity requirements for all extras belonging to this service
        foreach ($all_service_extras as $extra) {
            $extra_id = (int)$extra->id;
            $min_qty  = (int)$extra->min_quantity;
            $max_qty  = (int)$extra->max_quantity;

            $qty = isset($submitted_extras_map[$extra_id]) ? $submitted_extras_map[$extra_id] : 0;

            // Enforce min_quantity if min_quantity > 0, even if not submitted
            if ($qty < $min_qty) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('missing_required_extra', 'Service extra ID ' . $extra_id . ' requires a minimum quantity of ' . $min_qty . '.', array('status' => 400));
            }

            // Enforce max_quantity
            if ($qty > $max_qty) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_extra_quantity', 'Quantity ' . $qty . ' for service extra ID ' . $extra_id . ' exceeds the maximum of ' . $max_qty . '.', array('status' => 400));
            }

            if ($qty > 0) {
                $extras_total += (float)$extra->price * $qty;
                $filtered_extras[(string)$extra_id] = (string)$qty;
            }
        }

        // Validate that no unrecognized extra IDs were submitted
        foreach ($submitted_extras_map as $submitted_id => $qty) {
            if ($qty <= 0) continue;
            $found_valid = false;
            foreach ($all_service_extras as $extra) {
                if ((int)$extra->id === $submitted_id) {
                    $found_valid = true;
                    break;
                }
            }
            if (!$found_valid) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_extra', 'Service extra ID ' . $submitted_id . ' is invalid or does not belong to the selected experience experience.', array('status' => 400));
            }
        }

        // Calculate Total Price: service_price * attendees + extras_total
        $total_price = ($service_price * $requested_attendees) + $extras_total;

        // 6. Reuse or Create Customer with Bookly-safe defaults to prevent NOT NULL validation crashes
        $customer_id = null;
        if (!empty($customer_email)) {
            $customer_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_c WHERE email = %s", $customer_email));
        }
        if (!$customer_id && !empty($customer_phone)) {
            $customer_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_c WHERE phone = %s", $customer_phone));
        }

        if (!$customer_id) {
            $parts = explode(' ', trim($customer_name));
            $first_name = array_shift($parts);
            $last_name  = implode(' ', $parts);
            if (empty($last_name)) {
                $last_name = '';
            }
            if (empty($first_name)) {
                $first_name = $customer_name;
            }

            $c_insert = $wpdb->insert($table_c, array(
                'full_name'     => $customer_name,
                'first_name'    => $first_name,
                'last_name'     => $last_name,
                'phone'         => $customer_phone,
                'email'         => $customer_email,
                'notes'         => '',
                'info_fields'   => '[]',
                'country'       => '',
                'state'         => '',
                'postcode'      => '',
                'city'          => '',
                'street'        => '',
                'street_number' => '',
                'created_at'    => current_time('mysql')
            ));

            if ($c_insert === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('customer_creation_failed', 'Failed to create customer record.', array('status' => 500));
            }
            $customer_id = $wpdb->insert_id;
        }

        // 7. Payment Row: Skipped for v1 to ensure high compatibility and avoid fragile field crashes
        $payment_id = null;

        // 8. Generate Unique Token and Check Uniqueness in customer_appointments
        do {
            $token = bin2hex(random_bytes(6)); // 12 characters secure hex
            $token_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_ca WHERE token = %s", $token));
        } while ($token_exists > 0);

        // Build Note
        $notes = "Payment method: " . $payment_method;
        if ($payment_method === 'Already paid / Voucher' && !empty($payment_note)) {
            $notes .= "\nVoucher/Reference: " . $payment_note;
        }
        if (!empty($internal_note)) {
            $notes .= "\n\nInternal Note:\n" . $internal_note;
        }

        // Build Custom Fields JSON (including child count field 3185 if children > 0, or empty if 0)
        $custom_fields = array();
        if ($under_14 > 0) {
            $custom_fields[] = array(
                'id' => 3185,
                'value' => (string)$under_14
            );
        } else {
            $custom_fields[] = array(
                'id' => 3185,
                'value' => ''
            );
        }
        $custom_fields_json = json_encode($custom_fields);

        // Build Extras JSON
        $extras_json = empty($filtered_extras) ? '[]' : json_encode($filtered_extras);

        // 9. Insert Booking row
        $ca_insert_data = array(
            'customer_id' => $customer_id,
            'appointment_id' => $appointment_id,
            'payment_id' => $payment_id,
            'number_of_persons' => $requested_attendees,
            'units' => 1,
            'notes' => $notes,
            'extras' => $extras_json,
            'extras_multiply_nop' => 1,
            'custom_fields' => $custom_fields_json,
            'status' => 'approved',
            'status_changed_at' => current_time('mysql'),
            'token' => $token,
            'locale' => null,
            'created_from' => 'backend',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $ca_insert = $wpdb->insert($table_ca, $ca_insert_data);

        if ($ca_insert === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('booking_creation_failed', 'Failed to create the booking record.', array('status' => 500));
        }

        $booking_id = $wpdb->insert_id;

        // 10. Commit Transaction
        $wpdb->query('COMMIT');

        return array(
            'success' => true,
            'booking_id' => $booking_id,
            'token' => $token,
            'payment_id' => $payment_id,
            'total_price' => $total_price
        );
    }
}

