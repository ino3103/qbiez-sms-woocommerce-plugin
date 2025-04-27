<?php

/**
 * Plugin Name: Q-SMS Integration
 * Description: Integrates Q-SMS gateway with WooCommerce to send SMS notifications.
 * Version: 1.0
 * Author: QBIEZ
 * Text Domain: q-sms-integration
 * Author URI: https://sms.qbiez.com/
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
define('Q_SMS_INTEGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('Q_SMS_INTEGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));

class QSMSGatewayIntegration
{
    private $option_key = 'qsms_settings';

    public function __construct()
    {
        add_action('admin_init', array($this, 'initialize_settings'));
        add_action('woocommerce_thankyou', array($this, 'send_sms_on_first_order'));
        add_action('woocommerce_order_status_changed', array($this, 'send_sms_on_status_change'), 10, 4);
        add_action('admin_menu', array($this, 'add_settings_page')); // Add settings page link.
    }

    /**
     * Initialize settings section and fields.
     */
    public function initialize_settings()
    {
        register_setting('qsms-settings', $this->option_key);

        // Add settings section.
        add_settings_section(
            'qsms_section',
            'Q-SMS Settings',
            null,
            'qsms_settings'
        );

        // Define SMS templates for each order status.
        $statuses = array(
            'wc-pending'    => 'Pending',
            'wc-processing' => 'Processing',
            'wc-cancelled'  => 'Cancelled',
            'wc-on-hold'    => 'On Hold',
            'wc-completed'  => 'Completed',
            'wc-refunded'   => 'Refunded'
        );

        // Set default templates for all statuses.
        $default_templates = array(
            'wc-pending'    => 'Dear {{first_name}}, your order (#{{order_id}}) is pending. Total: {{price}}.',
            'wc-processing' => 'Dear {{first_name}}, your order (#{{order_id}}) is being processed. Total: {{price}}.',
            'wc-cancelled'  => 'Dear {{first_name}}, your order (#{{order_id}}) has been cancelled. Total: {{price}}.',
            'wc-on-hold'    => 'Dear {{first_name}}, your order (#{{order_id}}) is on hold. Total: {{price}}.',
            'wc-completed'  => 'Dear {{first_name}}, your order (#{{order_id}}) has been completed. Total: {{price}}.',
            'wc-refunded'   => 'Dear {{first_name}}, your order (#{{order_id}}) has been refunded. Total: {{price}}.'
        );

        // Get existing options or initialize with defaults.
        $options = get_option($this->option_key, array());

        // Ensure default templates are set if not already configured.
        foreach ($statuses as $key => $label) {
            if (!isset($options[$key])) {
                $options[$key] = $default_templates[$key]; // Set default template.
            }
            if (!isset($options[$key . '_enabled'])) {
                $options[$key . '_enabled'] = 1; // Enable SMS for this status by default.
            }
        }

        // Set default admin template if not already configured.
        if (!isset($options['admin_template'])) {
            $options['admin_template'] = 'Hey Admin! A new order has been received. Order ID: #{{order_id}}, Total: {{price}}';
        }

        // Save the updated options.
        update_option($this->option_key, $options);

        // Add settings fields for each status.
        foreach ($statuses as $key => $label) {
            add_settings_field(
                $key,
                "SMS for {$label} Orders",
                array($this, 'render_textarea_input'),
                'qsms_settings',
                'qsms_section',
                array('key' => $key)
            );
        }

        // API credentials.
        add_settings_field(
            'api_token',
            'API Token',
            array($this, 'render_text_input'),
            'qsms_settings',
            'qsms_section',
            array('key' => 'api_token')
        );
        add_settings_field(
            'sender_id',
            'Sender ID',
            array($this, 'render_text_input'),
            'qsms_settings',
            'qsms_section',
            array('key' => 'sender_id')
        );

        // Admin numbers for new order notifications.
        add_settings_field(
            'admin_numbers',
            'Admin Phone Numbers',
            array($this, 'render_admin_numbers_input'),
            'qsms_settings',
            'qsms_section'
        );
        add_settings_field(
            'enable_admin_notifications',
            'Enable Admin Notifications',
            array($this, 'render_checkbox_input'),
            'qsms_settings',
            'qsms_section',
            array('key' => 'enable_admin_notifications')
        );

        // Admin SMS template.
        add_settings_field(
            'admin_template',
            'Admin SMS Template',
            array($this, 'render_textarea_input'),
            'qsms_settings',
            'qsms_section',
            array('key' => 'admin_template')
        );
    }

    /**
     * Render a text input field.
     */
    public function render_text_input($args)
    {
        $options = get_option($this->option_key);
        echo '<input type="text" name="' . esc_attr($this->option_key) . '[' . esc_attr($args['key']) . ']" value="' . esc_attr($options[$args['key']] ?? '') . '" class="regular-text" />';
    }

    /**
     * Render a textarea input field.
     */
    public function render_textarea_input($args)
    {
        $options = get_option($this->option_key);
        echo '<textarea name="' . esc_attr($this->option_key) . '[' . esc_attr($args['key']) . ']" rows="5" cols="50" style="width: 100%; max-width: 600px; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">' . esc_textarea($options[$args['key']] ?? '') . '</textarea>';
        echo '<p class="description">Shortcodes: {{first_name}}, {{last_name}}, {{full_name}}, {{price}}, {{product_name}}, {{order_id}}, {{order_date}}, {{payment_method}}, {{shipping_method}}</p>';
    }

    /**
     * Render admin numbers input field.
     */
    public function render_admin_numbers_input()
    {
        $options = get_option($this->option_key);
        echo '<input type="text" name="' . esc_attr($this->option_key) . '[admin_numbers]" value="' . esc_attr($options['admin_numbers'] ?? '') . '" class="regular-text" />';
        echo '<p class="description">Enter admin phone numbers separated by commas (e.g., 255789...,255789...).</p>';
    }

    /**
     * Render a checkbox input field.
     */
    public function render_checkbox_input($args)
    {
        $options = get_option($this->option_key);
        echo '<input type="checkbox" name="' . esc_attr($this->option_key) . '[' . esc_attr($args['key']) . ']" value="1" ' . checked(1, $options[$args['key']] ?? 0, false) . ' />';
    }

    /**
     * Add a link to the settings page under Settings menu.
     */
    public function add_settings_page()
    {
        add_options_page(
            'Q-SMS Settings', // Page title
            'Q-SMS Settings', // Menu title
            'manage_options', // Capability required
            'qsms_settings',  // Menu slug
            array($this, 'settings_page_callback') // Callback function
        );
    }

    /**
     * Render the settings page content.
     */
    public function settings_page_callback()
    {
        echo '<div class="wrap">';
        echo '<h1><img src="' . esc_url(Q_SMS_INTEGRATION_PLUGIN_URL . 'assets/logo.png') . '" alt="Q-SMS Logo" style="width: 150px; margin-bottom: 20px;"> Q-SMS Settings</h1>';

        // Display registration link for users without API credentials.
        $options = get_option($this->option_key);
        if (empty($options['api_token'])) {
            echo '<div class="notice notice-info"><p>Don\'t have API credentials? <a href="https://sms.qbiez.com/login" target="_blank">Register here</a> to get started.</p></div>';
        }

        echo '<form action="options.php" method="post">';

        // Output nonce, action, and option_page fields for the settings form.
        settings_fields('qsms-settings');

        // API Credentials Section
        echo '<div style="margin-bottom: 20px; background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<h2 style="margin-top: 0; color: #23282d;">API Credentials</h2>';
        echo '<table class="form-table">';
        echo '<tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="api_token">API Token</label></th>';
        echo '<td><input type="text" name="' . esc_attr($this->option_key) . '[api_token]" value="' . esc_attr($options['api_token'] ?? '') . '" class="regular-text" /></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="sender_id">Sender ID</label></th>';
        echo '<td><input type="text" name="' . esc_attr($this->option_key) . '[sender_id]" value="' . esc_attr($options['sender_id'] ?? '') . '" class="regular-text" /></td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        // Admin Notifications Section
        echo '<div style="margin-bottom: 20px; background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<h2 style="color: #23282d;">Admin Notifications</h2>';
        echo '<table class="form-table">';
        echo '<tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="admin_numbers">Admin Phone Numbers</label></th>';
        echo '<td><input type="text" name="' . esc_attr($this->option_key) . '[admin_numbers]" value="' . esc_attr($options['admin_numbers'] ?? '') . '" class="regular-text" />';
        echo '<p class="description">Enter admin phone numbers separated by commas (e.g., 255789...,255789...).</p></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="enable_admin_notifications">Enable Admin Notifications</label></th>';
        echo '<td><input type="checkbox" name="' . esc_attr($this->option_key) . '[enable_admin_notifications]" value="1" ' . checked(1, $options['enable_admin_notifications'] ?? 0, false) . ' /></td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        // Admin SMS Template Section
        echo '<div style="margin-bottom: 20px; background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<h2 style="color: #23282d;">Admin SMS Template</h2>';
        echo '<table class="form-table">';
        echo '<tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="admin_template">Admin SMS Template</label></th>';
        echo '<td><textarea name="' . esc_attr($this->option_key) . '[admin_template]" rows="5" cols="50" style="width: 100%; max-width: 600px; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">' . esc_textarea($options['admin_template'] ?? '') . '</textarea>';
        echo '<p class="description">Shortcodes: {{order_id}}, {{price}}, {{first_name}}, {{last_name}}, {{full_name}}, {{product_name}}, {{order_date}}, {{payment_method}}, {{shipping_method}}</p></td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        // Order Status Selection Section
        echo '<div style="margin-bottom: 20px; background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<h2 style="color: #23282d;">Order Statuses</h2>';
        echo '<p>Select the order statuses for which you want to send SMS notifications:</p>';
        $statuses = array(
            'wc-pending'    => 'Pending',
            'wc-processing' => 'Processing',
            'wc-cancelled'  => 'Cancelled',
            'wc-on-hold'    => 'On Hold',
            'wc-completed'  => 'Completed',
            'wc-refunded'   => 'Refunded'
        );
        $options = get_option($this->option_key);
        echo '<table class="form-table">';
        echo '<tbody>';
        foreach ($statuses as $key => $label) {
            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr($key) . '_enabled">' . esc_html($label) . ' Orders</label></th>';
            echo '<td><input type="checkbox" name="' . esc_attr($this->option_key) . '[' . esc_attr($key) . '_enabled]" value="1" ' . checked(1, $options[$key . '_enabled'] ?? 0, false) . ' /></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        // SMS Templates Section
        echo '<div style="background-color: #ffffff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<h2 style="color: #23282d;">SMS Templates</h2>';
        echo '<p>Customize the SMS messages for each selected order status:</p>';
        echo '<table class="form-table">';
        echo '<tbody>';
        foreach ($statuses as $key => $label) {
            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . ' Orders</label></th>';
            echo '<td><textarea name="' . esc_attr($this->option_key) . '[' . esc_attr($key) . ']" rows="5" cols="50" style="width: 100%; max-width: 600px; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">' . esc_textarea($options[$key] ?? '') . '</textarea>';
            echo '<p class="description">Shortcodes: {{first_name}}, {{last_name}}, {{full_name}}, {{price}}, {{product_name}}, {{order_id}}, {{order_date}}, {{payment_method}}, {{shipping_method}}</p></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        // Save Button
        submit_button('Save Changes', 'primary', '', false, array('style' => 'margin-top: 20px;'));

        echo '</form>';
        echo '</div>';
    }

    /**
     * Format price for SMS.
     */
    private function format_price_for_sms($price)
    {
        $formatted_price = wc_price($price); // Get the formatted price with HTML tags.
        $plain_text_price = wp_strip_all_tags($formatted_price); // Strip HTML tags.
        $plain_text_price = str_replace('&nbsp;', ' ', $plain_text_price); // Replace &nbsp; with a regular space.
        return $plain_text_price;
    }

    /**
     * Send an SMS when a customer places their first order.
     */
    public function send_sms_on_first_order($order_id)
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception("Order not found.");
            }

            // Check if SMS has already been sent for this order.
            if (get_post_meta($order_id, '_qsms_sent', true)) {
                return; // SMS already sent.
            }

            $billing_email = $order->get_billing_email();
            if (!$billing_email) {
                throw new Exception("Billing email not available.");
            }

            $args = array(
                'limit'      => 2,
                'meta_key'   => '_billing_email',
                'meta_value' => $billing_email,
                'return'     => 'ids'
            );
            $orders = wc_get_orders($args);
            if (count($orders) > 1) {
                return; // Not the first order.
            }

            $order_status = $order->get_status();
            $status_key   = 'wc-' . $order_status;
            $options      = get_option($this->option_key);
            if (empty($options[$status_key])) {
                throw new Exception("No template defined for this status.");
            }

            $template = $options[$status_key];
            $message  = $this->replace_shortcodes($template, $order);

            $phone = $this->format_phone_number($order->get_billing_phone());
            if (!$phone) {
                throw new Exception("Invalid phone number.");
            }

            $this->send_sms($phone, $message);

            // Mark SMS as sent for this order.
            update_post_meta($order_id, '_qsms_sent', true);

            // Send SMS to admin if enabled.
            if (isset($options['enable_admin_notifications']) && $options['enable_admin_notifications'] == 1 && !empty($options['admin_numbers'])) {
                $admin_numbers = array_filter(array_map('trim', explode(',', $options['admin_numbers']))); // Split by comma, trim, and remove empty values.

                if (!empty($admin_numbers)) {
                    $admin_template = $options['admin_template'] ?? 'Hey Admin! A new order has been received. Order ID: #{{order_id}}, Total: {{price}}';
                    $admin_message  = $this->replace_shortcodes($admin_template, $order);

                    foreach ($admin_numbers as $admin_number) {
                        $admin_number = $this->format_phone_number($admin_number);
                        if ($admin_number) {
                            $this->send_sms($admin_number, $admin_message);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[Q-SMS Error] First Order SMS: " . $e->getMessage());
        }
    }

    /**
     * Send an SMS when an order's status is changed manually.
     */
    public function send_sms_on_status_change($order_id, $old_status, $new_status, $order)
    {
        try {
            if (!$order) {
                throw new Exception("Order not found.");
            }

            $status_key = 'wc-' . $new_status;
            $options    = get_option($this->option_key);
            if (empty($options[$status_key])) {
                return; // No SMS template defined for this status.
            }

            $template = $options[$status_key];
            $message  = $this->replace_shortcodes($template, $order);

            $phone = $this->format_phone_number($order->get_billing_phone());
            if (!$phone) {
                throw new Exception("Invalid phone number.");
            }

            $this->send_sms($phone, $message);
        } catch (Exception $e) {
            error_log("[Q-SMS Error] Status Change SMS: " . $e->getMessage());
        }
    }

    /**
     * Replace shortcodes in the SMS template.
     */
    private function replace_shortcodes($template, $order)
    {
        $replacements = array(
            '{{first_name}}'      => $order->get_billing_first_name(),
            '{{last_name}}'       => $order->get_billing_last_name(),
            '{{full_name}}'       => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '{{price}}'           => $this->format_price_for_sms($order->get_total()), // Use the formatted price.
            '{{product_name}}'    => implode(', ', array_column($order->get_items(), 'name')),
            '{{order_id}}'        => $order->get_id(),
            '{{order_date}}'      => wc_format_datetime($order->get_date_created()),
            '{{payment_method}}'  => $order->get_payment_method_title(),
            '{{shipping_method}}' => $order->get_shipping_method()
        );

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Format phone number for the Q-SMS API.
     */
    private function format_phone_number($phone)
    {
        if (empty($phone)) {
            return '';
        }

        $phone = trim($phone);
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone); // Remove spaces, hyphens, and parentheses.
        if (strpos($phone, '+') === 0) {
            $phone = substr($phone, 1); // Remove leading plus sign.
        }
        if (strpos($phone, '0') === 0 && strlen($phone) == 10) {
            $phone = '255' . substr($phone, 1); // Prepend "255" for Tanzanian numbers.
        }
        return $phone;
    }

    /**
     * Send an SMS using the Q-SMS API.
     */
    private function send_sms($phone, $message)
    {
        try {
            $options = get_option($this->option_key);
            $api_token = $options['api_token'] ?? '';
            $sender_id = $options['sender_id'] ?? 'INFO';

            if (empty($api_token) || empty($phone) || empty($message)) {
                throw new Exception("Missing required information (API token, phone, or message).");
            }

            $postData = array(
                'api_token' => $api_token,
                'recipient' => $phone,
                'sender_id' => $sender_id,
                'type'      => 'plain',
                'message'   => $message
            );

            $url = 'https://sms.qbiez.com/api/http/sms/send';
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/json',
                    'Accept: application/json'
                ),
                CURLOPT_POSTFIELDS     => json_encode($postData)
            ));

            $response = curl_exec($ch);
            if ($response === false) {
                throw new Exception("CURL error: " . curl_error($ch));
            }

            curl_close($ch);
            error_log("[Q-SMS Success] SMS sent to $phone: $message");
        } catch (Exception $e) {
            error_log("[Q-SMS Error] API Request Failed: " . $e->getMessage());
        }
    }
}

// Instantiate the plugin class.
new QSMSGatewayIntegration();
