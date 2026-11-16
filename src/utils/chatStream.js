/**
 * Tutor chat streaming client.
 *
 * The tutor endpoint (the WordPress plugin's REST route, see
 * wordpress-plugin/coderobots/includes/rest.php) speaks SSE —
 * `data: {json}\n\n` frames with a `type` discriminator:
 *   content | progress | done | error
 */

import runtime from '../config/runtime';

// The WordPress plugin injects its REST route at page load;
// VITE_TUTOR_ENDPOINT_URL is the local-development fallback.
const TUTOR_ENDPOINT_URL =
  runtime.tutorEndpointUrl || import.meta.env.VITE_TUTOR_ENDPOINT_URL;

/**
 * Read `data: {json}` SSE events off a fetch Response body.
 * Yields each parsed event object; returns on {type:'done'}, throws on
 * {type:'error'}. Unparseable frames are warned about and skipped.
 */
async function* readSseEvents(response) {
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;

    buffer += decoder.decode(value, { stream: true });

    // Process complete SSE messages (lines starting with "data: ")
    const lines = buffer.split('\n');
    buffer = lines.pop() || ''; // Keep incomplete line in buffer

    for (const line of lines) {
      if (!line.startsWith('data: ')) continue;
      const dataStr = line.slice(6); // Remove "data: " prefix

      let data;
      try {
        data = JSON.parse(dataStr);
      } catch (parseError) {
        console.warn('Failed to parse SSE data:', dataStr, parseError);
        continue;
      }

      if (data.type === 'done') {
        return; // Stream complete
      }
      if (data.type === 'error') {
        throw new Error(data.error);
      }
      yield data;
    }
  }
}

async function postJson(url, payload, extraHeaders = {}) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...extraHeaders },
    body: JSON.stringify(payload),
  });
  if (!response.ok) {
    // Surface a server-provided reason when there is one (e.g. the WordPress
    // tutor route's rate-limit / expired-page messages: {message} or {error}).
    let reason = '';
    try {
      const body = await response.json();
      reason = body?.message || body?.error || '';
    } catch {
      // not JSON
    }
    throw new Error(reason || `HTTP error! status: ${response.status}`);
  }
  return response;
}

/**
 * Stream a tutor-pipeline completion. The server owns prompt assembly; the
 * payload carries raw fields only.
 *
 * @param {Object} payload - { history, user_msg, hw_mode, level, lang,
 *   code?, console? }
 * @returns {AsyncGenerator} - Yields {type:'content', content} and
 *   {type:'progress', stage, status, payload?} events
 */
export async function* streamTutorCompletion(payload) {
  if (!TUTOR_ENDPOINT_URL) {
    throw new Error('No tutor endpoint configured (WordPress plugin config or VITE_TUTOR_ENDPOINT_URL).');
  }

  try {
    // Host-supplied headers, e.g. the WordPress plugin's X-WP-Nonce.
    const response = await postJson(TUTOR_ENDPOINT_URL, payload, runtime.tutorHeaders || {});
    yield* readSseEvents(response);
  } catch (error) {
    console.error('Error in streamTutorCompletion:', error);
    throw error;
  }
}
