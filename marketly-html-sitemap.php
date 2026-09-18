<?php

/**
 * Plugin Name: Marketly HTML Sitemap
 * Description: Lightweight HTML sitemap shortcodes for pages and posts, with a drop-in compatibility layer for Simple Sitemap Pro.
 * Version: 2.0.0
 * Author: Marketly Digital
 * Text Domain: marketly-html-sitemap
 * Requires at least: 5.5
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
     * Handle used for the front-end stylesheet.
     */
    const STYLE_HANDLE = 'marketly-html-sitemap';

    /**
     * Shortcode tags this plugin answers to.
     *
     * The `simple-sitemap` / `ss` tags are the legacy Simple Sitemap Pro tags,
     * kept so existing page content keeps rendering after that plugin is removed.
     *
     * @var string[]
     */
    const SHORTCODE_TAGS = array(
        'marketly_page_sitemap',
        'marketly_post_sitemap',
        'simple-sitemap',
        'ss',
        'simple-sitemap-group',
        'ssg',
        'simple-sitemap-child',
        'ssc',
        'simple-sitemap-menu',
        'ssm',
        'simple-sitemap-tax',
        'sst',
    );

    /**
     * Legacy Simple Sitemap Pro tags mapped to the method that renders them.
     *
     * Each feature has a long tag and a short alias, exactly as the original
     * plugin registered them.
     *
     * @var array<string,string>
     */
    const LEGACY_SHORTCODES = array(
        'simple-sitemap'       => 'render_legacy_sitemap',
        'ss'                   => 'render_legacy_sitemap',
        'simple-sitemap-group' => 'render_legacy_group',
        'ssg'                  => 'render_legacy_group',
        'simple-sitemap-child' => 'render_legacy_child',
        'ssc'                  => 'render_legacy_child',
        'simple-sitemap-menu'  => 'render_legacy_menu',
        'ssm'                  => 'render_legacy_menu',
        'simple-sitemap-tax'   => 'render_legacy_tax',
        'sst'                  => 'render_legacy_tax',
    );

    /**
     * Register the plugin.
     *
     * @return void
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_shortcodes'));
        add_action('init', array(__CLASS__, 'register_legacy_shortcodes'), 20);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_styles'), 5);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_styles'));
    }

    /**
     * Register the plugin's own shortcodes.
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
     * Register the legacy Simple Sitemap Pro shortcodes.
     *
     * Runs late on `init` so that if Simple Sitemap Pro is still active it wins
     * and both plugins can sit side by side during a migration. Once that plugin
     * is deactivated these take over and existing content renders unchanged.
     *
     * @return void
     */
    public static function register_legacy_shortcodes()
    {
        foreach (self::LEGACY_SHORTCODES as $tag => $method) {
            /**
             * Filter whether to claim a legacy shortcode tag.
             *
             * @param bool   $claim Whether to register the tag.
             * @param string $tag   Shortcode tag.
             */
            $claim = apply_filters(
                'marketly_html_sitemap_claim_legacy_tag',
                ! shortcode_exists($tag),
                $tag
            );

            if ($claim) {
                add_shortcode($tag, array(__CLASS__, $method));
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Styles
     * ------------------------------------------------------------------ */

    /**
     * Register the (inline) stylesheet.
     *
     * The rules below are the front-end subset of Simple Sitemap Pro's
     * `simple-sitemap.css` that affects the markup this plugin produces —
     * list indentation, spacing between post-type groups, and the excerpt /
     * separator / empty-state styling.
     *
     * @return void
     */
    public static function register_styles()
    {
        wp_register_style(self::STYLE_HANDLE, false, array(), '2.0.0');

        $css = '
.simple-sitemap-container ul,
.marketly-html-sitemap ul {
	margin: 0 0 0 1.2em;
	padding: 0;
}
.simple-sitemap-wrap:not(:first-of-type),
.marketly-html-sitemap__wrap:not(:first-of-type) {
	margin-top: 1.5em;
}
.simple-sitemap-container .excerpt {
	font-size: 0.85em;
}
.simple-sitemap-container span.excerpt {
	position: relative;
	left: 8px;
}
.simple-sitemap-container .separator {
	border-bottom: 1px #eee solid;
	margin-bottom: -5px;
	margin-top: 18px;
	padding: 0;
}
.simple-sitemap-container ul.main > li:last-child .separator {
	border-bottom: 0;
}
.simple-sitemap-container .no-posts {
	font-style: italic;
}
';

        wp_add_inline_style(self::STYLE_HANDLE, $css);
    }

    /**
     * Enqueue the stylesheet when the current entry uses one of our shortcodes.
     *
     * @return void
     */
    public static function maybe_enqueue_styles()
    {
        if (! is_singular()) {
            return;
        }

        $post = get_post();

        if (! $post instanceof \WP_Post) {
            return;
        }

        foreach (self::SHORTCODE_TAGS as $tag) {
            if (has_shortcode($post->post_content, $tag)) {
                wp_enqueue_style(self::STYLE_HANDLE);
                return;
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Legacy Simple Sitemap Pro shortcode
     * ------------------------------------------------------------------ */

    /**
     * Render the legacy [simple-sitemap] / [ss] shortcode.
     *
     * Supported attributes (matching Simple Sitemap Pro):
     * types, orderby, order, exclude, exclude_child, show_label, post_type_tag,
     * page_depth, links, target_blank, show_excerpt, excerpt_tag, title_tag, id.
     *
     * Examples:
     * [simple-sitemap types='post' orderby='menu_order']
     * [simple-sitemap types='page' orderby='menu_order' exclude='17723,31572']
     * [simple-sitemap types='page, post' post_type_tag='h2' orderby='menu_order']
     *
     * @param array  $attributes Shortcode attributes.
     * @param string $content    Enclosed content (unused).
     * @param string $tag        Shortcode tag.
     * @return string
     */
    public static function render_legacy_sitemap($attributes = array(), $content = '', $tag = 'simple-sitemap')
    {
        if (! is_array($attributes)) {
            $attributes = array();
        }

        $attributes = shortcode_atts(
            array(
                'id'              => '',
                'types'           => 'page',
                'orderby'         => 'title',
                'order'           => 'asc',
                'exclude'         => '',
                'exclude_child'   => 'false',
                'exclude_current' => 'no',
                'show_label'      => 'true',
                'post_type_tag'   => 'h3',
                'page_depth'      => 0,
                'links'           => 'true',
                'target_blank'    => 'false',
                'show_excerpt'    => 'false',
                'excerpt_tag'     => 'div',
                'title_tag'       => '',
                'container_tag'   => 'ul',
                'render'          => '',

                // Styling attributes carried over from the Pro modules.
                'sitemap_item_line_height' => '',
                'sitemap_container_margin' => '',
                'max_width'                => '',
            ),
            $attributes,
            $tag
        );

        $post_types = array_values(
            array_filter(
                array_map('trim', explode(',', (string) $attributes['types']))
            )
        );

        if (empty($post_types)) {
            return '<div>' . esc_html__(
                "Use the 'types' shortcode attribute to select one or more post types.",
                'marketly-html-sitemap'
            ) . '</div>';
        }

        $excluded_ids = self::parse_ids($attributes['exclude']);

        if (self::is_truthy($attributes['exclude_current']) && is_singular()) {
            $excluded_ids[] = get_queried_object_id();
        }

        if (self::is_truthy($attributes['exclude_child']) && ! empty($excluded_ids)) {
            $excluded_ids = self::add_descendant_ids($excluded_ids);
        }

        $excluded_ids = array_values(array_unique(array_filter($excluded_ids)));

        wp_enqueue_style(self::STYLE_HANDLE);

        $sections = '';

        foreach ($post_types as $post_type) {
            if (! post_type_exists($post_type)) {
                continue;
            }

            $sections .= self::render_post_type_section(
                $post_type,
                $attributes,
                $excluded_ids
            );
        }

        if ('' === $sections) {
            return '';
        }

        // Matches Simple Sitemap Pro: a generated id keeps multiple sitemaps on
        // one page from colliding, and the tab class is what its CSS hooks onto.
        $container_id = sanitize_html_class((string) $attributes['id']);

        if ('' === $container_id) {
            $container_id = uniqid();
        }

        $render_class = empty($attributes['render']) ? ' tab-disabled' : ' tab-enabled';

        $container_classes = 'simple-sitemap-container simple-sitemap-container-'
            . $container_id . $render_class;

        $styles = self::build_styles($attributes, '#simple-sitemap-container-' . $container_id);

        $output = '';

        if ('' !== $styles) {
            $output .= '<style type="text/css">' . $styles . '</style>';
        }

        $output .= '<div id="simple-sitemap-container-' . esc_attr($container_id) . '"'
            . ' class="' . esc_attr($container_classes) . '">'
            . $sections
            . '</div>';

        // The trailing clearfix is part of Simple Sitemap Pro's output — content
        // placed after the sitemap relies on it when the list floats. It is
        // appended after sanitising, which would otherwise strip the semicolon.
        return wp_kses_post($output) . '<br style="clear: both;">';
    }

    /**
     * Render one post-type block of the legacy sitemap.
     *
     * @param string $post_type    Post type name.
     * @param array  $attributes   Validated shortcode attributes.
     * @param int[]  $excluded_ids Post IDs to leave out.
     * @return string
     */
    private static function render_post_type_section($post_type, $attributes, $excluded_ids)
    {
        $query_arguments = array(
            'post_type'           => $post_type,
            'orderby'             => $attributes['orderby'],
            'order'               => $attributes['order'],
            'posts_per_page'      => -1,
            'ignore_sticky_posts' => 1,
            'no_found_rows'       => true,
        );

        if (! empty($excluded_ids)) {
            $query_arguments['post__not_in'] = $excluded_ids;
        }

        /**
         * Filter the legacy sitemap query arguments.
         *
         * @param array  $query_arguments Query arguments.
         * @param string $post_type       Post type being rendered.
         * @param array  $attributes      Validated shortcode attributes.
         */
        $query_arguments = apply_filters(
            'marketly_html_sitemap_legacy_args',
            $query_arguments,
            $post_type,
            $attributes
        );

        $query = new \WP_Query($query_arguments);

        $label = self::post_type_label($post_type, $attributes);

        if (empty($query->posts)) {
            $post_type_object = get_post_type_object($post_type);
            $post_type_name   = $post_type_object ? strtolower($post_type_object->labels->name) : $post_type;

            return '<div class="simple-sitemap-wrap">'
                . $label
                . '<p class="no-posts">'
                . sprintf(
                    /* translators: %s: plural post type name, e.g. "pages" */
                    esc_html__('Sorry, no %s found.', 'marketly-html-sitemap'),
                    esc_html($post_type_name)
                )
                . '</p></div>';
        }

        if (is_post_type_hierarchical($post_type)) {
            $items = self::render_hierarchical_items($query->posts, $attributes, $post_type);
        } else {
            $items = self::render_flat_items($query->posts, $attributes);
        }

        return '<div class="simple-sitemap-wrap">'
            . $label
            . '<ul class="simple-sitemap-' . esc_attr($post_type) . ' main">'
            . $items
            . '</ul></div>';
    }

    /**
     * Render a nested list for a hierarchical post type (pages).
     *
     * @param \WP_Post[] $posts      Posts to render.
     * @param array      $attributes Validated shortcode attributes.
     * @param string     $post_type  Post type being rendered.
     * @return string
     */
    private static function render_hierarchical_items($posts, $attributes, $post_type)
    {
        $walker_args = $attributes;
        $walker_args['pages_with_children'] = array();

        foreach ($posts as $post) {
            if ($post->post_parent) {
                $walker_args['pages_with_children'][$post->post_parent] = true;
            }
        }

        $walker = new Sitemap_Page_Walker();
        $depth  = absint($attributes['page_depth']);

        return $walker->walk($posts, $depth, $walker_args);
    }

    /**
     * Render a flat list for a non-hierarchical post type (posts, CPTs).
     *
     * @param \WP_Post[] $posts      Posts to render.
     * @param array      $attributes Validated shortcode attributes.
     * @return string
     */
    private static function render_flat_items($posts, $attributes)
    {
        $items = '';

        foreach ($posts as $post) {
            $title = self::build_title(
                get_the_title($post),
                get_permalink($post),
                $attributes
            );

            $items .= '<li class="sitemap-item">'
                . $title
                . self::build_excerpt($post, $attributes)
                . '</li>';
        }

        return $items;
    }

    /**
     * Build the heading that precedes a post-type list.
     *
     * @param string $post_type  Post type name.
     * @param array  $attributes Validated shortcode attributes.
     * @return string
     */
    private static function post_type_label($post_type, $attributes)
    {
        if (! self::is_truthy($attributes['show_label'])) {
            return '';
        }

        $post_type_object = get_post_type_object($post_type);

        if (! $post_type_object) {
            return '';
        }

        $tag = tag_escape((string) $attributes['post_type_tag']);

        if ('' === $tag) {
            $tag = 'h3';
        }

        return '<' . $tag . ' class="post-type">'
            . esc_html($post_type_object->labels->name)
            . '</' . $tag . '>';
    }

    /**
     * Build a sitemap entry's title, optionally linked and optionally wrapped.
     *
     * @param string $title_text Post title.
     * @param string $permalink  Post permalink.
     * @param array  $attributes Validated shortcode attributes.
     * @return string
     */
    public static function build_title($title_text, $permalink, $attributes)
    {
        $title_open  = '';
        $title_close = '';

        if (! empty($attributes['title_tag'])) {
            $title_tag   = tag_escape((string) $attributes['title_tag']);
            $title_open  = '<' . $title_tag . '>';
            $title_close = '</' . $title_tag . '>';
        }

        if ('' === trim((string) $title_text)) {
            $title_text = __('(no title)', 'marketly-html-sitemap');
        }

        if (! self::is_truthy($attributes['links'])) {
            return $title_open . $title_text . $title_close;
        }

        $target = self::is_truthy($attributes['target_blank'])
            ? ' target="_blank" rel="noopener"'
            : '';

        return $title_open
            . '<a href="' . esc_url($permalink) . '"' . $target . '>' . $title_text . '</a>'
            . $title_close;
    }

    /**
     * Build a sitemap entry's excerpt, when the shortcode asked for one.
     *
     * @param \WP_Post $post       Post being rendered.
     * @param array    $attributes Validated shortcode attributes.
     * @return string
     */
    public static function build_excerpt($post, $attributes)
    {
        if (! self::is_truthy($attributes['show_excerpt'])) {
            return '';
        }

        $excerpt_tag = tag_escape((string) $attributes['excerpt_tag']);

        if ('' === $excerpt_tag) {
            $excerpt_tag = 'div';
        }

        return '<' . $excerpt_tag . ' class="excerpt">'
            . get_the_excerpt($post)
            . '</' . $excerpt_tag . '>';
    }

    /**
     * Expand a list of post IDs to include every descendant.
     *
     * @param int[] $ids Post IDs.
     * @return int[]
     */
    private static function add_descendant_ids($ids)
    {
        foreach ($ids as $id) {
            $children = get_pages(
                array(
                    'child_of'    => $id,
                    'post_status' => 'publish',
                )
            );

            if (! empty($children)) {
                $ids = array_merge($ids, wp_list_pluck($children, 'ID'));
            }
        }

        return $ids;
    }

    /* ---------------------------------------------------------------------
     * Legacy [simple-sitemap-group] — posts grouped by taxonomy term
     * ------------------------------------------------------------------ */

    /**
     * Render the legacy [simple-sitemap-group] / [ssg] shortcode.
     *
     * Lists one block per taxonomy term, each holding that term's posts.
     *
     * Examples:
     * [simple-sitemap-group]
     * [simple-sitemap-group type='post' tax='category']
     * [simple-sitemap-group tax='post_tag' exclude_terms='news,updates']
     * [simple-sitemap-group taxonomy_links='true' term_tag='h4']
     *
     * @param array  $attributes Shortcode attributes.
     * @param string $content    Enclosed content (unused).
     * @param string $tag        Shortcode tag.
     * @return string
     */
    public static function render_legacy_group($attributes = array(), $content = '', $tag = 'simple-sitemap-group')
    {
        if (! is_array($attributes)) {
            $attributes = array();
        }

        $attributes = shortcode_atts(
            array(
                'id'                       => '',
                'type'                     => 'post',
                'tax'                      => 'category',
                'page_depth'               => 0,
                'title_tag'                => '',
                'show_excerpt'             => 'false',
                'excerpt_tag'              => 'div',
                'links'                    => 'true',
                'target_blank'             => 'false',
                'orderby'                  => 'title',
                'order'                    => 'asc',
                'post_type_tag'            => 'h3',
                'show_label'               => 'true',
                'container_tag'            => 'ul',
                'num_terms'                => 0,
                'term_tag'                 => 'h3',
                'taxonomy_links'           => 'false',
                'include_terms'            => '',
                'exclude_terms'            => '',
                'list_icon'                => 'true',
                'render_class'             => '',
                'sitemap_item_line_height' => '',
                'sitemap_container_margin' => '',
            ),
            $attributes,
            $tag
        );

        $post_type = (string) $attributes['type'];

        if (! post_type_exists($post_type)) {
            return '<h5 style="line-height:1.25em;">Post type \''
                . esc_html($post_type) . '\' not recognized.</h5>';
        }

        $taxonomy = (string) $attributes['tax'];

        // An unusable taxonomy produces the notice inside the container, the way
        // the original did — not an early return.
        $taxonomy_usable = '' !== $taxonomy
            && in_array($taxonomy, get_object_taxonomies($post_type), true);

        $container_id = sanitize_text_field((string) $attributes['id']);

        if ('' === $container_id) {
            $container_id = uniqid();
        }

        $render_class = ' ' . sanitize_html_class((string) $attributes['render_class']);
        $format_class = self::is_truthy($attributes['list_icon']) ? '' : ' hide-icon';

        $sitemap_unique_id = 'simple-sitemap-container-' . $container_id;
        $container_classes = 'simple-sitemap-container ' . $sitemap_unique_id
            . $render_class . $format_class;

        $styles = self::build_styles($attributes, '#' . $sitemap_unique_id);

        $terms = $taxonomy_usable ? get_terms(array('taxonomy' => $taxonomy)) : array();

        if (is_wp_error($terms) || empty($terms)) {
            $terms = array();
        }

        $include_terms = self::parse_term_list($attributes['include_terms']);
        $exclude_terms = self::parse_term_list($attributes['exclude_terms']);

        $sections = '';

        foreach ($terms as $term) {
            $slug = strtolower($term->slug);

            if (! empty($include_terms) && ! in_array($slug, $include_terms, true)) {
                continue;
            }

            if (in_array($slug, $exclude_terms, true)) {
                continue;
            }

            $query_arguments = array(
                'post_type'           => $post_type,
                'orderby'             => $attributes['orderby'],
                'order'               => $attributes['order'],
                'posts_per_page'      => -1,
                'ignore_sticky_posts' => 1,
                'no_found_rows'       => true,
                'tax_query'           => array(
                    array(
                        'taxonomy' => $taxonomy,
                        'field'    => 'slug',
                        'terms'    => $term->slug,
                    ),
                ),
            );

            /** This filter is documented in this file's render_post_type_section(). */
            $query_arguments = apply_filters(
                'marketly_html_sitemap_legacy_args',
                $query_arguments,
                $post_type,
                $attributes
            );

            $query = new \WP_Query($query_arguments);

            $sections .= '<div class="' . esc_attr('simple-sitemap-wrap' . $render_class)
                . ' ' . esc_attr($slug) . '">'
                . self::term_heading($term, $taxonomy, $attributes);

            if (empty($query->posts)) {
                $post_type_object = get_post_type_object($post_type);
                $name = $post_type_object ? strtolower($post_type_object->labels->name) : $post_type;
                $sections .= '<p class="no-posts">' . sprintf(
                    /* translators: %s: plural post type name, e.g. "posts" */
                    esc_html__('Sorry, no %s found.', 'marketly-html-sitemap'),
                    esc_html($name)
                ) . '</p></div>';
                continue;
            }

            $items = is_post_type_hierarchical($post_type)
                ? self::render_hierarchical_items($query->posts, $attributes, $post_type)
                : self::render_flat_items($query->posts, $attributes);

            $sections .= '<ul class="simple-sitemap-' . esc_attr($post_type) . ' main">'
                . $items . '</ul></div>';
        }

        wp_enqueue_style(self::STYLE_HANDLE);

        $body = '<div id="' . esc_attr($sitemap_unique_id) . '"'
            . ' class="' . esc_attr($container_classes) . '">'
            . wp_kses_post(self::post_type_label($post_type, $attributes))
            . ($taxonomy_usable ? $sections : 'No posts found.')
            . '</div>'
            . '<br style="clear: both;">';

        // The group shortcode sanitises its whole body (the main one does not),
        // which is what drops the semicolon from the clearfix style. Any <style>
        // block is kept outside that call so its CSS survives intact.
        $output = '';

        if ('' !== $styles) {
            $output .= '<style type="text/css">' . $styles . '</style>';
        }

        return $output . wp_kses_post($body);
    }

    /**
     * Build the heading that introduces one taxonomy term.
     *
     * @param \WP_Term $term       Term being rendered.
     * @param string   $taxonomy   Taxonomy name.
     * @param array    $attributes Validated shortcode attributes.
     * @return string
     */
    private static function term_heading($term, $taxonomy, $attributes)
    {
        $term_tag = tag_escape((string) $attributes['term_tag']);

        if ('' === $term_tag) {
            $term_tag = 'h3';
        }

        $name = $term->name;

        if (self::is_truthy($attributes['taxonomy_links'])) {
            $link = get_term_link($term->slug, $taxonomy);

            if (! is_wp_error($link)) {
                $name = '<a href="' . esc_url($link) . '">' . $name . '</a>';
            }
        }

        return wp_kses_post('<' . $term_tag . '>' . $name . '</' . $term_tag . '>');
    }

    /* ---------------------------------------------------------------------
     * Legacy [simple-sitemap-child] — child pages of one parent
     * ------------------------------------------------------------------ */

    /**
     * Render the legacy [simple-sitemap-child] / [ssc] shortcode.
     *
     * Examples:
     * [simple-sitemap-child child_of='42']
     * [simple-sitemap-child child_of='42' title_li='@']
     * [simple-sitemap-child exclude='12,34' nofollow='true']
     * [simple-sitemap-child child_of='42' show_excerpt='true' page_excerpt_length='40']
     *
     * @param array  $attributes Shortcode attributes.
     * @param string $content    Enclosed content (unused).
     * @param string $tag        Shortcode tag.
     * @return string
     */
    public static function render_legacy_child($attributes = array(), $content = '', $tag = 'simple-sitemap-child')
    {
        if (! is_array($attributes)) {
            $attributes = array();
        }

        $attributes = shortcode_atts(
            array(
                'include'             => '',
                'exclude'             => '',
                'child_of'            => '0',
                'title_li'            => '',
                'nofollow'            => 'false',
                'post_type'           => 'page',
                'show_excerpt'        => 'false',
                'page_excerpt_length' => '25',
            ),
            $attributes,
            $tag
        );

        $walker                 = new Sitemap_Child_Walker();
        $walker->show_excerpt   = self::is_truthy($attributes['show_excerpt']);
        $walker->excerpt_length = absint($attributes['page_excerpt_length']);

        $title_li = (string) $attributes['title_li'];

        // '@' is the original plugin's shorthand for "link to the parent page".
        if ('@' === $title_li) {
            $child_of = absint($attributes['child_of']);
            $title_li = $child_of
                ? '<a href=' . esc_url(get_permalink($child_of)) . '>' . get_the_title($child_of) . '</a>'
                : '';
        }

        $query_arguments = array(
            'include'   => $attributes['include'],
            'exclude'   => $attributes['exclude'],
            'child_of'  => $attributes['child_of'],
            'title_li'  => $title_li,
            'post_type' => $attributes['post_type'],
            'echo'      => 0,
            'walker'    => $walker,
        );

        /**
         * Filter the child sitemap query arguments.
         *
         * @param array $query_arguments wp_list_pages() arguments.
         * @param array $attributes      Validated shortcode attributes.
         */
        $query_arguments = apply_filters(
            'marketly_html_sitemap_child_args',
            $query_arguments,
            $attributes
        );

        $list = wp_list_pages($query_arguments);

        if (self::is_truthy($attributes['nofollow'])) {
            $list = self::rel_nofollow($list);
        }

        wp_enqueue_style(self::STYLE_HANDLE);

        return wp_kses_post("<ul class='ss-top-level'>" . $list . '</ul>');
    }

    /* ---------------------------------------------------------------------
     * Legacy [simple-sitemap-menu] — render a registered nav menu
     * ------------------------------------------------------------------ */

    /**
     * Render the legacy [simple-sitemap-menu] / [ssm] shortcode.
     *
     * Examples:
     * [simple-sitemap-menu menu='Main Menu']
     * [simple-sitemap-menu menu='Footer' label='Site map']
     * [simple-sitemap-menu menu='Main Menu' exclude_menu_ids='6181,8664']
     *
     * @param array  $attributes Shortcode attributes.
     * @param string $content    Enclosed content (unused).
     * @param string $tag        Shortcode tag.
     * @return string
     */
    public static function render_legacy_menu($attributes = array(), $content = '', $tag = 'simple-sitemap-menu')
    {
        if (! is_array($attributes)) {
            $attributes = array();
        }

        $attributes = shortcode_atts(
            array(
                'menu'                 => '',
                'container'            => false,
                'menu_class'           => 'simple-sitemap-nav-menu',
                'horizontal_separator' => ', ',
                'list_icon'            => 'true',
                'container_class'      => '',
                'label'                => '',
                'exclude_menu_ids'     => '',
                'include_menu_ids'     => '',
            ),
            $attributes,
            $tag
        );

        $unique_menu_id = 'ssm_' . uniqid();

        // Menu items are hidden with CSS rather than filtered out of the menu,
        // matching how Simple Sitemap Pro implemented include/exclude here.
        $css = '';

        if (! empty($attributes['exclude_menu_ids'])) {
            $css = self::menu_id_selectors($unique_menu_id, $attributes['exclude_menu_ids'])
                . ' { display: none; }';
        } elseif (! empty($attributes['include_menu_ids'])) {
            $css = '#' . $unique_menu_id . ' li { display: none; }'
                . self::menu_id_selectors($unique_menu_id, $attributes['include_menu_ids'])
                . ' { display: list-item; }';
        }

        $format_class      = self::is_truthy($attributes['list_icon']) ? '' : ' hide-icon';
        $container_classes = 'simple-sitemap-container simple-sitemap-menu' . $format_class;

        $label = ! empty($attributes['label'])
            ? '<h3>' . $attributes['label'] . '</h3>'
            : '';

        $menu_html = wp_nav_menu(
            array(
                'menu'            => $attributes['menu'],
                'container'       => $attributes['container'],
                'container_class' => $attributes['container_class'],
                'menu_class'      => $attributes['menu_class'],
                'echo'            => false,
            )
        );

        wp_enqueue_style(self::STYLE_HANDLE);

        $output = '<div id="' . esc_attr($unique_menu_id) . '"'
            . ' class="' . esc_attr($container_classes) . '">'
            . '<style>' . $css . '</style>'
            . $label
            . $menu_html
            . '</div>'
            . '<br style="clear: both;">';

        return wp_kses_post($output);
    }

    /**
     * Build a comma-separated selector list for a set of menu item IDs.
     *
     * @param string $unique_menu_id Container element id.
     * @param string $ids            Comma-separated menu item IDs.
     * @return string
     */
    private static function menu_id_selectors($unique_menu_id, $ids)
    {
        $selectors = array();

        foreach (explode(',', (string) $ids) as $id) {
            $id = absint(trim($id));

            if ($id) {
                $selectors[] = '#' . $unique_menu_id . ' li#menu-item-' . $id;
            }
        }

        return implode(', ', $selectors);
    }

    /* ---------------------------------------------------------------------
     * Legacy [simple-sitemap-tax] — list taxonomy terms
     * ------------------------------------------------------------------ */

    /**
     * Render the legacy [simple-sitemap-tax] / [sst] shortcode.
     *
     * Examples:
     * [simple-sitemap-tax]
     * [simple-sitemap-tax taxonomy='post_tag' show_count='1']
     * [simple-sitemap-tax taxonomy='category' exclude='3,7' depth='2']
     *
     * Note: `nofollow` is accepted because the original plugin accepted it, but
     * it had no effect there either — term links are never rewritten.
     *
     * @param array  $attributes Shortcode attributes.
     * @param string $content    Enclosed content (unused).
     * @param string $tag        Shortcode tag.
     * @return string
     */
    public static function render_legacy_tax($attributes = array(), $content = '', $tag = 'simple-sitemap-tax')
    {
        if (! is_array($attributes)) {
            $attributes = array();
        }

        $attributes = shortcode_atts(
            array(
                'taxonomy'   => 'category',
                'include'    => '',
                'exclude'    => '',
                'depth'      => '0',
                'child_of'   => '0',
                'title_li'   => '',
                'nofollow'   => 'false',
                'show_count' => '0',
                'orderby'    => 'name',
                'order'      => 'ASC',
                'hide_empty' => '0',
                'echo'       => '0',
            ),
            $attributes,
            $tag
        );

        wp_enqueue_style(self::STYLE_HANDLE);

        $query_arguments = array(
            'hide_empty'         => $attributes['hide_empty'],
            'orderby'            => $attributes['orderby'],
            'order'              => $attributes['order'],
            'show_count'         => $attributes['show_count'],
            'title_li'           => '',
            'child_of'           => $attributes['child_of'],
            'depth'              => $attributes['depth'],
            'include'            => self::parse_ids($attributes['include']),
            'exclude'            => self::parse_ids($attributes['exclude']),
            'taxonomy'           => $attributes['taxonomy'],
            'echo'               => 0,
            'use_desc_for_title' => false,
        );

        /**
         * Filter the taxonomy sitemap query arguments.
         *
         * @param array $query_arguments wp_list_categories() arguments.
         * @param array $attributes      Validated shortcode attributes.
         */
        $query_arguments = apply_filters(
            'marketly_html_sitemap_tax_args',
            $query_arguments,
            $attributes
        );

        return '<ul>' . wp_list_categories($query_arguments) . '</ul>';
    }

    /* ---------------------------------------------------------------------
     * Native shortcodes
     * ------------------------------------------------------------------ */

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

        wp_enqueue_style(self::STYLE_HANDLE);

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

        wp_enqueue_style(self::STYLE_HANDLE);

        return sprintf(
            '<nav class="marketly-html-sitemap marketly-post-sitemap" aria-label="%1$s"><ul class="marketly-html-sitemap__list">%2$s</ul></nav>',
            esc_attr__('Post sitemap', 'marketly-html-sitemap'),
            $items
        );
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Build the inline styles the sitemap container needs, if any.
     *
     * @param array  $attributes        Validated shortcode attributes.
     * @param string $container_css_id  CSS id selector for the container.
     * @return string
     */
    private static function build_styles($attributes, $container_css_id)
    {
        $styles = '';

        if (! empty($attributes['sitemap_item_line_height'])) {
            $styles .= ' ' . $container_css_id . ' .sitemap-item { line-height: '
                . sanitize_text_field($attributes['sitemap_item_line_height']) . '; }';
        }

        if (! empty($attributes['sitemap_container_margin'])) {
            $styles .= ' ' . $container_css_id . ' { margin: '
                . sanitize_text_field($attributes['sitemap_container_margin']) . '; }';
        }

        if (! empty($attributes['max_width'])) {
            $styles .= ' ' . $container_css_id . ' { max-width: '
                . sanitize_text_field($attributes['max_width']) . '; }';
        }

        return $styles;
    }

    /**
     * Split a comma-separated list of term slugs into a trimmed array.
     *
     * @param mixed $value Shortcode value.
     * @return string[]
     */
    private static function parse_term_list($value)
    {
        if (! is_scalar($value) || '' === trim((string) $value)) {
            return array();
        }

        return array_values(
            array_filter(
                array_map(
                    static function ($slug) {
                        return strtolower(trim($slug));
                    },
                    explode(',', (string) $value)
                ),
                static function ($slug) {
                    return '' !== $slug;
                }
            )
        );
    }

    /**
     * Add rel="nofollow" to every link in a block of markup.
     *
     * @param string $html Markup containing anchors.
     * @return string
     */
    private static function rel_nofollow($html)
    {
        return preg_replace_callback(
            '|<a (.+?)>|i',
            static function ($matches) {
                $atts = shortcode_parse_atts($matches[1]);
                $rel  = 'nofollow';

                if (! empty($atts['rel'])) {
                    $parts = array_map('trim', explode(' ', $atts['rel']));

                    if (! in_array('nofollow', $parts, true)) {
                        $parts[] = 'nofollow';
                    }

                    $rel = implode(' ', $parts);
                    unset($atts['rel']);

                    $rebuilt = '';

                    foreach ($atts as $name => $value) {
                        $rebuilt .= $name . '="' . esc_attr($value) . '" ';
                    }

                    $matches[1] = rtrim($rebuilt);
                }

                return '<a ' . $matches[1] . ' rel="' . esc_attr($rel) . '">';
            },
            $html
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
    public static function is_truthy($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            array('1', 'true', 'yes', 'on'),
            true
        );
    }
}

/**
 * Walker that reproduces Simple Sitemap Pro's nested page markup.
 *
 * Emits `<li class="sitemap-item page_item page-item-{ID}">`, adding
 * `page_item_has_children` on parents and wrapping child levels in
 * `<ul class="children">`, so existing theme CSS keeps matching.
 */
final class Sitemap_Page_Walker extends \Walker_Page
{

    /**
     * Open a child level.
     *
     * @param string $output Walker output, by reference.
     * @param int    $depth  Current depth.
     * @param array  $args   Walker arguments.
     * @return void
     */
    public function start_lvl(&$output, $depth = 0, $args = array())
    {
        $indent  = str_repeat("\t", $depth);
        $output .= "\n" . $indent . "<ul class='children'>\n";
    }

    /**
     * Close a child level.
     *
     * @param string $output Walker output, by reference.
     * @param int    $depth  Current depth.
     * @param array  $args   Walker arguments.
     * @return void
     */
    public function end_lvl(&$output, $depth = 0, $args = array())
    {
        $indent  = str_repeat("\t", $depth);
        $output .= $indent . "</ul>\n";
    }

    /**
     * Open a page list item.
     *
     * @param string   $output       Walker output, by reference.
     * @param \WP_Post $page         Page object.
     * @param int      $depth        Current depth.
     * @param array    $args         Walker arguments.
     * @param int      $current_page Current page ID.
     * @return void
     */
    public function start_el(&$output, $page, $depth = 0, $args = array(), $current_page = 0)
    {
        $indent = $depth ? str_repeat("\t", $depth) : '';

        $css_class = array('sitemap-item', 'page_item', 'page-item-' . $page->ID);

        if (! empty($args['pages_with_children'][$page->ID])) {
            $css_class[] = 'page_item_has_children';
        }

        // Simple Sitemap Pro uses the raw post_title here (not get_the_title())
        // for pages, while using the filtered title for other post types.
        // Mirrored so migrated pages render byte-for-byte the same.
        $title_text = $page->post_title;

        if ('' === $title_text) {
            /* translators: %d: ID of a post */
            $title_text = sprintf(__('#%d (no title)'), $page->ID);
        }

        $title = Plugin::build_title(
            $title_text,
            get_permalink($page->ID),
            $args
        );

        $output .= $indent
            . '<li class="' . esc_attr(implode(' ', $css_class)) . '">'
            . $title
            . Plugin::build_excerpt($page, $args);
    }

    /**
     * Close a page list item.
     *
     * @param string   $output Walker output, by reference.
     * @param \WP_Post $page   Page object.
     * @param int      $depth  Current depth.
     * @param array    $args   Walker arguments.
     * @return void
     */
    public function end_el(&$output, $page, $depth = 0, $args = array())
    {
        $output .= "</li>\n";
    }
}

/**
 * Walker for [simple-sitemap-child].
 *
 * WordPress's own Walker_Page markup, with an optional excerpt appended to each
 * item — which is all Simple Sitemap Pro's child walker added.
 */
final class Sitemap_Child_Walker extends \Walker_Page
{

    /**
     * Whether to append an excerpt to each item.
     *
     * @var bool
     */
    public $show_excerpt = false;

    /**
     * Word count for the generated excerpt.
     *
     * @var int
     */
    public $excerpt_length = 25;

    /**
     * Open a page list item, appending the excerpt when asked for.
     *
     * @param string   $output       Walker output, by reference.
     * @param \WP_Post $page         Page object.
     * @param int      $depth        Current depth.
     * @param array    $args         Walker arguments.
     * @param int      $current_page Current page ID.
     * @return void
     */
    public function start_el(&$output, $page, $depth = 0, $args = array(), $current_page = 0)
    {
        parent::start_el($output, $page, $depth, $args, $current_page);

        if (! $this->show_excerpt) {
            return;
        }

        $output .= '<div class="excerpt">'
            . wp_trim_words(strip_shortcodes($page->post_content), $this->excerpt_length)
            . '</div>';
    }
}

Plugin::init();
