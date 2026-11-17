<?php
/**
 * REST route POST /wp-json/coderobots/v1/tutor — the AI tutor endpoint.
 *
 * Request body (JSON):
 *   { history: [{role, content}], user_msg, hw_mode: spike|microbit|lego,
 *     level: beginner|intermediate|experienced, lang: da|en, code?, console? }
 * Response: text/event-stream of `data: {json}` events
 *   - progress: {type, stage: summarize|doc_routing|outline|simulate, status, payload?}
 *   - content:  {type, content}  (final-answer tokens)
 *   - done:     {type}
 *   - error:    {type, error}
 *
 * The model itself is reached through the LLM client a provider plugin
 * registers (includes/llm-client.php); without one the route answers 503.
 *
 * Abuse protection (the endpoint spends the site's model quota and needs
 * no login): a wp_rest nonce printed into the app page (X-WP-Nonce), a
 * per-IP hourly request cap, and request size limits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CODEROBOTS_MAX_BODY_BYTES = 400000;
const CODEROBOTS_MAX_FIELD_CHARS = 60000;
const CODEROBOTS_MAX_HISTORY = 200;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'coderobots/v1',
			'/tutor',
			array(
				'methods'             => 'POST',
				'callback'            => 'coderobots_rest_tutor',
				'permission_callback' => 'coderobots_rest_tutor_permission',
			)
		);
	}
);

function coderobots_rest_tutor_permission( WP_REST_Request $request ) {
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error( 'coderobots_bad_nonce', __( 'This page has expired. Reload the page and try again.', 'coderobots' ), array( 'status' => 403 ) );
	}
	return true;
}

/** Per-IP fixed-window counter. Returns true when the request is allowed. */
function coderobots_rate_limit_ok() {
	$limit = (int) coderobots_settings()['rate_limit_per_hour'];
	if ( $limit <= 0 ) {
		return true;
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'coderobots_rl_' . md5( $ip . '|' . gmdate( 'YmdH' ) );
	$n   = (int) get_transient( $key );
	if ( $n >= $limit ) {
		return false;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	return true;
}

/**
 * Validate and normalise the request body.
 *
 * @return array|WP_Error
 */
function coderobots_tutor_request( WP_REST_Request $request, array $hw_modes ) {
	if ( strlen( (string) $request->get_body() ) > CODEROBOTS_MAX_BODY_BYTES ) {
		return new WP_Error( 'coderobots_too_large', __( 'The conversation is too long. Start a new chat.', 'coderobots' ), array( 'status' => 413 ) );
	}
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'coderobots_bad_request', 'Expected a JSON body.', array( 'status' => 400 ) );
	}

	$str = function ( $key ) use ( $body ) {
		return isset( $body[ $key ] ) && is_string( $body[ $key ] ) ? $body[ $key ] : '';
	};

	$user_msg = $str( 'user_msg' );
	$hw_mode  = $str( 'hw_mode' );
	if ( '' === $user_msg || '' === $hw_mode ) {
		return new WP_Error( 'coderobots_bad_request', 'user_msg and hw_mode are required', array( 'status' => 400 ) );
	}
	if ( ! in_array( $hw_mode, $hw_modes, true ) ) {
		return new WP_Error( 'coderobots_bad_request', 'Unsupported hw_mode.', array( 'status' => 400 ) );
	}

	$level = $str( 'level' );
	if ( ! in_array( $level, array( 'beginner', 'intermediate', 'experienced' ), true ) ) {
		$level = 'intermediate';
	}
	$lang = $str( 'lang' );
	if ( ! isset( coderobots_locales()[ $lang ] ) ) {
		$lang = 'da';
	}

	$history = array();
	$raw     = isset( $body['history'] ) && is_array( $body['history'] ) ? $body['history'] : array();
	foreach ( array_slice( array_values( $raw ), -CODEROBOTS_MAX_HISTORY ) as $m ) {
		if ( ! is_array( $m ) || ! isset( $m['role'], $m['content'] ) || ! is_string( $m['content'] ) ) {
			continue;
		}
		if ( ! in_array( $m['role'], array( 'user', 'assistant', 'system' ), true ) ) {
			continue;
		}
		$history[] = array(
			'role'    => $m['role'],
			'content' => mb_substr( $m['content'], 0, CODEROBOTS_MAX_FIELD_CHARS, 'UTF-8' ),
		);
	}

	return array(
		'history'  => $history,
		'user_msg' => mb_substr( $user_msg, 0, CODEROBOTS_MAX_FIELD_CHARS, 'UTF-8' ),
		'hw_mode'  => $hw_mode,
		'level'    => $level,
		'lang'     => $lang,
		'code'     => mb_substr( $str( 'code' ), 0, CODEROBOTS_MAX_FIELD_CHARS, 'UTF-8' ),
		'console'  => mb_substr( $str( 'console' ), 0, CODEROBOTS_MAX_FIELD_CHARS, 'UTF-8' ),
	);
}

function coderobots_rest_tutor( WP_REST_Request $request ) {
	$prompts = CodeRobots_Tutor::load_prompts();
	if ( ! $prompts ) {
		return new WP_Error( 'coderobots_no_prompts', 'Tutor prompts are missing from the plugin (prompts/tutor-prompts.json). Reinstall the packaged plugin.', array( 'status' => 500 ) );
	}
	$req = coderobots_tutor_request( $request, array_keys( $prompts['hw'] ) );
	if ( is_wp_error( $req ) ) {
		return $req;
	}
	if ( ! coderobots_rate_limit_ok() ) {
		return new WP_Error( 'coderobots_rate_limited', __( 'Too many questions from this network right now. Wait a little and try again.', 'coderobots' ), array( 'status' => 429 ) );
	}

	// Keepalive comment during long non-streamed stages (nginx/FastCGI read
	// timeouts default to 60 s). Returning false aborts on disconnect.
	$send = function ( $text ) {
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- SSE frames of JSON.
		flush();
		return ! connection_aborted();
	};
	$llm = coderobots_llm_client(
		function () use ( $send ) {
			return $send( ": keepalive\n\n" );
		}
	);
	if ( ! $llm ) {
		return new WP_Error( 'coderobots_no_llm', __( 'The AI tutor is not set up on this site yet (no language-model provider plugin is active).', 'coderobots' ), array( 'status' => 503 ) );
	}

	// ---- Switch to a raw SSE stream; WordPress' JSON response is bypassed.
	// Agent loop: up to 4 model calls plus 3 simulator runs.
	$simulator = apply_filters( 'coderobots_tutor_agent_enabled', true ) ? CodeRobots_Simulator::instance() : null;
	@set_time_limit( $simulator ? 900 : 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore
	if ( function_exists( 'apache_setenv' ) ) {
		@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore
	}
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	status_header( 200 );
	header( 'Content-Type: text/event-stream; charset=utf-8' );
	header( 'Cache-Control: no-cache, no-store' );
	header( 'X-Accel-Buffering: no' ); // nginx: don't buffer.
	header( 'X-Content-Type-Options: nosniff' );

	// Some proxies hold the first bytes back; a padding comment forces headers out.
	$send( ':' . str_repeat( ' ', 2048 ) . "\n\n" );

	$tutor = new CodeRobots_Tutor(
		$llm,
		$prompts,
		$simulator,
		function () use ( $send ) {
			return $send( ": keepalive\n\n" );
		}
	);
	$tutor->run(
		$req,
		function ( array $event ) use ( $send ) {
			return $send( 'data: ' . wp_json_encode( $event ) . "\n\n" );
		}
	);
	exit;
}
