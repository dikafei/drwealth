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

		// Take rows in the order they appear in the CSV — top to bottom —
		// rather than re-sorting by created_at. The export's own row order
		// isn't guaranteed to be chronological, and re-sorting also made the
		// importer fragile to date-format changes (e.g. a file re-saved in
		// Excel). "Limit" means the first N rows of the file, full stop.
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

		// Case-insensitive: Substack itself exports lowercase "true"/"false",
		// but a file that's been opened and re-saved in Excel/Sheets often
		// comes back as "TRUE"/"FALSE", which would otherwise silently
		// classify every subscriber in the file as inactive/Free.
		$is_active = isset( $row['active_subscription'] ) && 'true' === strtolower( trim( $row['active_subscription'] ) );
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
		if ( $is_active && 'lifetime' === strtolower( trim( $plan_raw ) ) ) {
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
		$effective = self::get_effective_membership_info( $user_id );

		self::ensure_membership( $user_id, $effective['slug'], self::to_local_mysql( $effective['expiry'] ) );

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * The highest WooCommerce Membership tier this user qualifies for,
	 * across every publication they've been imported from.
	 */
	public static function determine_effective_membership( $user_id ) {
		$info = self::get_effective_membership_info( $user_id );

		return $info['slug'];
	}

	/**
	 * Same as determine_effective_membership(), but also returns which
	 * publication's data is behind that tier, plus that publication's raw
	 * `plan` and `expiry` — used by the welcome email to say something
	 * meaningful about "your plan and expiry date".
	 *
	 * Tier always wins first (free < premium < premium-lifetime). Within the
	 * same tier — e.g. one publication says "premium-membership" from a
	 * monthly plan and another says "premium-membership" from a yearly plan
	 * — the one with the furthest-out expiry wins, so a longer plan is never
	 * shadowed by a shorter one just because it happened to be tracked
	 * first.
	 *
	 * @return array{slug: string, publication: string, plan_raw: string, expiry: string}
	 */
	public static function get_effective_membership_info( $user_id ) {
		$rank = array(
			self::MEMBERSHIP_PLAN_FREE_SLUG             => 0,
			self::MEMBERSHIP_PLAN_PREMIUM_SLUG          => 1,
			self::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG => 2,
		);

		$best = array(
			'slug'        => self::MEMBERSHIP_PLAN_FREE_SLUG,
			'publication' => '',
		);
		$best_rank      = -1;
		$best_expiry_ts = false;

		foreach ( self::get_tracked_publications( $user_id ) as $publication_key ) {
			$slug = get_user_meta( $user_id, self::META_MEMBERSHIP . '_' . $publication_key, true );

			if ( ! isset( $rank[ $slug ] ) ) {
				continue;
			}

			$candidate_rank      = $rank[ $slug ];
			$candidate_expiry_ts = self::expiry_timestamp( $user_id, $publication_key );

			$wins = false;

			if ( $candidate_rank > $best_rank ) {
				$wins = true;
			} elseif ( $candidate_rank === $best_rank ) {
				// Tie within the same tier: prefer whichever has a real
				// expiry date further in the future. If the current best
				// has no usable expiry at all, any candidate with one wins.
				if ( $candidate_expiry_ts && ( ! $best_expiry_ts || $candidate_expiry_ts > $best_expiry_ts ) ) {
					$wins = true;
				}
			}

			if ( $wins ) {
				$best_rank      = $candidate_rank;
				$best_expiry_ts = $candidate_expiry_ts;
				$best           = array(
					'slug'        => $slug,
					'publication' => $publication_key,
				);
			}
		}

		$best['plan_raw'] = $best['publication'] ? get_user_meta( $user_id, self::META_PLAN . '_' . $best['publication'], true ) : '';
		$best['expiry']   = $best['publication'] ? get_user_meta( $user_id, self::META_EXPIRY . '_' . $best['publication'], true ) : '';

		return $best;
	}

	/**
	 * Human-readable label for a membership plan slug, for use in the UI
	 * and in the welcome email.
	 */
	public static function get_plan_label( $slug ) {
		$labels = array(
			self::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG => 'Premium Lifetime Membership',
			self::MEMBERSHIP_PLAN_PREMIUM_SLUG          => 'Premium Membership',
			self::MEMBERSHIP_PLAN_FREE_SLUG             => 'Free Membership',
		);

		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
	}

	/**
	 * Parsed expiry timestamp for a user's given publication, or false if
	 * missing/unparseable.
	 */
	protected static function expiry_timestamp( $user_id, $publication_key ) {
		$raw = get_user_meta( $user_id, self::META_EXPIRY . '_' . $publication_key, true );

		return $raw ? strtotime( $raw ) : false;
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
	 *
	 * These memberships are granted directly (not through a subscription
	 * product purchase), so nothing else on the site will ever expire them.
	 * $end_date fills that gap by setting the membership's own expiration to
	 * match Substack's, and is kept in sync on every re-import (so a renewal
	 * on Substack pushes the WooCommerce expiry out too). Pass '' for plans
	 * that shouldn't expire (lifetime) or when there's no usable expiry data.
	 *
	 * @param int    $user_id   WordPress user ID.
	 * @param string $plan_slug Membership plan slug.
	 * @param string $end_date  Site-local MySQL datetime, or '' to leave/clear it.
	 */
	protected static function ensure_membership( $user_id, $plan_slug, $end_date = '' ) {
		if ( ! self::memberships_available() ) {
			return;
		}

		$plan = wc_memberships_get_membership_plan( $plan_slug );

		if ( ! $plan ) {
			return;
		}

		$membership = wc_memberships_get_user_membership( $user_id, $plan->get_id() );

		if ( ! $membership ) {
			$membership = wc_memberships_create_user_membership(
				array(
					'user_id' => $user_id,
					'plan_id' => $plan->get_id(),
				)
			);
		} elseif ( 'active' !== $membership->get_status() ) {
			$membership->update_status( 'active' );
		}

		// wc_memberships_create_user_membership() is documented to return the
		// new WC_Memberships_User_Membership, but fall back to a fresh lookup
		// in case a given version returns something else (e.g. a post ID).
		if ( ! ( $membership instanceof WC_Memberships_User_Membership ) ) {
			$membership = wc_memberships_get_user_membership( $user_id, $plan->get_id() );
		}

		if ( ! ( $membership instanceof WC_Memberships_User_Membership ) ) {
			return;
		}

		if ( self::MEMBERSHIP_PLAN_PREMIUM_LIFETIME_SLUG === $plan_slug ) {
			$membership->set_end_date( '' ); // Lifetime never expires.
		} elseif ( $end_date ) {
			$membership->set_end_date( $end_date );
		}
	}

	/**
	 * Convert a Substack ISO-8601 datetime (UTC) into a site-local MySQL
	 * datetime, for setting a WooCommerce membership's end date. Returns ''
	 * if the source value is missing or unparseable.
	 */
	protected static function to_local_mysql( $iso_date ) {
		$timestamp = $iso_date ? strtotime( $iso_date ) : false;

		return $timestamp ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ) : '';
	}

	protected static function memberships_available() {
		return function_exists( 'wc_memberships_get_membership_plan' )
			&& function_exists( 'wc_memberships_get_user_membership' )
			&& function_exists( 'wc_memberships_create_user_membership' );
	}
}
