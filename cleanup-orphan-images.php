<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: Cleanup Orphan Images
 * Description: Finds and deletes orphan image files from the uploads directory that are not registered in WordPress.
 * Version: 1.8.1
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

// Add Settings link on Plugins page.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_url  = admin_url( 'upload.php?page=cleanup-orphan-images' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'cleanup-orphan-images' ) . '</a>';
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
		add_action( 'wp_ajax_cleanup_images_delete_orphans_ajax', array( $this, 'handle_orphan_deletion_ajax' ) );
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
		wp_enqueue_script(
			'cleanup-images-admin',
			plugin_dir_url( __FILE__ ) . 'admin.js',
			array( 'jquery' ),
			'1.0',
			true
		);

		$upload_dir = wp_upload_dir();

		wp_localize_script(
			'cleanup-images-admin',
			'cleanup_images',
			array(
				'ajax_url'              => admin_url( 'admin-ajax.php' ),
				'scan_nonce'            => wp_create_nonce( 'cleanup_images_scan_orphans_nonce' ),
				'delete_nonce'          => wp_create_nonce( 'cleanup_images_delete_orphans_action' ),
				'scanning_text'         => esc_html__( 'Scanning for orphan files, please wait...', 'cleanup-orphan-images' ),
				'no_orphans_found_text' => esc_html__( 'No orphan files found.', 'cleanup-orphan-images' ),
				'error_text'            => esc_html__( 'An error occurred during the scan.', 'cleanup-orphan-images' ),
				'upload_base_dir'       => trailingslashit( $upload_dir['basedir'] ),
			)
		);

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
			__( 'Cleanup Orphan Images', 'cleanup-orphan-images' ),
			__( 'Cleanup Orphan Images', 'cleanup-orphan-images' ),
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
			<h1><?php esc_html_e( 'Cleanup Orphan Images', 'cleanup-orphan-images' ); ?></h1>
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
				wp_die( esc_html__( 'Unauthorized access', 'cleanup-orphan-images' ) );
			}
			$scan_results  = $this->scan_for_orphan_files();
			$scan_executed = true;
		}
		?>
		<div class="orphan-scanner-wrap" style="margin-top: 1em;">
			
			<div class="notice notice-info inline" style="margin: 15px 0;">
				<p><strong><?php esc_html_e( 'What are Orphan  Images?', 'cleanup-orphan-images' ); ?></strong></p>
				<p><?php esc_html_e( 'Those are physical image files that are invisible in the Media Library because they are not registered in the WordPress database, typically left by failed uploads, plugins, FTP transfers, or database restores.', 'cleanup-orphan-images' ); ?></p>
				<p><strong style="color: #d63638;"><?php esc_html_e( 'Warning:', 'cleanup-orphan-images' ); ?></strong> <?php esc_html_e( 'Some files may still be used by themes or plugins that reference them directly by URL. Always review before deleting and consider backing up your uploads folder first.', 'cleanup-orphan-images' ); ?></p>
			</div>
			<p><strong><?php esc_html_e( 'Supported formats:', 'cleanup-orphan-images' ); ?></strong></p>
			<ul style="margin-top: 5px; margin-left: 20px;">
				<li><strong><?php esc_html_e( 'Images:', 'cleanup-orphan-images' ); ?></strong> JPG, JPEG, PNG, GIF, WebP, BMP, TIFF, SVG, ICO</li>
				<li><strong><?php esc_html_e( 'Documents:', 'cleanup-orphan-images' ); ?></strong> PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, ODP, TXT, RTF, CSV</li>
				<li><strong><?php esc_html_e( 'Audio:', 'cleanup-orphan-images' ); ?></strong> MP3, WAV, OGG, FLAC, AAC, M4A, WMA</li>
				<li><strong><?php esc_html_e( 'Video:', 'cleanup-orphan-images' ); ?></strong> MP4, MOV, AVI, WMV, MKV, WebM, FLV, M4V, MPEG, MPG</li>
				<li><strong><?php esc_html_e( 'Archives:', 'cleanup-orphan-images' ); ?></strong> ZIP, RAR, 7Z, TAR, GZ</li>
			</ul>
			<p><strong><?php esc_html_e( 'Scanning time', 'cleanup-orphan-images' ); ?></strong> <?php esc_html_e( 'depends on the number of files in your uploads directory and the size of your Media Library. Large sites may take several minutes.', 'cleanup-orphan-images' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'upload.php?page=cleanup-orphan-images' ) ); ?>">
				<?php wp_nonce_field( 'cleanup_images_scan_orphans_action', 'cleanup_images_scan_orphans_nonce' ); ?>
				<p>
					<button type="submit" name="scan_orphan_files" class="button button-primary"><?php esc_html_e( 'Scan for Orphan Files', 'cleanup-orphan-images' ); ?></button>
				</p>
			</form>

			<?php if ( $scan_executed ) : ?>
				<h3><?php esc_html_e( 'Scan Results', 'cleanup-orphan-images' ); ?></h3>

				<?php if ( empty( $scan_results['orphan_files'] ) ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'No orphan files found.', 'cleanup-orphan-images' ); ?></p></div>
				<?php else : ?>
					<p>
						<?php
						/* translators: %d: number of orphan files found */
						printf( esc_html__( 'Found %d orphan files:', 'cleanup-orphan-images' ), count( $scan_results['orphan_files'] ) );
						?>
					</p>

					<?php
					// Add warning for large numbers of files.
					$max_input_vars = (int) ini_get( 'max_input_vars' );
					if ( count( $scan_results['orphan_files'] ) > ( $max_input_vars - 50 ) ) :
						?>
						<div class="notice notice-warning inline"><p>
							<strong><?php esc_html_e( 'Warning:', 'cleanup-orphan-images' ); ?></strong>
							<?php
							printf(
								/* translators: 1: number of files found, 2: server's max_input_vars limit */
								esc_html__( 'You have %1$d files, but your server is limited to processing %2$d form fields at once. Consider selecting files in smaller batches to avoid incomplete deletions.', 'cleanup-orphan-images' ),
								absint( count( $scan_results['orphan_files'] ) ),
								absint( $max_input_vars )
							);
							?>
						</p></div>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="cleanup_images_delete_orphans">
						<?php wp_nonce_field( 'cleanup_images_delete_orphans_action', '_wpnonce_delete_orphans' ); ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th class="check-column"><input type="checkbox" id="cb-select-all-orphans"></th>
									<th><?php esc_html_e( 'Image File Path', 'cleanup-orphan-images' ); ?></th>
									<th><?php esc_html_e( 'File Size', 'cleanup-orphan-images' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $scan_results['orphan_files'] as $file_path ) : ?>
								<?php $file_size = file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : 'Unknown'; ?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="orphan_files_to_delete[]" value="<?php echo esc_attr( $file_path ); ?>"></th>
									<td><?php echo esc_html( $file_path ); ?></td>
									<td><?php echo esc_html( $file_size ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php submit_button( __( 'Delete Selected Orphan Images', 'cleanup-orphan-images' ), 'delete', 'submit-delete-orphans', true, array( 'class' => 'button button-danger' ) ); ?>
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
			// Also store just the filename for scaled image matching.
			$files[ basename( $relative_path ) ] = true;
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
						$size_file                   = $file_dir . $size_data['file'];
						$files[ $size_file ]         = true;
						$files[ $size_data['file'] ] = true;
					}
				}
			}

			// Add original image if exists (WordPress 5.3+ big image handling).
			if ( ! empty( $meta['original_image'] ) ) {
				$original_file                    = $file_dir . $meta['original_image'];
				$files[ $original_file ]          = true;
				$files[ $meta['original_image'] ] = true;
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
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'], '', $file_path );
		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), '/' );

		// Check exact path match.
		if ( isset( $media_library_files[ $relative_path ] ) ) {
			return true;
		}

		// Check filename only (for scaled images).
		$filename = basename( $file_path );
		if ( isset( $media_library_files[ $filename ] ) ) {
			return true;
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
				'message' => esc_html__( 'No files selected for deletion.', 'cleanup-orphan-images' ),
				'type'    => 'error',
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode
			wp_safe_redirect( admin_url( 'upload.php?page=cleanup-orphan-images&tab=orphan_files&notice=' . rawurlencode( base64_encode( wp_json_encode( $notice_data ) ) ) ) );
			exit;
		}
		// Build message.
		$message = '';
		if ( $deleted_count > 0 ) {
			$message = sprintf(
				/* translators: %d: number of orphan images deleted */
				_n(
					'%d orphan image deleted.',
					'%d orphan images deleted.',
					$deleted_count,
					'cleanup-orphan-images'
				),
				$deleted_count
			);
		}
		if ( $failed_count > 0 ) {
			if ( ! empty( $message ) ) {
				$message .= ' ';
			}
			$message .= sprintf(
				/* translators: %d: number of images that could not be deleted */
				_n(
					'%d image could not be deleted.',
					'%d images could not be deleted.',
					$failed_count,
					'cleanup-orphan-images'
				),
				$failed_count
			);
		}
		if ( empty( $message ) ) {
			$message = esc_html__( 'No action taken.', 'cleanup-orphan-images' );
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

	/**
	 * Handle orphan file deletion via AJAX.
	 */
	public function handle_orphan_deletion_ajax() {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized access' );
		}
		// Check nonce - accept both parameter names for compatibility.
		$nonce = '';
		if ( isset( $_POST['_wpnonce_delete_orphans'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce_delete_orphans'] ) );
		} elseif ( isset( $_POST['_ajax_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['_ajax_nonce'] ) );
		}
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'cleanup_images_delete_orphans_action' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}
		$deleted_count = 0;
		$failed_count  = 0;
		$upload_dir    = wp_upload_dir();
		$base_dir      = trailingslashit( str_replace( '\\', '/', $upload_dir['basedir'] ) );
		// Check if files are selected.
		if ( isset( $_POST['orphan_files_to_delete'] ) && is_array( $_POST['orphan_files_to_delete'] ) ) {
			// Set time limit for batch operations (discouraged but necessary for large file operations).
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}
			$files_to_delete = array_map( 'sanitize_text_field', wp_unslash( $_POST['orphan_files_to_delete'] ) );
			foreach ( $files_to_delete as $file_path ) {
				// Normalize the file path.
				$normalized_file_path = str_replace( '\\', '/', $file_path );
				$normalized_file_path = preg_replace( '#/+#', '/', $normalized_file_path );
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
		}
		// Send JSON response.
		wp_send_json_success(
			array(
				'deleted' => $deleted_count,
				'failed'  => $failed_count,
				'message' => sprintf(
				/* translators: %d: number of images deleted via AJAX */
					_n(
						'%d image deleted.',
						'%d images deleted.',
						$deleted_count,
						'cleanup-orphan-images'
					),
					$deleted_count
				),
			)
		);
	}
}
Cleanup_Images_Plugin::get_instance();
