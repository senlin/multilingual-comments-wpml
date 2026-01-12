<?php
/**
 * Plugin Name: Multilingual Comments WPML
 * Plugin URI: https://wordpress.org/plugins/multilingual-comments-wpml
 * Description: This plugin combines comments from all translations of the posts and pages using WPML. Comments are internally still attached to the post or page in the language they were made on.
 *
 * Version: 1.2.1
 * Author: Pieter Bos
 * Author URI: https://so-wp.com
 *
 * Requires at least:	4.9
 * Tested up to:		6.9

 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 *
 * Text Domain: multilingual-comments-wpml
 *
 * GitHub Plugin URI:	https://github.com/senlin/multilingual-comments-wpml
 * GitHub Branch:		main

 * @package WordPress
 * @author Pieter Bos
 * @since 1.0.0
 */


/**
 * This is a fixed version of the no longer maintained WPML Comment Merging plugin:
 * http://wordpress.org/extend/plugins/wpml-comment-merging/ and https://github.com/JulioPotier/wpml-comments-merging
 */

// don't load the plugin file directly
if (!defined('ABSPATH')) exit;

class Multilingual_Comments_WPML
{
	public function __construct()
	{
		register_activation_hook(__FILE__, [$this, 'activate']);
        // Remove the later-stage comments_array filter
        // add_filter('comments_array', [$this, 'merge_comments'], 100, 2);

        // Add earlier-stage filter for comment queries
    	add_filter('comments_clauses', [$this, 'modify_comments_query'], 5, 2);
		add_filter('get_comments_number', [$this, 'merge_comment_count'], 100, 2);
	}

	public function is_wpml_active()
	{
		if (!function_exists('is_plugin_active')) {
			include_once(ABSPATH . 'wp-admin/includes/plugin.php');
		}

		return is_plugin_active('sitepress-multilingual-cms/sitepress.php');
	}

	public function activate()
	{
		if (!$this->is_wpml_active()) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die(
				__(
					'The "Multilingual Comments (WPML)" plugin requires the WPML plugin to be installed and activated.',
					'multilingual-comments-wpml'
				),
				__('Plugin Activation Error', 'multilingual-comments-wpml'),
				array('response' => 200, 'back_link' => true)
			);
		}
	}

	public function modify_comments_query($clauses, $query)
	{
	    global $wpdb, $sitepress;

	    // Ensure we are only modifying queries for a specific post (avoid affecting global queries)
	    if (empty($query->query_vars['post_id'])) {
	        return $clauses;
	    }

	    $post_ID = $query->query_vars['post_id'];
	    $post = get_post($post_ID);

	    // If the post does not exist, return the unmodified query
	    if (!$post) {
	        return $clauses;
	    }

	    // Temporarily remove WPML's built-in comment filtering to prevent conflicts
	    remove_filter('comments_clauses', array($sitepress, 'comments_clauses'));

	    // Get a list of all active languages
	    $languages = apply_filters('wpml_active_languages', null, 'skip_missing=1');
	    $post_ids = [$post_ID]; // Start with the current post ID

	    // Loop through available languages and get translated post IDs
	    foreach ($languages as $code => $l) {
	        if (!$l['active']) { // Skip the current active language
	            $translated_id = apply_filters('wpml_object_id', $post_ID, $post->post_type, false, $l['language_code']);
	            if ($translated_id) {
	                $post_ids[] = $translated_id; // Add translated post ID to the array
	            }
	        }
	    }

	    // Ensure all post IDs are unique and properly formatted for SQL queries
	    $post_ids = array_map('intval', array_unique($post_ids));

	    // Modify the WHERE clause to include all translations in the comment query
	    $clauses['where'] = preg_replace(
	        "/comment_post_ID = \d+/", // Look for the default `comment_post_ID = X` condition
	        "comment_post_ID IN (" . implode(',', $post_ids) . ")", // Replace it with multiple post IDs
	        $clauses['where']
	    );

	    /**
	     * Fix Pagination Issue:
	     * - WordPress paginates comments **before** applying the `comments_clauses` filter.
	     * - Since we are now fetching more comments, we need to adjust the LIMIT clause manually.
	     * - `number` represents the number of comments per page.
	     * - `offset` ensures pagination remains consistent.
	     */
	    if (!empty($query->query_vars['number'])) {
	        $clauses['limits'] = "LIMIT " . (int) $query->query_vars['number'] . " OFFSET " . (int) $query->query_vars['offset'];
	    }

	    // Re-add WPML's comment filtering after modifying the query
	    add_filter('comments_clauses', array($sitepress, 'comments_clauses'), 10, 2);

	    return $clauses;
	}


	public function merge_comment_count($count, $post_ID)
	{
		$languages = apply_filters('wpml_active_languages', null, 'skip_missing=1');

		$post = get_post($post_ID);
		$type = $post->post_type;

		foreach ($languages as $l) {
			if (!$l['active']) {
				$otherID = apply_filters('wpml_object_id', $post_ID, $type, false, $l['language_code']);
				if ($otherID) {
					$otherpost = get_post($otherID);
					if ($otherpost) {
						$count = $count + $otherpost->comment_count;
					}
				}
			}
		}

		return $count;
	}
}

$multilingual_comments_wpml = new Multilingual_Comments_WPML();
