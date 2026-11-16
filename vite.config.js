import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'

// LEGO Education mode runs Pyodide in a Web Worker and uses SharedArrayBuffer
// + Atomics.wait for synchronous JS→main-thread RPC. Browsers only expose
// SharedArrayBuffer when the document is cross-origin isolated, which
// requires both headers below. The WordPress plugin emits the same headers
// in production. COEP 'credentialless' (not 'require-corp') keeps
// third-party fetches (Pyodide CDN) working without CORP.
const crossOriginIsolationHeaders = {
    'Cross-Origin-Opener-Policy': 'same-origin',
    'Cross-Origin-Embedder-Policy': 'credentialless',
}

export default defineConfig({
    plugins: [
        tailwindcss(),
        react(),
    ],
    server: {
        headers: crossOriginIsolationHeaders,
    },
    preview: {
        headers: crossOriginIsolationHeaders,
    },
})
