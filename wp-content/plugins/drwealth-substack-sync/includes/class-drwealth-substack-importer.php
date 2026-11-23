<?php
/**
 * Core import logic: takes an uploaded Substack export .zip and turns the
 * published posts inside it into WordPress posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DrWealth_Substack_Importer {

	/**
	 * Publication configuration: maps a publication key (matches the
	 * Substack subdomain) to its label and the existing WordPress "market"
	 * category every post from that publication should be tagged with.
	 */
	const PUBLICATIONS = array(
		'finbiteinsights' => array(
			'label'                => 'Finbite Insights',
			'market_category_name' => 'United States',
			'market_category_slug' => 'us',
			'brand_category_slug'  => 'finbite-insights',
		),
		'growthdragons'   => array(
			'label'                => 'Growth Dragons',
			'market_category_name' => 'China',
			'market_category_slug' => 'cn',
			'brand_category_slug'  => 'growth-dragons',
		),
	);

	/**
	 * The existing category used to gate paid ("only_paid") content. Free
	 * ("everyone") posts get no extra category beyond the market one — there
	 * is no separate "free" category on the site today.
	 */
	const PAID_CATEGORY_NAME = 'Premium';
	const PAID_CATEGORY_SLUG = 'premium';

	const META_SUBSTACK_ID = '_drwealth_substack_post_id';
	const META_PUBLICATION = '_drwealth_substack_publication';
	const META_AUDIENCE    = '_drwealth_substack_audience';
	const META_SOURCE_URL  = '_drwealth_substack_source_url';

	/**
	 * Import a Substack export zip.
	 *
	 * @param string $zip_path        Absolute path to the uploaded zip file.
	 * @param string $publication_key Key from self::PUBLICATIONS.
	 * @param int    $limit           Max number of posts to import (most recent published first).
	 * @return array {
	 *     @type int   $created Number of new posts created.
	 *     @type int   $updated Number of existing posts overwritten.
	 *     @type array $skipped Substack post IDs skipped (e.g. missing HTML file).
	 *     @type array $errors  Human-readable error strings.
	 * }
	 */
	public static function import_zip( $zip_path, $publication_key, $limit = 10, $author_id = 0, $overwrite = false ) {

		$result = array(
			'created' => 0,
			'updated' => 0,
			'matched' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		if ( ! isset( self::PUBLICATIONS[ $publication_key ] ) ) {
			$result['errors'][] = sprintf( 'Unknown publication "%s".', $publication_key );
			return $result;
		}

		// Never fall back to "whoever is logged in": the export has no author, so one must be chosen.
		if ( $author_id <= 0 || ! get_userdata( $author_id ) ) {
			$result['errors'][] = 'Choose the author the imported posts should belong to.';
			return $result;
		}

		// Everything this run CREATES (posts and downloaded images) is tagged with this ID so
		// the run can be undone later (Member Tools > Posts > Undo an import run).
		self::$run_id = gmdate( 'Ymd-His' );

		self::begin_import_guards();

		try {
			return self::run_import( $zip_path, $publication_key, $limit, $author_id, (bool) $overwrite, $result );
		} finally {
			self::end_import_guards();
		}
	}

	/** True while a post import is running (other code, e.g. snippets, can check this). */
	public static $importing = false;

	/** ID of the current import run (set at the start of import_zip). */
	public static $run_id = '';

	/** ID of the post the last import_row() created, updated or matched (0 if none). */
	public static $last_id = 0;

	/**
	 * Import ONE post sent by the browser (batch import): the page reads the export .zip in the
	 * browser and sends each post separately, so nothing large is uploaded and each request is short.
	 *
	 * @param array  $row             post_id, post_date, audience, title, subtitle (from posts.csv).
	 * @param string $html            The post's HTML from posts/<post_id>.html.
	 * @param string $publication_key Key from self::PUBLICATIONS.
	 * @param int    $author_id       Author for new posts.
	 * @param bool   $overwrite       Overwrite posts that are already on the site.
	 * @param string $run_id          Run ID (date-time) the browser generated for this batch.
	 * @return array{outcome:string,id:int,error:string}
	 */
	public static function import_one( array $row, $html, $publication_key, $author_id, $overwrite, $run_id ) {
		if ( ! isset( self::PUBLICATIONS[ $publication_key ] ) ) {
			return array( 'outcome' => 'error', 'id' => 0, 'error' => 'Unknown publication.' );
		}

		if ( $author_id <= 0 || ! get_userdata( $author_id ) ) {
			return array( 'outcome' => 'error', 'id' => 0, 'error' => 'Choose an author.' );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		self::$run_id = preg_replace( '/[^0-9-]/', '', (string) $run_id );

		self::begin_import_guards();

		try {
			$outcome = self::import_row( $row, $publication_key, '', (int) $author_id, (bool) $overwrite, (string) $html );
		} finally {
			self::end_import_guards();
		}

		if ( is_wp_error( $outcome ) ) {
			return array( 'outcome' => 'error', 'id' => 0, 'error' => $outcome->get_error_message() );
		}

		return array( 'outcome' => (string) $outcome, 'id' => (int) self::$last_id, 'error' => '' );
	}

	/** Post/attachment meta: the run that created this item. */
	const META_RUN = '_drwealth_substack_run';

	/**
	 * A post import must never email anyone, post to social, or reach Kit. Mail is swallowed,
	 * Jetpack Publicize is switched off and calls to the email-marketing APIs are blocked
	 * while the import runs. (Image downloads from Substack are not affected.)
	 */
	protected static function begin_import_guards() {
		self::$importing = true;

		add_filter( 'pre_wp_mail', '__return_true', 9999 );
		add_filter( 'publicize_should_publicize_published_post', '__return_false', 9999 );
		add_filter( 'pre_http_request', array( __CLASS__, 'block_marketing_requests' ), 9999, 3 );
	}

	protected static function end_import_guards() {
		self::$importing = false;

		remove_filter( 'pre_wp_mail', '__return_true', 9999 );
		remove_filter( 'publicize_should_publicize_published_post', '__return_false', 9999 );
		remove_filter( 'pre_http_request', array( __CLASS__, 'block_marketing_requests' ), 9999 );
	}

	public static function block_marketing_requests( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( preg_match( '/(^|\.)(kit\.com|convertkit\.com|convertkit-mail\d*\.com|mailchimp\.com)$/i', $host ) ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK (blocked during import)',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return $pre;
	}

	protected static function run_import( $zip_path, $publication_key, $limit, $author_id, $overwrite, $result ) {

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$extract_to = DrWealth_Substack_Zip_Helper::extract( $zip_path );

		if ( is_wp_error( $extract_to ) ) {
			$result['errors'][] = 'Could not extract zip: ' . $extract_to->get_error_message();
			return $result;
		}

		$csv_path = DrWealth_Substack_Zip_Helper::find_file( $extract_to, 'posts.csv' );

		if ( ! $csv_path ) {
			$result['errors'][] = 'posts.csv not found inside the uploaded export.';
			DrWealth_Substack_Zip_Helper::cleanup( $extract_to );
			return $result;
		}

		$posts_dir = trailingslashit( dirname( $csv_path ) ) . 'posts';
		$rows      = DrWealth_Substack_Zip_Helper::parse_csv( $csv_path );

		// Only published newsletter posts. Rows are kept in the order they
		// appear in posts.csv (top to bottom) rather than re-sorted by
		// post_date — "limit" means the first N published rows of the file.
		$rows = array_filter(
			$rows,
			function ( $row ) {
				return isset( $row['is_published'] ) && 'true' === $row['is_published'];
			}
		);

		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = array_slice( $rows, 0, $limit );

		foreach ( $rows as $row ) {
			$outcome = self::import_row( $row, $publication_key, $posts_dir, (int) $author_id, $overwrite );

			if ( is_wp_error( $outcome ) ) {
				$result['errors'][] = $row['post_id'] . ': ' . $outcome->get_error_message();
				continue;
			}

			if ( 'created' === $outcome ) {
				$result['created']++;
			} elseif ( 'updated' === $outcome ) {
				$result['updated']++;
			} elseif ( 'matched' === $outcome ) {
				$result['matched'][] = $row['post_id'];
			} else {
				$result['skipped'][] = $row['post_id'];
			}
		}

		DrWealth_Substack_Zip_Helper::cleanup( $extract_to );

		return $result;
	}

	/**
	 * Import a single CSV row (one Substack post) into WordPress.
	 *
	 * @return string|WP_Error 'created', 'updated', 'skipped', or a WP_Error on failure.
	 */
	protected static function import_row( array $row, $publication_key, $posts_dir, $author_id = 0, $overwrite = false, $html = null ) {

		self::$last_id = 0;

		$substack_id = trim( isset( $row['post_id'] ) ? $row['post_id'] : '' );

		if ( '' === $substack_id ) {
			return 'skipped';
		}

		$html_path = trailingslashit( $posts_dir ) . $substack_id . '.html';

		// $html is given when the browser sends the post directly (batch import); otherwise read it from the extracted export.
		if ( null === $html && ! file_exists( $html_path ) ) {
			return 'skipped';
		}

		$slug = self::slug_from_id( $substack_id );

		// For matching an older copy of the same article by title + date.
		$match_title = sanitize_text_field( html_entity_decode( isset( $row['title'] ) ? $row['title'] : '', ENT_QUOTES ) );
		$match_date  = ! empty( $row['post_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $row['post_date'] ) ) : '';

		$existing_id = self::find_existing_post( $substack_id, $publication_key, $slug, $match_title, $match_date );

		// A post that is already on the site (same Substack ID, or same slug as one already
		// there): never create a "-2" duplicate and never touch its categories. By default it
		// is only linked to its Substack ID and left exactly as it is.
		if ( $existing_id && ! $overwrite ) {
			if ( ! get_post_meta( $existing_id, self::META_SUBSTACK_ID, true ) ) {
				update_post_meta( $existing_id, self::META_SUBSTACK_ID, $substack_id );
				update_post_meta( $existing_id, self::META_PUBLICATION, $publication_key );
			}

			self::$last_id = (int) $existing_id;

			return 'matched';
		}

		$raw_html = ( null !== $html ) ? $html : file_get_contents( $html_path );
		$content  = self::clean_content( $raw_html );
		$title    = sanitize_text_field( html_entity_decode( isset( $row['title'] ) ? $row['title'] : $substack_id, ENT_QUOTES ) );

		$pub_data     = self::PUBLICATIONS[ $publication_key ];
		$audience_key = ( isset( $row['audience'] ) && 'only_paid' === $row['audience'] ) ? 'only_paid' : 'everyone';

		// Market category only: the Finbite Insights / Growth Dragons names are not shown to readers.
		// The source stays in post meta (META_PUBLICATION) as the hidden marker.
		$category_ids = array(
			self::get_or_create_category( $pub_data['market_category_name'], $pub_data['market_category_slug'] ),
		);

		if ( 'only_paid' === $audience_key ) {
			$category_ids[] = self::get_or_create_category( self::PAID_CATEGORY_NAME, self::PAID_CATEGORY_SLUG );
		}

		$category_ids = array_values( array_unique( array_filter( $category_ids ) ) );

		$post_date_gmt = self::to_mysql_gmt( isset( $row['post_date'] ) ? $row['post_date'] : '' );
		$post_date     = get_date_from_gmt( $post_date_gmt );

		$postarr = array(
			'post_title'    => $title,
			'post_content'  => $content,
			'post_status'   => 'publish',
			'post_type'     => 'post',
			'post_date'     => $post_date,
			'post_date_gmt' => $post_date_gmt,
		);

		if ( ! $existing_id && '' !== $slug ) {
			$postarr['post_name'] = $slug; // The Substack slug, so the link rewrite below lands on this post.
		}

		// The Substack export has no author field, so the author is chosen on
		// the import form. Without this WordPress would use whoever is logged in.
		if ( $author_id > 0 && get_userdata( $author_id ) ) {
			$postarr['post_author'] = $author_id;
		}

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$new_id        = wp_update_post( $postarr, true );
			$is_new        = false;
		} else {
			$new_id = wp_insert_post( $postarr, true );
			$is_new = true;
		}

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Categories are only set on a brand-new post; an existing one keeps its own.
		if ( $is_new ) {
			update_post_meta( $new_id, self::META_RUN, self::$run_id );
			wp_set_post_categories( $new_id, $category_ids, false );

			// Imported posts have no "last updated" date: the publish date is the Substack date.
			delete_post_meta( $new_id, '_drwealth_updated_gmt' );

			// Substack subtitle -> Jetpack SEO description. The SEO title is left empty on purpose.
			$subtitle = isset( $row['subtitle'] ) ? sanitize_text_field( html_entity_decode( $row['subtitle'], ENT_QUOTES ) ) : '';

			if ( '' !== $subtitle && ! get_post_meta( $new_id, 'advanced_seo_description', true ) ) {
				update_post_meta( $new_id, 'advanced_seo_description', $subtitle );
			}
		}

		update_post_meta( $new_id, self::META_SUBSTACK_ID, $substack_id );
		update_post_meta( $new_id, self::META_PUBLICATION, $publication_key );
		update_post_meta( $new_id, self::META_AUDIENCE, $audience_key );
		update_post_meta( $new_id, self::META_SOURCE_URL, self::source_url( $publication_key, $substack_id ) );

		// Pull every image into the Media Library and point the post content at
		// the local copies (instead of hot-linking Substack's servers).
		$localized = self::localize_images( $content, $new_id );

		if ( $localized['content'] !== $content ) {
			wp_update_post(
				array(
					'ID'           => $new_id,
					'post_content' => $localized['content'],
				)
			);
		}

		if ( ! has_post_thumbnail( $new_id ) ) {
			if ( $localized['first_id'] ) {
				set_post_thumbnail( $new_id, $localized['first_id'] );
			} else {
				self::maybe_set_featured_image( $new_id, $raw_html );
			}
		}

		self::$last_id = (int) $new_id;

		$result = $is_new ? 'created' : 'updated';

		drwealth_substack_stamp( 'post', $new_id, $result );

		return $result;
	}

	const META_IMAGE_SOURCE = '_drwealth_substack_image_source';

	/**
	 * Replace every Substack-hosted <img> in the content with a local Media
	 * Library image (downloaded once, reused on re-import), and repoint the
	 * links that wrap them.
	 *
	 * @return array{content: string, first_id: int}
	 */
	protected static function localize_images( $content, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$first_id = 0;
		$map      = array(); // image key (uuid_WxH.ext) => local full URL.

		// <picture><source srcset=Substack CDN> would still load remote images.
		$content = preg_replace( '#<source\b[^>]*>#i', '', $content );

		$content = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( $post_id, &$first_id, &$map ) {
				$tag = new WP_HTML_Tag_Processor( $m[0] );

				if ( ! $tag->next_tag( 'img' ) ) {
					return $m[0];
				}

				$original = self::original_image_url( $tag );

				if ( ! $original ) {
					return $m[0];
				}

				$attachment_id = self::sideload_once( $original, $post_id );

				if ( ! $attachment_id ) {
					return $m[0]; // Leave as-is if the download failed.
				}

				if ( ! $first_id ) {
					$first_id = $attachment_id;
				}

				$key = self::image_key( $original );
				if ( $key ) {
					$map[ $key ] = wp_get_attachment_url( $attachment_id );
				}

				$alt = (string) $tag->get_attribute( 'alt' );

				return wp_get_attachment_image(
					$attachment_id,
					'full',
					false,
					array(
						'class'   => 'wp-image-' . $attachment_id,
						'alt'     => $alt,
						'loading' => 'lazy',
					)
				);
			},
			$content
		);

		// Links wrapping the images pointed at Substack's CDN: send them to the local file.
		$content = preg_replace_callback(
			'#(<a\b[^>]*\bhref=")(https?://(?:substackcdn\.com|[a-z0-9.-]*substack[a-z0-9.-]*\.amazonaws\.com)[^"]*)(")#i',
			function ( $m ) use ( $map ) {
				$key = self::image_key( html_entity_decode( $m[2], ENT_QUOTES ) );

				return ( $key && isset( $map[ $key ] ) ) ? $m[1] . esc_url( $map[ $key ] ) . $m[3] : $m[0];
			},
			$content
		);

		return array(
			'content'  => $content,
			'first_id' => $first_id,
		);
	}

	/* ------------------------------------------------------------ Re-clean (no re-import needed) */

	/**
	 * Posts that still carry something the importer should have cleaned:
	 *  - a link to a newsletter post on substack.com        (any post)
	 *  - a Mighty Networks link (dr-wealth.mn.co)            (any post)
	 *  - a Substack CDN / S3 image URL (hrefs, embedded cards) (posts the import created)
	 *
	 * @return int[] Post IDs.
	 */
	public static function reclean_candidates() {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'post' AND post_status NOT IN ('trash','auto-draft')
			AND ( post_content LIKE '%substack.com/p/%' OR post_content LIKE '%dr-wealth.mn.co%'
				OR post_content LIKE '%substackcdn.com%' OR post_content LIKE '%substack-post-media%' )
			ORDER BY ID"
		);

		$out = array();

		foreach ( $ids as $id ) {
			if ( self::reclean_content( (int) $id, get_post_field( 'post_content', $id ), false ) !== get_post_field( 'post_content', $id ) ) {
				$out[] = (int) $id;
			}
		}

		return $out;
	}

	/**
	 * The cleaned content of a post. With $download false, image URLs are left as they are
	 * (used to find which posts would change without downloading anything).
	 */
	protected static function reclean_content( $post_id, $content, $download ) {
		$created = 'created' === get_post_meta( $post_id, DRWEALTH_SUBSTACK_ORIGIN_META, true );

		// Links to newsletter posts anywhere in the content (including embedded post cards) -> same slug on this site.
		$content = preg_replace_callback(
			'#https?://(?:www\.)?(?:finbiteinsights|growthdragons)\.substack\.com/p/([a-z0-9-]+)[^"\'\s<>&]*#i',
			function ( $m ) {
				return home_url( '/' . strtolower( $m[1] ) . '/' );
			},
			$content
		);

		// Mighty Networks community links: remove the link, keep the text.
		$content = preg_replace( '#<a\b[^>]*\bhref="https?://dr-wealth\.mn\.co[^"]*"[^>]*>(.*?)</a>#is', '$1', $content );

		if ( ! $created || ! $download ) {
			if ( $created && ! $download && preg_match( '#substackcdn\.com|substack-post-media#i', $content ) ) {
				// Detection only: mark the content as "would change" (never saved).
				return $content . '<!--dw-reclean-images-->';
			}

			return $content;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$cache = array();

		return preg_replace_callback(
			'#https?://(?:substackcdn\.com/image/fetch/[^"\'\s<>&)]+|[a-z0-9.-]*substack[a-z0-9.-]*\.amazonaws\.com/[^"\'\s<>&)]+)#i',
			function ( $m ) use ( $post_id, &$cache ) {
				$url = $m[0];

				if ( isset( $cache[ $url ] ) ) {
					return $cache[ $url ];
				}

				$original = $url;

				if ( preg_match( '#substackcdn\.com/image/fetch/[^/]*/(https?%3A.+)$#i', $url, $o ) ) {
					$original = rawurldecode( $o[1] );
				}

				$attachment_id = self::sideload_once( esc_url_raw( $original ), $post_id );
				$cache[ $url ] = $attachment_id ? wp_get_attachment_url( $attachment_id ) : $url;

				return $cache[ $url ];
			},
			$content
		);
	}

	/**
	 * Clean the posts found by reclean_candidates(). Writes post_content straight to the
	 * database so the modified date, revisions and save hooks are not touched.
	 *
	 * @return array{changed:int, left:int, found:int}
	 */
	public static function reclean_run( $apply, $deadline = 0 ) {
		global $wpdb;

		$ids     = self::reclean_candidates();
		$changed = 0;

		if ( ! $apply ) {
			return array( 'changed' => 0, 'left' => count( $ids ), 'found' => count( $ids ) );
		}

		self::$run_id = '';
		self::begin_import_guards();

		foreach ( $ids as $id ) {
			if ( $deadline && time() > $deadline ) {
				break;
			}

			$old = get_post_field( 'post_content', $id );
			$new = self::reclean_content( $id, $old, true );

			if ( $new !== $old ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
				clean_post_cache( $id );
				$changed++;
			}
		}

		self::end_import_guards();

		return array( 'changed' => $changed, 'left' => count( self::reclean_candidates() ), 'found' => count( $ids ) );
	}

	/**
	 * The original (full-size) image URL for a Substack <img>: prefer the S3 URL
	 * in data-attrs, else unwrap a substackcdn fetch URL, else the src itself.
	 */
	protected static function original_image_url( $tag ) {
		$attrs = $tag->get_attribute( 'data-attrs' );

		if ( is_string( $attrs ) && '' !== $attrs ) {
			$json = json_decode( $attrs, true );
			if ( is_array( $json ) && ! empty( $json['src'] ) ) {
				return esc_url_raw( $json['src'] );
			}
		}

		$src = (string) $tag->get_attribute( 'src' );

		if ( preg_match( '#substackcdn\.com/image/fetch/[^/]*/(https?%3A.+)$#i', $src, $m ) ) {
			return esc_url_raw( rawurldecode( $m[1] ) );
		}

		// Jetpack/Photon proxy of an S3 URL.
		if ( preg_match( '#^https?://i\d\.wp\.com/(substack[^?]+)#i', $src, $m ) ) {
			return esc_url_raw( 'https://' . $m[1] );
		}

		return preg_match( '#^https?://#i', $src ) ? esc_url_raw( $src ) : '';
	}

	/** Stable key for an image, e.g. "dc1099a7-...-..._1050x1162.png". */
	protected static function image_key( $url ) {
		$url = rawurldecode( $url );

		return preg_match( '#([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}_\d+x\d+\.[a-z0-9]+)#i', $url, $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * Download an image into the Media Library, unless this exact source URL
	 * was already imported (then reuse that attachment).
	 *
	 * @return int Attachment ID, or 0 on failure.
	 */
	protected static function sideload_once( $url, $post_id ) {
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => self::META_IMAGE_SOURCE,
				'meta_value'     => $url,
			)
		);

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		// Fallback for images downloaded by an earlier version of this importer
		// (no source-URL meta): match on the unique Substack filename
		// (a UUID, so a match is safe) and adopt it.
		$key = self::image_key( $url );

		if ( $key ) {
			$stem  = pathinfo( $key, PATHINFO_FILENAME );
			$found = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_query'     => array(
						array(
							'key'     => '_wp_attached_file',
							'value'   => $stem,
							'compare' => 'LIKE',
						),
					),
				)
			);

			if ( ! empty( $found ) ) {
				update_post_meta( $found[0], self::META_IMAGE_SOURCE, $url );
				return (int) $found[0];
			}
		}

		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$name = sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ) );

		// Substack sometimes serves images as "*.bin" (WordPress refuses that): name the file after what it really is.
		$info = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $tmp ) : false;

		if ( $info && ! empty( $info['mime'] ) && ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $name ) ) {
			$ext  = ltrim( (string) image_type_to_extension( $info[2] ), '.' );
			$ext  = 'jpeg' === $ext ? 'jpg' : $ext;
			$name = ( preg_replace( '/\.[a-z0-9]+$/i', '', $name ) ?: 'substack-image' ) . '.' . ( $ext ?: 'jpg' );
		}

		$id = media_handle_sideload(
			array(
				'name'     => $name ? $name : 'substack-image.jpg',
				'tmp_name' => $tmp,
			),
			$post_id
		);

		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return 0;
		}

		update_post_meta( $id, self::META_IMAGE_SOURCE, $url );

		if ( self::$run_id ) {
			update_post_meta( $id, self::META_RUN, self::$run_id ); // Created by this run (so it can be undone).
		}

		return (int) $id;
	}

	/**
	 * Find a WordPress post previously imported from a given Substack post,
	 * scoped to a publication so that IDs can never collide across the two
	 * Substacks.
	 *
	 * @return int Post ID, or 0 if none found.
	 */
	/**
	 * Find an older post that is the same article as a Substack one, when the slug alone does not match.
	 * Skips posts created by an import run (those are the new copies) and $exclude.
	 *
	 * 1. Slug prefix: the old slug starts with Substack's (truncated) slug, published within 3 days.
	 * 2. Same title (ignoring case, punctuation and HTML entities), published within 1 day.
	 *
	 * @param string $title     Post title.
	 * @param string $post_date Site-local publish date (Y-m-d H:i:s).
	 * @param string $slug      Substack slug (post name).
	 * @param int    $exclude   Post ID to ignore.
	 * @return int Post ID or 0.
	 */
	public static function find_match_by_content( $title, $post_date, $slug, $exclude = 0 ) {
		global $wpdb;

		$ts = $post_date ? strtotime( $post_date ) : false;

		if ( ! $ts ) {
			return 0;
		}

		$statuses = "('publish','future','draft','pending','private')";
		$not_run  = $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s)", self::META_RUN ); // phpcs:ignore WordPress.DB

		if ( '' !== (string) $slug ) {
			$id = $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status IN $statuses AND p.post_name LIKE %s AND p.post_date BETWEEN %s AND %s AND p.ID <> %d AND $not_run ORDER BY p.ID ASC LIMIT 1", // phpcs:ignore WordPress.DB
					$wpdb->esc_like( $slug ) . '%',
					gmdate( 'Y-m-d H:i:s', $ts - 3 * DAY_IN_SECONDS ),
					gmdate( 'Y-m-d H:i:s', $ts + 3 * DAY_IN_SECONDS ),
					(int) $exclude
				)
			);

			if ( $id ) {
				return (int) $id;
			}
		}

		if ( '' === trim( (string) $title ) ) {
			return 0;
		}

		$norm = function ( $s ) {
			return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) ) ) );
		};

		$want = $norm( $title );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p WHERE p.post_type = 'post' AND p.post_status IN $statuses AND p.post_date BETWEEN %s AND %s AND p.ID <> %d AND $not_run ORDER BY p.ID ASC", // phpcs:ignore WordPress.DB
				gmdate( 'Y-m-d H:i:s', $ts - DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $ts + DAY_IN_SECONDS ),
				(int) $exclude
			)
		);

		foreach ( (array) $rows as $row ) {
			if ( '' !== $want && $norm( $row->post_title ) === $want ) {
				return (int) $row->ID;
			}
		}

		return 0;
	}

	protected static function find_existing_post( $substack_id, $publication_key, $slug = '', $title = '', $post_date = '' ) {
		$existing = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_SUBSTACK_ID,
						'value' => $substack_id,
					),
					array(
						'key'   => self::META_PUBLICATION,
						'value' => $publication_key,
					),
				),
			)
		);

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		// Posts from the first (earlier) import have longer slugs than Substack's (WordPress built
		// them from the full title), so an exact slug match misses them. Also match on the slug
		// prefix or on the same title + date.
		if ( '' !== (string) $title || '' !== $slug ) {
			$by_content = self::find_match_by_content( $title, $post_date, $slug, 0 );

			if ( $by_content ) {
				return $by_content;
			}
		}

		// Not imported before: is there already a post with this slug (e.g. from the earlier
		// import)? Matching on the slug avoids creating a "-2" duplicate.
		if ( '' !== $slug ) {
			$by_slug = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'name'           => $slug,
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);

			if ( ! empty( $by_slug ) ) {
				return (int) $by_slug[0];
			}
		}

		return 0;
	}

	/** The post slug part of a Substack post ID ("12345.my-post" -> "my-post"). */
	protected static function slug_from_id( $substack_id ) {
		$parts = explode( '.', $substack_id, 2 );

		return isset( $parts[1] ) ? sanitize_title( $parts[1] ) : '';
	}

	/**
	 * Strip Substack-only reader artifacts (subscribe / share / "Leave a comment" buttons and
	 * the in-email paywall marker), rewrite links to the old Substack posts so they point at
	 * the same post on this site, remove Mighty Networks links (keeping the text), and
	 * sanitize the remaining HTML for post_content.
	 *
	 * The whole post is already gated at the WordPress level via its
	 * membership category, so these Substack-specific elements aren't needed.
	 */
	protected static function clean_content( $html ) {
		$html = preg_replace( '/<p class="button-wrapper".*?<\/p>/s', '', $html );
		$html = preg_replace( '/<div class="paywall-jump"[^>]*><\/div>/s', '', $html );
		$html = self::strip_substack_widgets( $html );

		// Links to the old newsletter posts -> the same post on this site.
		$html = preg_replace_callback(
			'#(\bhref=")https?://(?:www\.)?(?:finbiteinsights|growthdragons)\.substack\.com/p/([a-z0-9-]+)[^"]*(")#i',
			function ( $m ) {
				return $m[1] . esc_url( home_url( '/' . strtolower( $m[2] ) . '/' ) ) . $m[3];
			},
			$html
		);

		// Mighty Networks community links: remove the link, keep its text.
		$html = preg_replace( '#<a\b[^>]*\bhref="https?://dr-wealth\.mn\.co[^"]*"[^>]*>(.*?)</a>#is', '$1', $html );

		return wp_kses_post( $html );
	}

	/**
	 * Remove Substack widgets that don't belong on this site: subscribe / share / comment
	 * buttons, subscription boxes and the paywall marker. Falls back to the unchanged HTML
	 * if it can't be parsed.
	 */
	protected static function strip_substack_widgets( $html ) {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadHTML( '<?xml encoding="utf-8" ?><div id="dw-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		if ( ! $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return $html;
		}

		$xpath   = new DOMXPath( $doc );
		$classes = array( 'button-wrapper', 'paywall-jump', 'subscription-widget-wrap', 'subscription-widget-wrap-editor', 'subscribe-widget', 'captioned-button-wrap', 'share-widget' );
		$tests   = array();

		foreach ( $classes as $class ) {
			$tests[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
		}

		$tests[] = "@data-component-name='PaywallToDOM'";

		$nodes = array();

		foreach ( $xpath->query( '//*[' . implode( ' or ', $tests ) . ']' ) as $node ) {
			$nodes[] = $node;
		}

		foreach ( $nodes as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		$root = $xpath->query( '//div[@id="dw-root"]' )->item( 0 );
		$out  = '';

		if ( $root ) {
			foreach ( $root->childNodes as $child ) {
				$out .= $doc->saveHTML( $child );
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return '' !== $out ? $out : $html;
	}

	/**
	 * Get (or create) a WordPress category term. Looks up by slug first
	 * (the more reliable identifier for an already-existing category like
	 * "United States" / "China" / "Premium"), falling back to name, and
	 * only creating a new term if neither matches.
	 *
	 * @return int Term ID (0 on failure).
	 */
	protected static function get_or_create_category( $name, $slug = '' ) {
		if ( $slug ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( $term ) {
				return (int) $term->term_id;
			}
		}

		$term = get_term_by( 'name', $name, 'category' );

		if ( $term ) {
			return (int) $term->term_id;
		}

		$args     = $slug ? array( 'slug' => $slug ) : array();
		$inserted = wp_insert_term( $name, 'category', $args );

		if ( is_wp_error( $inserted ) ) {
			// Likely created concurrently; look it up again.
			$term = get_term_by( 'name', $name, 'category' );
			return $term ? (int) $term->term_id : 0;
		}

		return (int) $inserted['term_id'];
	}

	/**
	 * Convert a Substack ISO-8601 date string to a MySQL UTC datetime.
	 */
	protected static function to_mysql_gmt( $iso_date ) {
		$timestamp = strtotime( $iso_date );

		if ( ! $timestamp ) {
			$timestamp = time();
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Best-effort link back to the original Substack post.
	 */
	protected static function source_url( $publication_key, $substack_id ) {
		$parts = explode( '.', $substack_id, 2 );
		$slug  = isset( $parts[1] ) ? $parts[1] : $substack_id;

		return sprintf( 'https://%s.substack.com/p/%s', $publication_key, $slug );
	}

	/**
	 * Sideload the first image found in the raw post HTML as the post's
	 * featured image, if one isn't already set.
	 */
	protected static function maybe_set_featured_image( $post_id, $raw_html ) {
		if ( ! preg_match( '/<img[^>]+src="([^"]+)"/i', $raw_html, $matches ) ) {
			return;
		}

		$image_url = html_entity_decode( $matches[1], ENT_QUOTES );
		$image_id  = media_sideload_image( $image_url, $post_id, null, 'id' );

		if ( ! is_wp_error( $image_id ) ) {
			if ( self::$run_id ) {
				update_post_meta( $image_id, self::META_RUN, self::$run_id );
			}

			set_post_thumbnail( $post_id, $image_id );
		}
	}

}
