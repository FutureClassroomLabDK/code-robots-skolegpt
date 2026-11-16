=== CodeRobots ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

AI-assisted Python editor for LEGO SPIKE Prime, LEGO Education (CS & AI) and micro:bit, with an AI tutor.

== Description ==

Students write Python in the browser, run it on a connected robot, and get help from an AI tutor.

* Hardware: LEGO SPIKE Prime (USB), LEGO Education CS & AI (Bluetooth), BBC micro:bit v2 (USB).
* No accounts and no data stored on the site: code and chats are kept in the student's own browser (localStorage).
* The tutor runs inside WordPress: students' browsers talk only to this site. The tutor pipeline (prompts, stages) is part of this plugin; the language model itself is reached through a separate provider plugin (e.g. the SkoleGPT proxy) that holds the model credentials. The browser also loads Pyodide (LEGO mode) and a terminal library from the jsDelivr CDN.
* Danish and English UI.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose coderobots.zip → Install → Activate.
2. Install and configure a language-model provider plugin (the SkoleGPT proxy). Without one the editor works, but the tutor chat answers "not set up".
3. Settings → CodeRobots: pick the default language and the per-network request limit.
4. Create a page and add the shortcode [coderobots] (a Shortcode block works). Publish it.

The page with the shortcode is shown full-screen as the editor; the theme is not used on that page. The editor is also always reachable at https://YOUR-SITE/?coderobots=app.

== Requirements and troubleshooting ==

* Students need desktop Chrome or Edge: robots connect over Web Serial (SPIKE Prime, micro:bit) and Web Bluetooth (LEGO CS & AI).
* The editor page is sent with the headers Cross-Origin-Opener-Policy: same-origin and Cross-Origin-Embedder-Policy: credentialless, which LEGO mode requires. If LEGO mode reports that the page is not cross-origin isolated:
  - Exclude the editor page (and URLs containing ?coderobots=) from page caching / CDN caching — a cached copy may be served without these headers. The plugin already sets DONOTCACHEPAGE for well-behaved cache plugins.
  - Make sure a security plugin or the host doesn't strip or override these two headers.
* The tutor streams its answer (Server-Sent Events) from /wp-json/coderobots/v1/tutor. If answers appear all at once or time out, the web server/proxy is buffering: the plugin sends X-Accel-Buffering: no (nginx) and keepalive comments every 10 s, but some hosts need response buffering/compression disabled for that URL.
* "Tutor requests per hour, per network" limits each IP address. Schools often share one address — size it for a full class, or set 0 to disable.
* "This page has expired" in the chat: the page's security token is older than ~24 h; reload the page.
* The plugin's app files must be served from the same domain as the site (not rewritten to an external CDN domain).
* If the host blocks downloading .py files from wp-content, LEGO mode cannot load app/legoeducation.py; allow that file.

== Developers ==

Built from the code-robots-skolegpt repository with `npm run build`. Do not edit app/ by hand; rebuild instead.

* The action `coderobots_head` runs inside <head> of the editor page if you need to add tags (e.g. analytics).
* The filter `coderobots_llm_client` supplies the language-model client: return an object implementing `CodeRobots_LLM_Client` (includes/llm-client.php). Arguments: the current value (null) and an `$on_idle` callable the client should invoke every ~10 s while waiting on the model.
