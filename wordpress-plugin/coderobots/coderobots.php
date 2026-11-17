<?php
/**
 * Plugin Name:       CodeRobots
 * Description:       AI-assisted Python editor for LEGO SPIKE Prime, LEGO Education (CS & AI) and micro:bit, with an AI tutor. Add the [coderobots] shortcode to a page. Student work is stored only in the browser. The tutor needs a language-model provider plugin (e.g. the SkoleGPT proxy).
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Future Classroom Lab DK
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       coderobots
 *
 * How it works: the editor is a single-page app built from the
 * code-robots-skolegpt repo (npm run build) into app/. A page whose content
 * contains the [coderobots] shortcode is served as a standalone, full-screen
 * document — the theme is bypassed because the app owns the whole viewport
 * and ships global CSS — with the COOP/COEP headers that LEGO Education mode
 * needs (SharedArrayBuffer). The same app is also always reachable at
 * /?coderobots=app.
 *
 * The tutor runs inside WordPress: the app posts to the REST route
 * coderobots/v1/tutor (includes/rest.php), which runs the 4-stage tutor
 * pipeline (includes/class-coderobots-tutor.php). The language model is
 * reached through a client that a separate provider plugin registers on the
 * `coderobots_llm_client` filter (includes/llm-client.php) — this plugin
 * holds no model credentials.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CODEROBOTS_VERSION', '1.2.0' );
define( 'CODEROBOTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CODEROBOTS_URL', plugin_dir_url( __FILE__ ) );
define( 'CODEROBOTS_OPTION', 'coderobots_settings' );
define( 'CODEROBOTS_SHORTCODE', 'coderobots' );
define( 'CODEROBOTS_QUERY_VAR', 'coderobots' );

require_once CODEROBOTS_DIR . 'includes/llm-client.php';
require_once CODEROBOTS_DIR . 'includes/class-coderobots-simulator.php';
require_once CODEROBOTS_DIR . 'includes/class-coderobots-tutor.php';
require_once CODEROBOTS_DIR . 'includes/rest.php';

/** Languages the bundled app ships (keep in sync with src/config/instance.js). */
function coderobots_locales() {
	return array(
		'da' => 'Dansk',
		'en' => 'English',
	);
}

function coderobots_settings() {
	$defaults = array(
		'rate_limit_per_hour' => 300,
		'default_locale'      => 'da',
	);
	$saved = get_option( CODEROBOTS_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/* -------------------------------------------------------------------------
 * Front end: serve the app
 * ---------------------------------------------------------------------- */

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = CODEROBOTS_QUERY_VAR;
		return $vars;
	}
);

/** True when the current request should render the app instead of the theme. */
function coderobots_is_app_request() {
	if ( get_query_var( CODEROBOTS_QUERY_VAR ) ) {
		return true;
	}
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_queried_object();
	return $post instanceof WP_Post && has_shortcode( $post->post_content, CODEROBOTS_SHORTCODE );
}

add_action(
	'template_redirect',
	function () {
		if ( is_admin() || is_feed() || is_embed() || ! coderobots_is_app_request() ) {
			return;
		}
		if ( post_password_required() ) {
			return; // Let the theme show the password form.
		}
		coderobots_render_app_page();
		exit;
	},
	1
);

/**
 * Fallback for contexts the template_redirect hook can't intercept (widgets,
 * page builders that store content outside post_content, archive listings):
 * link to the standalone app instead of rendering a broken embed.
 */
add_shortcode(
	CODEROBOTS_SHORTCODE,
	function () {
		$url = add_query_arg( CODEROBOTS_QUERY_VAR, 'app', home_url( '/' ) );
		return sprintf(
			'<p class="coderobots-launch"><a class="wp-element-button" href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Open CodeRobots', 'coderobots' )
		);
	}
);

/**
 * Read the Vite manifest and collect the entry script plus every stylesheet
 * reachable through static imports.
 *
 * @return array{script:string, styles:string[], preload:string[]}|null
 */
function coderobots_assets() {
	$manifest_path = CODEROBOTS_DIR . 'app/.vite/manifest.json';
	if ( ! is_readable( $manifest_path ) ) {
		return null;
	}
	$manifest = json_decode( (string) file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $manifest ) ) {
		return null;
	}

	$entry_key = null;
	foreach ( $manifest as $key => $chunk ) {
		if ( ! empty( $chunk['isEntry'] ) ) {
			$entry_key = $key;
			break;
		}
	}
	if ( null === $entry_key ) {
		return null;
	}

	$styles  = array();
	$preload = array();
	$seen    = array();
	$walk    = function ( $key, $is_entry ) use ( &$walk, &$styles, &$preload, &$seen, $manifest ) {
		if ( isset( $seen[ $key ] ) || ! isset( $manifest[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;
		$chunk        = $manifest[ $key ];
		if ( ! $is_entry && ! empty( $chunk['file'] ) ) {
			$preload[] = $chunk['file'];
		}
		foreach ( isset( $chunk['css'] ) ? $chunk['css'] : array() as $css ) {
			$styles[] = $css;
		}
		foreach ( isset( $chunk['imports'] ) ? $chunk['imports'] : array() as $import ) {
			$walk( $import, false );
		}
	};
	$walk( $entry_key, true );

	return array(
		'script'  => $manifest[ $entry_key ]['file'],
		'styles'  => array_values( array_unique( $styles ) ),
		'preload' => array_values( array_unique( $preload ) ),
	);
}

function coderobots_render_app_page() {
	$assets   = coderobots_assets();
	$settings = coderobots_settings();
	$app_url  = CODEROBOTS_URL . 'app/';

	// Tell page-cache plugins not to store this response: a cached copy
	// served without the headers below would break LEGO mode.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	// Cross-origin isolation for LEGO Education mode (SharedArrayBuffer).
	// 'credentialless' keeps CDN loads (Pyodide, xterm) and the tutor
	// endpoint working without CORP headers on those hosts.
	header( 'Cross-Origin-Opener-Policy: same-origin' );
	header( 'Cross-Origin-Embedder-Policy: credentialless' );

	$config = array(
		'assetBase'        => $app_url,
		'defaultLocale'    => $settings['default_locale'],
		'tutorEndpointUrl' => rest_url( 'coderobots/v1/tutor' ),
		'tutorHeaders'     => array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ),
	);

	$title = get_query_var( CODEROBOTS_QUERY_VAR ) ? get_bloginfo( 'name' ) : wp_get_document_title();
	$lang  = $settings['default_locale'];
	?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
	<?php
	if ( function_exists( 'get_site_icon_url' ) && get_site_icon_url() ) {
		printf( '<link rel="icon" href="%s">' . "\n", esc_url( get_site_icon_url( 32 ) ) );
	}
	if ( $assets ) {
		foreach ( $assets['styles'] as $css ) {
			printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( $app_url . $css ) );
		}
		foreach ( $assets['preload'] as $js ) {
			printf( '<link rel="modulepreload" href="%s">' . "\n", esc_url( $app_url . $js ) );
		}
	}
	?>
<script>window.CODEROBOTS_CONFIG = <?php echo wp_json_encode( $config ); ?>;</script>
	<?php
	/** Hook for site owners to add analytics or extra tags to the app page. */
	do_action( 'coderobots_head' );
	?>
</head>
<body>
	<?php if ( $assets ) : ?>
<div id="root"></div>
<script type="module" src="<?php echo esc_url( $app_url . $assets['script'] ); ?>"></script>
	<?php else : ?>
<p style="font-family:sans-serif;padding:2em">
		<?php esc_html_e( 'CodeRobots is installed, but its app files are missing. Reinstall the plugin from the packaged coderobots.zip.', 'coderobots' ); ?>
</p>
	<?php endif; ?>
</body>
</html>
	<?php
}

/* -------------------------------------------------------------------------
 * Admin: Settings → CodeRobots
 * ---------------------------------------------------------------------- */

add_action(
	'admin_init',
	function () {
		register_setting(
			'coderobots',
			CODEROBOTS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'coderobots_sanitize_settings',
				'default'           => array(),
			)
		);

		add_settings_section( 'coderobots_main', '', '__return_false', 'coderobots' );

		add_settings_field(
			'rate_limit_per_hour',
			__( 'Tutor requests per hour, per network', 'coderobots' ),
			function () {
				$s = coderobots_settings();
				printf(
					'<input type="number" min="0" step="1" class="small-text" name="%s[rate_limit_per_hour]" value="%d">',
					esc_attr( CODEROBOTS_OPTION ),
					(int) $s['rate_limit_per_hour']
				);
				echo '<p class="description">' . esc_html__( 'Counted per IP address. A whole school usually shares one address, so size this for a full class. 0 = no limit.', 'coderobots' ) . '</p>';
			},
			'coderobots',
			'coderobots_main'
		);

		add_settings_field(
			'default_locale',
			__( 'Default language', 'coderobots' ),
			function () {
				$s = coderobots_settings();
				printf( '<select name="%s[default_locale]">', esc_attr( CODEROBOTS_OPTION ) );
				foreach ( coderobots_locales() as $code => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $s['default_locale'], $code, false ), esc_html( $label ) );
				}
				echo '</select>';
				echo '<p class="description">' . esc_html__( 'Students can still switch language in the app; their choice is remembered in their browser.', 'coderobots' ) . '</p>';
			},
			'coderobots',
			'coderobots_main'
		);
	}
);

function coderobots_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = coderobots_settings();

	if ( isset( $input['rate_limit_per_hour'] ) ) {
		$out['rate_limit_per_hour'] = max( 0, (int) $input['rate_limit_per_hour'] );
	}

	$locale = isset( $input['default_locale'] ) ? (string) $input['default_locale'] : '';
	if ( isset( coderobots_locales()[ $locale ] ) ) {
		$out['default_locale'] = $locale;
	}

	return $out;
}

add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( 'CodeRobots', 'coderobots' ),
			__( 'CodeRobots', 'coderobots' ),
			'manage_options',
			'coderobots',
			'coderobots_render_settings_page'
		);
	}
);

function coderobots_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$app_url = add_query_arg( CODEROBOTS_QUERY_VAR, 'app', home_url( '/' ) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'CodeRobots', 'coderobots' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: %s: shortcode */
				esc_html__( 'Add %s to any page. That page is then shown full-screen as the CodeRobots editor (the theme is not used on it).', 'coderobots' ),
				'<code>[coderobots]</code>'
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'The editor is also always available at:', 'coderobots' ); ?>
			<a href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $app_url ); ?></code></a>
		</p>
		<p><?php esc_html_e( 'Connecting robots needs Chrome or Edge on a desktop (Web Serial / Web Bluetooth). Student code and chats are stored only in that browser; nothing is saved on this site.', 'coderobots' ); ?></p>
		<?php if ( ! coderobots_llm_provider_available() ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'No language-model provider plugin is active, so the AI tutor will not answer. Install and configure the SkoleGPT proxy plugin.', 'coderobots' ); ?></p></div>
		<?php endif; ?>
		<?php if ( CodeRobots_Simulator::instance() ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Robot simulator found: the tutor writes code, tests it in the simulator (up to 3 runs) and then answers.', 'coderobots' ); ?></p></div>
		<?php else : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Robot simulator not configured (CODEROBOTS_EDSIM_DIR in wp-config.php, plus bubblewrap): the tutor answers directly without testing its code.', 'coderobots' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! CodeRobots_Tutor::load_prompts() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Tutor prompts are missing (prompts/tutor-prompts.json). Reinstall from the packaged coderobots.zip.', 'coderobots' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! coderobots_assets() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'App files are missing from the plugin folder (app/.vite/manifest.json). Reinstall from the packaged coderobots.zip.', 'coderobots' ); ?></p></div>
		<?php endif; ?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'coderobots' );
			do_settings_sections( 'coderobots' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=coderobots' ) ), esc_html__( 'Settings', 'coderobots' ) )
		);
		return $links;
	}
);
