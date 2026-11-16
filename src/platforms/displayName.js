/**
 * Short, localized display names for platforms — e.g. "SPIKE Prime" in the title bar instead of the full
 * `platform.label` ("LEGO SPIKE Prime"). Falls back to the platform label.
 */
const LABEL_KEYS = {
  spike: 'spikePrime',
  lego: 'legoEducation',
  microbit: 'microbit',
};

export function platformDisplayName(platform, t) {
  if (!platform) return null;
  const key = LABEL_KEYS[platform.id];
  return key ? t(key) : platform.label;
}
