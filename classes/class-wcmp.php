<?php

/**
 * WCMp Main Class
 *
 * @version		2.2.0
 * @package		WCMp
 * @author 		WC Marketplace
 */
if (!defined('ABSPATH')) {
    exit;
}

final class WCMp {

    public $plugin_url;
    public $plugin_path;
    public $version;
    public $token;
    public $text_domain;
    public $library;
    public $shortcode;
    public $admin;
    public $endpoints;
    public $frontend;
    public $vendor_hooks;
    public $template;
    public $ajax;
    public $taxonomy;
    public $product;
    private $file;
    public $settings;
    public $wcmp_wp_fields;
    public $user;
    public $vendor_caps;
    public $vendor_dashboard;
    public $transaction;
    public $email;
    public $review_rating;
    public $coupon;
    public $more_product_array = array();
    public $payment_gateway;
    public $wcmp_frontend_lib;
    public $cron_job;
    public $product_qna;
    public $commission;

    /**
     * Class construct
     * @param object $file
     */
    public function __construct($file) {
        $this->file = $file;
        $this->plugin_url = trailingslashit(plugins_url('', $plugin = $file));
        $this->plugin_path = trailingslashit(dirname($file));
        $this->token = WCMp_PLUGIN_TOKEN;
        $this->text_domain = WCMp_TEXT_DOMAIN;
        $this->version = WCMp_PLUGIN_VERSION;

        // Intialize WCMp Widgets
        $this->init_custom_widgets();

        // Init payment gateways
        $this->init_payment_gateway();

        // Intialize Crons
        $this->init_cron_job();

        // Intialize WCMp
        add_action('init', array(&$this, 'init'));

        add_action('admin_init', array(&$this, 'wcmp_admin_init'));
        // Intialize WCMp Emails
        add_filter('woocommerce_email_classes', array(&$this, 'wcmp_email_classes'));
        // WCMp Update Notice
        add_action('in_plugin_update_message-dc-woocommerce-multi-vendor/dc_product_vendor.php', array(&$this, 'wcmp_plugin_update_message'));

        // Secure commission notes
        add_filter('comments_clauses', array(&$this, 'exclude_order_comments'), 10, 1);
        add_filter('comment_feed_where', array(&$this, 'exclude_order_comments_from_feed_where'));
    }

    public function exclude_order_comments($clauses) {
        $clauses['where'] .= ( $clauses['where'] ? ' AND ' : '' ) . " comment_type != 'commission_note' ";
        return $clauses;
    }

    public function exclude_order_comments_from_feed_where($where) {
        return $where . ( $where ? ' AND ' : '' ) . " comment_type != 'commission_note' ";
    }

    /**
     * Initialize plugin on WP init
     */
    function init() {
        if (is_user_wcmp_pending_vendor(get_current_vendor_id()) || is_user_wcmp_rejected_vendor(get_current_vendor_id()) || is_user_wcmp_vendor(get_current_vendor_id())) {
            show_admin_bar(apply_filters('wcmp_show_admin_bar', false));
        }
        // Init Text Domain
        $this->load_plugin_textdomain();
        // Init library
        $this->load_class('library');
        $this->library = new WCMp_Library();

        $this->wcmp_frontend_fields = $this->library->load_wcmp_frontend_fields();

        //Init endpoints
        $this->load_class('endpoints');
        $this->endpoints = new WCMp_Endpoints();
        // Init custom capabilities
        $this->init_custom_capabilities();

        // Init product vendor custom post types
        $this->init_custom_post();

        $this->load_class('payment-gateways');
        $this->payment_gateway = new WCMp_Payment_Gateways();

        $this->load_class('seller-review-rating');
        $this->review_rating = new WCMp_Seller_Review_Rating();
        // Init ajax
        if (defined('DOING_AJAX')) {
            $this->load_class('ajax');
            $this->ajax = new WCMp_Ajax();
        }
        // Init main admin action class 
        if (is_admin()) {
            $this->load_class('admin');
            $this->admin = new WCMp_Admin();
        }
        if (!is_admin() || defined('DOING_AJAX')) {
            // Init main frontend action class
            $this->load_class('frontend');
            $this->frontend = new WCMp_Frontend();
            // Init shortcode
            $this->load_class('shortcode');
            $this->shortcode = new WCMp_Shortcode();
            //Vendor Dashboard Hooks
            $this->load_class('vendor-hooks');
            $this->vendor_hooks = new WCMp_Vendor_Hooks();
        }
        // Init templates
        $this->load_class('template');
        $this->template = new WCMp_Template();
        add_filter('template_include', array($this, 'template_loader'), 15);
        // Init vendor action class
        $this->load_class('vendor-details');
        // Init Calculate commission class
        $this->load_class('calculate-commission');
        $this->commission = new WCMp_Calculate_Commission();
        // Init product vendor taxonomies
        $this->init_taxonomy();
        // Init product action class 
        $this->load_class('product');
        $this->product = new WCMp_Product();
        // Init Product QNA
        $this->load_class('product-qna');
        $this->product_qna = new WCMp_Product_QNA();
        // Init email activity action class 
        $this->load_class('email');
        $this->email = new WCMp_Email();
        // WCMp Fields Lib
        $this->wcmp_wp_fields = $this->library->load_wp_fields();
        // Load Jquery style
        $this->library->load_jquery_style_lib();
        // Init user roles
        $this->init_user_roles();

        // Init custom reports
        $this->init_custom_reports();

        // Init vendor dashboard
        $this->init_vendor_dashboard();
        // Init vendor coupon
        $this->init_vendor_coupon();

        if (!wp_next_scheduled('migrate_multivendor_table') && !get_option('multivendor_table_migrated', false)) {
            wp_schedule_event(time(), 'hourly', 'migrate_multivendor_table');
        }
        do_action('wcmp_init');
    }

    /**
     * plugin admin init callback
     */
    function wcmp_admin_init() {
        $previous_plugin_version = get_option('dc_product_vendor_plugin_db_version');
        /* Migrate WCMp data */
        do_wcmp_data_migrate($previous_plugin_version, $this->version);
    }

    /**
     * Load vendor shop page template
     * @param type $template
     * @return type
     */
    function template_loader($template) {
        global $WCMp;
        if (is_tax($WCMp->taxonomy->taxonomy_name)) {
            $template = $this->template->locate_template('taxonomy-dc_vendor_shop.php');
        }
        return $template;
    }

    /**
     * Load Localisation files.
     *
     * Note: the first-loaded translation file overrides any following ones if the same translation is present
     *
     * @access public
     * @return void
     */
    public function load_plugin_textdomain() {
        $locale = is_admin() && function_exists('get_user_locale') ? get_user_locale() : get_locale();
        $locale = apply_filters('plugin_locale', $locale, 'dc-woocommerce-multi-vendor');
        load_textdomain('dc-woocommerce-multi-vendor', WP_LANG_DIR . '/dc-woocommerce-multi-vendor/dc-woocommerce-multi-vendor-' . $locale . '.mo');
        load_plugin_textdomain('dc-woocommerce-multi-vendor', false, plugin_basename(dirname(dirname(__FILE__))) . '/languages');
    }

    /**
     * Helper method to load other class
     * @param type $class_name
     */
    public function load_class($class_name = '') {
        if ('' != $class_name && '' != $this->token) {
            require_once ( 'class-' . esc_attr($this->token) . '-' . esc_attr($class_name) . '.php' );
        }
    }

    /**
     * Sets a constant preventing some caching plugins from caching a page. Used on dynamic pages
     *
     * @access public
     * @return void
     */
    function nocache() {
        if (!defined('DONOTCACHEPAGE')) {
            // WP Super Cache constant
            define("DONOTCACHEPAGE", "true");
        }
    }

    /**
     * Get Ajax URL.
     *
     * @return string
     */
    public function ajax_url() {
        return admin_url('admin-ajax.php', 'relative');
    }

    /**
     * Init WCMp User and define users roles
     *
     * @access public
     * @return void
     */
    function init_user_roles() {
        $this->load_class('user');
        $this->user = new WCMp_User();
    }

    /**
     * Init WCMp product vendor taxonomy.
     *
     * @access public
     * @return void
     */
    function init_taxonomy() {
        $this->load_class('taxonomy');
        $this->taxonomy = new WCMp_Taxonomy();
        register_activation_hook(__FILE__, 'flush_rewrite_rules');
    }

    /**
     * Init WCMp product vendor post type.
     *
     * @access public
     * @return void
     */
    function init_custom_post() {
        /* Commission post type */
        $this->load_class('post-commission');

        new WCMp_Commission();
        /* transaction post type */
        $this->load_class('post-transaction');
        $this->transaction = new WCMp_Transaction();
        /* WCMp notice post type */
        $this->load_class('post-notices');
        new WCMp_Notices();
        /* University post type */
        $this->load_class('post-university');
        new WCMp_University();
        /* Vendor registration data post type */
        $this->load_class('post-vendorapplication');
        new WCMp_Vendor_Application();
        /* Flush wp rewrite rule and update permalink structure */
        register_activation_hook(__FILE__, 'flush_rewrite_rules');
    }

    /**
     * Init WCMp vendor reports.
     *
     * @access public
     * @return void
     */
    function init_custom_reports() {
        // Init custom report
        $this->load_class('report');
        new WCMp_Report();
    }

    /**
     * Init WCMp vendor widgets.
     *
     * @access public
     * @return void
     */
    function init_custom_widgets() {
        $this->load_class('widget-init');
        new WCMp_Widget_Init();
    }

    /**
     * Init WCMp vendor capabilities.
     *
     * @access public
     * @return void
     */
    function init_custom_capabilities() {
        $this->load_class('capabilities');
        $this->vendor_caps = new WCMp_Capabilities();
    }

    /**
     * Init WCMp Dashboard Function
     *
     * @access public
     * @return void
     */
    function init_vendor_dashboard() {
        $this->load_class('vendor-dashboard');
        $this->vendor_dashboard = new WCMp_Admin_Dashboard();
    }

    /**
     * Init Cron Job
     * 
     * @access public
     * @return void
     */
    function init_cron_job() {
        add_filter('cron_schedules', array($this, 'add_wcmp_corn_schedule'));
        $this->load_class('cron-job');
        $this->cron_job = new WCMp_Cron_Job();
    }

    private function init_payment_gateway() {
        $this->load_class('payment-gateway');
    }

    /**
     * Init Vendor Coupon
     *
     * @access public
     * @return void
     */
    function init_vendor_coupon() {
        $this->load_class('coupon');
        $this->coupon = new WCMp_Coupon();
    }

    /**
     * Add WCMp weekly and monthly corn schedule
     *
     * @access public
     * @param schedules array
     * @return schedules array
     */
    function add_wcmp_corn_schedule($schedules) {
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display' => __('Every 7 Days', $this->text_domain)
        );
        $schedules['monthly'] = array(
            'interval' => 2592000,
            'display' => __('Every 1 Month', $this->text_domain)
        );
        $schedules['fortnightly'] = array(
            'interval' => 1296000,
            'display' => __('Every 15 Days', $this->text_domain)
        );
        return $schedules;
    }

    /**
     * Register WCMp emails class
     *
     * @access public
     * @return array
     */
    function wcmp_email_classes($emails) {
        include( 'emails/class-wcmp-email-vendor-new-account.php' );
        include( 'emails/class-wcmp-email-admin-new-vendor-account.php' );
        include( 'emails/class-wcmp-email-approved-vendor-new-account.php' );
        include( 'emails/class-wcmp-email-rejected-vendor-new-account.php' );
        include( 'emails/class-wcmp-email-vendor-new-order.php' );
        include( 'emails/class-wcmp-email-vendor-notify-shipped.php' );
        include( 'emails/class-wcmp-email-vendor-new-product-added.php' );
        include( 'emails/class-wcmp-email-admin-added-new-product-to-vendor.php' );
        include( 'emails/class-wcmp-email-vendor-new-commission-transaction.php' );
        include( 'emails/class-wcmp-email-vendor-direct-bank.php' );
        include( 'emails/class-wcmp-email-admin-withdrawal-request.php' );
        include( 'emails/class-wcmp-email-vendor-orders-stats-report.php' );

        $emails['WC_Email_Vendor_New_Account'] = new WC_Email_Vendor_New_Account();
        $emails['WC_Email_Admin_New_Vendor_Account'] = new WC_Email_Admin_New_Vendor_Account();
        $emails['WC_Email_Approved_New_Vendor_Account'] = new WC_Email_Approved_New_Vendor_Account();
        $emails['WC_Email_Rejected_New_Vendor_Account'] = new WC_Email_Rejected_New_Vendor_Account();
        $emails['WC_Email_Vendor_New_Order'] = new WC_Email_Vendor_New_Order();
        $emails['WC_Email_Notify_Shipped'] = new WC_Email_Notify_Shipped();
        $emails['WC_Email_Vendor_New_Product_Added'] = new WC_Email_Vendor_New_Product_Added();
        $emails['WC_Email_Admin_Added_New_Product_to_Vendor'] = new WC_Email_Admin_Added_New_Product_to_Vendor();
        $emails['WC_Email_Vendor_Commission_Transactions'] = new WC_Email_Vendor_Commission_Transactions();
        $emails['WC_Email_Vendor_Direct_Bank'] = new WC_Email_Vendor_Direct_Bank();
        $emails['WC_Email_Admin_Widthdrawal_Request'] = new WC_Email_Admin_Widthdrawal_Request();
        $emails['WC_Email_Vendor_Orders_Stats_Report'] = new WC_Email_Vendor_Orders_Stats_Report();

        return $emails;
    }

    /**
     * Return data for script handles.
     * @since  3.0.6 
     * @param  string $handle
     * @return array|bool
     */
    public function wcmp_get_script_data($handle) {
        global $WCMp;

        switch ($handle) {
            case 'frontend_js' :
                $params = array(
                    'ajax_url' => $this->ajax_url(),
                    'messages' => array('confirm_dlt_pro' => __("Are you sure and want to delete this Product?\nYou can't undo this action ...", 'dc-woocommerce-multi-vendor')),
                );
                break;
            
            case 'product_manager_js' :
                $params = array(
                    'ajax_url' => $this->ajax_url(),
                    'messages' => get_frontend_product_manager_messages(),
                );
                break;

            case 'coupon_manager_js' :
                $params = array(
                    'ajax_url' => $this->ajax_url(),
                    'messages' => get_frontend_coupon_manager_messages(),
                );
                break;
            
            case 'wcmp_frontend_vdashboard_js' :
            case 'wcmp_single_product_multiple_vendors' :
            case 'wcmp_customer_qna_js' :
            case 'wcmp_new_vandor_announcements_js' :
                $params = array(
                    'ajax_url' => $this->ajax_url(),
                );
                break;
            
            case 'wcmp_seller_review_rating_js' :
                $params = array(
                    'ajax_url' => $this->ajax_url(),
                    'messages' => array(
                        'rating_error_msg_txt' => __('Please rate the vendor', 'dc-woocommerce-multi-vendor'),
                        'review_error_msg_txt' => __('Please review your vendor and minimum 10 Character required', 'dc-woocommerce-multi-vendor'),
                        'review_success_msg_txt' => __('Your review submitted successfully', 'dc-woocommerce-multi-vendor'),
                        'review_failed_msg_txt' => __('Error in system please try again later', 'dc-woocommerce-multi-vendor'),
                    ),
                );
                break;

            default:
                $params = false;
        }

        return apply_filters('wcmp_get_script_data', $params, $handle);
    }

    /**
     * Localize a WCMp script once.
     * @since  3.0.6 
     * @param  string $handle
     */
    public function localize_script($handle) {
        if ( $data = $this->wcmp_get_script_data($handle) ) {
            $name = str_replace('-', '_', $handle) . '_script_data';
            wp_localize_script($handle, $name, apply_filters($name, $data));
        }
    }

    /**
     * Show plugin changes. Code adapted from W3 Total Cache and Woocommerce.
     */
    public static function wcmp_plugin_update_message($args) {
        $transient_name = 'wcmp_upgrade_notice_' . $args['Version'];
        if (false === ( $upgrade_notice = get_transient($transient_name) )) {
            $response = wp_safe_remote_get('https://plugins.svn.wordpress.org/dc-woocommerce-multi-vendor/trunk/readme.txt');
            if (!is_wp_error($response) && !empty($response['body'])) {
                $upgrade_notice = self::parse_update_notice($response['body'], $args['new_version']);
                set_transient($transient_name, $upgrade_notice, DAY_IN_SECONDS);
            }
        }
        echo '<style type="text/css">.wcmp_plugin_upgrade_notice{background-color:#ec4e2a;padding:10px;color:#fff;}.wcmp_plugin_upgrade_notice:before{content: "\f534";padding-right:5px;}</style>';
        echo wp_kses_post($upgrade_notice);
    }

    /**
     * Parse update notice from readme file.
     * Code adapted from W3 Total Cache and Woocommerce
     * 
     * @param  string $content
     * @param  string $new_version
     * @return string
     */
    private static function parse_update_notice($content, $new_version) {
        // Output Upgrade Notice.
        $matches = null;
        $regexp = '~==\s*Upgrade Notice\s*==\s*=\s*(.*)\s*=(.*)(=\s*' . preg_quote(WCMp_PLUGIN_VERSION) . '\s*=|$)~Uis';
        $upgrade_notice = '';

        if (preg_match($regexp, $content, $matches)) {
            $notices = (array) preg_split('~[\r\n]+~', trim($matches[2]));

            // Convert the full version strings to minor versions.
            $notice_version_parts = explode('.', trim($matches[1]));
            $current_version_parts = explode('.', WCMp_PLUGIN_VERSION);

            if (3 !== sizeof($notice_version_parts)) {
                return;
            }

            $notice_version = $notice_version_parts[0] . '.' . $notice_version_parts[1];
            $current_version = $current_version_parts[0] . '.' . $current_version_parts[1];

            // Check the latest stable version and ignore trunk.
            if (version_compare($current_version, $notice_version, '<')) {

                $upgrade_notice .= '<div class="wcmp_plugin_upgrade_notice dashicons-before">';

                foreach ($notices as $index => $line) {
                    $upgrade_notice .= preg_replace('~\[([^\]]*)\]\(([^\)]*)\)~', '<a href="${2}">${1}</a>', $line);
                }

                $upgrade_notice .= '</div> ';
            }
        }

        return wp_kses_post($upgrade_notice);
    }

}
