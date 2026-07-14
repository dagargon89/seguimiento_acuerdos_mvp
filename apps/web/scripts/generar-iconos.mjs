/**
 * Genera los iconos de la PWA (favicon.svg + PNGs) con el monograma "PJ"
 * en Space Grotesk 600, convertido a paths SVG para no depender de fuentes
 * instaladas en el sistema.
 *
 * Uso (one-off; los resultados se commitean):
 *   1. Descargar la TTF estática de Space Grotesk 600 a scripts/SpaceGrotesk-600.ttf:
 *      curl -s -A "" "https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600"
 *      → tomar la url(...).ttf y bajarla (la TTF no se commitea, ver .gitignore)
 *   2. npm i --no-save sharp text-to-svg
 *   3. node scripts/generar-iconos.mjs
 *
 * Colores del tema "Cívica Nocturna" (src/styles/tokens/colors.css):
 *   fondo #0b0f15 (--sidebar-bg), monograma #2fbfa5 (--teal).
 */
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import TextToSVG from 'text-to-svg';

const raiz = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const dirIconos = path.join(raiz, 'public', 'icons');
const FONDO = '#0b0f15';
const TEAL = '#2fbfa5';
const LADO = 1024;

const t2s = TextToSVG.loadSync(path.join(raiz, 'scripts', 'SpaceGrotesk-600.ttf'));

/** Path SVG del monograma "PJ" centrado, escalado a `alto` px de fontSize. */
function pathMonograma(alto) {
  return t2s.getD('PJ', {
    x: LADO / 2,
    y: LADO / 2,
    fontSize: alto,
    anchor: 'center middle',
    letterSpacing: -0.02,
  });
}

/**
 * SVG 1024×1024 autocontenido.
 * - normal: esquinas redondeadas y monograma grande (los launchers lo muestran tal cual).
 * - maskable: full-bleed sin esquinas y monograma dentro del 80% central (zona segura).
 */
function svgIcono({ maskable }) {
  const radio = maskable ? 0 : 200;
  const d = pathMonograma(maskable ? 400 : 480);
  const barra = maskable
    ? ''
    : `<rect x="${LADO / 2 - 150}" y="${LADO * 0.78}" width="300" height="28" rx="14" fill="${TEAL}" opacity="0.55"/>`;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${LADO} ${LADO}">
  <rect width="${LADO}" height="${LADO}" rx="${radio}" fill="${FONDO}"/>
  <path d="${d}" fill="${TEAL}"/>
  ${barra}
</svg>`;
}

await mkdir(dirIconos, { recursive: true });

const normal = Buffer.from(svgIcono({ maskable: false }));
const maskable = Buffer.from(svgIcono({ maskable: true }));

// favicon.svg: la variante normal, con el texto ya como paths (sin fuentes).
await writeFile(path.join(raiz, 'public', 'favicon.svg'), normal);

const salidas = [
  { svg: normal, tam: 192, archivo: 'pwa-192.png' },
  { svg: normal, tam: 512, archivo: 'pwa-512.png' },
  { svg: maskable, tam: 512, archivo: 'maskable-512.png' },
  { svg: normal, tam: 96, archivo: 'favicon-96.png' },
];
for (const { svg, tam, archivo } of salidas) {
  await sharp(svg, { density: 300 }).resize(tam, tam).png().toFile(path.join(dirIconos, archivo));
}

// apple-touch-icon: 180×180, fondo opaco full-bleed (iOS pinta negro el alfa
// y redondea las esquinas él mismo → usamos la variante sin esquinas).
await sharp(maskable, { density: 300 })
  .resize(180, 180)
  .flatten({ background: FONDO })
  .png()
  .toFile(path.join(dirIconos, 'apple-touch-icon.png'));

for (const f of ['favicon.svg', 'icons/pwa-192.png', 'icons/pwa-512.png', 'icons/maskable-512.png', 'icons/favicon-96.png', 'icons/apple-touch-icon.png']) {
  const meta = await sharp(path.join(raiz, 'public', f)).metadata();
  console.log(`${f}: ${meta.width}×${meta.height} (${meta.format})`);
}
