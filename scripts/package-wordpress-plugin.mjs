/**
 * Assemble the installable WordPress plugin after `vite build -c vite.wordpress.config.js`:
 *
 *   wordpress-plugin/coderobots/   (PHP source)
 * + wordpress-plugin/build/app/    (Vite output)
 * + prompts/tutor-prompts.json     (exported from prompts/*.py)
 * → dist-wordpress/coderobots/     (unzipped plugin, e.g. to symlink into a dev WP)
 * → dist-wordpress/coderobots.zip  (upload via Plugins → Add New → Upload)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import JSZip from 'jszip';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pluginSrc = path.join(root, 'wordpress-plugin/coderobots');
const appSrc = path.join(root, 'wordpress-plugin/build/app');
const outRoot = path.join(root, 'dist-wordpress');
const outPlugin = path.join(outRoot, 'coderobots');

if (!fs.existsSync(path.join(appSrc, '.vite/manifest.json'))) {
  console.error('Missing build output — run vite build -c vite.wordpress.config.js first.');
  process.exit(1);
}

fs.rmSync(outRoot, { recursive: true, force: true });
fs.mkdirSync(outRoot, { recursive: true });
fs.cpSync(pluginSrc, outPlugin, { recursive: true });
fs.cpSync(appSrc, path.join(outPlugin, 'app'), { recursive: true });

// Tutor prompt bundles for the PHP pipeline, exported from prompts/*.py.
fs.mkdirSync(path.join(outPlugin, 'prompts'), { recursive: true });
try {
  execFileSync('python3', [
    '-I',
    path.join(root, 'scripts/export_tutor_prompts.py'),
    path.join(outPlugin, 'prompts/tutor-prompts.json'),
  ], { stdio: 'inherit' });
} catch (err) {
  console.error('Exporting tutor prompts failed (python3 is required for the build).');
  throw err;
}

// Not used by the plugin: PHP renders its own document.
for (const unused of ['index.html', 'fonts']) {
  fs.rmSync(path.join(outPlugin, 'app', unused), { recursive: true, force: true });
}

// Defense in depth: the public bundle must never carry backend/client code
// for services this project dropped.
const forbidden = [/supabase\.co/i, /@supabase\//, /createClient\(\s*supabase/i, /esptool/i, /XIAO_ESP32/];
const offenders = [];
const walk = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(p);
    else if (/\.(js|css|html)$/.test(entry.name)) {
      const text = fs.readFileSync(p, 'utf8');
      for (const re of forbidden) if (re.test(text)) offenders.push(`${path.relative(outPlugin, p)} matches ${re}`);
    }
  }
};
walk(path.join(outPlugin, 'app'));
if (offenders.length) {
  console.error('Forbidden code found in the WordPress bundle:\n  ' + offenders.join('\n  '));
  process.exit(1);
}

const zip = new JSZip();
const addDir = (dir, zipPath) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    const zp = `${zipPath}/${entry.name}`;
    if (entry.isDirectory()) addDir(p, zp);
    else zip.file(zp, fs.readFileSync(p));
  }
};
addDir(outPlugin, 'coderobots');
const buf = await zip.generateAsync({ type: 'nodebuffer', compression: 'DEFLATE', compressionOptions: { level: 9 } });
fs.writeFileSync(path.join(outRoot, 'coderobots.zip'), buf);

console.log(`Plugin folder: ${path.relative(root, outPlugin)}`);
console.log(`Plugin zip:    ${path.relative(root, path.join(outRoot, 'coderobots.zip'))} (${(buf.length / 1024 / 1024).toFixed(1)} MB)`);
