<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Initial Sync by Facebook feed class
 */
class FacebookProductFeed
{

    const FACEBOOK_CATALOG_FEED_FILENAME = 'fae_product_catalog.csv';
    const FB_ADDITIONAL_IMAGES_FOR_FEED = 5;
    const FEED_NAME = 'Initial product sync from WooCommerce. DO NOT DELETE.';
    const FB_PRODUCT_GROUP_ID = 'fb_product_group_id';
    const FB_VISIBILITY = 'fb_visibility';

    private $has_default_product_count = 0;
    private $no_default_product_count = 0;

    public function __construct(
        $facebook_catalog_id,
        $fbgraph,
        $feed_id = null)
    {
        $this->facebook_catalog_id = $facebook_catalog_id;
        $this->fbgraph = $fbgraph;
        $this->feed_id = $feed_id;
    }

    public function generate_productfeed_csv()
    {
        $this->log_feed_progress('Generating product feed file');
        $post_ids = $this->get_product_wpid();
        $all_parent_product = array_map(function ($post_id) {
            if (get_post_type($post_id) == 'product_variation') {
                return wp_get_post_parent_id($post_id);
            }
        }, $post_ids);
        $all_parent_product = array_filter(array_unique($all_parent_product));
        $wp_ids = array_diff($post_ids, $all_parent_product);

        $csv = $this->get_product_feed_header_row();

        foreach ($wp_ids as $wp_id) {
            $woo_product = new FacebookProduct($wp_id);
            if ($woo_product->is_hidden()) {
                continue;
            }
            if (get_option('woocommerce_hide_out_of_stock_items') === 'yes' &&
                !$woo_product->is_in_stock()) {
                continue;
            }
            $csv .= $this->prepare_product_for_feed(
                $woo_product, $product_group_attribute_variants);
        }

        return $csv;
    }

    public function generate_productfeed_file()
    {
        $this->log_feed_progress('Generating product feed file');
        $post_ids = $this->get_product_wpid();
        $all_parent_product = array_map(function ($post_id) {
            if (get_post_type($post_id) == 'product_variation') {
                return wp_get_post_parent_id($post_id);
            }
        }, $post_ids);
        $all_parent_product = array_filter(array_unique($all_parent_product));
        $product_ids = array_diff($post_ids, $all_parent_product);
        return $this->write_product_feed_file($product_ids);
    }

    public function write_product_feed_file($wp_ids)
    {
        try {
            $feed_file =
                fopen(dirname(__FILE__) . DIRECTORY_SEPARATOR .
                    (self::FACEBOOK_CATALOG_FEED_FILENAME), "w");
            fwrite($feed_file, $this->get_product_feed_header_row());

            $product_group_attribute_variants = array();
            foreach ($wp_ids as $wp_id) {
                $woo_product = new FacebookProduct($wp_id);
                if ($woo_product->is_hidden()) {
                    continue;
                }
                if (get_option('woocommerce_hide_out_of_stock_items') === 'yes' &&
                    !$woo_product->is_in_stock()) {
                    continue;
                }
                $product_data_as_feed_row = $this->prepare_product_for_feed(
                    $woo_product, $product_group_attribute_variants);
                fwrite($feed_file, $product_data_as_feed_row);
            }
            fclose($feed_file);
            wp_reset_postdata();
            return dirname(__FILE__) . DIRECTORY_SEPARATOR .
                (self::FACEBOOK_CATALOG_FEED_FILENAME);
        } catch (Exception $e) {
            //self::log(json_encode($e->getMessage()));
            return false;
        }
    }

    public function get_product_feed_header_row()
    {
        return 'id,title,description,image_link,link,product_type,' .
            'brand,price,availability,item_group_id,checkout_url,' .
            'additional_image_link,sale_price_effective_date,sale_price,condition,' .
            'visibility,default_product,variant' . PHP_EOL;
    }

    /**
     * Assemble product payload in feed upload for initial sync.
     **/
    private function prepare_product_for_feed(
        $woo_product,
        &$attribute_variants)
    {
        $product_data = $woo_product->prepare_product(null, true);
        $item_group_id = $product_data['retailer_id'];
        // prepare variant column for variable products
        $product_data['variant'] = '';
        if (
        FacebookUtils::is_variation_type($woo_product->get_type())
        ) {
            $parent_id = $woo_product->get_parent_id();

            if (!isset($attribute_variants[$parent_id])) {
                $parent_product = new FacebookProduct($parent_id);

                $gallery_urls = array_filter($parent_product->get_gallery_urls());
                $variation_id = $parent_product->find_matching_product_variation();
                $variants_for_group = $parent_product->prepare_variants_for_group(true);
                $parent_attribute_values = array();
                $parent_attribute_values['gallery_urls'] = $gallery_urls;
                $parent_attribute_values['default_variant_id'] = $variation_id;
                $parent_attribute_values['item_group_id'] =
                    FacebookUtils::get_fb_retailer_id($parent_product);
                foreach ($variants_for_group as $variant) {
                    $parent_attribute_values[$variant['product_field']] =
                        $variant['options'];
                }
                // cache product group variants
                $attribute_variants[$parent_id] = $parent_attribute_values;
            }
            $parent_attribute_values = $attribute_variants[$parent_id];
            $variants_for_item =
                $woo_product->prepare_variants_for_item($product_data);
            $variant_feed_column = array();
            foreach ($variants_for_item as $variant_array) {
                static::format_variant_for_feed(
                    $variant_array['product_field'],
                    $variant_array['options'][0],
                    $parent_attribute_values,
                    $variant_feed_column);
            }
            if (isset($product_data['custom_data'])) {
                foreach ($product_data['custom_data'] as $product_field => $value) {
                    static::format_variant_for_feed(
                        $product_field,
                        $value,
                        $parent_attribute_values,
                        $variant_feed_column);
                }
            }
            if (!empty($variant_feed_column)) {
                $product_data['variant'] =
                    "\"" . implode(',', $variant_feed_column) . "\"";
            }
            if (isset($parent_attribute_values['gallery_urls'])) {
                $product_data['additional_image_urls'] =
                    array_merge($product_data['additional_image_urls'],
                        $parent_attribute_values['gallery_urls']);
            }
            if (isset($parent_attribute_values['item_group_id'])) {
                $item_group_id = $parent_attribute_values['item_group_id'];
            }

            $product_data['default_product'] =
                $parent_attribute_values['default_variant_id'] == $woo_product->id
                    ? 'default'
                    : '';

            // If this group has default variant value, log this product item
            if (isset($parent_attribute_values['default_variant_id']) &&
                !empty($parent_attribute_values['default_variant_id'])) {
                $this->has_default_product_count++;
            } else {
                $this->no_default_product_count++;
            }
        }

        // log simple product
        if (!isset($product_data['default_product'])) {
            $this->no_default_product_count++;
            $product_data['default_product'];
        }

        return
            $product_data['retailer_id'] . ',' .
            static::format_string_for_feed($product_data['name']) . ',' .
            static::format_string_for_feed($product_data['description']) . ',' .
            $product_data['image_url'] . ',' .
            $product_data['url'] . ',' .
            static::format_string_for_feed($product_data['category']) . ',' .
            static::format_string_for_feed($product_data['brand']) . ',' .
            static::format_price_for_feed(
                $product_data['price'], $product_data['currency']) . ',' .
            $product_data['availability'] . ',' .
            $item_group_id . ',' .
            $product_data['checkout_url'] . ',' .
            static::format_additional_image_url(
                $product_data['additional_image_urls']) . ',' .
            $product_data['sale_price_start_date'] . '/' .
            $product_data['sale_price_end_date'] . ',' .
            static::format_price_for_feed(
                $product_data['sale_price'], $product_data['currency']) . ',' .
            'new' . ',' .
            $product_data['visibility'] . ',' .
            $product_data['default_product'] . ',' .
            $product_data['variant'] . PHP_EOL;
    }

    private function create_feed()
    {
        $result = $this->fbgraph->create_feed(
            $this->facebook_catalog_id, array('name' => self::FEED_NAME));
        if (is_wp_error($result) || !isset($result['body'])) {
            $this->log_feed_progress(json_encode($result));
            return null;
        }
        $decode_result = FacebookUtils::decode_json($result['body']);
        $feed_id = $decode_result->id;
        if (!$feed_id) {
            $this->log_feed_progress(
                'Response from creating feed not return feed id!');
            return null;
        }
        return $feed_id;
    }

    private function create_upload($facebook_feed_id)
    {
        $result = $this->fbgraph->create_upload(
            $facebook_feed_id, dirname(__FILE__) . DIRECTORY_SEPARATOR .
            (self::FACEBOOK_CATALOG_FEED_FILENAME));
        if (is_null($result) || !isset($result['id']) || !$result['id']) {
            $this->log_feed_progress(json_encode($result));
            return null;
        }
        $upload_id = $result['id'];
        return $upload_id;
    }

    private static function format_additional_image_url($product_image_urls)
    {
        // returns the top 10 additional image urls separated by a comma
        // according to feed api rules
        $product_image_urls = array_slice(
            $product_image_urls,
            0,
            self::FB_ADDITIONAL_IMAGES_FOR_FEED);
        if ($product_image_urls) {
            return "\"" . implode(',', $product_image_urls) . "\"";
        } else {
            return '';
        }
    }

    private static function format_string_for_feed($text)
    {
        if ((bool)$text) {
            return "\"" . str_replace('"', "'", $text) . "\"";
        } else {
            return '';
        }
    }

    private static function format_price_for_feed($value, $currency)
    {
        return (string)(round($value / (float)100, 2)) . $currency;
    }

    private static function format_variant_for_feed(
        $product_field,
        $value,
        $parent_attribute_values,
        &$variant_feed_column)
    {
        if (!array_key_exists($product_field, $parent_attribute_values)) {
            return;
        }
        array_push($variant_feed_column,
            $product_field . ':' .
            implode('/', $parent_attribute_values[$product_field]) . ':' .
            $value);
    }

    public function is_upload_complete(&$settings)
    {
        $result = $this->fbgraph->get_upload_status($settings['fb_upload_id']);
        if (is_wp_error($result) || !isset($result['body'])) {
            $this->log_feed_progress(json_encode($result));
            return 'error';
        }
        $decode_result = self::decode_json($result['body'], true);
        $end_time = $decode_result['end_time'];
        if (isset($end_time)) {
            $settings['upload_end_time'] = $end_time;
            return 'complete';
        } else {
            return 'in progress';
        }
    }

    // Log progress in local log file and FB.
    public function log_feed_progress($msg, $object = array())
    {
        //FacebookUtils::fblog($msg, $object);
        //$msg = empty($object) ? $msg : $msg . json_encode($object);
        //FacebookUtils::log($msg);
    }

    public function get_product_wpid()
    {
        $post_ids = self::get_wp_posts(
            null,
            null,
            array('product', 'product_variation'));
        return $post_ids;
    }

    /**
     * Helper function to query posts.
     */
    public static function get_wp_posts(
        $product_group_id = null,
        $compare_condition = null,
        $post_type = 'product')
    {
        $args = array(
            'fields' => 'ids',
            'meta_query' => array(
                (($product_group_id) ?
                    array(
                        'key' => $product_group_id,
                        'compare' => $compare_condition,
                    ) : array()
                ),
            ),
            'post_status' => 'publish',
            'post_type' => $post_type,
            'posts_per_page' => -1,
        );
        return get_posts($args);
    }

    public static function decode_json($json_string, $assoc = false)
    {
        // Plugin requires 5.6.0 but for some user use 5.5.9 JSON_BIGINT_AS_STRING
        // will cause 502 issue when redirect.
        return version_compare(phpversion(), '5.6.0') >= 0
            ? json_decode($json_string, $assoc, 512, JSON_BIGINT_AS_STRING)
            : json_decode($json_string, $assoc, 512);
    }
}

