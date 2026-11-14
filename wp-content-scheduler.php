<?php
/**
 * Plugin Name: Content Scheduler
 * Plugin URI: https://github.com/Azanumer/wp-content-scheduler
 * Description: Show posts only inside a date window — schedule seasonal pages, time-limited offers and embargoed posts, plus [show_between] / [hide_between] shortcodes for inline content.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-content-scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCS_VERSION', '1.0.0' );
define( 'WCS_OPTION', 'wcs_settings' );
define( 'WCS_META_START', '_wcs_visible_from' );
define( 'WCS_META_END', '_wcs_visible_until' );

/**
 * Default settings.
 *
 * @return array
 */
function wcs_default_settings() {
	return array(
		'action'       => '404', // '404' or 'redirect'.
		'redirect_url' => '',
		'post_types'   => array( 'post', 'page' ),
	);
}

/**
 * Get merged settings.
 *
 * @return array
 */
function wcs_get_settings() {
	$saved = get_option( WCS_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( wcs_default_settings(), $saved );
}

/**
 * Post types the scheduler applies to.
 *
 * @return array
 */
function wcs_post_types() {
	$settings = wcs_get_settings();
	$types    = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );
	return apply_filters( 'wcs_post_types', $types );
}

/**
 * Parse a stored datetime-local value ("2026-12-01T09:00") into a Unix
 * timestamp in the site timezone. Returns 0 for an empty value.
 *
 * @param string $value Stored value.
 * @return int
 */
function wcs_parse_datetime( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return 0;
	}
	$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$dt = date_create( $value, $tz );
	if ( false === $dt ) {
		return 0;
	}
	return (int) $dt->getTimestamp();
}

/**
 * Is a post inside its visibility window right now?
 *
 * No dates set = always visible. Only a start = visible from that moment.
 * Only an end = visible until that moment.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function wcs_is_visible( $post_id ) {
	$from = wcs_parse_datetime( get_post_meta( $post_id, WCS_META_START, true ) );
	$to   = wcs_parse_datetime( get_post_meta( $post_id, WCS_META_END, true ) );
	$now  = current_time( 'timestamp' );

	if ( $from > 0 && $now < $from ) {
		return false;
	}
	if ( $to > 0 && $now > $to ) {
		return false;
	}
	return true;
}

/**
 * Front-end enforcement: out-of-window single posts behave as configured
 * (404, or a redirect). Logged-in users who can edit the post still see
 * it, so editors can preview scheduled content.
 */
function wcs_enforce_window() {
	if ( ! is_singular() ) {
		return;
	}
	$post_id = get_queried_object_id();
	if ( ! $post_id || ! in_array( get_post_type( $post_id ), wcs_post_types(), true ) ) {
		return;
	}
	if ( current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( wcs_is_visible( $post_id ) ) {
		return;
	}

	$settings = wcs_get_settings();
	$action   = apply_filters( 'wcs_out_of_window_action', $settings['action'], $post_id );

	if ( 'redirect' === $action ) {
		$url = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : home_url( '/' );
		wp_safe_redirect( esc_url_raw( $url ), 302 );
		exit;
	}

	// Default: pretend the post does not exist yet / any more.
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'wcs_enforce_window' );

/**
 * Keep out-of-window posts out of archive and search listings for
 * visitors, so a seasonal offer does not leak into the blog roll.
 * Editors still see their own posts (capability check above does not
 * apply here, so we only filter the front-end main query).
 */
function wcs_filter_main_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( is_singular() ) {
		return;
	}
	$types = array_intersect( (array) $query->get( 'post_type', array( 'post' ) ), wcs_post_types() );
	if ( empty( $types ) ) {
		return;
	}
	$meta_query = (array) $query->get( 'meta_query', array() );
	$now        = current_time( 'Y-m-d H:i:s' );
	$meta_query[] = array(
		'relation' => 'OR',
		array(
			'key'     => WCS_META_START,
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => WCS_META_START,
			'value'   => '',
			'compare' => '=',
		),
		array(
			'key'     => WCS_META_START,
			'value'   => $now,
			'compare' => '<=',
			'type'    => 'DATETIME',
		),
	);
	$meta_query[] = array(
		'relation' => 'OR',
		array(
			'key'     => WCS_META_END,
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => WCS_META_END,
			'value'   => '',
			'compare' => '=',
		),
		array(
			'key'     => WCS_META_END,
			'value'   => $now,
			'compare' => '>=',
			'type'    => 'DATETIME',
		),
	);
	$query->set( 'meta_query', $meta_query );
}
add_action( 'pre_get_posts', 'wcs_filter_main_query' );

/**
 * Shortcode: show the enclosed content only between two datetimes.
 *
 * [show_between start="2026-12-01 09:00" end="2026-12-25 23:59"]...[/show_between]
 * Either attribute may be omitted for a one-sided window.
 *
 * @param array  $atts Shortcode attributes.
 * @param string $content Enclosed content.
 * @return string
 */
function wcs_show_between_shortcode( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'start' => '',
			'end'   => '',
		),
		$atts,
		'show_between'
	);
	$from = wcs_parse_datetime( $atts['start'] );
	$to   = wcs_parse_datetime( $atts['end'] );
	$now  = current_time( 'timestamp' );

	if ( $from > 0 && $now < $from ) {
		return '';
	}
	if ( $to > 0 && $now > $to ) {
		return '';
	}
	return do_shortcode( $content );
}
add_shortcode( 'show_between', 'wcs_show_between_shortcode' );

/**
 * Shortcode: hide the enclosed content between two datetimes.
 *
 * [hide_between start="2026-12-01" end="2026-12-25"]...[/hide_between]
 *
 * @param array  $atts Shortcode attributes.
 * @param string $content Enclosed content.
 * @return string
 */
function wcs_hide_between_shortcode( $atts, $content = '' ) {
	$atts = shortcode_atts(
		array(
			'start' => '',
			'end'   => '',
		),
		$atts,
		'hide_between'
	);
	$from = wcs_parse_datetime( $atts['start'] );
	$to   = wcs_parse_datetime( $atts['end'] );
	$now  = current_time( 'timestamp' );

	if ( $from > 0 && $to > 0 && $now >= $from && $now <= $to ) {
		return '';
	}
	if ( 0 === $from && $to > 0 && $now <= $to ) {
		return '';
	}
	if ( $from > 0 && 0 === $to && $now >= $from ) {
		return '';
	}
	return do_shortcode( $content );
}
add_shortcode( 'hide_between', 'wcs_hide_between_shortcode' );

/**
 * Meta box on the post editor.
 */
function wcs_add_meta_box() {
	foreach ( wcs_post_types() as $type ) {
		add_meta_box(
			'wcs-schedule',
			__( 'Visibility window', 'wp-content-scheduler' ),
			'wcs_render_meta_box',
			$type,
			'side',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'wcs_add_meta_box' );

/**
 * Render the meta box.
 *
 * @param WP_Post $post Current post.
 */
function wcs_render_meta_box( $post ) {
	wp_nonce_field( 'wcs_save_meta', 'wcs_nonce' );
	// Stored as "Y-m-d H:i:s"; the datetime-local input needs "Y-m-dTH:i".
	$from = substr( str_replace( ' ', 'T', (string) get_post_meta( $post->ID, WCS_META_START, true ) ), 0, 16 );
	$to   = substr( str_replace( ' ', 'T', (string) get_post_meta( $post->ID, WCS_META_END, true ) ), 0, 16 );
	?>
	<p>
		<label for="wcs_visible_from"><?php esc_html_e( 'Visible from', 'wp-content-scheduler' ); ?></label><br />
		<input type="datetime-local" id="wcs_visible_from" name="wcs_visible_from" value="<?php echo esc_attr( $from ); ?>" style="width: 100%;" />
	</p>
	<p>
		<label for="wcs_visible_until"><?php esc_html_e( 'Visible until', 'wp-content-scheduler' ); ?></label><br />
		<input type="datetime-local" id="wcs_visible_until" name="wcs_visible_until" value="<?php echo esc_attr( $to ); ?>" style="width: 100%;" />
	</p>
	<p class="description"><?php esc_html_e( 'Leave both empty for always visible. Times use the site timezone.', 'wp-content-scheduler' ); ?></p>
	<?php
}

/**
 * Save the meta box.
 *
 * @param int $post_id Post ID.
 */
function wcs_save_meta_box( $post_id ) {
	if ( ! isset( $_POST['wcs_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wcs_nonce'] ), 'wcs_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! in_array( get_post_type( $post_id ), wcs_post_types(), true ) ) {
		return;
	}

	foreach ( array( 'wcs_visible_from' => WCS_META_START, 'wcs_visible_until' => WCS_META_END ) as $field => $key ) {
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			// Reject values that cannot be parsed — a bad date must never
			// silently hide a post. Store normalized "Y-m-d H:i:s" so the
			// DATETIME comparisons in pre_get_posts are unambiguous.
			$ts = wcs_parse_datetime( $value );
			if ( 0 === $ts ) {
				continue;
			}
			update_post_meta( $post_id, $key, date( 'Y-m-d H:i:s', $ts ) );
		}
	}
}
add_action( 'save_post', 'wcs_save_meta_box' );

/**
 * Settings page under Settings → Content Scheduler.
 */
function wcs_register_settings_page() {
	add_options_page(
		__( 'Content Scheduler', 'wp-content-scheduler' ),
		__( 'Content Scheduler', 'wp-content-scheduler' ),
		'manage_options',
		'wp-content-scheduler',
		'wcs_render_settings_page'
	);
}
add_action( 'admin_menu', 'wcs_register_settings_page' );

/**
 * Register settings.
 */
function wcs_register_settings() {
	register_setting( 'wcs_settings_group', WCS_OPTION, 'wcs_sanitize_settings' );
}
add_action( 'admin_init', 'wcs_register_settings' );

/**
 * Sanitize settings on save.
 *
 * @param array $input Raw input.
 * @return array
 */
function wcs_sanitize_settings( $input ) {
	$out = wcs_default_settings();
	if ( ! is_array( $input ) ) {
		return $out;
	}
	$out['action'] = ( isset( $input['action'] ) && 'redirect' === $input['action'] ) ? 'redirect' : '404';
	$out['redirect_url'] = isset( $input['redirect_url'] ) ? esc_url_raw( $input['redirect_url'] ) : '';
	$types = array();
	if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
		$public = get_post_types( array( 'public' => true ) );
		foreach ( $input['post_types'] as $type ) {
			$type = sanitize_key( $type );
			if ( isset( $public[ $type ] ) ) {
				$types[] = $type;
			}
		}
	}
	$out['post_types'] = ! empty( $types ) ? $types : array( 'post', 'page' );
	return $out;
}

/**
 * Render the settings page.
 */
function wcs_render_settings_page() {
	$settings = wcs_get_settings();
	$types    = get_post_types( array( 'public' => true ), 'objects' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Content Scheduler', 'wp-content-scheduler' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wcs_settings_group' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Out-of-window posts', 'wp-content-scheduler' ); ?></th>
					<td>
						<label><input type="radio" name="<?php echo esc_attr( WCS_OPTION ); ?>[action]" value="404" <?php checked( $settings['action'], '404' ); ?> /> <?php esc_html_e( 'Show a 404 page', 'wp-content-scheduler' ); ?></label><br />
						<label><input type="radio" name="<?php echo esc_attr( WCS_OPTION ); ?>[action]" value="redirect" <?php checked( $settings['action'], 'redirect' ); ?> /> <?php esc_html_e( 'Redirect visitors', 'wp-content-scheduler' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect URL', 'wp-content-scheduler' ); ?></th>
					<td><input type="url" name="<?php echo esc_attr( WCS_OPTION ); ?>[redirect_url]" value="<?php echo esc_attr( $settings['redirect_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Apply to', 'wp-content-scheduler' ); ?></th>
					<td>
						<?php foreach ( $types as $type ) : ?>
							<label style="display: inline-block; margin-right: 12px;">
								<input type="checkbox" name="<?php echo esc_attr( WCS_OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $settings['post_types'], true ) ); ?> />
								<?php echo esc_html( $type->labels->singular_name ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Shortcodes', 'wp-content-scheduler' ); ?></h2>
		<p><code>[show_between start="2026-12-01 09:00" end="2026-12-25 23:59"]...[/show_between]</code></p>
		<p><code>[hide_between start="2026-12-01" end="2026-12-25"]...[/hide_between]</code></p>
		<p class="description"><?php esc_html_e( 'Either date may be omitted for a one-sided window. Times use the site timezone.', 'wp-content-scheduler' ); ?></p>
	</div>
	<?php
}
