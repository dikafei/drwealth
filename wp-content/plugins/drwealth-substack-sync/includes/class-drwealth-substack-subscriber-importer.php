<?php
/**
 * Imports subscribers from a Substack "email_list" export CSV into
 * WordPress users, and assigns them into a WooCommerce Membership plan
 * based on their Substack subscription status.
 *
 * Runs independently from the post importer (separate admin form/action),
 * even though both read from the same uploaded export zip.
 *
 * Both Substack publications (Finbite Insights, Growth Dragons) feed into
 * one combined WordPress site, so there's no publication picker here: the
 * publication is auto-detected per file (see detect_publication_from_filename())
 * purely so it can be recorded for historical/admin reference. When the same
 * person is found under more than one publication, they're given whichever
 * membership plan ranks highest overall (see determine_effective_membership()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DrWealth_Substack_Subscriber_Importer {

	/**
	 * The WooCommerce Membership plans currently in use. "insiders-membership"
	 * and "insiders-lifetime-membership" also exist on the site, but we don't
	 * assign those yet — there's no signal in the export to distinguish an
	 * "insider" from a regular premium subscriber. Similarly, "comp" and
	 * "other" plan values, and the yearly/monthly distinction, aren't
	 * differentiated yet (undecided whether the client wants that split) —
	 * they're all just treated as regular active subscribers.
	 */
	const MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG = 'premium-lifetime-membership';
	const MEMBERSHIP_PLAN_PREMIUM_SLUG          = 'premium-membership';
	const MEMBERSHIP_PLAN_FREE_SLUG             = 'free-membership';

	// Per-publication meta (suffixed with "_<publication_key>").
	const META_PLAN       = '_drwealth_substack_plan';
	const META_ACTIVE     = '_drwealth_substack_active_subscription';
	const META_EXPIRY     = '_drwealth_substack_expiry';
	const META_CREATED_AT = '_drwealth_substack_created_at';
	const META_MEMBERSHIP = '_drwealth_substack_membership_plan';

	// Not suffixed: a running list of which publications this user came from.
	const META_PUBLICATIONS = '_drwealth_substack_publications';

	/**
	 * Import subscribers from a Substack export zip. There's no publication
	 * picker here (unlike the post importer) — both Substacks feed into the
	 * same combined WordPress site, so the publication is only used as a
	 * historical label. It's auto-detected from the CSV filename itself
	 * (Substack names it "email_list.<publication>.csv"); each zip is
	 * assumed to contain exactly one such file, since that's how Substack's
	 * own per-publication export works.
	 *
	 * The same person will often get imported twice over time — once from
	 * each publication's export — which is exactly what
	 * determine_effective_membership() is for: it looks across every
	 * publication a user is known from and keeps whichever tier is highest,
	 * regardless of which import (or which run) it came from.
	 *
	 * @param string $zip_path Absolute path to the uploaded zip file.
	 * @param int    $limit    Max number of subscribers to import (most recently created first).
	 * @return array {
	 *     @type int   $created Number of new WP users created.
	 *     @type int   $updated Number of existing WP users overwritten.
	 *     @type array $skipped Emails skipped (e.g. blank/invalid).
	 *     @type array $errors  Human-readable error strings.
	 * }
	 */
	public static function import_zip( $zip_path, $limit = 10 ) {

		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => array(),
			'errors'  => array(),
		);

		if ( ! self::memberships_available() ) {
			$result['errors'][] = 'WooCommerce Memberships functions were not found — users will be created/updated, but no membership plan will be assigned.';
		}

		$extract_to = DrWealth_Substack_Zip_Helper::extract( $zip_path );

		if ( is_wp_error( $extract_to ) ) {
			$result['errors'][] = 'Could not extract zip: ' . $extract_to->get_error_message();
			return $result;
		}

		$csv_path = DrWealth_Substack_Zip_Helper::find_file_by_prefix( $extract_to, 'email_list', '.csv' );

		if ( ! $csv_path ) {
			$result['errors'][] = 'No email_list*.csv found inside the uploaded export.';
			DrWealth_Substack_Zip_Helper::cleanup( $extract_to );
			return $result;
		}

		$publication_key = self::detect_publication_from_filename( $csv_path );
		$rows            = DrWealth_Substack_Zip_Helper::parse_csv( $csv_path );

		// Most recently created subscribers first.
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( isset( $b['created_at'] ) ? $b['created_at'] : '', isset( $a['created_at'] ) ? $a['created_at'] : '' );
			}
		);

		$limit = max( 1, min( 1000, (int) $limit ) );
		$rows  = array_slice( $rows, 0, $limit );

		foreach ( $rows as $row ) {
			$outcome = self::import_row( $row, $publication_key );

			if ( is_wp_error( $outcome ) ) {
				$result['errors'][] = ( isset( $row['email'] ) ? $row['email'] : '?' ) . ': ' . $outcome->get_error_message();
				continue;
			}

			if ( 'created' === $outcome ) {
				$result['created']++;
			} elseif ( 'updated' === $outcome ) {
				$result['updated']++;
			} else {
				$result['skipped'][] = isset( $row['email'] ) ? $row['email'] : '';
			}
		}

		DrWealth_Substack_Zip_Helper::cleanup( $extract_to );

		return $result;
	}

	/**
	 * Derive the publication key from a "email_list.<publication>.csv"
	 * filename. Falls back to "unknown" for anything that doesn't match —
	 * import still proceeds, it's just labeled generically.
	 */
	protected static function detect_publication_from_filename( $csv_path ) {
		$filename = basename( $csv_path );

		if ( preg_match( '/^email_list\.([a-z0-9_-]+)\.csv$/i', $filename, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return 'unknown';
	}

	/**
	 * Import a single CSV row (one subscriber) into a WordPress user.
	 *
	 * @return string|WP_Error 'created', 'updated', 'skipped', or a WP_Error on failure.
	 */
	protected static function import_row( array $row, $publication_key ) {

		$email = isset( $row['email'] ) ? sanitize_email( trim( $row['email'] ) ) : '';

		if ( ! $email || ! is_email( $email ) ) {
			return 'skipped';
		}

		// Dedupe key: email. Re-importing the same subscriber updates the
		// existing WordPress user instead of creating a duplicate.
		$existing_user = get_user_by( 'email', $email );
		$is_new        = ! $existing_user;

		if ( $existing_user ) {
			$user_id = $existing_user->ID;
		} else {
			$user_id = self::create_user( $email );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		$is_active = isset( $row['active_subscription'] ) && 'true' === $row['active_subscription'];
		$plan_raw  = isset( $row['plan'] ) ? $row['plan'] : '';

		update_user_meta( $user_id, self::META_ACTIVE . '_' . $publication_key, $is_active ? 'true' : 'false' );
		update_user_meta( $user_id, self::META_PLAN . '_' . $publication_key, $plan_raw );
		update_user_meta( $user_id, self::META_EXPIRY . '_' . $publication_key, isset( $row['expiry'] ) ? $row['expiry'] : '' );
		update_user_meta( $user_id, self::META_CREATED_AT . '_' . $publication_key, isset( $row['created_at'] ) ? $row['created_at'] : '' );

		// This publication's own tier — kept per-publication for historical
		// reference (shown on the user profile), even though the actual
		// WooCommerce membership assigned below is the combined one.
		//
		// Lifetime if the export's plan is "lifetime", Premium for any other
		// active subscription (yearly, monthly, comp, or "other" — not yet
		// differentiated), Free if inactive.
		if ( $is_active && 'lifetime' === $plan_raw ) {
			$tier = self::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG;
		} elseif ( $is_active ) {
			$tier = self::MEMBERSHIP_PLAN_PREMIUM_SLUG;
		} else {
			$tier = self::MEMBERSHIP_PLAN_FREE_SLUG;
		}

		update_user_meta( $user_id, self::META_MEMBERSHIP . '_' . $publication_key, $tier );

		self::track_publication( $user_id, $publication_key );

		// Both Substacks feed into the same WordPress site, so if this
		// person exists under more than one publication, they get whichever
		// plan is highest across all of them — not just this publication's.
		$effective_slug = self::determine_effective_membership( $user_id );

		self::ensure_membership( $user_id, $effective_slug );

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * The highest WooCommerce Membership tier this user qualifies for,
	 * across every publication they've been imported from.
	 */
	public static function determine_effective_membership( $user_id ) {
		$rank = array(
			self::MEMBERSHIP_PLAN_FREE_SLUG             => 0,
			self::MEMBERSHIP_PLAN_PREMIUM_SLUG          => 1,
			self::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG => 2,
		);

		$best_slug = self::MEMBERSHIP_PLAN_FREE_SLUG;
		$best_rank = 0;

		foreach ( self::get_tracked_publications( $user_id ) as $publication_key ) {
			$slug = get_user_meta( $user_id, self::META_MEMBERSHIP . '_' . $publication_key, true );

			if ( isset( $rank[ $slug ] ) && $rank[ $slug ] > $best_rank ) {
				$best_rank = $rank[ $slug ];
				$best_slug = $slug;
			}
		}

		return $best_slug;
	}

	/**
	 * Which Substack publication(s) this user is known from.
	 *
	 * @return string[]
	 */
	public static function get_tracked_publications( $user_id ) {
		$existing = get_user_meta( $user_id, self::META_PUBLICATIONS, true );

		return $existing ? array_filter( explode( ',', $existing ) ) : array();
	}

	/**
	 * Create a new WordPress user for a subscriber. Uses wp_insert_user()
	 * directly (not wc_create_new_customer()), so no "new account"/welcome
	 * emails are triggered for a bulk import.
	 *
	 * @return int|WP_Error User ID, or WP_Error on failure.
	 */
	protected static function create_user( $email ) {
		$local_part = current( explode( '@', $email ) );
		$base_login = sanitize_user( $local_part, true );

		if ( '' === $base_login ) {
			$base_login = 'subscriber';
		}

		$login = $base_login;
		$i     = 1;

		while ( username_exists( $login ) ) {
			$i++;
			$login = $base_login . $i;
		}

		return wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 20, true ),
				'role'       => 'customer',
			)
		);
	}

	/**
	 * Record which Substack publication(s) this user is known from.
	 */
	protected static function track_publication( $user_id, $publication_key ) {
		$list = self::get_tracked_publications( $user_id );

		if ( ! in_array( $publication_key, $list, true ) ) {
			$list[] = $publication_key;
		}

		update_user_meta( $user_id, self::META_PUBLICATIONS, implode( ',', $list ) );
	}

	/**
	 * Make sure the user holds an active membership in the given plan
	 * (by slug), creating one if they don't already have it. Existing
	 * memberships in other plans are left untouched.
	 */
	protected static function ensure_membership( $user_id, $plan_slug ) {
		if ( ! self::memberships_available() ) {
			return;
		}

		$plan = wc_memberships_get_membership_plan( $plan_slug );

		if ( ! $plan ) {
			return;
		}

		$membership = wc_memberships_get_user_membership( $user_id, $plan->get_id() );

		if ( ! $membership ) {
			wc_memberships_create_user_membership(
				array(
					'user_id' => $user_id,
					'plan_id' => $plan->get_id(),
				)
			);
			return;
		}

		if ( 'active' !== $membership->get_status() ) {
			$membership->update_status( 'active' );
		}
	}

	protected static function memberships_available() {
		return function_exists( 'wc_memberships_get_membership_plan' )
			&& function_exists( 'wc_memberships_get_user_membership' )
			&& function_exists( 'wc_memberships_create_user_membership' );
	}
}
