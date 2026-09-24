<?php
/**
 * Only changes that could add a media reference make a scan stale.
 *
 * On a live Elementor site every visit rewrote builder caches and plugin
 * options, each of which marked the scan stale, so the scan reported that it
 * could not finish every single time.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Plugin;

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal post stand-in.
	 */
	final class WP_Post {
		/** @var string */
		public $post_type = 'post';
		/** @var string */
		public $post_status = 'publish';
		/** @var string */
		public $post_content = '';
		/** @var string */
		public $post_excerpt = '';
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * Stub serialisation.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	function maybe_serialize( $data ) {
		return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}
}

/**
 * Change relevance.
 */
final class ScanStalenessTest extends TestCase {

	/**
	 * Media URLs and image classes are references; other uploads are not.
	 *
	 * @return void
	 */
	public function test_references_media(): void {
		$this->assertTrue( Plugin::references_media( '<img class="wp-image-42" src="x">' ) );
		$this->assertTrue( Plugin::references_media( 'https://site.test/wp-content/uploads/2024/08/photo-300x200.jpg' ) );
		$this->assertTrue( Plugin::references_media( '{"url":"https:\/\/site.test\/wp-content\/uploads\/2026\/02\/1.png","id":88}' ) );
		$this->assertTrue( Plugin::references_media( '/wp-content/uploads/2023/05/brochure.pdf' ) );
		$this->assertTrue( Plugin::references_media( array( 'bg' => '/wp-content/uploads/a.webp' ) ) );

		// Builder CSS files live in uploads too, but they are not media.
		$this->assertFalse( Plugin::references_media( 'https://site.test/wp-content/uploads/elementor/css/post-61.css?ver=1' ) );
		$this->assertFalse( Plugin::references_media( 'a:2:{s:4:"time";i:1790237804;s:5:"count";i:3;}' ) );
		$this->assertFalse( Plugin::references_media( '' ) );
	}

	/**
	 * A number is a reference only under an image-like name.
	 *
	 * @return void
	 */
	public function test_bare_ids_need_an_image_like_key(): void {
		$this->assertTrue( Plugin::references_media( '42', '_thumbnail_id' ) );
		$this->assertTrue( Plugin::references_media( '12,15,19', '_product_image_gallery' ) );
		$this->assertTrue( Plugin::references_media( '7', 'hero_background' ) );
		$this->assertFalse( Plugin::references_media( '42', '_price' ) );
		$this->assertFalse( Plugin::references_media( '1790237804', 'last_run' ) );
	}

	/**
	 * Builder caches never make a scan stale.
	 *
	 * @return void
	 */
	public function test_derived_meta_is_ignored(): void {
		$css = '/wp-content/uploads/2024/08/photo.jpg';
		foreach ( array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_smao_alternates', '_wp_attachment_metadata', '_wp_attached_file', '_oembed_abc', '_edit_lock' ) as $key ) {
			$this->assertFalse( Plugin::relevant_meta( $key, $css ), $key . ' must not mark the scan stale' );
		}
		$this->assertTrue( Plugin::relevant_meta( '_elementor_data', '[{"settings":{"image":{"url":"' . $css . '","id":5}}}]' ) );
		$this->assertTrue( Plugin::relevant_meta( '_thumbnail_id', 42 ) );
		$this->assertFalse( Plugin::relevant_meta( '_elementor_data', '[{"settings":{"title":"Hello"}}]' ) );
	}

	/**
	 * Options follow the same rule, with the known ID holders always counted.
	 *
	 * @return void
	 */
	public function test_options(): void {
		$this->assertFalse( Plugin::relevant_option( '_transient_feed_x', '/wp-content/uploads/a.jpg' ) );
		$this->assertFalse( Plugin::relevant_option( 'smao_scan', '/wp-content/uploads/a.jpg' ) );
		$this->assertFalse( Plugin::relevant_option( 'rewrite_rules', '/wp-content/uploads/a.jpg' ) );
		$this->assertFalse( Plugin::relevant_option( 'elementor_log', array( 'count' => 3 ) ) );
		$this->assertTrue( Plugin::relevant_option( 'site_icon', '91' ) );
		$this->assertTrue( Plugin::relevant_option( 'theme_mods_astra', array( 'custom_logo' => 12 ) ) );
		$this->assertTrue( Plugin::relevant_option( 'widget_media_image', array( 2 => array( 'attachment_id' => 3 ) ) ) );
		$this->assertTrue( Plugin::relevant_option( 'some_plugin_banner', '/wp-content/uploads/b.png' ) );
	}

	/**
	 * Only real content saves count.
	 *
	 * @return void
	 */
	public function test_posts(): void {
		$post               = new WP_Post();
		$post->post_content = '<!-- wp:image {"id":9} --><img class="wp-image-9" src="/wp-content/uploads/x.jpg">';
		$this->assertTrue( Plugin::relevant_post( $post ) );

		$plain               = new WP_Post();
		$plain->post_content = 'Just words.';
		$this->assertFalse( Plugin::relevant_post( $plain ) );

		foreach ( array( 'revision', 'oembed_cache', 'customize_changeset', 'attachment' ) as $type ) {
			$other            = clone $post;
			$other->post_type = $type;
			$this->assertFalse( Plugin::relevant_post( $other ), $type );
		}
		$draft              = clone $post;
		$draft->post_status = 'auto-draft';
		$this->assertFalse( Plugin::relevant_post( $draft ) );
	}

	/**
	 * Long values are read in slices, not abandoned.
	 *
	 * @return void
	 */
	public function test_long_values_are_read_in_slices(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/engine/class-scanner.php' );
		$this->assertStringContainsString( 'self::match_rest(', $code );
		$this->assertStringNotContainsString( "> 262144 ) {\n\t\t\t\t\t\t\t\$state['incomplete'] = true;", $code );
		// A change during the scan triggers a recheck, not a blanket downgrade.
		$this->assertStringContainsString( "'recheck' === \$state['phase']", $code );
		$this->assertStringNotContainsString( 'Site changed during scan. Rescan during a quiet period.', $code );
	}
}
