import microbitPlatform from './microbit';
import legoPlatform from './lego';
import spikePlatform from './spike';

export const PLATFORMS = [spikePlatform, legoPlatform, microbitPlatform];

const PLATFORMS_BY_ID = PLATFORMS.reduce((acc, platform) => {
  acc[platform.id] = platform;
  return acc;
}, {});

export function getPlatform(id) {
  return PLATFORMS_BY_ID[id] || null;
}
