<?php
/**
 * Free unlocks: let logged-in Free-membership / lapsed members read a limited
 * number of restricted (Premium) articles before the paywall fully applies.
 *
 * - Logged-out visitors: unchanged (normal WooCommerce Memberships paywall).
 * - Eligible: logged in, can't view the post, and has a membership record of any
 *   status (Free plan, or a paid plan that expired/cancelled).
 * - Allowance: N unlocks, per month (default) or in total. Settings > Free Unlocks.
 * - An unlocked post stays readable for the rest of the period (month), and
 *   re-reading it doesn't use another credit.
 *
 * Works on top of the existing WooCommerce Memberships paywall message: that
 * message still shows; we add an "Unlock" box under it and, once unlocked,
 * replace the paywall with the real post content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DRWEALTH_UNLOCKS_META   = 'drwealth_free_unlocks'; // array( post_id => 'Y-m' ).
const DRWEALTH_UNLOCKS_OPTION = 'drwealth_free_unlocks_settings';

/* ---------------------------------------------------------------- Settings */

function drwealth_unlocks_settings() {
	return wp_parse_args(
		get_option( DRWEALTH_UNLOCKS_OPTION, array() ),
		array(
			'enabled' => 1,
			'limit'   => 3,
			'period'  => 'monthly', // monthly|total.
		)
	);
}

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Free Unlocks', 'Free Unlocks', 'manage_options', 'drwealth-free-unlocks', 'drwealth_unlocks_render_settings' );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'drwealth_free_unlocks',
			DRWEALTH_UNLOCKS_OPTION,
			array(
				'sanitize_callback' => function ( $in ) {
					return array(
						'enabled' => empty( $in['enabled'] ) ? 0 : 1,
						'limit'   => max( 0, (int) ( isset( $in['limit'] ) ? $in['limit'] : 3 ) ),
						'period'  => ( isset( $in['period'] ) && 'total' === $in['period'] ) ? 'total' : 'monthly',
					);
				},
			)
		);
	}
);

function drwealth_unlocks_render_settings() {
	$s = drwealth_unlocks_settings();
	?>
	<div class="wrap">
		<h1>Free Unlocks</h1>
		<p>Lets logged-in Free-membership and lapsed members open a few restricted articles before the paywall fully applies.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'drwealth_free_unlocks' ); ?>
			<table class="form-table">
				<tr>
					<th>Enabled</th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( DRWEALTH_UNLOCKS_OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?> /> Allow free unlocks</label></td>
				</tr>
				<tr>
					<th><label for="dw_unlock_limit">Free unlocks</label></th>
					<td><input type="number" min="0" id="dw_unlock_limit" name="<?php echo esc_attr( DRWEALTH_UNLOCKS_OPTION ); ?>[limit]" value="<?php echo esc_attr( $s['limit'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="dw_unlock_period">Period</label></th>
					<td>
						<select id="dw_unlock_period" name="<?php echo esc_attr( DRWEALTH_UNLOCKS_OPTION ); ?>[period]">
							<option value="monthly" <?php selected( $s['period'], 'monthly' ); ?>>Per calendar month (resets monthly)</option>
							<option value="total" <?php selected( $s['period'], 'total' ); ?>>In total (never resets)</option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/* ------------------------------------------------------------------- Logic */

function drwealth_unlocks_period_key() {
	$s = drwealth_unlocks_settings();
	return 'total' === $s['period'] ? 'total' : wp_date( 'Y-m' );
}

/**
 * Unlocks that count in the current period: post_id => period key.
 *
 * Also honours the old "3 free articles per month" WPCode snippet's data (user meta
 * "free_articles_YYYY_MM", a list of post IDs) so members who already used some of
 * their free articles this month don't get a fresh allowance when the snippet is
 * switched off. Only applies to the monthly period.
 */
function drwealth_unlocks_current( $user_id ) {
	$all = get_user_meta( $user_id, DRWEALTH_UNLOCKS_META, true );
	$all = is_array( $all ) ? $all : array();
	$key = drwealth_unlocks_period_key();

	$current = array_filter(
		$all,
		function ( $period ) use ( $key ) {
			return $period === $key;
		}
	);

	if ( 'total' !== $key ) {
		$legacy = get_user_meta( $user_id, 'free_articles_' . str_replace( '-', '_', $key ), true );

		foreach ( array_filter( (array) $legacy ) as $legacy_post_id ) {
			$current[ (int) $legacy_post_id ] = $key;
		}
	}

	return $current;
}

function drwealth_unlocks_remaining( $user_id ) {
	$s = drwealth_unlocks_settings();
	return max( 0, (int) $s['limit'] - count( drwealth_unlocks_current( $user_id ) ) );
}

/** When the monthly allowance resets (first day of next month, site time), e.g. "1 December 2026". '' for "in total". */
function drwealth_unlocks_reset_date() {
	if ( 'total' === drwealth_unlocks_period_key() ) {
		return '';
	}

	$next = new DateTimeImmutable( 'first day of next month midnight', wp_timezone() );

	return wp_date( 'j F Y', $next->getTimestamp() );
}

function drwealth_user_has_unlocked( $post_id, $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();

	if ( ! $user_id ) {
		return false;
	}

	return array_key_exists( (int) $post_id, drwealth_unlocks_current( $user_id ) );
}

/** Restricted for this visitor by WooCommerce Memberships? */
function drwealth_unlocks_post_is_paywalled( $post_id, $user_id ) {
	if ( ! function_exists( 'wc_memberships_is_post_content_restricted' ) || ! wc_memberships_is_post_content_restricted( $post_id ) ) {
		return false;
	}

	if ( function_exists( 'wc_memberships_user_can' ) && wc_memberships_user_can( $user_id, 'view', array( 'post' => $post_id ) ) ) {
		return false;
	}

	return true;
}

/** Logged in, has a membership record of any status (Free plan or lapsed paid). */
function drwealth_unlocks_user_is_eligible( $user_id ) {
	if ( ! $user_id || ! function_exists( 'wc_memberships_get_user_memberships' ) ) {
		return false;
	}

	$memberships = wc_memberships_get_user_memberships( $user_id );

	return ! empty( $memberships );
}

function drwealth_unlocks_can_unlock_now( $post_id, $user_id ) {
	$s = drwealth_unlocks_settings();

	return $s['enabled']
		&& drwealth_unlocks_user_is_eligible( $user_id )
		&& drwealth_unlocks_post_is_paywalled( $post_id, $user_id );
}

/* ----------------------------------------------------------------- Handler */

add_action(
	'admin_post_drwealth_unlock_post',
	function () {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$user_id = get_current_user_id();

		check_admin_referer( 'drwealth_unlock_' . $post_id );

		$back = $post_id ? get_permalink( $post_id ) : home_url( '/' );

		if ( $post_id && $user_id && ! drwealth_user_has_unlocked( $post_id, $user_id )
			&& drwealth_unlocks_can_unlock_now( $post_id, $user_id )
			&& drwealth_unlocks_remaining( $user_id ) > 0 ) {

			$all             = get_user_meta( $user_id, DRWEALTH_UNLOCKS_META, true );
			$all             = is_array( $all ) ? $all : array();
			$all[ $post_id ] = drwealth_unlocks_period_key();
			update_user_meta( $user_id, DRWEALTH_UNLOCKS_META, $all );
			$back = add_query_arg( 'dw_unlocked', '1', $back );
		}

		wp_safe_redirect( $back );
		exit;
	}
);

/* ----------------------------------------------------------- Front end */

/**
 * Restricted posts are personal for logged-in users (paywall vs unlocked article vs
 * "N unlocks left"), so make sure no page cache serves one visitor's version to
 * another or keeps showing a stale one.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_singular() || ! is_user_logged_in() ) {
			return;
		}

		if ( function_exists( 'wc_memberships_is_post_content_restricted' ) && wc_memberships_is_post_content_restricted( get_queried_object_id() ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	},
	0
);


/**
 * Last in line on the_content: either swap the paywall for the real content
 * (already unlocked) or add the unlock box under the paywall.
 */
add_filter(
	'the_content',
	function ( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// Not while an excerpt is being built (the paywall teaser, meta descriptions,
		// search results etc. run the content through this same filter), and only
		// for the article actually being viewed, not related-post loops on the page.
		if ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) || doing_filter( 'the_excerpt' ) || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}

		$post_id = get_the_ID();
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return $content; // Logged-out: normal paywall.
		}

		$s = drwealth_unlocks_settings();

		if ( ! $s['enabled'] || ! drwealth_unlocks_user_is_eligible( $user_id ) ) {
			return $content;
		}

		// Unlocked this period: show the real article (only if Woo would still block it).
		if ( drwealth_user_has_unlocked( $post_id, $user_id ) ) {
			if ( drwealth_unlocks_post_is_paywalled( $post_id, $user_id ) ) {
				return drwealth_unlocks_raw_content( $post_id );
			}
			return $content;
		}

		return $content;
	},
	PHP_INT_MAX
);

/** HTML of the unlock box for the given post/user. */
function drwealth_unlocks_box_html( $post_id, $user_id ) {
	$s         = drwealth_unlocks_settings();
	$remaining = drwealth_unlocks_remaining( $user_id );
	$per       = 'total' === $s['period'] ? '' : ' this month';

	ob_start();
	?>
	<div class="drwealth-unlock-box" style="margin:0 0 24px;padding:16px 20px;border:1px solid #e5d3c8;border-left:4px solid #D26133;border-radius:8px;background:#fdf6f2;text-align:center;">
		<?php if ( $remaining > 0 ) : ?>
			<p><strong>You have <?php echo (int) $remaining; ?> of <?php echo (int) $s['limit']; ?> free article unlocks left<?php echo esc_html( $per ); ?>.</strong></p>
			<form method="post" class="drwealth-unlock-form" data-post="<?php echo (int) $post_id; ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="drwealth_unlock_post" />
				<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
				<?php wp_nonce_field( 'drwealth_unlock_' . $post_id ); ?>
				<button type="submit" class="button">Unlock this article</button>
			</form>
		<?php else : ?>
			<?php $reset = drwealth_unlocks_reset_date(); ?>
				<p><strong>You've used all <?php echo (int) $s['limit']; ?> of your free article unlocks<?php echo esc_html( $per ); ?>.</strong>
					<?php echo $reset ? 'They reset on ' . esc_html( $reset ) . '. ' : ''; ?>Subscribe to keep reading now.</p>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Inject the box into the final page HTML (not via the_content, so it never leaks
 * into auto-generated excerpts): above the Memberships restriction message,
 * falling back to the start of the article body (theme independent).
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_singular() || ! is_user_logged_in() ) {
			return;
		}

		$post_id = get_queried_object_id();
		$user_id = get_current_user_id();
		$s       = drwealth_unlocks_settings();

		if ( ! $post_id || ! $s['enabled'] || ! drwealth_unlocks_user_is_eligible( $user_id )
			|| drwealth_user_has_unlocked( $post_id, $user_id )
			|| ! drwealth_unlocks_post_is_paywalled( $post_id, $user_id ) ) {
			return;
		}

		// Build the box now: output buffering is not allowed inside an ob callback.
		$box_html = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), drwealth_unlocks_box_html( $post_id, $user_id ) );

		ob_start(
			function ( $html ) use ( $box_html ) {
				if ( false !== strpos( $html, 'class="drwealth-unlock-box"' ) ) {
					return $html;
				}

				$box = $box_html;

				$count = 0;
				$out   = preg_replace(
					'#(<div[^>]*class="[^"]*wc-memberships-restriction-message)#',
					$box . '$1',
					$html,
					1,
					$count
				);

				// Fallback: start of the article body (JNews .content-inner, block theme
				// .wp-block-post-content, classic .entry-content), first one found.
				foreach ( array( 'content-inner', 'wp-block-post-content', 'entry-content' ) as $body_class ) {
					if ( $count ) {
						break;
					}

					$out = preg_replace(
						'#(<div[^>]*class="[^"]*' . $body_class . '[^"]*"[^>]*>)#',
						'$1' . $box,
						$html,
						1,
						$count
					);
				}

				return ( $count && null !== $out ) ? $out : $html;
			}
		);
	},
	1
);

/**
 * Remember where the reader was when they pressed "Unlock", so the page they land on
 * after the unlock (the same article, now open) is scrolled to the same spot.
 */
add_action(
	'wp_footer',
	function () {
		if ( ! is_singular() || ! is_user_logged_in() ) {
			return;
		}

		$post_id  = (int) get_queried_object_id();
		$unlocked = isset( $_GET['dw_unlocked'] ) && drwealth_user_has_unlocked( $post_id, get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<script id="drwealth-unlock-scroll">
		(function () {
			var key = 'dwUnlockY:<?php echo (int) $post_id; ?>';

			<?php if ( $unlocked ) : ?>
			try {
				var y = parseInt( sessionStorage.getItem( key ), 10 );
				sessionStorage.removeItem( key );
				if ( y > 0 ) {
					var go = function () { window.scrollTo( 0, y ); };
					go();
					window.addEventListener( 'load', go );
				}
			} catch ( e ) {}

			if ( window.history && history.replaceState ) {
				var u = new URL( location.href );
				u.searchParams.delete( 'dw_unlocked' );
				history.replaceState( null, '', u.pathname + u.search + u.hash );
			}
			<?php else : ?>
			document.addEventListener( 'submit', function ( e ) {
				if ( e.target && e.target.classList && e.target.classList.contains( 'drwealth-unlock-form' ) ) {
					try { sessionStorage.setItem( key, String( Math.round( window.pageYOffset ) ) ); } catch ( err ) {}
				}
			} );
			<?php endif; ?>
		})();
		</script>
		<?php
	},
	99
);

/** After an unlock: a short note at the top of the article saying how many unlocks are left. */
add_action(
	'template_redirect',
	function () {
		if ( ! is_singular() || ! is_user_logged_in() || ! isset( $_GET['dw_unlocked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$post_id = get_queried_object_id();
		$user_id = get_current_user_id();

		if ( ! $post_id || ! drwealth_user_has_unlocked( $post_id, $user_id ) ) {
			return;
		}

		$s         = drwealth_unlocks_settings();
		$remaining = drwealth_unlocks_remaining( $user_id );
		$per       = 'total' === $s['period'] ? '' : ' this month';
		$reset     = drwealth_unlocks_reset_date();

		$msg = 'Unlocked. ';
		if ( $remaining > 0 ) {
			$msg .= 'You have ' . $remaining . ' of ' . (int) $s['limit'] . ' free article unlocks left' . $per . '.';
		} else {
			$msg .= 'That was your last free unlock' . $per . '.' . ( $reset ? ' They reset on ' . $reset . '.' : '' );
		}

		$note = '<div class="drwealth-unlock-note" style="margin:0 0 24px;padding:12px 16px;border:1px solid #d1e7dd;border-left:4px solid #198754;border-radius:8px;background:#f1faf5;text-align:center;"><strong>'
			. esc_html( $msg ) . '</strong></div>';
		$note = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $note );

		ob_start(
			function ( $html ) use ( $note ) {
				if ( false !== strpos( $html, 'drwealth-unlock-note' ) ) {
					return $html;
				}

				foreach ( array( 'content-inner', 'wp-block-post-content', 'entry-content' ) as $body_class ) {
					$count = 0;
					$out   = preg_replace(
						'#(<div[^>]*class="[^"]*' . $body_class . '[^"]*"[^>]*>)#',
						'$1' . $note,
						$html,
						1,
						$count
					);

					if ( $count && null !== $out ) {
						return $out;
					}
				}

				return $html;
			}
		);
	},
	1
);

/**
 * The post's real content, rendered without re-running the_content (which would
 * re-trigger the Memberships paywall).
 */
function drwealth_unlocks_raw_content( $post_id ) {
	$c = get_post_field( 'post_content', $post_id );
	// An unlocked post should show any [wcm_restrict] sections too: keep the
	// inner content, drop the wrapper tags.
	$c = preg_replace( '#\[/?wcm_restrict[^\]]*\]#i', '', $c );
	$c = do_blocks( $c );
	$c = wptexturize( $c );
	$c = wpautop( $c );
	$c = shortcode_unautop( $c );
	$c = do_shortcode( $c );
	$c = wp_filter_content_tags( $c );

	return $c;
}

/* ------------------------------------------------- Admin: user profile / reset */

/**
 * Admin-only: shows which articles a member has unlocked this period and lets an
 * admin reset them (handy for testing, or to give someone a fresh allowance).
 */
function drwealth_unlocks_render_profile( $user ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$current = drwealth_unlocks_current( $user->ID );
	$s       = drwealth_unlocks_settings();
	?>
	<h2>Free article unlocks</h2>
	<table class="form-table" role="presentation">
		<tr>
			<th>This <?php echo 'total' === $s['period'] ? 'account' : 'month'; ?></th>
			<td>
				<?php if ( $current ) : ?>
					<ul style="margin:0 0 8px 18px;list-style:disc;">
						<?php foreach ( array_keys( $current ) as $post_id ) : ?>
							<li><a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
					<p><?php echo (int) count( $current ); ?> of <?php echo (int) $s['limit']; ?> used.</p>
				<?php else : ?>
					<p>None used (<?php echo (int) $s['limit']; ?> available).</p>
				<?php endif; ?>
				<label><input type="checkbox" name="drwealth_reset_unlocks" value="1" /> Reset this period's unlocks when I click Update User</label>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'drwealth_unlocks_render_profile' );
add_action( 'edit_user_profile', 'drwealth_unlocks_render_profile' );

function drwealth_unlocks_save_profile( $user_id ) {
	if ( ! current_user_can( 'manage_options' ) || empty( $_POST['drwealth_reset_unlocks'] ) ) {
		return;
	}

	$key = drwealth_unlocks_period_key();

	// Our own record: drop this period's entries.
	$all = get_user_meta( $user_id, DRWEALTH_UNLOCKS_META, true );
	if ( is_array( $all ) ) {
		update_user_meta( $user_id, DRWEALTH_UNLOCKS_META, array_filter( $all, function ( $period ) use ( $key ) { return $period !== $key; } ) );
	}

	// The old "3 free articles per month" snippet's record for this month.
	if ( 'total' !== $key ) {
		delete_user_meta( $user_id, 'free_articles_' . str_replace( '-', '_', $key ) );
	}
}
add_action( 'personal_options_update', 'drwealth_unlocks_save_profile' );
add_action( 'edit_user_profile_update', 'drwealth_unlocks_save_profile' );
