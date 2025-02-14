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
 * Tested up to:		6.5

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

        // Only modify if we're querying for a specific post
        if (empty($query->query_vars['post_id'])) {
            return $clauses;
        }

        $post_ID = $query->query_vars['post_id'];
        $post = get_post($post_ID);
        
        if (!$post) {
            return $clauses;
        }

        // Temporarily remove WPML's comment filtering to prevent conflicts
        remove_filter('comments_clauses', array($sitepress, 'comments_clauses'));

        // Get all language versions of this post
        $languages = apply_filters('wpml_active_languages', null, 'skip_missing=1');
        $post_ids = [$post_ID];

        foreach ($languages as $code => $l) {
            if (!$l['active']) {
                $translated_id = apply_filters('wpml_object_id', $post_ID, $post->post_type, false, $l['language_code']);
                if ($translated_id) {
                    $post_ids[] = $translated_id;
                }
            }
        }

        // Modify the WHERE clause to include all language versions
        $post_ids = array_map('intval', array_unique($post_ids));
        $clauses['where'] = str_replace(
            $wpdb->prepare("AND comment_post_ID = %d", $post_ID),
            "AND comment_post_ID IN (" . implode(',', $post_ids) . ")",
            $clauses['where']
        );

        // Add back WPML's comment filtering to prevent conflicts
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
