/**
 * Build config for the WordPress plugin (`npm run build`).
 *
 * Extends vite.config.js with a relative base + manifest so the PHP plugin
 * can enqueue the hashed entry files from wherever WordPress installs it
 * (plugins_url).
 *
 * Output goes to wordpress-plugin/build/app; scripts/package-wordpress-plugin.mjs
 * then assembles dist-wordpress/coderobots/ and coderobots.zip.
 */
import { defineConfig, mergeConfig } from 'vite'
import baseConfig from './vite.config.js'

export default mergeConfig(
  baseConfig,
  defineConfig({
    base: './',
    build: {
      outDir: 'wordpress-plugin/build/app',
      emptyOutDir: true,
      manifest: true,
    },
  })
)
