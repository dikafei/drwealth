<?php
/**
 * Shared helpers for dealing with an uploaded Substack export .zip: safely
 * extracting it, locating files inside it, parsing CSVs, and cleaning up
 * afterwards. Used by both the post importer and the subscriber importer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DrWealth_Substack_Zip_Helper {

	/**
	 * Extract a zip into a fresh temp directory under uploads/.
	 *
	 * @return string|WP_Error Absolute path to the extraction directory, or a WP_Error.
	 */
	public static function extract( $zip_path ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();

		$upload_dir = wp_upload_dir();
		$extract_to = trailingslashit( $upload_dir['basedir'] ) . 'drwealth-substack-tmp/' . uniqid( 'import-' );

		$result = unzip_file( $zip_path, $extract_to );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $extract_to;
	}

	/**
	 * Recursively search a directory for a file with an exact name.
	 *
	 * @return string|false Absolute path, or false if not found.
	 */
	public static function find_file( $dir, $filename ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getFilename() === $filename ) {
				return $file->getPathname();
			}
		}

		return false;
	}

	/**
	 * Recursively search a directory for a file whose name starts with a
	 * given prefix and ends with a given extension (case-insensitive).
	 * Used for files like "email_list.<publication>.csv" whose exact name
	 * varies by publication.
	 *
	 * @return string|false Absolute path, or false if not found.
	 */
	public static function find_file_by_prefix( $dir, $prefix, $extension ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$name = $file->getFilename();

			if ( 0 !== stripos( $name, $prefix ) ) {
				continue;
			}

			if ( strlen( $name ) < strlen( $extension ) ) {
				continue;
			}

			if ( 0 === strcasecmp( substr( $name, -strlen( $extension ) ), $extension ) ) {
				return $file->getPathname();
			}
		}

		return false;
	}

	/**
	 * Parse a CSV into an array of associative rows keyed by header name.
	 */
	public static function parse_csv( $path ) {
		$rows   = array();
		$handle = fopen( $path, 'r' );

		if ( ! $handle ) {
			return $rows;
		}

		$header = fgetcsv( $handle );

		if ( ! $header ) {
			fclose( $handle );
			return $rows;
		}

		// Strip a UTF-8 BOM from the first header cell, if present.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );

		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( count( $data ) !== count( $header ) ) {
				continue;
			}
			$rows[] = array_combine( $header, $data );
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Remove a temporary extraction directory.
	 */
	public static function cleanup( $dir ) {
		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->exists( $dir ) ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
