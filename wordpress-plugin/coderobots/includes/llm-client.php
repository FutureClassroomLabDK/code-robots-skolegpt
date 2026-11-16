<?php
/**
 * LLM client seam.
 *
 * This plugin runs the tutor pipeline but does not talk to a language model
 * itself. A separate provider plugin (the SkoleGPT proxy) supplies the client
 * through the `coderobots_llm_client` filter:
 *
 *   add_filter( 'coderobots_llm_client', function ( $client, $on_idle ) {
 *       return $client ?: new My_Client( ..., $on_idle );
 *   }, 10, 2 );
 *
 * `$on_idle` is a callable the client should invoke roughly every 10 s while
 * it waits on the model (the REST route uses it to send SSE keepalives); if
 * it returns false the client should abort (the browser went away).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface CodeRobots_LLM_Client {

	/**
	 * Return the full completion as one string.
	 *
	 * @param array<int, array{role:string, content:string}> $messages
	 * @throws RuntimeException On misconfiguration or upstream errors.
	 */
	public function call( array $messages, $temperature = 0.7, $max_tokens = null );

	/**
	 * Stream the completion, calling $on_token( string $delta ) for every
	 * content delta. If $on_token returns false, stop early.
	 *
	 * @param array<int, array{role:string, content:string}> $messages
	 * @throws RuntimeException On misconfiguration or upstream errors.
	 */
	public function stream( array $messages, callable $on_token, $temperature = 0.7, $max_tokens = null );
}

/**
 * The LLM client registered by a provider plugin, or null when none is
 * installed/active.
 *
 * @param callable|null $on_idle See file comment.
 * @return CodeRobots_LLM_Client|null
 */
function coderobots_llm_client( $on_idle = null ) {
	$client = apply_filters( 'coderobots_llm_client', null, $on_idle );
	return $client instanceof CodeRobots_LLM_Client ? $client : null;
}

/** True when some plugin has registered an LLM client provider. */
function coderobots_llm_provider_available() {
	return (bool) has_filter( 'coderobots_llm_client' );
}
