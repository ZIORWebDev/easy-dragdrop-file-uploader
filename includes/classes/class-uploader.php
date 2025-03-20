<?php
namespace ZIOR\DragDrop;

use ElementorPro\Modules\Forms\Classes;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles DragDrop uploader for WordPress.
 */
class Uploader {

	/**
	 * Singleton instance of the class.
	 *
	 * @var Uploader|null
	 */
	private static ?Uploader $instance = null;

	private ?string $temp_file_path = '';

	/**
	 * Deletes all files inside a folder.
	 *
	 * @param string $folder Folder path.
	 * @return bool True on success, false on failure.
	 */
	function delete_files( $folder ) {
		global $wp_filesystem;

		if ( ! isset( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem->rmdir( $folder, true );
	}

	/**
	 * Retrieves the uploaded file from the Elementor form fields.
	 *
	 * This function extracts the uploaded file from the `$_FILES` superglobal.
	 * Since it deals with file uploads, sanitation is not applied here.
	 *
	 * @return array|bool The uploaded file array or false if no file is uploaded.
	 */
	private function get_uploaded_file( array $files ): array|bool {
		if ( empty( $files ) || ! is_array( $files ) ) {
			return false;
		}

		$field_name = array_key_first( $files['name'] );
		$field_name = sanitize_text_field( $field_name );
		$file_keys  = array( 'name', 'type', 'tmp_name', 'error', 'size' );
		$file       = array();

		// Extract the file from the multidimensional $_FILES structure.
		foreach ( $file_keys as $key ) {
			$sanitize_callback = in_array( $key, array( 'name', 'type', 'tmp_name' ), true ) ? 'sanitize_text_field' : 'intval';
			$file[ $key ] = is_array( $files[ $key ][ $field_name ] ) 
				? $sanitize_callback( $files[ $key ][ $field_name ][0] ) 
				: $sanitize_callback( $files[ $key ][ $field_name ] );
		}

		return $file;
	}

	/**
	 * Validate the file type against allowed types.
	 *
	 * This function applies the 'easy_dragdrop_validate_file_type' filter to allow
	 * external modification of the validation logic.
	 *
	 * @param array $file        File data array containing file details.
	 * @param array $valid_types Array of allowed file types.
	 * 
	 * @return bool True if the file type is valid, false otherwise.
	 */
	private function is_valid_file_type( array $file, array $valid_types ): bool {
		return apply_filters( 'easy_dragdrop_validate_file_type', false, $file, $valid_types );
	}

	/**
	 * Validate the file size against the maximum allowed size.
	 *
	 * This function applies the 'easy_dragdrop_validate_file_size' filter to allow
	 * external modification of the validation logic.
	 *
	 * @param array $file    File data array containing file details.
	 * @param int   $max_size Maximum allowed file size in bytes.
	 * 
	 * @return bool True if the file size is valid, false otherwise.
	 */
	private function is_valid_file_size( array $file, int $max_size ): bool {
		return apply_filters( 'easy_dragdrop_validate_file_size', false, $file, $max_size );
	}

	/**
	 * Safely move a file to avoid overwriting an existing file.
	 *
	 * @param string $source      The source file path.
	 * @param string $destination The destination file path.
	 * @return string|false The new file path if successful, false on failure.
	 */
	private function move_file( $source, $destination ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$path      = pathinfo( $destination );
		$dir       = $path['dirname'];
		$filename  = $path['filename'];
		$extension = isset( $path['extension'] ) ? '.' . $path['extension'] : '';

		$counter         = 1;
		$new_destination = $destination;

		while ( $wp_filesystem->exists( $new_destination ) ) {
			$new_destination = sprintf( '%s/%s-%d%s', $dir, $filename, $counter, $extension );
			$counter++;
		}

		return $wp_filesystem->move( $source, $new_destination ) ? $new_destination : false;
	}

	/**
	 * Constructor.
	 *
	 * Hooks into WordPress to enqueue scripts and styles.
	 */
	public function __construct() {
		$this->temp_file_path = wp_upload_dir()['basedir'] . '/easy-dragdrop-uploader-temp';

		add_action( 'wp_ajax_easy_dragdrop_upload', array( $this, 'handle_easy_dragdrop_upload' ), 10 );
		add_action( 'wp_ajax_nopriv_easy_dragdrop_upload', array( $this, 'handle_easy_dragdrop_upload' ), 10 );
		add_action( 'wp_ajax_easy_dragdrop_remove', array( $this, 'handle_easy_dragdrop_remove' ), 10 );
		add_action( 'wp_ajax_nopriv_easy_dragdrop_remove', array( $this, 'handle_easy_dragdrop_remove' ), 10 );
		add_action( 'easy_dragdrop_process_field', array( $this, 'process_easy_dragdrop_field' ), 10, 3 );

		add_filter( 'easy_dragdrop_validate_file_type', array( $this, 'validate_file_type' ), 10, 3 );
		add_filter( 'easy_dragdrop_validate_file_size', array( $this, 'validate_file_size' ), 10, 3 );
	}

	/**
	 * Retrieves the singleton instance of the class.
	 *
	 * @return Uploader The single instance of the class.
	 */
	public static function get_instance(): Uploader {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Handles the removal of an uploaded file.
	 *
	 * This function verifies the nonce for security, retrieves the file URL from the request,
	 * converts it to the file path, and attempts to delete the file from the server.
	 *
	 * @return void Outputs JSON response indicating success or failure.
	 */
	public function handle_easy_dragdrop_remove(): void {
		check_ajax_referer( 'easy_dragdrop_uploader_nonce', 'security' );

		// Retrieve the file id from the request body.
		$file_id = file_get_contents( 'php://input' );
		$file_id = sanitize_text_field( $file_id );

		if ( ! $file_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing file ID.', 'easy-dragdrop-file-uploader' ) )
			);
		}

		$temp_file_path = $this->temp_file_path . '/' . dirname( $file_id );

		if ( $this->delete_files( $temp_file_path ) ) {
			wp_send_json_success(
				array( 'message' => __( 'Files deleted successfully.', 'easy-dragdrop-file-uploader' ) )
			);
		} else {
			wp_send_json_error(
				array( 'message' => __( 'Failed to delete files.', 'easy-dragdrop-file-uploader' ) )
			);
		}
	}

	/**
	 * Handles file uploads.
	 *
	 * This function verifies security checks, validates the uploaded file, 
	 * processes the file upload, and saves it to a custom directory.
	 *
	 * @return void Outputs JSON response indicating success or failure.
	 */
	public function handle_easy_dragdrop_upload(): void {
		check_ajax_referer( 'easy_dragdrop_uploader_nonce', 'security' );

		// Retrieve and validate uploaded file.
		$files         = $_FILES['form_fields'] ?? array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uploaded_file = $this->get_uploaded_file( $files );

		if ( empty( $uploaded_file ) ) {
			wp_send_json_error( array( 'error' => __( 'No valid file uploaded.', 'easy-dragdrop-file-uploader' ) ) );

			return;
		}

		// Retrieve and validate file properties.
		$secret_key  = sanitize_text_field( wp_unslash( $_POST['secret_key'] ?? '' ) );
		$args        = decrypt_data( $secret_key );
		$valid_types = explode( ',', $args['types'] );

		if ( ! $this->is_valid_file_type( $uploaded_file, $valid_types ) ) {
			wp_send_json_error( array( 'error' => get_option( 'easy_dragdrop_file_type_error', '' ) ), 415 );

			return;
		}

		if ( ! $this->is_valid_file_size( $uploaded_file, $args['size'] ) ) {
			wp_send_json_error( array( 'error' => get_option( 'easy_dragdrop_file_size_error', '' ) ), 413 );

			return;
		}

		$unique_id      = wp_generate_uuid4();
		$temp_file_path = $this->temp_file_path . '/' . $unique_id;

		wp_mkdir_p( $temp_file_path );

		if ( $this->move_file( $uploaded_file['tmp_name'], $temp_file_path . '/' . $uploaded_file['name'] ) ) {
			wp_send_json_success(
				array(
					'file_id' => $unique_id . '/' . $uploaded_file['name']
				)
			);
		} else {
			wp_send_json_error(
				array(
					'error' => 'Failed to move uploaded file.'
				)
			);
		}
	}

	/**
	 * Processes the DragDrop field by moving files from the temporary directory to the upload directory.
	 *
	 * @param array               $field         The field data.
	 * @param Classes\Form_Record $record        The form record instance.
	 * @param Classes\Ajax_Handler $ajax_handler The AJAX handler instance.
	 */
	public function process_easy_dragdrop_field( array $field, Classes\Form_Record $record, Classes\Ajax_Handler $ajax_handler ): void {
		$raw_values = (array) $field['raw_value']; // Ensure $raw_values is always an array.

		if ( empty( $raw_values ) ) {
			return;
		}

		$upload_dir  = wp_upload_dir();
		$upload_path = apply_filters( 'easy_dragdrop_upload_path', $upload_dir['path'] );
		$value_paths = $value_urls = [];

		foreach ( $raw_values as $unique_id ) {
			if ( empty( $unique_id ) ) {
				continue;
			}

			$source      = $this->temp_file_path . '/' . $unique_id;
			$destination = $upload_path . '/' . basename( $unique_id );

			// Move file to upload directory
			if ( $file_path = $this->move_file( $source, $destination ) ) {
				$value_paths[] = $file_path;
				$value_urls[]  = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
			}

			// Delete temporary folder containing the file
			$this->delete_files( dirname( $source ) );
		}

		// Store updated values in the record
		$record->update_field( $field['id'], 'value', implode( ', ', $value_urls ) );
		$record->update_field( $field['id'], 'raw_value', implode( ', ', $value_paths ) );
	}

	/**
	 * Validate if the uploaded file size is within the allowed limit.
	 *
	 * @param bool  $valid    Whether the file is already considered valid.
	 * @param array $file     Uploaded file data from $_FILES.
	 * @param int   $max_size Maximum allowed file size in bytes.
	 * @return bool True if the file size is valid, false otherwise.
	 */
	public function validate_file_size( bool $valid, array $file, int $max_size ): bool {
		if ( $valid ) {
			return true;
		}

		return ( $file['size'] <= $max_size );
	}

	/**
	 * Validate if the uploaded file type is allowed.
	 *
	 * @param bool   $valid         Whether the file is already considered valid.
	 * @param array  $file          Uploaded file data from $_FILES.
	 * @param array  $allowed_types List of allowed MIME types (e.g., ['image/png', 'image/jpeg']).
	 * @return bool True if the file type is valid, false otherwise.
	 */
	public function validate_file_type( bool $valid, array $file, array $allowed_types ): bool {
		if ( $valid ) {
			return true;
		}

		// Get the MIME type using wp_check_filetype.
		$file_type = wp_check_filetype( $file['name'] );

		// Validate file type against allowed MIME types.
		return ( ! empty( $file_type['type'] ) && in_array( $file_type['type'], $allowed_types, true ) );
	}
}