# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

There may be other agents working in this file, do not make changes or fixes to files that you are not working on. There may be linter errors unrelated to your work, but do not fix those.

## Commands

```bash
npm run dev            # Vite dev server
npm run build          # WordPress plugin → dist-wordpress/coderobots.zip
npm run lint           # ESLint + locale dictionary checks
npm run check:locales  # Locale key parity / dangling t() references only
```

There are no automated tests.

## What this is

A React + Vite SPA (Python editor + AI tutor chat for LEGO SPIKE Prime, LEGO
Education and micro:bit) shipped as a WordPress plugin. Fully anonymous: all
student data lives in the browser's localStorage. The only server component
is the plugin's tutor REST route.

The app renders a split-pane layout:
- **Left** — `SPIKEEditor`: CodeMirror editor + xterm.js terminal for the connected device
- **Right** — `ChatPanel`: AI tutor chat

`LanguageProvider` (outermost, `main.jsx`) → `SessionProvider` → `AppContent`.
Sessions are an implementation detail: the app resumes the most recent
session; "Switch hardware" (`HardwarePickerModal`) resumes or creates one
session per platform. "Clear chat" starts a new conversation.

## Configuration

`src/config/instance.js` holds branding, the offered platforms and locales.
`src/config/runtime.js` reads `window.CODEROBOTS_CONFIG`, injected by the
WordPress plugin at page load (`assetBase`, `tutorEndpointUrl`,
`tutorHeaders`, `defaultLocale`). For `npm run dev`, `VITE_TUTOR_ENDPOINT_URL`
in `.env.local` is the tutor endpoint fallback. Files from `public/` must be
referenced through `publicAsset()`, not root paths.

## Localization

Hand-rolled i18n: `src/contexts/LanguageContext.jsx` provides
`useLanguage()` → `{ lang, switchLang, t }`. `t(key)` does a flat lookup in
`src/locales/<lang>.json` with English fallback; the choice persists in
localStorage. Components use `t('camelCaseKey')` for every user-visible
string; new keys must be added to BOTH `en.json` and `da.json`
(`npm run check:locales` enforces parity and catches dangling references).

## Persistence

`src/services/persistence/store.js` is a localStorage-backed store
(`coderobots_local_db_v1`) with auto-increment integer ids, an in-memory
cache, per-table history caps, and quota-exceeded trimming.
`sessionManager.js` / `dataLogger.js` re-export `persistence/sessions.js` /
`persistence/logging.js`. Each session has a `hardware_platform`, multiple
conversations (one current) and code records (one current); code snapshots
are logged at key events (`run_device`, `ai_replace`, `chat_context`, ...).
`SessionContext` owns session state, debounced (1s) code autosave and
snapshots.

## Platforms

Registered in `src/platforms/index.js`; each folder exposes
`{ id, label, connectionType, stopCode, tutorHwMode, postConnectFiles?, starterCode? }`:
- `spike` → "LEGO SPIKE Prime" (SPIKE 3 firmware), connectionType `spike`, generic serial MicroPython REPL
- `lego` → "LEGO Education", connectionType `lego-ble` (Web Bluetooth + Pyodide, no serial; `stopCode: null`)
- `microbit` → connectionType `microbit`, serial REPL

`tutorHwMode` selects the tutor prompt bundle (`spike|lego|microbit`).
Short display names come from `src/platforms/displayName.js`.

### Serial (SPIKE, micro:bit)

`src/utils/microRepl.js` — `Board` class wrapping `@microbit/microbit-connection`
+ xterm.js. micro:bit connect: probe serial; if MicroPython is missing, flash
the bundled `.hex` from `src/assets/firmware/` over WebUSB inline
(`src/utils/microbitInstall.js`), then reconnect. On connect the platform's
`stopCode` halts running motors.

### LEGO Education

Devices pair over Web Bluetooth (`public/lego-education-ble.js`); student
Python runs in a Pyodide web worker (`public/pyodideWorker.js` loading
`public/legoeducation.py`), bridged by synchronous SharedArrayBuffer+Atomics
RPC (`src/utils/legoEducation/`). This REQUIRES cross-origin isolation —
COOP/COEP headers are set in `vite.config.js` (dev) and by the WordPress
plugin; removing them breaks LEGO mode. Pyodide boots lazily on first LEGO
connect (from a blob: URL when embedded, so it inherits the page's COEP).
Stop is `interruptPython()` + `stopAllMotion()`.

## AI tutor

`ChatPanel` sends raw fields `{history, user_msg, hw_mode, level, lang,
code?, console?}` to the tutor endpoint (`streamTutorCompletion` in
`src/utils/chatStream.js`) and reads SSE events `content | progress | done |
error`; `progress` events render as a localized collapsible thinking trace.
Prompts are assembled server-side.

## WordPress plugin (`wordpress-plugin/coderobots/`)

`npm run build` = `vite build -c vite.wordpress.config.js` (relative base +
manifest) + `scripts/package-wordpress-plugin.mjs`, which assembles PHP +
build into `dist-wordpress/coderobots/` and `coderobots.zip`, exporting the
prompt bundles from `prompts/*.py` to `prompts/tutor-prompts.json`
(`scripts/export_tutor_prompts.py`, needs python3).

The PHP plugin serves any page containing `[coderobots]` (or
`/?coderobots=app`) as a standalone document (no theme) with COOP/COEP
headers and injects `window.CODEROBOTS_CONFIG`. The REST route
`coderobots/v1/tutor` (`includes/rest.php`: wp_rest nonce, per-IP hourly
limit, size limits, SSE with keepalives) runs the 4-stage pipeline in
`includes/class-coderobots-tutor.php` (summarize → doc routing → outline →
streamed final answer, localized da/en).

The plugin holds NO model credentials. It gets a language-model client from
the `coderobots_llm_client` filter (`includes/llm-client.php`: interface
`CodeRobots_LLM_Client` with `call()` / `stream()`); the SkoleGPT client lives
in a separate, private provider plugin (SkoleGPT proxy). Do not add provider
code or credentials to this repo.

Prompt text: hardware doc bundles and level prompts in `prompts/*.py` (source
of truth); stage templates in `class-coderobots-tutor.php`.

## Styling

Plain CSS files co-located with components. Tailwind CSS v4 is also configured (via `@tailwindcss/vite`) but used sparingly.
