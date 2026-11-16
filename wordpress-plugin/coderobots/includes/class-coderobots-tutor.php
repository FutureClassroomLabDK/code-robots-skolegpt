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
 * Prompt TEXT lives in two places:
 *   - hardware doc bundles / levels: prompts/tutor-prompts.json, generated at
 *     build time from the repo's prompts/*.py (scripts/export_tutor_prompts.py);
 *   - the stage templates below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CodeRobots_Tutor {

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
		),
		'en' => array(
			'header'      => 'You are a friendly programming tutor. Write ALL replies in English. Write code comments in English. Never tell the student what their level is called.',
			'hw_level'    => 'Hardware: {hw}. Student level: {level}.',
			'docs'        => "Relevant documentation:\n{docs}",
			'outline'     => "Outline (use this as your plan for the answer):\n{outline}",
			'instruction' => 'Write the answer in English. Include a code block formatted as ```{code_lang} with English comments. Follow the outline and briefly explain the code so the student understands what is going on.',
		),
	);

	/** @var CodeRobots_LLM_Client */
	private $llm;
	/** @var array Decoded prompts/tutor-prompts.json */
	private $prompts;

	public function __construct( CodeRobots_LLM_Client $llm, array $prompts ) {
		$this->llm     = $llm;
		$this->prompts = $prompts;
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
		$history = $req['history'];
		try {
			// 1. SummarizeIfOver
			$tokens = self::count_messages_tokens( $history );
			if ( $tokens <= self::SUMMARIZE_THRESHOLD ) {
				$summary_payload = array( 'summarized' => false );
			} else {
				$prompt  = $this->pick( self::SUMMARIZE_PROMPTS, $req['lang'] );
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
			$outline = $this->llm->call( $this->stage_messages( 'outline', $req, $history, $selected, '' ) );
			$chars   = mb_strlen( $outline, 'UTF-8' );
			$preview = $chars <= 600 ? $outline : mb_substr( $outline, 0, 597, 'UTF-8' ) . '...';
			if ( false === $emit( self::progress( 'outline', array( 'outline' => $preview, 'outline_chars' => $chars ) ) ) ) {
				return;
			}

			// 4. FinalAnswer (streamed)
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
		} catch ( Throwable $e ) {
			$emit(
				array(
					'type'  => 'error',
					'error' => $e->getMessage(),
				)
			);
		}
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

	private function stage_messages( $stage, array $req, array $history, array $selected, $outline ) {
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
				str_replace( '{code_lang}', $code_lang, $tpl['instruction'] )
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
