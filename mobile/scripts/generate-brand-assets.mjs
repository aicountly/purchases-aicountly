#!/usr/bin/env node
/**
 * Draws Purchases' icon, Android adaptive layers and splash mark from vector shapes, and writes
 * them to assets/. Run after changing the composition: `npm run brand:assets`.
 *
 * The mark: a parchment docket, tilted as if just pulled from a pad, with a saffron approval
 * stamp pressed over its corner — on an ink-black field. No gradient, no gloss, no tile.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Resvg } from '@resvg/resvg-js';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(here, '..', 'assets');
fs.mkdirSync(out, { recursive: true });

const INK = '#1B1A17';
const PAPER = '#F3EEE4';
const RULE = '#C6BBA8';
const SIGNAL = '#E3A008';

/** The mark on a 1024 grid, scaled about the centre by `scale`. `mono` draws it in one colour. */
function mark({ scale = 1, mono = null } = {}) {
  const paper = mono ?? PAPER;
  const lines = mono ? 'none' : INK;
  const rule = mono ? 'none' : RULE;
  const stamp = mono ?? SIGNAL;
  const tick = mono ? 'none' : INK;
  const notches = [0, 1, 2, 3, 4]
    .map((i) => `<circle cx="318" cy="${330 + i * 92}" r="15" fill="${mono ? 'none' : INK}" />`)
    .join('');
  return `
  <g transform="translate(512 512) scale(${scale}) translate(-512 -512)">
    <g transform="rotate(-9 512 512)">
      <rect x="290" y="250" width="420" height="540" rx="22" fill="${paper}" />
      ${notches}
      <rect x="370" y="330" width="270" height="34" rx="6" fill="${lines}" />
      <rect x="370" y="420" width="250" height="22" rx="6" fill="${rule}" />
      <rect x="370" y="486" width="270" height="22" rx="6" fill="${rule}" />
      <rect x="370" y="552" width="190" height="22" rx="6" fill="${rule}" />
    </g>
    <circle cx="660" cy="690" r="150" fill="${mono ? 'none' : INK}" />
    <circle cx="660" cy="690" r="128" fill="${stamp}" />
    <circle cx="660" cy="690" r="98" fill="none" stroke="${mono ? 'none' : INK}" stroke-width="10" stroke-dasharray="18 14" />
    <path d="M604 694 l40 40 l78 -86" fill="none" stroke="${tick}" stroke-width="30" stroke-linecap="square" />
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
write('icon.png', 1024, svg(1024, mark({ scale: 1.05 }), INK));
// Android adaptive: the launcher masks to ~66% of the layer, so the mark sits inside that.
write('android-icon-foreground.png', 1024, svg(1024, mark({ scale: 0.7 })));
write('android-icon-background.png', 1024, svg(1024, '', INK));
write('android-icon-monochrome.png', 1024, svg(1024, mark({ scale: 0.7, mono: '#FFFFFF' })));
// Splash: the mark alone; app.json paints the ink background behind it.
write('splash-icon.png', 512, svg(1024, mark({ scale: 1 })));
