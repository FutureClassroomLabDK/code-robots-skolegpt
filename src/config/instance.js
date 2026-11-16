/**
 * App configuration: branding, offered hardware platforms and locales.
 *
 * Endpoint URLs and secrets never live here — the tutor endpoint comes from
 * the WordPress plugin at page load (src/config/runtime.js) or, for local
 * development, VITE_TUTOR_ENDPOINT_URL.
 */
const instance = {
  id: 'skolegpt',

  brand: {
    name: 'CodeRobots SkoleGPT',
    logoSrc: null,
    logoAlt: null,
    logoHeight: 40,
    fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
    fontUrl: null,
    // SkoleGPT navy, matching code.skolegptscience.com.
    colors: {
      primary: '#002E6D',
      primaryText: '#FFFFFF',
      secondary: '#002E6D',
      secondaryText: '#FFD166',
      accent: '#002E6D',
      accentHover: '#003D8F',
    },
  },

  // Which registered platforms (src/platforms/index.js) users can pick.
  platforms: ['spike', 'lego', 'microbit'],

  locales: {
    available: ['da', 'en'],
    default: 'da',
  },
};

export default instance;
