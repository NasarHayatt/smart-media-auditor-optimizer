<?php
/**
 * Cache invalidation.
 *
 * When something changes, only the pages that actually show it are cleared.
 * Emptying the whole cache on every edit throws away work that is still valid
 * and leaves the site slow until it is rebuilt.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Work out what a change affects, and clear just that.
 */
final class Purge {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! Cache::active() ) {
			return;
		}

		add_action( 'save_post', array( self::class, 'post' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'post' ), 10, 2 );
		add_action( 'trashed_post', array( self::class, 'post' ) );
		add_action( 'untrashed_post', array( self::class, 'post' ) );
		add_action( 'attachment_updated', array( self::class, 'post' ) );

		add_action( 'comment_post', array( self::class, 'comment' ), 10, 3 );
		add_action( 'edit_comment', array( self::class, 'comment' ) );
		add_action( 'deleted_comment', array( self::class, 'comment' ) );
		add_action( 'wp_set_comment_status', array( self::class, 'comment' ) );

		add_action( 'edited_term', array( self::class, 'term' ), 10, 3 );
		add_action( 'delete_term', array( self::class, 'term' ), 10, 3 );
		add_action( 'created_term', array( self::class, 'term' ), 10, 3 );

		// Anything that changes every page.
		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_sidebars_widgets', 'update_option_page_on_front', 'update_option_blogname', 'update_option_permalink_structure' ) as $hook ) {
			add_action( $hook, array( self::class, 'everything' ) );
		}

		// Stock and price changes alter product and shop markup.
		add_action( 'woocommerce_product_set_stock', array( self::class, 'product' ) );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( self::class, 'product' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( self::class, 'product' ) );
	}

	/**
	 * Clear the pages a post appears on.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    Post object, when the hook supplies one.
	 * @return void
	 */
	public static function post( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		$post    = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}

		self::urls( self::affected_by_post( $post ) );
	}

	/**
	 * Every URL a change to this post could alter.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function affected_by_post( \WP_Post $post ): array {
		$urls = array( home_url( '/' ) );

		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$urls[] = $permalink;
		}

		// The page that lists this post type.
		if ( 'post' === $post->post_type ) {
			$blog = (int) get_option( 'page_for_posts' );
			if ( $blog ) {
				$urls[] = (string) get_permalink( $blog );
			}
			$urls[] = (string) get_author_posts_url( (int) $post->post_author );
			$urls[] = (string) get_year_link( (int) get_the_date( 'Y', $post ) );
			$urls[] = (string) get_month_link( (int) get_the_date( 'Y', $post ), (int) get_the_date( 'm', $post ) );
		} else {
			$archive = get_post_type_archive_link( $post->post_type );
			if ( $archive ) {
				$urls[] = $archive;
			}
		}

		// Every taxonomy term the post belongs to.
		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		// WooCommerce keeps its shop on a dedicated page.
		if ( 'product' === $post->post_type && function_exists( 'wc_get_page_id' ) ) {
			$shop = (int) wc_get_page_id( 'shop' );
			if ( $shop > 0 ) {
				$urls[] = (string) get_permalink( $shop );
			}
		}

		/**
		 * Filter the URLs cleared when a post changes.
		 *
		 * @param array    $urls URLs.
		 * @param \WP_Post $post Post.
		 */
		return array_values( array_unique( array_filter( (array) apply_filters( 'smao_purge_urls', $urls, $post ) ) ) );
	}

	/**
	 * Clear the post a comment belongs to.
	 *
	 * @param int   $comment_id Comment ID.
	 * @param mixed $approved   Approval flag, when supplied.
	 * @param mixed $data       Comment data, when supplied.
	 * @return void
	 */
	public static function comment( $comment_id, $approved = null, $data = null ): void {
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment ) {
			return;
		}
		$post = get_post( (int) $comment->comment_post_ID );
		if ( $post instanceof \WP_Post ) {
			self::urls( array( (string) get_permalink( $post ), home_url( '/' ) ) );
		}
	}

	/**
	 * Clear a taxonomy archive.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			self::urls( array( $link, home_url( '/' ) ) );
		}
	}

	/**
	 * Clear a product and the pages that list it.
	 *
	 * @param mixed $product Product or ID.
	 * @return void
	 */
	public static function product( $product ): void {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;
		$post = get_post( $id );
		if ( $post instanceof \WP_Post ) {
			self::urls( self::affected_by_post( $post ) );
		}
	}

	/**
	 * Clear the whole cache, for changes that alter every page.
	 *
	 * @return void
	 */
	public static function everything(): void {
		Cache::flush();
		Warm::queue( array( home_url( '/' ) ) );
	}

	/**
	 * Clear a list of URLs and queue them for rebuilding.
	 *
	 * @param array $urls URLs.
	 * @return int Number cleared.
	 */
	public static function urls( array $urls ): int {
		$cleared = 0;
		foreach ( $urls as $url ) {
			if ( Cache::forget( (string) $url ) ) {
				++$cleared;
			}
		}
		if ( $urls ) {
			Warm::queue( $urls );
			update_option( 'smao_cache_purged', time(), false );
		}
		return $cleared;
	}
}
