<?php

/**
 * Plugin Name: Marketly HTML Sitemap
 * Description: Provides lightweight HTML sitemap shortcodes for pages and posts.
 * Version: 1.0.0
 * Author: Marketly Digital
 * Text Domain: marketly-html-sitemap
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

namespace Marketly\HtmlSitemap;

defined('ABSPATH') || exit;

/**
 * Main plugin class.
 */
final class Plugin
{

    /**
     * Register the plugin.
     *
     * @return void
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_shortcodes'));
    }

    /**
     * Register sitemap shortcodes.
     *
     * @return void
     */
    public static function register_shortcodes()
    {
        add_shortcode(
            'marketly_page_sitemap',
            array(__CLASS__, 'render_page_sitemap')
        );

        add_shortcode(
            'marketly_post_sitemap',
            array(__CLASS__, 'render_post_sitemap')
        );
    }

    /**
     * Render a hierarchical page sitemap.
     *
     * Examples:
     * [marketly_page_sitemap]
     * [marketly_page_sitemap exclude="123,456"]
     * [marketly_page_sitemap depth="2"]
     * [marketly_page_sitemap exclude_current="no"]
     *
     * @param array $attributes Shortcode attributes.
     * @return string
     */
    public static function render_page_sitemap($attributes = array())
    {
        $attributes = shortcode_atts(
            array(
                'exclude'         => '',
                'exclude_current' => 'yes',
                'depth'           => 0,
            ),
            $attributes,
            'marketly_page_sitemap'
        );

        $excluded_ids = self::parse_ids($attributes['exclude']);

        if (
            self::is_truthy($attributes['exclude_current'])
            && is_page()
        ) {
            $excluded_ids[] = get_queried_object_id();
            $excluded_ids   = array_unique(array_filter($excluded_ids));
        }

        $depth = absint($attributes['depth']);

        $query_arguments = array(
            'title_li'    => '',
            'echo'        => false,
            'post_type'   => 'page',
            'post_status' => 'publish',
            'sort_column' => 'menu_order,post_title',
            'sort_order'  => 'ASC',
            'depth'       => $depth,
        );

        if (! empty($excluded_ids)) {
            $query_arguments['exclude'] = implode(',', $excluded_ids);
        }

        /**
         * Filter the page sitemap query arguments.
         *
         * @param array $query_arguments Page query arguments.
         * @param array $attributes      Validated shortcode attributes.
         */
        $query_arguments = apply_filters(
            'marketly_html_sitemap_page_args',
            $query_arguments,
            $attributes
        );

        $page_items = wp_list_pages($query_arguments);

        if (empty($page_items)) {
            return '';
        }

        return sprintf(
            '<nav class="marketly-html-sitemap marketly-page-sitemap" aria-label="%1$s"><ul class="marketly-html-sitemap__list">%2$s</ul></nav>',
            esc_attr__('Page sitemap', 'marketly-html-sitemap'),
            $page_items
        );
    }

    /**
     * Render a post sitemap.
     *
     * Examples:
     * [marketly_post_sitemap]
     * [marketly_post_sitemap exclude="123,456"]
     * [marketly_post_sitemap limit="50"]
     * [marketly_post_sitemap orderby="date" order="DESC"]
     * [marketly_post_sitemap exclude_current="no"]
     *
     * @param array $attributes Shortcode attributes.
     * @return string
     */
    public static function render_post_sitemap($attributes = array())
    {
        $attributes = shortcode_atts(
            array(
                'exclude'         => '',
                'exclude_current' => 'yes',
                'limit'           => -1,
                'orderby'         => 'title',
                'order'           => 'ASC',
            ),
            $attributes,
            'marketly_post_sitemap'
        );

        $excluded_ids = self::parse_ids($attributes['exclude']);

        if (
            self::is_truthy($attributes['exclude_current'])
            && is_singular('post')
        ) {
            $excluded_ids[] = get_queried_object_id();
            $excluded_ids   = array_unique(array_filter($excluded_ids));
        }

        $allowed_orderby = array(
            'title',
            'date',
            'modified',
            'menu_order',
        );

        $orderby = strtolower((string) $attributes['orderby']);

        if (! in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'title';
        }

        $order = strtoupper((string) $attributes['order']);

        if (! in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'ASC';
        }

        $limit = self::validate_limit($attributes['limit']);

        $query_arguments = array(
            'post_type'              => 'post',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'post__not_in'           => $excluded_ids,
            'orderby'                => $orderby,
            'order'                  => $order,
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        /**
         * Filter the post sitemap query arguments.
         *
         * @param array $query_arguments Post query arguments.
         * @param array $attributes      Validated shortcode attributes.
         */
        $query_arguments = apply_filters(
            'marketly_html_sitemap_post_args',
            $query_arguments,
            $attributes
        );

        $query = new \WP_Query($query_arguments);

        if (empty($query->posts)) {
            return '';
        }

        $items = '';

        foreach ($query->posts as $post) {
            $title = get_the_title($post);

            if ('' === trim($title)) {
                $title = __('Untitled', 'marketly-html-sitemap');
            }

            $items .= sprintf(
                '<li class="marketly-html-sitemap__item"><a href="%1$s">%2$s</a></li>',
                esc_url(get_permalink($post)),
                esc_html($title)
            );
        }

        return sprintf(
            '<nav class="marketly-html-sitemap marketly-post-sitemap" aria-label="%1$s"><ul class="marketly-html-sitemap__list">%2$s</ul></nav>',
            esc_attr__('Post sitemap', 'marketly-html-sitemap'),
            $items
        );
    }

    /**
     * Convert comma-separated IDs into validated positive integers.
     *
     * @param mixed $value Shortcode value.
     * @return int[]
     */
    private static function parse_ids($value)
    {
        if (! is_scalar($value)) {
            return array();
        }

        $ids = array_map(
            'absint',
            explode(',', (string) $value)
        );

        return array_values(
            array_unique(
                array_filter($ids)
            )
        );
    }

    /**
     * Validate the post limit.
     *
     * Only -1 or a positive integer is accepted.
     *
     * @param mixed $value Shortcode value.
     * @return int
     */
    private static function validate_limit($value)
    {
        if ('-1' === (string) $value) {
            return -1;
        }

        $limit = absint($value);

        return $limit > 0 ? $limit : -1;
    }

    /**
     * Interpret common true-like shortcode values.
     *
     * @param mixed $value Shortcode value.
     * @return bool
     */
    private static function is_truthy($value)
    {
        return in_array(
            strtolower(trim((string) $value)),
            array('1', 'true', 'yes', 'on'),
            true
        );
    }
}

Plugin::init();
