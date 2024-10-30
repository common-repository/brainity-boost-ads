<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class FacebookWordpressPixelInjection
{
    public static $renderCache = array();
    const FB_PRIORITY_HIGH = 2;
    const FB_PRIORITY_LOW = 11;
    const FB_RETAILER_ID_PREFIX = 'wc_post_id_';
    public $isviewcontentRender = 0;

    public function __construct()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'brainity';

        $config = $wpdb->get_results("SELECT * FROM  $table_name ");

        $pixel_id = $config[0]->pixel_id;
        FacebookPixel::setPixelId($pixel_id);

        add_action(
            'wp_head',
            array($this, 'injectPixelCode'));
        add_action(
            'wp_head',
            array($this, 'injectPixelNoscriptCode'));
        add_action('woocommerce_after_single_product',
            array($this, 'inject_view_content_event'), self::FB_PRIORITY_HIGH);
        add_action('woocommerce_after_shop_loop',
            array($this, 'inject_view_category_event'));
        /*add_action('pre_get_posts',
            array($this, 'inject_search_event'));*/

        // AddToCart events
        add_action( 'woocommerce_add_to_cart', [ $this, 'inject_add_to_cart_event' ], 40, 4 );
        // AddToCart while AJAX is enabled
        add_action( 'woocommerce_ajax_added_to_cart', [ $this, 'add_filter_for_add_to_cart_fragments' ] );
        // AddToCart while using redirect to cart page
        if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
            add_filter( 'woocommerce_add_to_cart_redirect', [ $this, 'set_last_product_added_to_cart_upon_redirect' ], 10, 2 );
            add_action( 'woocommerce_ajax_added_to_cart',   [ $this, 'set_last_product_added_to_cart_upon_ajax_redirect' ] );
            add_action( 'woocommerce_after_cart',           [ $this, 'inject_add_to_cart_redirect_event' ], 10, 2 );
        }

        add_action('woocommerce_after_checkout_form',
            array($this, 'inject_initiate_checkout_event'));
        add_action('woocommerce_thankyou',
            array($this, 'inject_gateway_purchase_event'), self::FB_PRIORITY_HIGH);
        add_action('woocommerce_payment_complete',
            array($this, 'inject_purchase_event'), self::FB_PRIORITY_HIGH);

    }

    public function injectPixelCode()
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }

        self::$renderCache[0] = true;
        echo(FacebookPixel::getPixelBaseCode());
        echo(FacebookPixel::getPixelInitCode());
        echo(FacebookPixel::getPixelPageViewCode());
    }

    public function injectPixelNoscriptCode()
    {
        if (            
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }
        echo(FacebookPixel::getPixelNoscriptCode());
    }

    /**
     * Triggers ViewContent product pages
     */
    public function inject_view_content_event()
    {
        
        global $post;
        if ( empty(FacebookPixel::getPixelId() || !isset($post->ID)) ) {
            return;
        }
        $product = wc_get_product( $post->ID );
        $content_type = 'product_group';
        if ( ! $product ) {
            return;
        }

        // if product is variable or grouped, fire the pixel with content_type: product_group
        if ( $product->is_type( [ 'variable', 'grouped' ] ) ) {
            $content_type = 'product_group';
        } else {
            $content_type = 'product';
        }

        $content_ids = self::get_fb_content_ids($product);
        $this->isviewcontentRender = wp_json_encode($content_ids);
        FacebookPixel::inject_event( 'ViewContent', [
            'content_name' => $product->get_title(),
            'content_ids'  => $this->isviewcontentRender,
            'content_type' => $content_type,
            'value'        => $product->get_price(),
            'currency'     => get_woocommerce_currency(),
        ] );

    }

    /**
     * Triggers ViewCategory for product category listings
     */
    public function inject_view_category_event()
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }
        global $wp_query;

        $products = array_values(array_map(function ($item) {
            return wc_get_product($item->ID);
        },
            $wp_query->posts));

        // if any product is a variant, fire the pixel with
        // content_type: product_group
        $content_type = 'product';
        $product_ids = array();
        foreach ($products as $product) {
            if (!$product) {
                continue;
            }
            $product_ids = array_merge(
                $product_ids,
                self::get_fb_content_ids($product));
            if (self::is_variable_type($product->get_type())) {
                $content_type = 'product_group';
            }
        }

        $categories =
            self::get_product_categories(get_the_ID());

        FacebookPixel::inject_event(
            'ViewCategory',
            array(
                'content_name' => $categories['name'],
                'content_category' => $categories['categories'],
                'content_ids' => json_encode(array_slice($product_ids, 0, 10)),
                'content_type' => $content_type
            ),
            'trackCustom');
    }

    /**
     * Trigger AddToCart for cart page and woocommerce_after_cart hook.
     * When set 'redirect to cart', ajax call for button click and
     * woocommerce_add_to_cart will be skipped.
     */
    public function inject_add_to_cart_redirect_event()
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }

        $last_product_id = WC()->session->get( 'facebook_for_woocommerce_last_product_added_to_cart', 0 );

        $redirect_checked = get_option('woocommerce_cart_redirect_after_add', 'no');
        if ($redirect_checked == 'yes' && $last_product_id > 0) {
            $this->inject_add_to_cart_event();
            WC()->session->set( 'facebook_for_woocommerce_last_product_added_to_cart', 0 );
        }
    }

    /**
     * Triggers AddToCart for cart page and add_to_cart button clicks
     */
    public function inject_add_to_cart_event()
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }
        $product_ids = $this->get_content_ids_from_cart(WC()->cart->get_cart());

        FacebookPixel::inject_event(
            'AddToCart',
            array(
                'content_ids' => json_encode($product_ids),
                'content_type' => 'product',
                'value' => WC()->cart->total,
                'currency' => get_woocommerce_currency()
            ));
    }

    /**
     * Setups a filter to add an add to cart fragment whenever a product is added to the cart through Ajax.
     *
     * @see \WC_Facebookcommerce_EventsTracker::add_add_to_cart_event_fragment
     *
     * @internal
     *
     * @since 1.10.2
     */
    public function add_filter_for_add_to_cart_fragments() {

        if ( 'no' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
            add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'add_add_to_cart_event_fragment' ] );
        }
    }


    /**
     * Adds an add to cart fragment to trigger an AddToCart event.
     *
     * @internal
     *
     * @since 1.10.2
     *
     * @param array $fragments add to cart fragments
     * @return array
     */
    public function add_add_to_cart_event_fragment( $fragments ) {

        if ( !empty(FacebookPixel::getPixelId()) ) {

            /*$product_ids = $this->get_content_ids_from_cart(WC()->cart->get_cart());

            FacebookPixel::inject_event(
                'AddToCart',
                array(
                    'content_ids' => json_encode($product_ids),
                    'content_type' => 'product',
                    'value' => WC()->cart->total,
                    'currency' => get_woocommerce_currency()
                ));*/
            
        }

        return $fragments;
    }

    /**
     * Sets last product added to cart to session when adding a product to cart from an archive page and both AJAX adding and redirection to cart are enabled.
     *
     * @internal
     *
     * @since 1.10.2
     *
     * @param null|int $product_id the ID of the product just added to the cart
     */
    public function set_last_product_added_to_cart_upon_ajax_redirect( $product_id = null ) {
        if ( ! $product_id ) {
            return;
        }
        $product = wc_get_product( $product_id );
        if ( $product instanceof \WC_Product ) {
            WC()->session->set( 'facebook_for_woocommerce_last_product_added_to_cart', $product->get_id() );
        }
    }

    /**
     * Sets last product added to cart to session when adding to cart a product and redirection to cart is enabled.
     *
     * @internal
     *
     * @since 1.10.2
     *
     * @param string $redirect URL redirecting to (usually cart)
     * @param null|\WC_Product $product the product just added to the cart
     * @return string
     */
    public function set_last_product_added_to_cart_upon_redirect( $redirect, $product = null ) {

        if ( $product instanceof \WC_Product ) {
            WC()->session->set( 'facebook_for_woocommerce_last_product_added_to_cart', $product->get_id() );
        } else {
            
        }

        return $redirect;
    }

    /**
     * Triggers Purchase for thank you page for COD, BACS CHEQUE payment
     * which won't invoke woocommerce_payment_complete.
     */
    public function inject_gateway_purchase_event($order_id)
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }
        if (FacebookPixel::check_last_event('Purchase')) {
            return;
        }

        $order = new WC_Order($order_id);
        $payment = $order->get_payment_method();
        $this->inject_purchase_event($order_id);
        $this->inject_subscribe_event($order_id);
    }

    /**
     * Triggers Purchase for payment transaction complete and for the thank you
     * page in cases of delayed payment.
     */
    public function inject_purchase_event($order_id)
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }
        if (FacebookPixel::check_last_event('Purchase')) {
            return;
        }

        $this->inject_subscribe_event($order_id);

        $order = new WC_Order($order_id);
        $content_type = 'product';
        $product_ids = array();
        foreach ($order->get_items() as $item) {
            $product = wc_get_product($item['product_id']);
            $product_ids = array_merge(
                $product_ids,
                self::get_fb_content_ids($product));
            if (self::is_variable_type($product->get_type())) {
                $content_type = 'product_group';
            }
        }

        FacebookPixel::inject_event(
            'Purchase',
            array(
                'content_ids' => json_encode($product_ids),
                'content_type' => $content_type,
                'value' => $order->get_total(),
                'currency' => get_woocommerce_currency()
            ));
    }

    /**
     * Triggers InitiateCheckout for checkout page
     */
    public function inject_initiate_checkout_event()
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }
        if (FacebookPixel::check_last_event('InitiateCheckout')) {
            return;
        }

        $product_ids = $this->get_content_ids_from_cart(WC()->cart->get_cart());

        FacebookPixel::inject_event(
            'InitiateCheckout',
            array(
                'num_items' => WC()->cart->get_cart_contents_count(),
                'content_ids' => json_encode($product_ids),
                'content_type' => 'product',
                'value' => WC()->cart->total,
                'currency' => get_woocommerce_currency()
            ));
    }

    /**
     * Triggers Subscribe for payment transaction complete of purchase with
     * subscription.
     */
    public function inject_subscribe_event($order_id)
    {
        if (
            empty(FacebookPixel::getPixelId())
        ) {
            return;
        }
        if (!function_exists("wcs_get_subscriptions_for_order")) {
            return;
        }

        $subscription_ids = wcs_get_subscriptions_for_order($order_id);
        foreach ($subscription_ids as $subscription_id) {
            $subscription = new WC_Subscription($subscription_id);
            FacebookPixel::inject_event(
                'Subscribe',
                array(
                    'sign_up_fee' => $subscription->get_sign_up_fee(),
                    'value' => $subscription->get_total(),
                    'currency' => get_woocommerce_currency()
                ));
        }
    }

    public static function get_fb_content_ids($woo_product)
    {
        return array(self::get_fb_retailer_id($woo_product));
    }

    public static function is_variation_type($type)
    {
        return $type == 'variation' || $type == 'subscription_variation';
    }

    public static function is_variable_type($type)
    {
        return $type == 'variable' || $type == 'variable-subscription';
    }

    public static function get_fb_retailer_id($woo_product)
    {
        $woo_id = $woo_product->get_id();

        // Call $woo_product->get_id() instead of ->id to account for Variable
        // products, which have their own variant_ids.
        return $woo_product->get_sku() ? $woo_product->get_sku() . '_' .
            $woo_id : self::FB_RETAILER_ID_PREFIX . $woo_id;
    }

    /**
     * Return categories for products/pixel
     *
     * @access public
     * @param String $id
     * @return Array
     */
    public static function get_product_categories($wpid)
    {
        $category_path = wp_get_post_terms(
            $wpid,
            'product_cat',
            array('fields' => 'all'));
        $content_category = array_values(
            array_map(
                function ($item) {
                    return $item->name;
                },
                $category_path));
        $content_category_slice = array_slice($content_category, -1);
        $categories =
            empty($content_category) ? '""' : implode(', ', $content_category);
        return array(
            'name' => array_pop($content_category_slice),
            'categories' => $categories
        );
    }

    /**
     * Helper function to iterate through a cart and gather all content ids
     */
    private function get_content_ids_from_cart($cart)
    {
        $product_ids = array();
        foreach ($cart as $item) {
            $product_ids = array_merge(
                $product_ids,
                self::get_fb_content_ids($item['data']));
        }
        return $product_ids;
    }

}