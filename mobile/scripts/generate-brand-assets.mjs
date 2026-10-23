#!/usr/bin/env node
/**
 * Draws Purchases' icon, Android adaptive layers and splash mark from vector shapes, and writes
 * them to assets/. Run after changing the composition: `npm run brand:assets`.
 *
 * The mark: goods coming in. A light-orchid arrow drops into an open stone-white tray whose front
 * carries a graphite barcode (the delivery is counted by scanning) — on a deep plum field. Square,
 * flat shapes: no paper, no tilt, no stamp, no gradient.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Resvg } from '@resvg/resvg-js';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, '..', 'assets');
fs.mkdirSync(out, { recursive: true });

const PLUM = '#6E2A80';
const ORCHID = '#D3A6E0';
const STONE = '#F8F9FA';
const GRAPHITE = '#26282D';

/** Bar widths of the barcode, left to right (gaps are fixed). */
const BARS = [14, 6, 10, 22, 6, 14, 6, 18, 10, 6, 22, 6, 10, 14, 6, 18];

/** The arrow, pointing down into the tray. `grow` widens it by that much on every side. */
function arrow(grow = 0) {
  const g = grow;
  return `M${452 - g} ${176 - g} H${572 + g} V${404 - g * 0.4} H${686 + g * 2.4} L512 ${626 + g * 1.6} L${338 - g * 2.4} ${404 - g * 0.4} H${452 - g} Z`;
}

/** The mark on a 1024 grid, scaled about the centre by `scale`. `mono` draws a one-colour silhouette. */
function mark({ scale = 1, mono = null } = {}) {
  let x = 352;
  const bars = BARS.map((w) => {
    const bar = `<rect x="${x}" y="676" width="${w}" height="92" />`;
    x += w + 8;
    return bar;
  }).join('');
  if (mono) {
    // One colour: the tray with the barcode and the arrow's clearance cut out, then the arrow.
    return `
  <defs>
    <mask id="tray" maskUnits="userSpaceOnUse" x="0" y="0" width="1024" height="1024">
      <rect width="1024" height="1024" fill="#fff" />
      <path d="${arrow(26)}" fill="#000" />
      <g fill="#000">${bars}</g>
    </mask>
  </defs>
  <g transform="translate(512 512) scale(${scale}) translate(-512 -512)">
    <path d="M232 548 H392 V600 H632 V548 H792 V808 a36 36 0 0 1 -36 36 H268 a36 36 0 0 1 -36 -36 Z" fill="${mono}" mask="url(#tray)" />
    <path d="${arrow()}" fill="${mono}" stroke="${mono}" stroke-width="20" stroke-linejoin="round" />
  </g>`;
  }
  return `
  <g transform="translate(512 512) scale(${scale}) translate(-512 -512)">
    <path d="M232 548 H392 V600 H632 V548 H792 V808 a36 36 0 0 1 -36 36 H268 a36 36 0 0 1 -36 -36 Z" fill="${STONE}" />
    <g fill="${GRAPHITE}">${bars}</g>
    <path d="${arrow()}" fill="${ORCHID}" stroke="${PLUM}" stroke-width="72" stroke-linejoin="round" paint-order="stroke" />
    <path d="${arrow()}" fill="${ORCHID}" stroke="${ORCHID}" stroke-width="20" stroke-linejoin="round" />
  </g>`;
}

function svg(size, body, background = null) {
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 1024 1024">${background ? `<rect width="1024" height="1024" fill="${background}"/>` : ''}${body}</svg>`;
}

function write(name, size, content) {
  const png = new Resvg(content, { fitTo: { mode: 'width', value: size } }).render().asPng();
  fs.writeFileSync(path.join(out, name), png);
  process.stdout.write(`assets/${name}  ${size}×${size}\n`);
}

// iOS / store icon: opaque, full bleed.
write('icon.png', 1024, svg(1024, mark({ scale: 1.05 }), PLUM));
// Android adaptive: the launcher masks to ~66% of the layer, so the mark sits inside that.
write('android-icon-foreground.png', 1024, svg(1024, mark({ scale: 0.7 })));
write('android-icon-background.png', 1024, svg(1024, '', PLUM));
write('android-icon-monochrome.png', 1024, svg(1024, mark({ scale: 0.7, mono: '#FFFFFF' })));
// Splash: the mark alone; app.json paints the plum background behind it.
write('splash-icon.png', 512, svg(1024, mark({ scale: 1 })));
