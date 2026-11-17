<?php
/**
 * Tutor pipeline. Four stages over the language model:
 *   1. summarize    — collapse long histories (> ~5000 tokens) into one summary
 *   2. doc_routing  — pick the relevant doc bundle(s) for the hardware
 *   3. outline      — plan / pseudocode in the student's language
 *   4. final        — streamed answer (the only stage that emits content)
 *
 * Emits progress (stages 1–3), content (stage 4 tokens), then done — or a
 * single error.
 *
 * The multi-stage pipeline is currently OFF (PIPELINE_ENABLED = false): the
 * request goes straight to stage 4 with the full system prompt (all doc
 * bundles for the hardware, no outline) plus the raw chat history, and no
 * progress events are emitted. Re-enable by flipping the constant or via
 * add_filter( 'coderobots_tutor_pipeline_enabled', '__return_true' ).
 *
 * Agent mode (when a robot simulator is configured, see
 * class-coderobots-simulator.php) replaces all of the above: the model only
 * writes code, may test it in the simulator up to AGENT_MAX_RUNS times
 * (`simulate` progress events), and finishes with a short explanation
 * followed by the code. Disable with
 * add_filter( 'coderobots_tutor_agent_enabled', '__return_false' ).
 *
 * Prompt TEXT lives in two places:
 *   - hardware doc bundles / levels: prompts/tutor-prompts.json, generated at
 *     build time from the repo's prompts/*.py (scripts/export_tutor_prompts.py);
 *   - the stage templates below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CodeRobots_Tutor {

	/** Run summarize / doc routing / outline before the final answer. */
	const PIPELINE_ENABLED = false;

	const SUMMARIZE_THRESHOLD = 5000;

	const SUMMARIZE_PROMPTS = array(
		'da' => 'Du opsummerer en programmerings-tutoring-samtale mellem en elev og en AI-laerer. Samtalen kan blande prosa og kode. Lav et koncist resume paa dansk som bevarer: (a) hvad eleven bygger, (b) beslutninger der allerede er truffet, (c) eventuelle kodestumper ordret i kodeblokke, (d) uloeste spoergsmaal eller fejl. Hold det under 400 ord.',
		'en' => 'You are summarising a coding tutoring conversation between a student and an AI tutor. The conversation may mix prose with code. Produce a concise English summary that preserves: (a) what the student is building, (b) decisions already made, (c) any code snippets verbatim inside fenced blocks, (d) unresolved questions or errors. Keep it under 400 words.',
	);

	const OUTLINE_TEMPLATES = array(
		'da' => array(
			'header'   => 'Du er en erfaren robotik-programmerings-laerer. Din opgave er at skitsere en loesning paa elevens forespoergsel paa dansk INDEN der skrives kode. Naeste fase bruger denne skitse til at skrive det endelige svar.',
			'hw_level' => "Hardware: {hw}\nElevens niveau: {level}",
			'docs'     => "Relevant dokumentation:\n{docs}",
			'footer'   => "Output-format (skriv paa dansk, brug danske overskrifter):\n1. Problem (én saetning): genformuler hvad eleven oensker.\n2. Pseudokode-plan: nummererede trin der beskriver loesningen paa hoejt niveau.\n3. Afklarende spoergsmaal (kun hvis forespoergslen er oprigtigt tvetydig; ellers spring over).\n\nSkriv IKKE den endelige kode endnu. Hold skitsen under 500 ord.",
		),
		'en' => array(
			'header'   => "You are an expert robotics programming tutor. Your job is to outline a solution to the student's request in English BEFORE any code is written. The next stage will use this outline to write the final response.",
			'hw_level' => "Hardware target: {hw}\nStudent skill level: {level}",
			'docs'     => "Relevant documentation:\n{docs}",
			'footer'   => "Output format (English, with English headings):\n1. Problem (one sentence): restate what the student wants.\n2. Pseudocode plan: numbered steps describing the solution at a high level.\n3. Clarifying questions (only if the request is genuinely ambiguous; otherwise skip).\n\nDo NOT write the final code yet. Keep the outline under 500 words.",
		),
	);

	const FINAL_TEMPLATES = array(
		'da' => array(
			'header'      => 'Du er en venlig dansk programmerings-laerer. Skriv ALLE svar paa dansk (Dansk). Skriv kommentarer i koden paa dansk. Fortael aldrig eleven hvad deres niveau hedder.',
			'hw_level'    => 'Hardware: {hw}. Elevens niveau: {level}.',
			'docs'        => "Relevant dokumentation:\n{docs}",
			'outline'     => "Skitse (brug denne som plan for dit svar):\n{outline}",
			'instruction' => 'Skriv svaret paa dansk. Inkluder en kodeblok formateret som ```{code_lang} med danske kommentarer. Foelg skitsen og forklar kort omkring koden saa eleven forstaar hvad der sker.',
			'instruction_direct' => 'Skriv svaret paa dansk. Inkluder en kodeblok formateret som ```{code_lang} med danske kommentarer. Forklar kort omkring koden saa eleven forstaar hvad der sker.',
		),
		'en' => array(
			'header'      => 'You are a friendly programming tutor. Write ALL replies in English. Write code comments in English. Never tell the student what their level is called.',
			'hw_level'    => 'Hardware: {hw}. Student level: {level}.',
			'docs'        => "Relevant documentation:\n{docs}",
			'outline'     => "Outline (use this as your plan for the answer):\n{outline}",
			'instruction' => 'Write the answer in English. Include a code block formatted as ```{code_lang} with English comments. Follow the outline and briefly explain the code so the student understands what is going on.',
			'instruction_direct' => 'Write the answer in English. Include a code block formatted as ```{code_lang} with English comments. Briefly explain the code so the student understands what is going on.',
		),
	);

	/** Simulator runs the agent loop may use per answer. */
	const AGENT_MAX_RUNS = 3;

	/**
	 * Token cap per agent turn. SkoleGPT's Gemma 4 reasons first (a separate
	 * `reasoning` stream that counts toward max_tokens, ~100 tokens/s), so
	 * this must leave room for that; it mainly bounds a runaway turn.
	 */
	const AGENT_MAX_TOKENS = 12000;

	/** Agent loop protocol (system prompt) and the simulator-result turns. */
	const AGENT_TEMPLATES = array(
		'da' => array(
			'instruction' => "SAADAN ARBEJDER DU: Du er en kode-agent. Hvert svar du skriver er ET JSON-objekt og intet andet (ingen tekst udenfor):\n{\"code\": \"...\", \"action\": \"run\" eller \"done\", \"scenario\": \"...\", \"explanation\": \"...\"}\n- code: hele Python-programmet til elevens {hw} (hele filen, ikke et udsnit) med korte danske kommentarer.\n- action \"run\": test koden i en tekst-simulator af robotten. Du faar en tidslinje tilbage over hvad der skete (motorer, display, lyde, print, robottens position, fejl med linjenumre). Brug den til at tjekke at programmet virkelig goer det eleven bad om.\n- action \"done\": koden er faerdig. Den vises for eleven sammen med forklaringen.\n- scenario: den simulerede opstilling koden koeres i. Vaelg den der ligner elevens robot og opgave mest:\n{scenarios}\n- explanation: ved \"done\": en kort forklaring til eleven paa dansk (2-5 saetninger, gerne markdown men ingen kodeblokke), som vises over koden. Ved \"run\": én linje om hvad du vil tjekke.\nDu har hoejst {max_runs} koersler i simulatoren. Koer normalt koden mindst én gang foer \"done\". Naar resultatet passer med det eleven bad om, svar \"done\" med det samme. Simulatoren er en tilnaermelse af den rigtige robot: hvis den kun fejler fordi dens robot er bygget anderledes end elevens (fx andre porte), saa behold elevens opstilling og svar \"done\". Eleven kender ikke til simulatoren: naevn den ALDRIG i forklaringen eller i kodens kommentarer, og skriv koden til den rigtige robot.",
			'result'      => "SIMULATOR-RESULTAT (koersel {run} af {max_runs}, scenario {scenario}):\n{trace}",
			'next'        => 'Du har {left} koersel/koersler tilbage. Goer programmet det eleven bad om? Hvis ja, svar med action "done" og forklaringen til eleven. Hvis nej, ret koden og svar med action "run".',
			'last'        => 'Du har ikke flere koersler i simulatoren. Svar med action "done" nu: din bedste endelige kode og forklaringen til eleven. Hvis noget stadig ikke virker helt, saa naevn det kort i forklaringen.',
			'sim_failed'  => 'Simulatoren kunne ikke koere programmet: {error}',
			'fallback'    => 'Her er mit bedste bud paa et program. Det virker maaske ikke helt endnu, saa test det paa robotten.',
		),
		'en' => array(
			'instruction' => "HOW YOU WORK: You are a coding agent. Every reply you write is ONE JSON object and nothing else (no text outside it):\n{\"code\": \"...\", \"action\": \"run\" or \"done\", \"scenario\": \"...\", \"explanation\": \"...\"}\n- code: the complete Python program for the student's {hw} (the whole file, not a fragment) with short English comments.\n- action \"run\": test the code in a text simulator of the robot. You get back a timeline of what happened (motors, display, sounds, prints, robot position, errors with line numbers). Use it to check that the program really does what the student asked.\n- action \"done\": the code is finished. It is shown to the student together with the explanation.\n- scenario: the simulated setup to run the code in. Pick the one closest to the student's robot and task:\n{scenarios}\n- explanation: with \"done\": a short explanation for the student in English (2-5 sentences, markdown allowed but no code blocks), shown above the code. With \"run\": one line on what you want to check.\nYou have at most {max_runs} simulator runs. Normally run the code at least once before \"done\". When the result matches what the student asked, answer \"done\" straight away. The simulator approximates the real robot: if it only fails because its robot is built differently from the student's (e.g. other ports), keep the student's setup and answer \"done\". The student does not know about the simulator: NEVER mention it in the explanation or in code comments, and write the code for the real robot.",
			'result'      => "SIMULATOR RESULT (run {run} of {max_runs}, scenario {scenario}):\n{trace}",
			'next'        => 'You have {left} run(s) left. Does the program do what the student asked? If yes, reply with action "done" and the explanation for the student. If not, fix the code and reply with action "run".',
			'last'        => 'You have no simulator runs left. Reply with action "done" now: your best final code and the explanation for the student. If something may still not work, say so briefly in the explanation.',
			'sim_failed'  => 'The simulator could not run the program: {error}',
			'fallback'    => 'Here is my best attempt at the program. It may not work perfectly yet, so test it on your robot.',
		),
	);

	/** @var CodeRobots_LLM_Client */
	private $llm;
	/** @var array Decoded prompts/tutor-prompts.json */
	private $prompts;
	/** @var CodeRobots_Simulator|null Enables the agent loop when set. */
	private $simulator;
	/** @var callable|null Keepalive tick while the simulator runs. */
	private $on_idle;
	/** @var bool False once the provider rejected structured output. */
	private $json_mode = true;
	/** @var callable|null The current request's event sink. */
	private $emit;
	/** @var int Model call counter, labels reasoning events. */
	private $turn = 0;
	/** @var string Reasoning not yet sent to the browser. */
	private $reasoning_buf = '';
	/** @var float */
	private $reasoning_flushed = 0.0;

	public function __construct( CodeRobots_LLM_Client $llm, array $prompts, $simulator = null, $on_idle = null ) {
		$this->llm       = $llm;
		$this->prompts   = $prompts;
		$this->simulator = $simulator instanceof CodeRobots_Simulator ? $simulator : null;
		$this->on_idle   = $on_idle;
	}

	public static function load_prompts() {
		$path = CODEROBOTS_DIR . 'prompts/tutor-prompts.json';
		$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : null;
	}

	/** Hardware modes the bundled prompts support. */
	public function hw_modes() {
		return array_keys( $this->prompts['hw'] );
	}

	/* ---------------------------------------------------------------- tokens */

	public static function count_tokens( $text ) {
		return max( 1, intdiv( mb_strlen( (string) $text, 'UTF-8' ), 4 ) );
	}

	public static function count_messages_tokens( array $messages ) {
		$sum = 0;
		foreach ( $messages as $m ) {
			$sum += self::count_tokens( isset( $m['content'] ) ? $m['content'] : '' );
		}
		return $sum + 4 * count( $messages );
	}

	/* -------------------------------------------------------------- pipeline */

	/**
	 * Run the pipeline. $emit receives event arrays ({type: progress|content|done|error, ...})
	 * and may return false to abort (client disconnected).
	 *
	 * @param array $req Validated request: history, user_msg, hw_mode, level, lang, code?, console?
	 */
	public function run( array $req, callable $emit ) {
		// Every event goes through send(), which first flushes buffered reasoning.
		$this->emit = $emit;
		$emit       = array( $this, 'send' );
		if ( $this->llm instanceof CodeRobots_LLM_Reasoning_Client ) {
			$this->llm->set_reasoning_callback( array( $this, 'on_reasoning' ) );
		}
		$history = $req['history'];
		try {
			if ( $this->simulator && CodeRobots_Simulator::platform_for( $req['hw_mode'] ) ) {
				$this->run_agent( $req, $emit );
				return;
			}

			if ( ! apply_filters( 'coderobots_tutor_pipeline_enabled', self::PIPELINE_ENABLED ) ) {
				// Pipeline off: one streamed call with every doc bundle and no outline.
				$this->stream_final( $req, $history, array_keys( (array) $this->prompts['hw'][ $req['hw_mode'] ]['bundles'] ), '', $emit );
				return;
			}

			// 1. SummarizeIfOver
			$tokens = self::count_messages_tokens( $history );
			if ( $tokens <= self::SUMMARIZE_THRESHOLD ) {
				$summary_payload = array( 'summarized' => false );
			} else {
				$prompt  = $this->pick( self::SUMMARIZE_PROMPTS, $req['lang'] );
				$this->next_turn();
				$summary = $this->llm->call( array_merge( array( array( 'role' => 'system', 'content' => $prompt ) ), $history ) );
				$history = array(
					array(
						'role'    => 'system',
						'content' => "Earlier conversation summary:\n" . $summary,
					),
				);
				$summary_payload = array(
					'summarized'    => true,
					'before_tokens' => $tokens,
					'after_tokens'  => self::count_messages_tokens( $history ),
				);
			}
			if ( false === $emit( self::progress( 'summarize', $summary_payload ) ) ) {
				return;
			}

			// 2. ClassifyDocs
			$selected = $this->classify_docs( $req['hw_mode'], $req['user_msg'] );
			if ( false === $emit( self::progress( 'doc_routing', array( 'bundles' => $selected ) ) ) ) {
				return;
			}

			// 3. Outline
			$this->next_turn();
			$outline = $this->llm->call( $this->stage_messages( 'outline', $req, $history, $selected, '' ) );
			$chars   = mb_strlen( $outline, 'UTF-8' );
			$preview = $chars <= 600 ? $outline : mb_substr( $outline, 0, 597, 'UTF-8' ) . '...';
			if ( false === $emit( self::progress( 'outline', array( 'outline' => $preview, 'outline_chars' => $chars ) ) ) ) {
				return;
			}

			// 4. FinalAnswer (streamed)
			$this->stream_final( $req, $history, $selected, $outline, $emit );
		} catch ( Throwable $e ) {
			$emit(
				array(
					'type'  => 'error',
					'error' => $e->getMessage(),
				)
			);
		}
		if ( $this->llm instanceof CodeRobots_LLM_Reasoning_Client ) {
			$this->llm->set_reasoning_callback( null );
		}
	}

	/**
	 * Emit an event to the browser (after any buffered reasoning).
	 *
	 * @internal Public only so it can be used as a callable.
	 */
	public function send( array $event ) {
		if ( false === $this->flush_reasoning() ) {
			return false;
		}
		return call_user_func( $this->emit, $event );
	}

	/**
	 * Reasoning delta from the LLM client. Batched (~200 chars or 0.25 s) so
	 * the browser gets a few events per second instead of one per token.
	 *
	 * @internal Public only so it can be used as a callable.
	 */
	public function on_reasoning( $delta ) {
		$this->reasoning_buf .= (string) $delta;
		if ( strlen( $this->reasoning_buf ) >= 200 || microtime( true ) - $this->reasoning_flushed >= 0.25 ) {
			return $this->flush_reasoning();
		}
		return true;
	}

	private function flush_reasoning() {
		if ( '' === $this->reasoning_buf ) {
			return true;
		}
		$delta                   = $this->reasoning_buf;
		$this->reasoning_buf     = '';
		$this->reasoning_flushed = microtime( true );
		return call_user_func(
			$this->emit,
			array(
				'type'  => 'reasoning',
				'turn'  => $this->turn,
				'delta' => $delta,
			)
		);
	}

	/** Start a new model call: reasoning after this belongs to a new turn. */
	private function next_turn() {
		$this->flush_reasoning();
		++$this->turn;
	}

	private function stream_final( array $req, array $history, array $selected, $outline, callable $emit ) {
		$this->next_turn();
		$this->llm->stream(
			$this->stage_messages( 'final', $req, $history, $selected, $outline ),
			function ( $token ) use ( $emit ) {
				return $emit(
					array(
						'type'    => 'content',
						'content' => $token,
					)
				);
			}
		);
		$emit( array( 'type' => 'done' ) );
	}

	/* ------------------------------------------------------------ agent loop */

	/**
	 * Code-only agent: the model replies with {code, action, scenario,
	 * explanation}; "run" executes the code in the simulator and feeds the
	 * trace back, "done" ends the loop. At most AGENT_MAX_RUNS runs, then one
	 * last call that may only answer "done". Each run is reported as a
	 * `simulate` progress event; the final answer is emitted as one content
	 * event: explanation, then a ```python block.
	 */
	private function run_agent( array $req, callable $emit ) {
		$platform  = CodeRobots_Simulator::platform_for( $req['hw_mode'] );
		$scenarios = $this->simulator->scenarios( $platform );
		$names     = array_keys( $scenarios );
		$default   = CodeRobots_Simulator::DEFAULT_SCENARIOS[ $platform ];
		if ( ! in_array( $default, $names, true ) ) {
			$default = $names[0];
		}
		$tpl = $this->pick( self::AGENT_TEMPLATES, $req['lang'] );

		$messages = $this->stage_messages( 'agent', $req, $req['history'], array_keys( (array) $this->prompts['hw'][ $req['hw_mode'] ]['bundles'] ), '', $scenarios );
		$runs     = 0;
		$last     = null; // Last reply that contained code.

		while ( true ) {
			$final = $runs >= self::AGENT_MAX_RUNS;
			$raw   = $this->agent_call( $messages, self::agent_schema( $names, $final ) );
			$reply = self::parse_agent_reply( $raw );

			$empty = null === $reply || ( '' === trim( $reply['code'] ) && '' === trim( $reply['explanation'] ) );
			if ( $empty && $last ) {
				// Broken or empty turn after earlier attempts: answer with the last code.
				$last['explanation'] = $tpl['fallback'];
				$emit(
					array(
						'type'    => 'content',
						'content' => self::agent_answer( $last ),
					)
				);
				$emit( array( 'type' => 'done' ) );
				return;
			}
			if ( $empty ) {
				// Not usable JSON: pass any text through as the answer.
				if ( '' === trim( (string) $raw ) ) {
					throw new RuntimeException( 'The AI tutor returned an empty answer. Please try again.' );
				}
				$emit(
					array(
						'type'    => 'content',
						'content' => trim( $raw ),
					)
				);
				$emit( array( 'type' => 'done' ) );
				return;
			}
			if ( '' !== trim( $reply['code'] ) ) {
				$last = $reply;
			}

			if ( $final || 'run' !== $reply['action'] || '' === trim( $reply['code'] ) ) {
				$emit(
					array(
						'type'    => 'content',
						'content' => self::agent_answer( $reply ),
					)
				);
				$emit( array( 'type' => 'done' ) );
				return;
			}

			++$runs;
			$scenario = in_array( $reply['scenario'], $names, true ) ? $reply['scenario'] : $default;
			$call     = self::progress(
				'tool_call',
				array(
					'tool'     => 'simulator',
					'run'      => $runs,
					'max_runs' => self::AGENT_MAX_RUNS,
					'scenario' => $scenario,
					'note'     => trim( $reply['explanation'] ),
					'code'     => $reply['code'],
				)
			);
			$call['status'] = 'running';
			if ( false === $emit( $call ) ) {
				return;
			}
			try {
				$res = $this->simulator->run( $reply['code'], $platform, $scenario, $this->on_idle );
			} catch ( CodeRobots_Simulator_Cancelled $e ) {
				return;
			} catch ( RuntimeException $e ) {
				$res = array(
					'ok'    => false,
					'text'  => str_replace( '{error}', $e->getMessage(), $tpl['sim_failed'] ),
					'error' => array(
						'type'    => 'SimulatorError',
						'message' => $e->getMessage(),
						'line'    => null,
					),
				);
			}

			$error = '';
			if ( $res['error'] ) {
				$error = trim( ( isset( $res['error']['type'] ) ? $res['error']['type'] . ': ' : '' ) . ( isset( $res['error']['message'] ) ? (string) $res['error']['message'] : '' ) );
				if ( ! empty( $res['error']['line'] ) ) {
					$error .= ' (line ' . (int) $res['error']['line'] . ')';
				}
			}
			$progress = self::progress(
				'simulate',
				array(
					'run'      => $runs,
					'max_runs' => self::AGENT_MAX_RUNS,
					'scenario' => $scenario,
					'ok'       => $res['ok'],
					'error'    => $error,
					'trace'    => $res['text'],
				)
			);
			if ( false === $emit( $progress ) ) {
				return;
			}

			$left       = self::AGENT_MAX_RUNS - $runs;
			$messages[] = array(
				'role'    => 'assistant',
				'content' => (string) wp_json_encode( $reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => strtr(
					$tpl['result'],
					array(
						'{run}'      => $runs,
						'{max_runs}' => self::AGENT_MAX_RUNS,
						'{scenario}' => $scenario,
						'{trace}'    => $res['text'],
					)
				) . "\n\n" . ( $left > 0 ? str_replace( '{left}', (string) $left, $tpl['next'] ) : $tpl['last'] ),
			);
		}
	}

	/**
	 * One agent turn. Uses structured output when the client supports it;
	 * falls back to plain completion (the system prompt already asks for
	 * JSON) if the provider rejects it.
	 */
	private function agent_call( array $messages, array $schema ) {
		$this->next_turn();
		if ( $this->json_mode && $this->llm instanceof CodeRobots_LLM_JSON_Client ) {
			try {
				return $this->llm->call_json( $messages, $schema, 'tutor_agent_turn', 0.3, self::AGENT_MAX_TOKENS );
			} catch ( RuntimeException $e ) {
				if ( false !== stripos( $e->getMessage(), 'cancel' ) ) {
					throw $e;
				}
				$this->json_mode = false;
			}
		}
		return $this->llm->call( $messages, 0.3, self::AGENT_MAX_TOKENS );
	}

	private static function agent_schema( array $scenario_names, $final ) {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'code'        => array( 'type' => 'string' ),
				'action'      => array(
					'type' => 'string',
					'enum' => $final ? array( 'done' ) : array( 'run', 'done' ),
				),
				'scenario'    => array(
					'type' => 'string',
					'enum' => array_values( $scenario_names ),
				),
				'explanation' => array( 'type' => 'string' ),
			),
			'required'             => array( 'code', 'action', 'scenario', 'explanation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Parse an agent turn, tolerating code fences and text around the JSON.
	 *
	 * @return array{code:string, action:string, scenario:string, explanation:string}|null
	 */
	private static function parse_agent_reply( $raw ) {
		$text = trim( (string) $raw );
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}
		if ( ! is_array( $data ) || ( ! isset( $data['code'] ) && ! isset( $data['explanation'] ) ) ) {
			return null;
		}
		$str = function ( $key ) use ( $data ) {
			return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
		};
		$code = $str( 'code' );
		// Some models wrap the program in a fence inside the JSON string.
		if ( preg_match( '/^\s*```[a-zA-Z]*\n(.*?)\n?```\s*$/s', $code, $m ) ) {
			$code = $m[1];
		}
		return array(
			'code'        => $code,
			'action'      => 'run' === $str( 'action' ) ? 'run' : 'done',
			'scenario'    => $str( 'scenario' ),
			'explanation' => $str( 'explanation' ),
		);
	}

	/** Final chat answer: explanation, then the program in a python fence. */
	private static function agent_answer( array $reply ) {
		$parts = array();
		if ( '' !== trim( $reply['explanation'] ) ) {
			$parts[] = trim( $reply['explanation'] );
		}
		if ( '' !== trim( $reply['code'] ) ) {
			$parts[] = "```python\n" . rtrim( $reply['code'] ) . "\n```";
		}
		return implode( "\n\n", $parts );
	}

	private static function progress( $stage, array $payload ) {
		$evt = array(
			'type'   => 'progress',
			'stage'  => $stage,
			'status' => 'done',
		);
		if ( $payload ) {
			$evt['payload'] = $payload;
		}
		return $evt;
	}

	private function pick( array $by_lang, $lang ) {
		return isset( $by_lang[ $lang ] ) ? $by_lang[ $lang ] : $by_lang['da'];
	}

	/* ----------------------------------------------------------- doc router */

	private function classify_docs( $hw, $user_msg ) {
		$hw_prompts = $this->prompts['hw'][ $hw ];
		$bundles    = (array) $hw_prompts['bundles'];
		$names      = array_keys( $bundles );
		$defaults   = array_values( array_filter( (array) $hw_prompts['defaults'], function ( $n ) use ( $bundles ) {
			return isset( $bundles[ $n ] );
		} ) );

		if ( count( $names ) <= 1 ) {
			return $names;
		}

		$selected = $this->route( $bundles, (array) $hw_prompts['descriptions'], $user_msg );
		if ( ! $selected ) {
			return $defaults;
		}
		// Always merge in the hardware's default (e.g. lego 'shared' rules).
		foreach ( $defaults as $d ) {
			if ( ! in_array( $d, $selected, true ) ) {
				$selected[] = $d;
			}
		}
		return $selected;
	}

	/** Port of pipeline/extras.py DocRouter.select. */
	private function route( array $bundles, array $descriptions, $query ) {
		$names   = array_keys( $bundles );
		$catalog = array();
		foreach ( $names as $n ) {
			$catalog[] = '  - ' . $n . ': ' . ( isset( $descriptions[ $n ] ) ? $descriptions[ $n ] : '(no description)' );
		}
		$example = $names ? $names[0] : 'example';
		// Matches Python's json.dumps(list) spacing.
		$names_json = '[' . implode( ', ', array_map( 'wp_json_encode', $names ) ) . ']';

		$system = "You are a documentation router. Pick the bundle(s) whose description matches the user's query. Be selective: choose the smallest set that actually covers what they asked. Do NOT include every bundle.\n\n"
			. 'Output format: a single JSON array of bundle names and nothing else. '
			. 'Examples of valid output: ["' . $example . '"]   or   ["' . $example . '", "shared"]   or   [].' . "\n\n"
			. "Available bundles:\n" . implode( "\n", $catalog ) . "\n\n"
			. 'Valid bundle names (use these exact strings): ' . $names_json . '.';
		$user = 'User query: ' . $query . "\n\nRespond with ONLY the JSON array.";

		$this->next_turn();
		$response = $this->llm->call(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			0.1
		);

		$cleaned = trim( $response );
		if ( 0 === strpos( $cleaned, '```' ) ) {
			$parts   = explode( '```', $cleaned );
			$cleaned = count( $parts ) > 1 ? $parts[1] : $cleaned;
			if ( 0 === strpos( $cleaned, 'json' ) ) {
				$cleaned = substr( $cleaned, 4 );
			}
		}
		$selected = json_decode( trim( $cleaned ), true );
		if ( ! is_array( $selected ) || ( $selected && array_keys( $selected ) !== range( 0, count( $selected ) - 1 ) ) ) {
			return array();
		}
		return array_values( array_filter( $selected, function ( $n ) use ( $bundles ) {
			return is_string( $n ) && isset( $bundles[ $n ] );
		} ) );
	}

	/* ------------------------------------------------------- prompt assembly */

	private function stage_messages( $stage, array $req, array $history, array $selected, $outline, array $scenarios = array() ) {
		$hw         = $req['hw_mode'];
		$level      = $req['level'];
		$hw_prompts = $this->prompts['hw'][ $hw ];
		$level_text = isset( $this->prompts['levels'][ $level ] ) ? $this->prompts['levels'][ $level ] : '';
		$code_lang  = 'python';

		$docs = array();
		foreach ( $selected as $n ) {
			if ( isset( $hw_prompts['bundles'][ $n ] ) ) {
				$docs[] = $hw_prompts['bundles'][ $n ];
			}
		}
		$docs = implode( "\n\n", $docs );

		$hw_level_vars = array(
			'{hw}'    => $hw,
			'{level}' => $level,
		);

		if ( 'outline' === $stage ) {
			$tpl    = $this->pick( self::OUTLINE_TEMPLATES, $req['lang'] );
			$system = self::join_sections(
				$tpl['header'],
				strtr( $tpl['hw_level'], $hw_level_vars ),
				$level_text,
				$hw_prompts['preamble'],
				'' !== $docs ? str_replace( '{docs}', $docs, $tpl['docs'] ) : '',
				$hw_prompts['examples'],
				$tpl['footer']
			);
		} elseif ( 'agent' === $stage ) {
			$tpl     = $this->pick( self::FINAL_TEMPLATES, $req['lang'] );
			$agent   = $this->pick( self::AGENT_TEMPLATES, $req['lang'] );
			$catalog = array();
			foreach ( $scenarios as $name => $desc ) {
				$catalog[] = '  - ' . $name . ( '' !== $desc ? ': ' . $desc : '' );
			}
			$system = self::join_sections(
				$tpl['header'],
				strtr( $tpl['hw_level'], $hw_level_vars ),
				$level_text,
				$hw_prompts['preamble'],
				'' !== $docs ? str_replace( '{docs}', $docs, $tpl['docs'] ) : '',
				$hw_prompts['examples'],
				strtr(
					$agent['instruction'],
					array(
						'{hw}'        => $hw,
						'{scenarios}' => implode( "\n", $catalog ),
						'{max_runs}'  => self::AGENT_MAX_RUNS,
					)
				)
			);
		} else {
			$tpl    = $this->pick( self::FINAL_TEMPLATES, $req['lang'] );
			$system = self::join_sections(
				$tpl['header'],
				strtr( $tpl['hw_level'], $hw_level_vars ),
				$level_text,
				$hw_prompts['preamble'],
				'' !== $docs ? str_replace( '{docs}', $docs, $tpl['docs'] ) : '',
				$hw_prompts['examples'],
				'' !== $outline ? str_replace( '{outline}', $outline, $tpl['outline'] ) : '',
				str_replace( '{code_lang}', $code_lang, '' !== $outline ? $tpl['instruction'] : $tpl['instruction_direct'] )
			);
		}

		return array_merge(
			array( array( 'role' => 'system', 'content' => $system ) ),
			$history,
			array( array( 'role' => 'user', 'content' => self::user_with_context( $req, $code_lang ) ) )
		);
	}

	private static function join_sections( ...$parts ) {
		return implode( "\n\n", array_filter( $parts, function ( $p ) {
			return '' !== (string) $p;
		} ) );
	}

	/** Code / console fences stacked before the student's message. */
	private static function user_with_context( array $req, $code_lang ) {
		$parts = array();
		if ( '' !== $req['code'] ) {
			$parts[] = "```{$code_lang}\n{$req['code']}\n```";
		}
		if ( '' !== $req['console'] ) {
			$parts[] = "````\n{$req['console']}\n````";
		}
		$parts[] = $req['user_msg'];
		return implode( "\n\n", $parts );
	}
}
