<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: Cleanup Orphan Images
 * Description: Finds and deletes orphan image files from the uploads directory that are not registered in WordPress.
 * Version: 1.8.4
 * Author: Manly Electronics
 * Author URI: https://manlyelectronics.com.au
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package Cleanup_Orphan_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'CLEANUP_ORPHAN_IMAGES_VERSION', '1.8.4' );

// Add Settings link on Plugins page.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_url  = admin_url( 'upload.php?page=cleanup-orphan-images' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">Settings</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

/**
 * Main plugin class for Cleanup Orphan Images.
 *
 * @since 1.0.0
 */
class Cleanup_Images_Plugin {

	/**
	 * Single instance of this class.
	 *
	 * @var Cleanup_Images_Plugin|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance of this class.
	 *
	 * @return Cleanup_Images_Plugin The singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Sets up action hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_cleanup_images_delete_orphans', array( $this, 'handle_orphan_deletion' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'upload_page_cleanup-orphan-images' !== $hook ) {
			return;
		}
		// Inline-only script handle for the select-all checkbox logic.
		wp_register_script( 'cleanup-images-admin', false, array( 'jquery' ), CLEANUP_ORPHAN_IMAGES_VERSION, true );
		wp_enqueue_script( 'cleanup-images-admin' );

		// Inline script for orphan files select all.
		$orphan_script = "
			jQuery(document).ready(function($) {
				var \$checkboxes = $('input[name=\"orphan_files_to_delete[]\"]');
				var \$selectAll = $('#cb-select-all-orphans');

				if (\$checkboxes.length === 0) return;

				// Select all functionality
				\$selectAll.on('click', function() {
					\$checkboxes.prop('checked', $(this).prop('checked'));
					updateSelectAllState();
				});

				\$checkboxes.on('change', function() {
					updateSelectAllState();
				});

				function updateSelectAllState() {
					var totalCheckboxes = \$checkboxes.length;
					var checkedCheckboxes = \$checkboxes.filter(':checked').length;

					if (checkedCheckboxes === 0) {
						\$selectAll.prop('indeterminate', false);
						\$selectAll.prop('checked', false);
					} else if (checkedCheckboxes === totalCheckboxes) {
						\$selectAll.prop('indeterminate', false);
						\$selectAll.prop('checked', true);
					} else {
						\$selectAll.prop('indeterminate', true);
					}
				}
			});
		";
		wp_add_inline_script( 'cleanup-images-admin', $orphan_script );
	}

	/**
	 * Add the admin menu page under Media.
	 */
	public function add_admin_menu() {
		add_media_page(
			'Cleanup Orphan Images',
			'Cleanup Orphan Images',
			'manage_options',
			'cleanup-orphan-images',
			array( $this, 'display_admin_page' )
		);
	}

	/**
	 * Display the main admin page.
	 */
	public function display_admin_page() {
		// Check for notice parameter in URL (read-only display, data is sanitized and escaped).
		$notice_html = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display, no state change.
		if ( isset( $_GET['notice'] ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$notice_data = json_decode( base64_decode( urldecode( sanitize_text_field( wp_unslash( $_GET['notice'] ) ) ) ), true );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( $notice_data && isset( $notice_data['message'] ) && isset( $notice_data['type'] ) ) {
				$type_class   = 'error' === $notice_data['type'] ? 'notice-error' : 'notice-success';
				$notice_html  = '<div class="notice ' . esc_attr( $type_class ) . ' is-dismissible">';
				$notice_html .= '<p>' . esc_html( $notice_data['message'] ) . '</p>';
				$notice_html .= '</div>';
			}
		}

		?>
		<div class="wrap">
			<h1>Cleanup Orphan Images</h1>
			<?php echo wp_kses_post( $notice_html ); ?>
			<?php $this->display_orphan_scanner(); ?>
		</div>
		<?php
	}

	/**
	 * Display the orphan file scanner section.
	 */
	public function display_orphan_scanner() {
		// Check if scan was requested.
		$scan_results  = null;
		$scan_executed = false;
		if ( isset( $_POST['scan_orphan_files'] ) && isset( $_POST['cleanup_images_scan_orphans_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cleanup_images_scan_orphans_nonce'] ) ), 'cleanup_images_scan_orphans_action' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Unauthorized access' );
			}
			$scan_results  = $this->scan_for_orphan_files();
			$scan_executed = true;
		}
		?>
		<div class="orphan-scanner-wrap" style="margin-top: 1em;">
			
			<div class="notice notice-info inline" style="margin: 15px 0;">
				<p><strong>What are Orphan Images?</strong></p>
				<p>Those are physical image files that are invisible in the Media Library because they are not registered in the WordPress database, typically left by failed uploads, plugins, FTP transfers, or database restores.</p>
				<p><strong style="color: #d63638;">Warning:</strong> Some files may still be used by themes or plugins that reference them directly by URL. Always review before deleting and consider backing up your uploads folder first.</p>
			</div>
			<p><strong>Supported formats:</strong></p>
			<ul style="margin-top: 5px; margin-left: 20px;">
				<li><strong>Images:</strong> JPG, JPEG, PNG, GIF, WebP, BMP, TIFF, SVG, ICO</li>
				<li><strong>Documents:</strong> PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, ODP, TXT, RTF, CSV</li>
				<li><strong>Audio:</strong> MP3, WAV, OGG, FLAC, AAC, M4A, WMA</li>
				<li><strong>Video:</strong> MP4, MOV, AVI, WMV, MKV, WebM, FLV, M4V, MPEG, MPG</li>
				<li><strong>Archives:</strong> ZIP, RAR, 7Z, TAR, GZ</li>
			</ul>
			<p><strong>Scanning time</strong> depends on the number of files in your uploads directory and the size of your Media Library. Large sites may take several minutes.</p>
			<?php $scan_upload_dir = wp_get_upload_dir(); ?>
			<p><strong>Scanned folder:</strong> <code><?php echo esc_html( trailingslashit( $scan_upload_dir['basedir'] ) ); ?></code> (including all subfolders)</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'upload.php?page=cleanup-orphan-images' ) ); ?>">
				<?php wp_nonce_field( 'cleanup_images_scan_orphans_action', 'cleanup_images_scan_orphans_nonce' ); ?>
				<p>
					<button type="submit" name="scan_orphan_files" class="button button-primary">Scan for Orphan Files</button>
				</p>
			</form>

			<?php if ( $scan_executed ) : ?>
				<h3>Scan Results</h3>

				<?php if ( empty( $scan_results['orphan_files'] ) ) : ?>
					<div class="notice notice-success inline"><p>No orphan files found.</p></div>
				<?php else : ?>
					<?php
					$batch_limit   = 1000;
					$total_orphans = count( $scan_results['orphan_files'] );
					$batch_files   = array_slice( $scan_results['orphan_files'], 0, $batch_limit );
					?>
					<p>Found <?php echo absint( $total_orphans ); ?> orphan files:</p>

					<?php if ( $total_orphans > $batch_limit ) : ?>
						<div class="notice notice-warning inline"><p>Showing the first <?php echo absint( $batch_limit ); ?> of <?php echo absint( $total_orphans ); ?> orphan files. Delete this batch and scan again to process the next one.</p></div>
					<?php endif; ?>

					<?php
					// Add warning for large numbers of files.
					$max_input_vars = (int) ini_get( 'max_input_vars' );
					if ( count( $batch_files ) > ( $max_input_vars - 50 ) ) :
						?>
						<div class="notice notice-warning inline"><p>
							<strong>Warning:</strong>
							You have <?php echo absint( count( $batch_files ) ); ?> files, but your server is limited to processing <?php echo absint( $max_input_vars ); ?> form fields at once. Consider selecting files in smaller batches to avoid incomplete deletions.
						</p></div>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cleanup_images_delete_orphans">
						<?php wp_nonce_field( 'cleanup_images_delete_orphans_action', '_wpnonce_delete_orphans' ); ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th class="check-column"><input type="checkbox" id="cb-select-all-orphans"></th>
									<th>Image File Path</th>
									<th>File Size</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $batch_files as $file_path ) : ?>
								<?php $file_size = file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : 'Unknown'; ?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="orphan_files_to_delete[]" value="<?php echo esc_attr( $file_path ); ?>"></th>
									<td><?php echo esc_html( $file_path ); ?></td>
									<td><?php echo esc_html( $file_size ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php submit_button( 'Delete Selected Orphan Images', 'delete', 'submit-delete-orphans', true, array( 'class' => 'button button-danger' ) ); ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Scan uploads directory for orphan files.
	 *
	 * @return array Array containing orphan_files and all_files.
	 */
	private function scan_for_orphan_files() {
		$upload_dir       = wp_upload_dir();
		$upload_path      = trailingslashit( $upload_dir['basedir'] );
		$files_in_uploads = $this->get_all_media_files_from_directory( $upload_path );

		// Pre-load ALL media library files in a single query for O(1) lookups.
		$media_library_files = $this->get_all_media_library_files();

		$orphan_files = array();
		foreach ( $files_in_uploads as $file_path ) {
			if ( ! $this->is_file_in_media_library_fast( $file_path, $media_library_files ) ) {
				$orphan_files[] = $file_path;
			}
		}
		return array(
			'orphan_files' => $orphan_files,
			'all_files'    => $files_in_uploads,
		);
	}

	/**
	 * Get all files registered in the media library in a single query.
	 * Returns an array with relative paths as keys for O(1) lookup.
	 */
	private function get_all_media_library_files() {
		global $wpdb;

		$files = array();

		// Get all attached files in one query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);

		foreach ( $results as $relative_path ) {
			// Store the full relative path.
			$files[ $relative_path ] = true;
		}

		// Also get all thumbnail/size variations from _wp_attachment_metadata.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$metadata_results = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'"
		);

		foreach ( $metadata_results as $serialized_meta ) {
			$meta = maybe_unserialize( $serialized_meta );
			if ( ! is_array( $meta ) ) {
				continue;
			}

			// Get the directory from the main file.
			$file_dir = '';
			if ( ! empty( $meta['file'] ) ) {
				$file_dir = dirname( $meta['file'] );
				if ( '.' === $file_dir ) {
					$file_dir = '';
				} else {
					$file_dir .= '/';
				}
			}

			// Add all image sizes.
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size_data ) {
					if ( ! empty( $size_data['file'] ) ) {
						$files[ $file_dir . $size_data['file'] ] = true;
					}
				}
			}

			// Add original image if exists (WordPress 5.3+ big image handling).
			if ( ! empty( $meta['original_image'] ) ) {
				$files[ $file_dir . $meta['original_image'] ] = true;
			}
		}

		return $files;
	}

	/**
	 * Fast check if file is in media library using pre-loaded data.
	 * O(1) lookup instead of database query.
	 *
	 * @param string $file_path           Absolute file path to check.
	 * @param array  $media_library_files Pre-loaded media library files array.
	 * @return bool True if file is in media library.
	 */
	private function is_file_in_media_library_fast( $file_path, $media_library_files ) {
		$upload_dir = wp_upload_dir();
		// Normalize slashes on both sides before stripping the base dir (Windows compatibility).
		$base_dir      = trailingslashit( str_replace( '\\', '/', $upload_dir['basedir'] ) );
		$relative_path = str_replace( '\\', '/', $file_path );
		$relative_path = ltrim( str_replace( $base_dir, '', $relative_path ), '/' );

		// Fail-safe: if the base dir could not be stripped, never report the file as orphan.
		if ( preg_match( '#^[A-Za-z]:/#', $relative_path ) ) {
			return true;
		}

		// Check exact path match.
		if ( isset( $media_library_files[ $relative_path ] ) ) {
			return true;
		}

		// Check size variants (e.g. image-150x150.webp) of a registered file in the same directory.
		if ( preg_match( '/^(.+)-\d+x\d+(\.[^.]+)$/', $relative_path, $matches ) ) {
			if ( isset( $media_library_files[ $matches[1] . $matches[2] ] ) || isset( $media_library_files[ $matches[1] . '-scaled' . $matches[2] ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all media files from a directory recursively.
	 *
	 * @param string $dir Directory path to scan.
	 * @return array Array of file paths.
	 */
	private function get_all_media_files_from_directory( $dir ) {
		$files = array();
		// Define supported media file extensions.
		$media_extensions = array(
			// Images.
			'jpg',
			'jpeg',
			'png',
			'gif',
			'webp',
			'bmp',
			'tiff',
			'tif',
			'svg',
			'ico',
			// Documents.
			'pdf',
			'doc',
			'docx',
			'xls',
			'xlsx',
			'ppt',
			'pptx',
			'odt',
			'ods',
			'odp',
			'txt',
			'rtf',
			'csv',
			// Audio.
			'mp3',
			'wav',
			'ogg',
			'flac',
			'aac',
			'm4a',
			'wma',
			// Video.
			'mp4',
			'mov',
			'avi',
			'wmv',
			'mkv',
			'webm',
			'flv',
			'm4v',
			'mpeg',
			'mpg',
			// Archives.
			'zip',
			'rar',
			'7z',
			'tar',
			'gz',
		);

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD // Ignore read errors.
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$file_extension = strtolower( pathinfo( $file->getRealPath(), PATHINFO_EXTENSION ) );
					// Only include supported media files.
					if ( in_array( $file_extension, $media_extensions, true ) ) {
						$files[] = $file->getRealPath();
					}
				}
			}
		} catch ( Exception $e ) {
			// Directory not readable, skip silently.
			unset( $e );
		}
		return $files;
	}

	/**
	 * Handle orphan file deletion via form submission.
	 */
	public function handle_orphan_deletion() {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized access' );
		}
		// Check nonce.
		if ( ! isset( $_POST['_wpnonce_delete_orphans'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_delete_orphans'] ) ), 'cleanup_images_delete_orphans_action' ) ) {
			wp_die( 'Invalid nonce' );
		}
		$deleted_count = 0;
		$failed_count  = 0;
		$upload_dir    = wp_upload_dir();
		$base_dir      = trailingslashit( str_replace( '\\', '/', $upload_dir['basedir'] ) );
		// Check if files are selected.
		if ( isset( $_POST['orphan_files_to_delete'] ) && is_array( $_POST['orphan_files_to_delete'] ) ) {
			// Set time limit for large operations (discouraged but necessary for large file operations).
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}
			$files_to_delete = array_map( 'sanitize_text_field', wp_unslash( $_POST['orphan_files_to_delete'] ) );
			foreach ( $files_to_delete as $file_path ) {
				// Sanitize and normalize the file path.
				$file_path            = sanitize_text_field( $file_path );
				$normalized_file_path = str_replace( '\\', '/', $file_path );
				$normalized_file_path = preg_replace( '#/+#', '/', $normalized_file_path ); // Remove double slashes.

				// Check if file exists and is within uploads directory.
				$file_exists    = file_exists( $file_path );
				$in_uploads_dir = 0 === strpos( $normalized_file_path, $base_dir );

				if ( $file_exists && $in_uploads_dir ) {
					if ( wp_delete_file( $file_path ) ) {
						++$deleted_count;
					} else {
						++$failed_count;
					}
				} else {
					++$failed_count;
				}
			}
		} else {
			// No files selected.
			$notice_data = array(
				'message' => 'No files selected for deletion.',
				'type'    => 'error',
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode
			wp_safe_redirect( admin_url( 'upload.php?page=cleanup-orphan-images&tab=orphan_files&notice=' . rawurlencode( base64_encode( wp_json_encode( $notice_data ) ) ) ) );
			exit;
		}
		// Build message.
		$message = '';
		if ( $deleted_count > 0 ) {
			$message = sprintf( 1 === $deleted_count ? '%d orphan image deleted.' : '%d orphan images deleted.', $deleted_count );
		}
		if ( $failed_count > 0 ) {
			if ( ! empty( $message ) ) {
				$message .= ' ';
			}
			$message .= sprintf( 1 === $failed_count ? '%d image could not be deleted.' : '%d images could not be deleted.', $failed_count );
		}
		if ( empty( $message ) ) {
			$message = 'No action taken.';
		}
		$notice_type = $deleted_count > 0 ? 'success' : 'error';
		wp_safe_redirect(
			admin_url(
				'upload.php?page=cleanup-orphan-images&tab=orphan_files&notice=' . rawurlencode(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for safe URL encoding of notice data.
					base64_encode(
						wp_json_encode(
							array(
								'message' => $message,
								'type'    => $notice_type,
							)
						)
					)
				)
			)
		);
		exit;
	}
}
Cleanup_Images_Plugin::get_instance();
