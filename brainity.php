<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/*
Plugin Name: Brainity: Prospecting and Retargeting Ads on Autopilot
Description: Brainity automatically launches Social Ads campaigns to finds potential customers, brings them to your store and retargets those who left without buying.
Plugin URI: https://wordpress.org/plugins/brainity-boost-ads
Author: Brainity
Author URI: https://www.brainity.co
Version: 1.0.14
*/

//Plugin Base

define('WPBRAINITY_BASE', plugin_basename(__FILE__));
//Plugin PAtH
//Plugin URL
define('WPBRAINITY_URL', plugin_dir_url(__FILE__));
//Plugin assets URL
define('WPBRAINITY_ASSETS_URL', WPBRAINITY_URL . 'assets/');
//Plugin
define('WPBRAINITY_PLUGIN', 'wp-brainity');
//URL API
define('WPBRAINITY_API_URL', 'https://app.brainity.co');
//URL APP
define('WPBRAINITY_APP_URL', 'https://app.brainity.co');

global $brainity_db_version;
$brainity_db_version = '1.0';

require_once('pixel/FacebookPixel.php');
require_once('pixel/FacebookWordpressPixelInjection.php');
require_once('productfeed/FacebookProductFeed.php');
require_once('productfeed/FacebookProduct.php');
require_once('productfeed/FacebookUtils.php');

class brainity
{
    function __construct()
    {
        global $xml_has_some_error;
        $xml_has_some_error = false;
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_activation_hook(__FILE__, array(__CLASS__, 'db_install'));
        register_activation_hook(__FILE__, array(__CLASS__, 'db_install_data'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
        register_uninstall_hook(__FILE__, array(__CLASS__, 'uninstall'));

        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_scripts'));
        // Register WordPress pixel injection controlling where to fire pixel
        add_action('init', array(__CLASS__, 'registerPixelInjection'), 0);
        add_action('wp_brainity_feed_update', array(__CLASS__, 'wp_brainity_feed_update'));
        add_action('wp_brainity_pixel_update', array(__CLASS__, 'wp_brainity_pixel_update'));

        if (is_admin()) {
            add_action('wp_ajax_brainity_ajax_update', array(__CLASS__, 'ajax_update'));
            add_action('wp_ajax_brainity_ajax_get_host', array(__CLASS__, 'ajax_get_host'));
        }

        add_action('rest_api_init', function () {
            register_rest_route('brainity/v1', '/product-feed', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'get_product_feed'),
            ));
        });

        add_action('rest_api_init', function () {
            register_rest_route('brainity/v1', '/settings', array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'get_settings'),
            ));

            register_rest_route('brainity/v1', '/settings', array(
                'methods' => 'POST',
                'callback' => array(__CLASS__, 'set_settings'),
            ));
        });
    }

    public static function get_product_feed($data)
    {
        header('Content-type: text/csv');

        $token = $data->get_param('token');

        if (empty($token)) {
            return new WP_REST_Response('Not found', 404);
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");

        if (count($config) === 0) {
            return new WP_REST_Response('Not found', 404);
        }

        if ($config[0]->token !== $token) {
            return new WP_REST_Response('Not found', 404);
        }

        $facebookProductFeed = new FacebookProductFeed('', '');
        $csv = $facebookProductFeed->generate_productfeed_csv();

        echo($csv);
        exit;
    }

    public static function get_settings($data)
    {
        header('Content-type: application/json');

        $token = $data->get_param('token');

        if (empty($token)) {
            return new WP_REST_Response('Not found', 404);
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");

        if ($config[0]->token !== $token) {
            return new WP_REST_Response('Not found', 404);
        }

        if (count($config) === 0) {
            return new WP_REST_Response('Not found', 404);
        }

        if ($config[0]->token !== $token) {
            return new WP_REST_Response('Not found', 404);
        }

        $config[0]->facebook_for_woocomerce = get_option(
            'facebook_config',
            array(
                'pixel_id' => '0'
            )
        );

        echo(json_encode($config[0]));
        exit;
    }

    public static function set_settings($data)
    {
        header('Content-type: application/json');

        $token = $data->get_param('token');

        if (empty($token)) {
            return new WP_REST_Response('Not found', 404);
        }

        $pixel = $data->get_param('pixel_id');

        $setPixelFacebookForWoocomer = $data->get_param('set_facebook_for_woocomerce');
        if (!empty($setPixelFacebookForWoocomer) && $setPixelFacebookForWoocomer) {
            $pixelFacebookForWoocomer = $data->get_param('facebook_for_woocomerce');

            $options = get_option(
                'facebook_config',
                array(
                    'pixel_id' => '0'
                )
            );

            $options['pixel_id'] = $pixelFacebookForWoocomer;
            update_option('facebook_config', $options);
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");
        if ($config[0]->token !== $token) {
            return new WP_REST_Response('Not found', 404);
        }

        if (empty($pixel)) {
            $wpdb->query(
                $wpdb->prepare("UPDATE $table_name SET pixel_id = NULL")
            );
        } else {
            $wpdb->query(
                $wpdb->prepare("UPDATE $table_name SET pixel_id = %s", $pixel)
            );
        }

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");
        if (count($config) === 0) {
            return new WP_REST_Response('Not found', 404);
        }

        if ($config[0]->token !== $token) {
            return new WP_REST_Response('Not found', 404);
        }

        $config[0]->facebook_for_woocomerce = get_option(
            'facebook_config',
            array(
                'pixel_id' => '0'
            )
        );
        echo(json_encode($config[0]));
        exit;
    }

    static function registerPixelInjection()
    {
        return new FacebookWordpressPixelInjection();
    }

    static function db_install()
    {
        global $wpdb;
        global $brainity_db_version;

        $table_name = $wpdb->prefix . 'brainity';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		token varchar(500) DEFAULT '' NOT NULL,
		refresh_token varchar(500) DEFAULT '' NOT NULL,
		feed_token varchar(500) DEFAULT '' NOT NULL,
		pixel_id varchar(255) DEFAULT '' NOT NULL,
		is_logged_in tinyint(1) NOT NULL DEFAULT '0',
		is_configured tinyint(1) NOT NULL DEFAULT '0',
		
		PRIMARY KEY  (id)
	) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        add_option('brainity_db_version', $brainity_db_version);
    }

    static function db_install_data()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");

        if (count($config) === 0) {
            $wpdb->insert(
                $table_name,
                array(
                    'token' => '',
                    'feed_token' => '',
                    'refresh_token' => '',
                    'pixel_id' => '',
                    'is_configured' => 0
                )
            );
        }
    }

    static function admin_init()
    {
        global $wpwoof_values, $wpwoof_add_button, $wpwoof_add_tab, $wpwoof_message, $wpwoofeed_oldname;
        $wpwoof_values = array();
        $wpwoof_add_button = 'Generate the Feed';
        $wpwoof_add_tab = 'Add New Feed';
        $wpwoof_message = '';
        $wpwoofeed_oldname = '';

    }

    static function admin_menu()
    {
        add_menu_page('Brainity', 'Brainity', 'manage_options', 'brainity-settings', array(__CLASS__, 'menu_page_callback'), WPBRAINITY_URL . '/assets/img/favicon.png');
        add_submenu_page(
            null,
            'Brainity',
            'Brainity',
            'manage_options',
            'brainity-go-to-manager',
            array(__CLASS__, 'go_to_manager')
        );
    }

    static function menu_page_callback()
    {
        require_once('view/admin/settings.php');
    }

    static function go_to_manager()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name");

        $sso = brainity::jwt_post('generate-sso', $config[0]->token, []);

        self::wp_brainity_pixel_update();

        header('Location: ' . WPBRAINITY_APP_URL . '/login/auth-sso?sso_token=' . $sso->sso_token);
    }

    static function admin_enqueue_scripts()
    {
        global $brainity_db_version;

        $page = sanitize_text_field($_GET['page']);

        if ($page === 'brainity-settings') {
            //Admin Style
            wp_enqueue_style(WPBRAINITY_PLUGIN . '-style', WPBRAINITY_ASSETS_URL . 'css/app.css', array(), $brainity_db_version, false);
            //Admin Javascript
            wp_enqueue_script(WPBRAINITY_PLUGIN . '-script', WPBRAINITY_ASSETS_URL . 'js/admin.js', array('jquery'), $brainity_db_version, false);

            wp_localize_script(WPBRAINITY_PLUGIN . '-script', 'WPWOOF', array('ajaxurl' => admin_url('admin-ajax.php'), 'loading' => admin_url('images/loading.gif')));
        }
    }

    static function ajax_get_host()
    {
        echo json_encode(['success' => true, 'data' => WPBRAINITY_API_URL]);
        wp_die();
    }

    static function ajax_update()
    {
        header('Content-Type: application/json');

        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");
        $token = sanitize_text_field($_POST['jwt_token']);

        if (empty($token)) {
            echo json_encode(['success' => false]);
            wp_die();
        }

        if (count($config) >= 0) {
            $data = [
                'token' => $token,
                'is_logged_in' => 1
            ];
            $where = array('id' => $config[0]->id);

            $updated = $wpdb->update($table_name, $data, $where, array('%s'));

            if (false === $updated) {
                echo json_encode(['success' => false]);
            } else {
                // No error. You can check updated to see how many rows were changed.
                // Send shop config to data to Brainity API
                $response = brainity::jwt_post('shops', $token, [
                    'domain' => get_option('siteurl'),
                    'type' => 'woocommerce',
                    'shop_name' => woocommerce_page_title(false),
                    'product_feed_url' => sprintf(
                        '%s?rest_route=/brainity/v1/product-feed&token=%s',
                        get_site_url(),
                        $token
                    )
                ]);
                echo json_encode(['success' => true, 'data' => []]);
            }
        } else {
            echo json_encode(['success' => false]);
        }

        wp_die();
    }

    static function wp_brainity_pixel_update()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");

        if (count($config) === 0) {
            return;
        }

        if (!empty($config[0]->pixel_id)) {
            wp_clear_scheduled_hook('wp_brainity_pixel_update');
            return;
        }

        $response = brainity::jwt_get('get-pixel', $config[0]->token);

        $wpdb->query(
            $wpdb->prepare("UPDATE $table_name SET pixel_id = %s", $response->pixel_id)
        );
    }

    static function jwt_post($path, $token, $post)
    {
        $response = wp_remote_post(WPBRAINITY_API_URL . '/external-api/' . $path, [
            'body' => json_encode($post),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ],
            'sslverify' => false
        ]);

        return json_decode($response['body']);
    }

    static function jwt_get($path, $token)
    {
        $response = wp_remote_get(WPBRAINITY_API_URL . '/external-api/' . $path, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ],
            'sslverify' => false
        ]);

        return json_decode($response['body']);
    }

    static function activate()
    {
        wp_schedule_event(time(), 'hourly', 'wp_brainity_pixel_update');
    }

    static function deactivate()
    {
        wp_clear_scheduled_hook('wp_brainity_pixel_update');
    }

    static function deactivate_generate_error($error_message, $deactivate = true, $echo_error = false)
    {
        if ($deactivate) {
            deactivate_plugins(array(__FILE__));
        }
        $message = "<div class='notice notice-error is-dismissible'>
            <p>" . $error_message . "</p>
        </div>";
        if ($echo_error) {
            echo $message;
        } else {
            add_action('admin_notices', create_function('', 'echo "' . $message . '";'), 9999);
        }
    }

    static function uninstall()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM $table_name");

        if (count($config) !== 0 && !empty($config[0]->token)) {
            brainity::jwt_get('uninstall', $config[0]->token);
        }

        $wpdb->query("DROP TABLE IF EXISTS $table_name ;");
    }
}

new brainity;