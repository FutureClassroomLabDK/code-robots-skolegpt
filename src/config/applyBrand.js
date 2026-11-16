import instance from './instance';
import { publicAsset } from './runtime';

const brand = instance.brand;

export function applyBrand() {
  const root = document.documentElement.style;
  root.setProperty('--brand-primary', brand.colors.primary);
  root.setProperty('--brand-primary-text', brand.colors.primaryText);
  root.setProperty('--brand-secondary', brand.colors.secondary);
  root.setProperty('--brand-secondary-text', brand.colors.secondaryText);
  root.setProperty('--brand-accent', brand.colors.accent);
  root.setProperty('--brand-accent-hover', brand.colors.accentHover);
  root.setProperty('--brand-font-family', brand.fontFamily);

  if (brand.fontUrl) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    // Root-relative font URLs point into public/; resolve them against the
    // host's asset base when embedded.
    link.href = brand.fontUrl.startsWith('/') ? publicAsset(brand.fontUrl) : brand.fontUrl;
    document.head.appendChild(link);
  }
}
