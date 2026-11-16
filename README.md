# Code Robots med SkoleGPT

A browser-based Python editor with an AI tutor for classroom robotics —
LEGO SPIKE Prime, LEGO Education (CS & AI) and BBC micro:bit — packaged as a
WordPress plugin. Danish-first (Danish and English UI). No accounts and no
data stored on the server: student code and chats live in the student's own
browser.

Maintained by Future Classroom Lab DK.

## How it fits together

```
┌────────────── WordPress site ──────────────┐
│  coderobots plugin (this repo, AGPL)       │
│    • serves the editor app ([coderobots])  │
│    • /wp-json/coderobots/v1/tutor          │
│      4-stage tutor pipeline + prompts      │
│               │ coderobots_llm_client      │
│               ▼                            │
│  language-model provider plugin            │
│    (SkoleGPT proxy — separate, private)    │──► SkoleGPT API
└────────────────────────────────────────────┘
```

The tutor pipeline and all prompts are open source here. The plugin holds no
model credentials: it asks for a language-model client on the
`coderobots_llm_client` filter, which a provider plugin answers (interface in
`wordpress-plugin/coderobots/includes/llm-client.php`). Without a provider the
editor still works and the chat says the tutor is not set up.

## Build

```bash
npm install
npm run build        # → dist-wordpress/coderobots.zip (needs python3 for the prompt export)
```

Upload the zip under Plugins → Add New → Upload Plugin, then install a
provider plugin. See `wordpress-plugin/coderobots/readme.txt` for site setup
and hosting troubleshooting.

## Development

```bash
cp .env.example .env.local   # optional: VITE_TUTOR_ENDPOINT_URL for the chat
npm run dev                  # Vite dev server (editor + hardware work locally)
npm run lint                 # ESLint + locale dictionary checks
```

See `CLAUDE.md` for the architecture guide. There are no automated tests.

## Credits and license

Based on [purdue-ai-editor](https://github.com/FACE-Lab-PurdueUniversity/purdue-ai-editor)
by Duncan Johnson, Bill Church, Ethan Danahy, Yash Ajay Garje and Morgan
Hynes (FACE Lab, Purdue University), via the CodeRobots editor
(github.com/DuncanAJohnson/coderobots.ai).

Licensed under the GNU Affero General Public License v3.0 — see `LICENSE`.
