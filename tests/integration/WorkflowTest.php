<?php
use PHPUnit\Framework\TestCase;
use SMAO\Database;
use SMAO\Scanner;
use SMAO\Media;
use SMAO\Vault;
use SMAO\Optimizer;
use SMAO\Settings;
use SMAO\Plugin;
use SMAO\Admin;

final class WorkflowTest extends TestCase {
	private array $attachments = array();
	private array $posts = array();
	private array $options = array();
	private array $users = array();
	private int $admin;

	protected function setUp(): void {
		global $wpdb;
		// Remove this suite's oversized-source fixture after an interrupted test run.
		delete_option( 'test_big_record' );
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		$this->admin = $admins[0]->ID;
		wp_set_current_user( $this->admin );
		update_option( 'wp_attachment_pages_enabled', false );
		update_option( 'smao_settings', array_merge( Settings::defaults(), array( 'coverage_reviewed' => true, 'batch' => 200 ) ) );
		delete_option( 'smao_scan' );
		foreach ( array( 'media', 'tokens', 'evidence', 'jobs' ) as $table ) { $wpdb->query( 'DELETE FROM ' . Database::table( $table ) ); }
	}

	protected function tearDown(): void {
		wp_set_current_user( $this->admin );
		foreach ( $this->posts as $id ) { wp_delete_post( $id, true ); }
		foreach ( $this->options as $option ) { delete_option( $option ); }
		foreach ( $this->users as $id ) { wp_delete_user( $id ); }
		foreach ( $this->attachments as $id ) {
			if ( Vault::record( $id ) ) { Vault::restore( $id ); }
			wp_delete_attachment( $id, true );
		}
		delete_option( 'site_icon' );
	}

	public function test_live_progress_and_runtime_controls(): void {
		$id = $this->attachment( true );
		$state = Scanner::start();
		$this->assertGreaterThanOrEqual( 1, $state['total'] );
		$this->assertNotEmpty( $state['source_totals'] );
		$epoch = get_option( 'smao_epoch' );
		$settings = Settings::runtime( array( 'batch' => 1, 'source_batch' => 1000, 'time_budget' => 0, 'poll_interval' => 0, 'browser_worker' => false ) );
		$this->assertSame( 100, $settings['source_batch'] );
		$this->assertSame( 1, $settings['time_budget'] );
		$this->assertSame( 1, $settings['poll_interval'] );
		$this->assertFalse( $settings['browser_worker'] );
		$this->assertSame( $epoch, get_option( 'smao_epoch' ) );
		Scanner::tick();
		$live = \SMAO\Live::snapshot();
		$this->assertSame( 1, $live['summary']['discovered'] );
		$this->assertCount( 1, $live['recent'] );
		$this->assertNotEmpty( Database::state()['activity'] );
		while ( 'inventory' === Database::state()['phase'] ) { Scanner::tick(); }
		$report = \SMAO\Live::report( array( 'mime' => 'image/', 'search' => (string) $id, 'paged' => 999 ) );
		$this->assertSame( 1, $report['total'], wp_json_encode( $live['recent'] ) );
		$this->assertSame( 1, $report['page'] );
		$this->assertSame( $id, (int) $report['rows'][0]['attachment_id'] );
		$this->assertSame( 0, (int) $report['rows'][0]['optimized'] );
		$this->assertSame( 0, \SMAO\Live::report( array( 'search' => "%' OR 1=1 --" ) )['total'] );
		Scanner::control( 'pause' );
		$before = Database::state(); Scanner::tick();
		$this->assertSame( $before, Database::state() );
		Scanner::control( 'resume' );
		for ( $i = 0; $i < 500 && 'running' === Database::state()['state']; ++$i ) { Scanner::tick(); }
		$this->assertSame( 'complete', Database::state()['state'] );
		$this->assertSame( Database::state()['processed'], Database::state()['classified'] );
	}

	public function test_large_source_scan_finishes_in_bounded_requests_and_reports_cleanup(): void {
		global $wpdb;
		$id = $this->attachment();
		$key = 'test_throughput_' . bin2hex( random_bytes( 4 ) ) . '_';
		for ( $chunk = 0; $chunk < 60; ++$chunk ) {
			$values = array();
			for ( $i = 0; $i < 100; ++$i ) {
				$name = $key . ( $chunk * 100 + $i ); $this->options[] = $name;
				$values[] = $wpdb->prepare( '(%s,%s,%s)', $name, str_repeat( 'unrelated text ', 20 ), 'off' );
			}
			$wpdb->query( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES " . implode( ',', $values ) );
		}
		Scanner::start(); $ticks = 0;
		while ( 'running' === Database::state()['state'] && $ticks < 40 ) { Scanner::tick(); ++$ticks; }
		$this->assertSame( 'complete', Database::state()['state'] );
		$this->assertGreaterThanOrEqual( 6000, Database::state()['records'] );
		$this->assertLessThan( 30, $ticks, '6,000 source records must not require hundreds of cron visits.' );
		$this->assertNotEmpty( Database::state()['activity'] );
		$this->assertTrue( \SMAO\Live::cleanup()['ready'] );
		$epoch = get_option( 'smao_epoch' );
		update_post_meta( $id, '_edit_lock', time() . ':1' );
		$this->assertSame( $epoch, get_option( 'smao_epoch' ) );
		update_post_meta( $id, 'custom_reference', 'changed' );
		$this->assertNotSame( $epoch, get_option( 'smao_epoch' ) );
		$this->assertFalse( \SMAO\Live::cleanup()['ready'] );
		$old = Database::state()['id']; Scanner::control( 'restart' );
		$this->assertNotSame( $old, Database::state()['id'] );
		$this->assertSame( 0, Database::state()['processed'] );
	}

	public function test_rest_tick_advances_and_cleanup_setup_refuses_public_paths(): void {
		$id = $this->attachment(); Scanner::start();
		$request = new \WP_REST_Request( 'POST', '/smao/v1/control' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'command' => 'tick' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThan( 0, $response->get_data()['scan']['processed'] );
		$this->expectException( \RuntimeException::class );
		Vault::root( false, ABSPATH );
	}

	private function attachment( bool $image = false, string $format = 'jpg' ): int {
		$name = 'smao-test-' . bin2hex( random_bytes( 8 ) ) . ( $image ? '.' . $format : '.pdf' );
		$upload = wp_upload_bits( $name, null, '%PDF-1.4 isolated test data' );
		$this->assertEmpty( $upload['error'] );
		if ( $image ) {
			$bitmap = imagecreatetruecolor( 800, 600 );
			$step = 'png' === $format ? 1 : 3;
			for ( $y = 0; $y < 600; $y += $step ) { for ( $x = 0; $x < 800; $x += $step ) { imagefilledrectangle( $bitmap, $x, $y, $x + $step - 1, $y + $step - 1, mt_rand( 0, 0xffffff ) ); } }
			if ( 'png' === $format ) { imagepng( $bitmap, $upload['file'] ); }
			else { imagejpeg( $bitmap, $upload['file'], 100 ); }
			imagedestroy( $bitmap );
		}
		$id = wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => $image ? ( 'png' === $format ? 'image/png' : 'image/jpeg' ) : 'application/pdf', 'post_status' => 'inherit', 'post_date' => '2020-01-01 00:00:00', 'post_date_gmt' => '2020-01-01 00:00:00', 'import_id' => random_int( 8000000, 9000000 ) ), $upload['file'] );
		$this->assertIsInt( $id );
		$this->attachments[] = $id;
		if ( $image ) { wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) ); }
		return $id;
	}

	private function post( string $content ): int {
		$id = wp_insert_post( array( 'post_title' => 'SMAO test', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content ) );
		$this->posts[] = $id;
		return $id;
	}

	private function scan(): void {
		Database::lock( static fn() => Scanner::start() );
		for ( $i = 0; $i < 500 && 'running' === Database::state()['state']; ++$i ) { Database::lock( static fn() => Scanner::tick() ); }
		$this->assertSame( 'complete', Database::state()['state'], wp_json_encode( Database::state() ) );
	}

	private function status( int $id ): string {
		global $wpdb;
		$table = Database::table( 'media' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE attachment_id=%d", $id ) );
	}

	public function test_thumbnail_reference_keeps_whole_group_used(): void {
		$id = $this->attachment( true );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $meta['sizes'] );
		$url = wp_get_attachment_image_url( $id, 'thumbnail' );
		$this->post( '<img src="' . $url . '">' );
		$this->scan();
		$this->assertSame( 'used', $this->status( $id ) );
	}

	public function test_builder_and_custom_fields_prevent_unused(): void {
		$id = $this->attachment();
		$post = $this->post( '' );
		update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( array( 'settings' => array( 'file' => array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) ) ) ) ) ) );
		$this->scan();
		$this->assertSame( 'used', $this->status( $id ) );
		delete_post_meta( $post, '_elementor_data' );
		update_post_meta( $post, 'acf_file', $id );
		$this->scan();
		$this->assertSame( 'possible', $this->status( $id ) );
	}

	public function test_download_is_reported(): void {
		$id = $this->attachment();
		$this->post( '<a download href="' . wp_get_attachment_url( $id ) . '">Download</a>' );
		$this->scan();
		$this->assertSame( 'download', $this->status( $id ) );
	}

	public function test_unreviewed_coverage_never_labels_unused(): void {
		$id = $this->attachment();
		update_option( 'smao_settings', Settings::defaults() );
		$this->scan();
		$this->assertSame( 'possible', $this->status( $id ) );
	}

	public function test_unused_quarantine_and_hash_verified_restore(): void {
		$id = $this->attachment();
		$path = get_attached_file( $id );
		$hash = hash_file( 'sha256', $path );
		$this->scan();
		$this->assertSame( 'unused', $this->status( $id ) );
		Database::lock( static fn() => Vault::quarantine( $id ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertSame( 'quarantined', Vault::record( $id )['state'] );
		$this->assertFalse( wp_delete_attachment( $id, true ), 'Core deletion must not discard recovery context.' );
		Database::lock( static fn() => Vault::restore( $id ) );
		$this->assertSame( $hash, hash_file( 'sha256', $path ) );
		$this->assertNull( Vault::record( $id ) );
	}

	public function test_changed_site_blocks_quarantine(): void {
		$id = $this->attachment();
		$this->scan();
		$this->post( '<a href="' . wp_get_attachment_url( $id ) . '">File</a>' );
		$this->expectException( RuntimeException::class );
		Vault::quarantine( $id );
	}

	public function test_retention_blocks_early_permanent_purge(): void {
		$id = $this->attachment();
		$this->scan();
		Vault::quarantine( $id );
		$this->expectException( RuntimeException::class );
		Vault::purge( $id );
	}

	public function test_recent_upload_and_site_icon_protected(): void {
		$id = $this->attachment();
		wp_update_post( array( 'ID' => $id, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
		$this->scan();
		$this->assertSame( 'possible', $this->status( $id ) );
		update_option( 'site_icon', $id );
		$this->expectException( RuntimeException::class );
		Media::assert_local( $id );
	}

	public function test_remote_url_is_report_only_even_if_local_exists(): void {
		$id = $this->attachment();
		$filter = static fn( $url, $candidate ) => $candidate === $id ? 'https://cdn.example.test/a.pdf' : $url;
		add_filter( 'wp_get_attachment_url', $filter, 10, 2 );
		try {
			$this->scan();
			$this->assertSame( 'external', $this->status( $id ) );
			$this->expectException( RuntimeException::class );
			Media::assert_local( $id );
		} finally { remove_filter( 'wp_get_attachment_url', $filter, 10 ); }
	}

	public function test_oversized_record_prevents_unused_conclusions(): void {
		$id = $this->attachment();
		$this->options[] = 'smao_test_external_big_record';
		// Non-plugin option is necessary: plugin options are deliberately excluded.
		$this->options[] = 'test_big_record';
		add_option( 'test_big_record', str_repeat( 'x', 262145 ), '', false );
		$this->scan();
		$this->assertTrue( Database::state()['incomplete'] );
		$this->assertSame( 'possible', $this->status( $id ) );
	}

	public function test_shared_path_blocks_file_operations(): void {
		$id = $this->attachment();
		$other = $this->attachment();
		$original = get_post_meta( $other, '_wp_attached_file', true );
		update_post_meta( $other, '_wp_attachment_metadata', array( 'file' => get_post_meta( $id, '_wp_attached_file', true ) ) );
		try { $this->expectException( RuntimeException::class ); Media::assert_local( $id ); }
		finally { delete_post_meta( $other, '_wp_attachment_metadata' ); update_post_meta( $other, '_wp_attached_file', $original ); }
	}

	public function test_interrupted_quarantine_can_restore_partial_group(): void {
		$id = $this->attachment( true );
		$group = Media::group( $id );
		$path = Media::path( $group['main'] );
		$hash = hash_file( 'sha256', $path );
		Vault::prepare( $id, 'quarantine', $group );
		unlink( $path );
		Vault::restore( $id );
		$this->assertSame( $hash, hash_file( 'sha256', $path ) );
	}

	public function test_restore_refuses_to_overwrite_newer_content(): void {
		$id = $this->attachment();
		$group = Media::group( $id );
		$path = Media::path( $group['main'] );
		$original = file_get_contents( $path );
		Vault::prepare( $id, 'quarantine', $group );
		file_put_contents( $path, 'newer unrelated file' );
		try { $this->expectException( RuntimeException::class ); Vault::restore( $id ); }
		finally { file_put_contents( $path, $original ); }
	}

	public function test_lossy_optimization_and_original_restore(): void {
		$id = $this->attachment( true );
		$path = get_attached_file( $id );
		$before = hash_file( 'sha256', $path );
		$metadata = wp_get_attachment_metadata( $id );
		update_option( 'smao_settings', array_merge( Settings::get(), array( 'compression' => 'lossy', 'strip_exif' => true, 'quality' => 75, 'alternate' => 'webp' ) ) );
		Database::lock( static fn() => Optimizer::optimize( $id ) );
		$this->assertNotSame( $before, hash_file( 'sha256', $path ) );
		$this->assertGreaterThan( 0, get_post_meta( $id, '_smao_optimized', true )['saved'] );
		$this->assertIsArray( get_post_meta( $id, '_smao_alternates', true ) );
		$this->assertSame( 'image/jpeg', wp_get_image_mime( $path ) );
		Vault::restore( $id );
		$this->assertSame( $before, hash_file( 'sha256', $path ) );
		$this->assertEquals( $metadata, wp_get_attachment_metadata( $id ) );
	}

	public function test_unsupported_lossless_jpeg_is_rolled_back(): void {
		$id = $this->attachment( true );
		$path = get_attached_file( $id );
		$hash = hash_file( 'sha256', $path );
		try { Optimizer::optimize( $id ); $this->fail( 'Lossless JPEG should not silently become lossy.' ); }
		catch ( RuntimeException $e ) { $this->assertStringContainsString( 'Lossless JPEG', $e->getMessage() ); }
		$this->assertSame( $hash, hash_file( 'sha256', $path ) );
		$this->assertNull( Vault::record( $id ) );
	}

	public function test_png_alternate_delivery_keeps_original_fallback(): void {
		$id = $this->attachment( true, 'png' );
		$path = get_attached_file( $id );
		$hash = hash_file( 'sha256', $path );
		update_option( 'smao_settings', array_merge( Settings::get(), array( 'compression' => 'lossy', 'strip_exif' => true, 'quality' => 75, 'alternate' => 'webp', 'delivery' => true ) ) );
		Optimizer::optimize( $id );
		$alternates = get_post_meta( $id, '_smao_alternates', true );
		$this->assertNotEmpty( $alternates );
		$markup = wp_get_attachment_image( $id, 'full' );
		$this->assertStringContainsString( '<picture>', $markup );
		$this->assertStringContainsString( 'image/webp', $markup );
		$this->assertStringContainsString( '.png', $markup );
		$this->assertSame( 'image/png', wp_get_image_mime( $path ) );
		Vault::restore( $id );
		$this->assertSame( $hash, hash_file( 'sha256', $path ) );
		foreach ( $alternates as $alternate ) { $this->assertFileDoesNotExist( Media::path( $alternate['file'], false ) ); }
	}

	public function test_all_admin_screens_render_without_warnings(): void {
		foreach ( array( 'dashboard', 'scanner', 'review', 'optimizer', 'performance', 'quarantine', 'log', 'settings', 'system' ) as $screen ) {
			$_GET = array( 'page' => 'smao-' . $screen );
			ob_start();
			Admin::render();
			$html = ob_get_clean();
			$this->assertStringContainsString( 'Smart Media Auditor', $html );
			$this->assertStringNotContainsString( 'notice-error', $html );
		}
		$_GET = array();
	}

	public function test_rest_requires_nonce_and_administrator(): void {
		$request = new WP_REST_Request( 'POST', '/smao/v1/control' );
		$this->assertInstanceOf( WP_Error::class, Admin::permission( $request ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertTrue( Admin::permission( $request ) );
		$user = wp_insert_user( array( 'user_login' => 'smao-test-' . bin2hex( random_bytes( 6 ) ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
		$this->users[] = $user;
		wp_set_current_user( $user );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertInstanceOf( WP_Error::class, Admin::permission( $request ) );
	}

	public function test_pause_resume_cancel_and_job_reauthorization(): void {
		Scanner::start();
		Scanner::control( 'pause' );
		Scanner::tick();
		$this->assertSame( 0, Database::state()['cursor'] );
		Scanner::control( 'resume' );
		$this->assertSame( 'running', Database::state()['state'] );
		Scanner::control( 'cancel' );
		$this->assertSame( 'cancelled', Database::state()['state'] );
		$id = $this->attachment( true );
		Plugin::enqueue( 'optimize', array( $id ) );
		global $wpdb;
		$wpdb->update( Database::table( 'jobs' ), array( 'user_id' => 0 ), array( 'attachment_id' => $id ) );
		Plugin::tick();
		$job = $wpdb->get_row( 'SELECT * FROM ' . Database::table( 'jobs' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( 'failed', $job['state'] );
		$this->assertStringContainsString( 'permission', $job['message'] );
		$this->assertNull( Vault::record( $id ) );
	}

	public function test_rest_action_rejects_missing_confirmation_and_structured_ids(): void {
		$id = $this->attachment();
		$request = new WP_REST_Request( 'POST', '/smao/v1/action' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'action' => 'quarantine', 'ids' => array( $id ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( 'confirmation', $response->get_data()['message'] );
		$request->set_body_params( array( 'action' => 'quarantine', 'ids' => array( array( $id ) ), 'confirmation' => 'QUARANTINE' ) );
		$this->assertSame( 409, rest_do_request( $request )->get_status() );
		$this->assertFileExists( get_attached_file( $id ) );
		$this->assertNull( Vault::record( $id ) );
	}

	public function test_manual_purge_after_retention_keeps_attachment_tombstone(): void {
		$id = $this->attachment();
		$this->scan();
		Vault::quarantine( $id );
		$record = Vault::record( $id );
		$record['created'] = time() - 100 * DAY_IN_SECONDS;
		Vault::save( $record );
		Vault::purge( $id );
		$this->assertNull( Vault::record( $id ) );
		$this->assertSame( 'attachment', get_post_type( $id ) );
		$this->assertNotEmpty( get_post_meta( $id, '_smao_purged', true ) );
	}

	public function test_purge_rejects_new_reference_after_quarantine(): void {
		$id = $this->attachment();
		$this->scan();
		Vault::quarantine( $id );
		$record = Vault::record( $id );
		$record['created'] = time() - 100 * DAY_IN_SECONDS;
		Vault::save( $record );
		$this->post( '<a href="' . wp_get_attachment_url( $id ) . '">New reference</a>' );
		$this->expectException( RuntimeException::class );
		Vault::purge( $id );
	}

	public function test_cleanup_preserves_queued_work_and_recovery_records(): void {
		global $wpdb;
		$id = $this->attachment();
		Vault::prepare( $id, 'quarantine', Media::group( $id ) );
		$old = gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
		foreach ( array( 'complete', 'queued' ) as $state ) {
			$wpdb->insert( Database::table( 'jobs' ), array( 'attachment_id' => $id, 'user_id' => $this->admin, 'action' => 'optimize', 'state' => $state, 'created' => $old, 'message' => '' ) );
		}
		$this->assertGreaterThanOrEqual( 1, Plugin::cleanup() );
		$jobs = $wpdb->get_col( 'SELECT state FROM ' . Database::table( 'jobs' ) );
		$this->assertSame( array( 'queued' ), $jobs );
		$this->assertNotNull( Vault::record( $id ) );
	}
}
