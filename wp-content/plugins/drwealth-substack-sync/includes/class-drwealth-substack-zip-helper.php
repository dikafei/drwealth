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

		// The system temp folder, NOT uploads/: files in uploads/ are public on the web
		// (the export holds the subscriber list), and the temp folder is not.
		$extract_to = trailingslashit( get_temp_dir() ) . uniqid( 'drwealth-substack-import-' );

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
	 * Substack export files left behind in the PUBLIC uploads folder (by older versions of the
	 * importer): the old temp folder, any .zip that holds posts.csv / email_list*.csv, and loose
	 * posts.csv / email_list*.csv files. New imports never put anything there.
	 *
	 * @return string[] Absolute paths (files and the old temp folder).
	 */
	public static function find_leftovers() {
		$upload = wp_get_upload_dir();
		$base   = untrailingslashit( $upload['basedir'] );
		$tmp    = $base . '/drwealth-substack-tmp';
		$found  = array();

		if ( is_dir( $tmp ) ) {
			$found[] = $tmp;
		}

		if ( ! is_dir( $base ) ) {
			return $found;
		}

		$zips     = 0;
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = $file->getPathname();

			if ( 0 === strpos( $path, $tmp . '/' ) ) {
				continue; // Covered by the folder itself.
			}

			$name = strtolower( $file->getFilename() );
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );

			if ( 'csv' === $ext && ( 'posts.csv' === $name || 0 === strpos( $name, 'email_list' ) ) ) {
				$found[] = $path;
				continue;
			}

			if ( 'zip' === $ext && ++$zips <= 300 && class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();

				if ( true === $zip->open( $path ) ) {
					$hit = false;
					$max = min( $zip->numFiles, 5000 );

					for ( $i = 0; $i < $max; $i++ ) {
						$entry = strtolower( basename( (string) $zip->getNameIndex( $i ) ) );

						if ( 'posts.csv' === $entry || 0 === strpos( $entry, 'email_list' ) ) {
							$hit = true;
							break;
						}
					}

					$zip->close();

					if ( $hit ) {
						$found[] = $path;
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Delete everything find_leftovers() reports (it is re-run here, never a caller-supplied path).
	 *
	 * @return int Number of items deleted.
	 */
	public static function delete_leftovers() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$deleted = 0;

		foreach ( self::find_leftovers() as $path ) {
			if ( is_dir( $path ) ) {
				if ( $wp_filesystem && $wp_filesystem->delete( $path, true ) ) {
					$deleted++;
				}
			} elseif ( file_exists( $path ) ) {
				wp_delete_file( $path );
				$deleted++;
			}
		}

		return $deleted;
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
