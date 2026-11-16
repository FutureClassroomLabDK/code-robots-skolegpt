/**
 * Page-load configuration injected by a host page.
 *
 * The dev server serves the SPA from the site root and needs none of this.
 * When the app is embedded by the WordPress plugin
 * (wordpress-plugin/coderobots/), PHP prints `window.CODEROBOTS_CONFIG`
 * before the bundle loads:
 *
 *   {
 *     assetBase:         absolute URL of the built app dir (trailing slash),
 *     tutorEndpointUrl:  overrides VITE_TUTOR_ENDPOINT_URL,
 *     tutorHeaders:      extra request headers for the tutor endpoint,
 *     defaultLocale:     overrides instance.locales.default (if available),
 *   }
 *
 * All fields are optional; absent fields fall back to build-time values.
 */

const runtime =
  (typeof window !== 'undefined' && window.CODEROBOTS_CONFIG) || {};

/** True when a host page (e.g. WordPress) is embedding the app. */
export const isEmbedded = Boolean(runtime.assetBase);

/**
 * Resolve a file from `public/` to a URL that works both at the site root
 * and under a host-provided asset base (e.g. wp-content/plugins/...).
 */
export function publicAsset(path) {
  const base = runtime.assetBase || import.meta.env.BASE_URL || '/';
  return base.replace(/\/?$/, '/') + path.replace(/^\/+/, '');
}

export default runtime;
