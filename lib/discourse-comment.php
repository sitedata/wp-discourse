<?php
/**
 * Syncs Discourse comments with WordPress posts.
 *
 * @package WPDiscourse
 */

namespace WPDiscourse\DiscourseComment;

use WPDiscourse\Utilities\Utilities as DiscourseUtilities;

/**
 * Class DiscourseComment
 */
class DiscourseComment {

	/**
	 * Gives access to the plugin options.
	 *
	 * @access protected
	 * @var mixed|void
	 */
	protected $options;

	/**
	 * DiscourseComment constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'setup_options' ) );
//		add_action( 'sync_discourse_data', array( $this, 'sync_discourse_data' ) );
		add_filter( 'get_comments_number', array( $this, 'get_comments_number' ), 10, 2 );
		add_filter( 'comments_template', array( $this, 'comments_template' ), 20, 1 );
		add_filter( 'wp_kses_allowed_html', array( $this, 'extend_allowed_html' ), 10, 2 );
		add_filter( 'cron_schedules', array( $this, 'discourse_sync_schedule' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'discourse_comments_js' ) );
	}

	public function discourse_sync_schedule( $schedules ) {
		$sync_period_in_minutes = ! empty( $this->options['comment-sync-period'] ) ? $this->options['comment-sync-period'] : 10;
		if ( ! isset( $schedules['discourse_sync_period'] ) || 1 === intval( get_option( 'wpdc_sync_period_changed' ) ) ) {
			$schedules['discourse_sync_period'] = array(
				'interval' => $sync_period_in_minutes * 60,
				'display'  => __( 'Discourse Sync Period', 'wp-discourse' ),
			);

			update_option( 'wpdc_sync_period_changed', 0 );
		}

		return $schedules;
	}

	// Todo: if plugin returns a bad response, revert to syncing comments with old method.
	public function sync_discourse_data() {
		$last_sync   = get_option( 'wpdc_last_sync' );
		$sync_period = ceil( ( time() - $last_sync ) / 60 );

		$updated_topics = DiscourseUtilities::get_updated_topic_data( $sync_period );

		if ( is_wp_error( $updated_topics ) ) {

			return null;
		}

		if ( ! empty( $updated_topics ) ) {
			$skipped_topics = array();
			foreach ( $updated_topics as $updated_topic ) {
				$topic_id = $updated_topic->id;
				$title         = $updated_topic->title;
				$comment_count = $updated_topic->comment_count;
				$external_url  = $updated_topic->embed_url;

				$post_id = DiscourseUtilities::get_post_id_by_topic_id( $topic_id );

				write_log('post id from topic id', $post_id );

				$post_id = url_to_postid( $external_url );

				// The post's permalink has been updated and doesn't match the Discourse embed_url.
				if ( ! $post_id ) {
					$post = get_page_by_title( $title, 'OBJECT', 'post' );

					$post_id = $post ? $post->ID : null;
				}

				if ( $post_id ) {
					update_post_meta( $post_id, 'wpdc_sync_post_comments', 1 );
					update_post_meta( $post_id, 'discourse_comments_count', $comment_count );
				} else {
					$skipped_topics[] = $title;
				}

				if ( $skipped_topics ) {
					// This is used for adding an admin notice.
					update_option( 'wpdc_sync_skipped_topics', $skipped_topics );
				}
			}

			// Set the start for the next sync period.
			update_option( 'wpdc_last_sync', time() );
		}
	}

	/**
	 * Setup options.
	 */
	public function setup_options() {
		$this->options = DiscourseUtilities::get_options();

		// Setup the sync_discourse_data scheduled event. It's added here, instead of in the activation hook so that
		// it will be available for sites when they update the plugin.
//		if ( ! empty( $this->options['use-discourse-plugin'] ) && 1 === intval( $this->options['use-discourse-plugin'] ) ) {
//			$already_scheduled = 1 === intval( get_option( 'wpdc_discourse_sync_scheduled' ) );
//			$schedule_changed  = 1 === intval( get_option( 'wpdc_sync_period_changed' ) );
//			if ( ! $already_scheduled || $schedule_changed ) {
//				wp_clear_scheduled_hook( 'sync_discourse_data' );
//				wp_schedule_event( time(), 'discourse_sync_period', 'sync_discourse_data' );

//				update_option( 'wpdc_discourse_sync_scheduled', 1 );
//			}
//		}
	}

	/**
	 * Adds data-youtube-id to the allowable div attributes.
	 *
	 * Discourse returns the youtube video id as the value of the 'data-youtube-attribute',
	 * this function makes it possible to filter the comments with `wp_kses_post` without
	 * stripping out that attribute.
	 *
	 * @param array  $allowedposttags The array of allowed post tags.
	 * @param string $context The current context ('post', 'data', etc.).
	 *
	 * @return mixed
	 */
	public function extend_allowed_html( $allowedposttags, $context ) {
		if ( 'post' === $context ) {
			$allowedposttags['div'] = array(
				'class'           => true,
				'id'              => true,
				'style'           => true,
				'title'           => true,
				'role'            => true,
				'data-youtube-id' => array(),
			);
		}

		return $allowedposttags;
	}

	/**
	 * Enqueues the `comments.js` script.
	 *
	 * Hooks into 'wp_enqueue_scripts'.
	 */
	function discourse_comments_js() {
		if ( isset( $this->options['allowed_post_types'] ) && is_singular( $this->options['allowed_post_types'] ) ) {
			if ( $this->use_discourse_comments( get_the_ID() ) ) {
				wp_enqueue_script(
					'discourse-comments-js',
					WPDISCOURSE_URL . '/js/comments.js',
					array( 'jquery' ),
					get_option( 'discourse_version' ),
					true
				);
				// Localize script.
				$data = array(
					'url' => $this->options['url'],
				);
				wp_localize_script( 'discourse-comments-js', 'discourse', $data );
			}
		}
	}

	/**
	 * Checks if a post is using Discourse comments.
	 *
	 * @param int $postid The ID of the post.
	 *
	 * @return bool|int
	 */
	protected function use_discourse_comments( $postid ) {
		if ( empty( $this->options['use-discourse-comments'] ) ) {
			return 0;
		}

		$setting = get_post_meta( $postid, 'publish_to_discourse', true );

		return 1 === intval( $setting );
	}

	/**
	 * Syncs Discourse comments to WordPress.
	 *
	 * @param int $postid The WordPress post id.
	 */
	function sync_comments( $postid ) {
		global $wpdb;
		$discourse_options       = $this->options;
		$use_discourse_wp_plugin = ! empty( $discourse_options['use-discourse-plugin'] ) && 1 === intval( $discourse_options['use-discourse-plugin'] );
		$debug = isset( $discourse_options['debug-mode'] ) && 1 === intval( $discourse_options['debug-mode'] );

		if ( ! $use_discourse_wp_plugin ) {
			// Every 10 minutes do a json call to sync comment count and top comments.
			$last_sync = (int) get_post_meta( $postid, 'discourse_last_sync', true );
			$time      = date_create()->format( 'U' );
			$sync_post = $last_sync + 60 * 10 < $time;
		} else {
			$sync_post = 1 === intval( get_post_meta( $postid, 'wpdc_sync_post_comments', true ) );
		}

		if ( $debug || $sync_post ) {
			// Avoids a double sync.
			wp_cache_set( 'discourse_comments_lock', $wpdb->get_row( "SELECT GET_LOCK( 'discourse_lock', 0 ) got_it" ) );
			if ( 1 === intval( wp_cache_get( 'discourse_comments_lock' )->got_it ) ) {

				$comment_count            = intval( $discourse_options['max-comments'] );
				$min_trust_level          = intval( $discourse_options['min-trust-level'] );
				$min_score                = intval( $discourse_options['min-score'] );
				$min_replies              = intval( $discourse_options['min-replies'] );
				$bypass_trust_level_score = intval( $discourse_options['bypass-trust-level-score'] );

				$options = 'best=' . $comment_count . '&min_trust_level=' . $min_trust_level . '&min_score=' . $min_score;
				$options = $options . '&min_replies=' . $min_replies . '&bypass_trust_level_score=' . $bypass_trust_level_score;

				if ( isset( $discourse_options['only-show-moderator-liked'] ) && 1 === intval( $discourse_options['only-show-moderator-liked'] ) ) {
					$options = $options . '&only_moderator_liked=true';
				}
				$options = $options . '&api_key=' . $discourse_options['api-key'] . '&api_username=' . $discourse_options['publish-username'];

				$discourse_permalink = get_post_meta( $postid, 'discourse_permalink', true );
				if ( ! $discourse_permalink ) {

					return 0;
				}
				$permalink = esc_url_raw( $discourse_permalink ) . '/wordpress.json?' . $options;

				$result = wp_remote_get( $permalink );

				if ( DiscourseUtilities::validate( $result ) ) {

					$json = json_decode( $result['body'] );

					if ( isset( $json->posts_count ) ) {
						$posts_count = $json->posts_count - 1;
						if ( $posts_count < 0 ) {
							$posts_count = 0;
						}

						// Todo: comment this out for now for testing.
//							update_post_meta( $postid, 'discourse_comments_count', $posts_count );
						update_post_meta( $postid, 'discourse_comments_raw', esc_sql( $result['body'] ) );
					}
				}

				update_post_meta( $postid, 'wpdc_sync_post_comments', 0 );
			}// End if().

			wp_cache_set( 'discourse_comments_lock', $wpdb->get_results( "SELECT RELEASE_LOCK( 'discourse_lock' )" ) );
		}// End if().
	}

	/**
	 * Loads the comments template.
	 *
	 * Hooks into 'comments_template'.
	 *
	 * @param string $old The comments template returned by WordPress.
	 *
	 * @return string
	 */
	function comments_template( $old ) {
		global $post;

		if ( $this->use_discourse_comments( $post->ID ) ) {
			$this->sync_comments( $post->ID );
			$options = $this->options;
			// Use $post->comment_count because get_comments_number will return the Discourse comments
			// number for posts that are published to Discourse.
			$num_wp_comments = $post->comment_count;
			if ( ! isset( $options['show-existing-comments'] ) || 0 === intval( $options['show-existing-comments'] ) || 0 === intval( $num_wp_comments ) ) {
				// Only show the Discourse comments.
				return WPDISCOURSE_PATH . 'templates/comments.php';
			} else {
				// Show the Discourse comments then show the existing WP comments (in $old).
				include WPDISCOURSE_PATH . 'templates/comments.php';

				echo '<div class="discourse-existing-comments-heading">' . wp_kses_post( $options['existing-comments-heading'] ) . '</div>';

				return $old;
			}
		}

		// Show the existing WP comments.
		return $old;
	}

	/**
	 * Returns the 'discourse_comments_count' for posts that use Discourse comments.
	 *
	 * Returns the sum of the Discourse comments and the WordPress comments for posts that have both.
	 *
	 * @param int $count The comment count supplied by WordPress.
	 * @param int $post_id The ID of the post.
	 *
	 * @return mixed
	 */
	function get_comments_number( $count, $post_id ) {
		if ( $this->use_discourse_comments( $post_id ) ) {

			$single_page = is_single( $post_id ) || is_page( $post_id );
			$single_page = apply_filters( 'wpdc_single_page_comment_number_sync', $single_page, $post_id );

			// Only automatically sync comments for individual posts, it's too inefficient to do this with an archive page.
			if ( empty( $this->options['use-discourse-plugin'] ) ) {
				if ( $single_page ) {
					$this->sync_comments( $post_id );
				} else {
					// For archive pages, check $last_sync against $archive_page_sync_period.
					$archive_page_sync_period = intval( apply_filters( 'discourse_archive_page_sync_period', DAY_IN_SECONDS, $post_id ) );
					$last_sync                = intval( get_post_meta( $post_id, 'discourse_last_sync', true ) );

					if ( $last_sync + $archive_page_sync_period < time() ) {
						$this->sync_comments( $post_id );
					}
				}
			}

			$count = intval( get_post_meta( $post_id, 'discourse_comments_count', true ) );

			// If WordPress comments are also being used, add them to the comment count.
			if ( isset( $this->options['show-existing-comments'] ) && 1 === intval( $this->options['show-existing-comments'] ) ) {
				$current_post     = get_post( $post_id );
				$wp_comment_count = $current_post->comment_count;
				$count            = $count + $wp_comment_count;
			}
		}

		return $count;
	}
}
