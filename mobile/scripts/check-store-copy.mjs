#!/usr/bin/env node
/**
 * Pre-submit guard for the App Store copy in store/app-store-listing.md.
 *
 * Why: in Oct 2026 Apple rejected the AICOUNTLY apps under Guideline 4.3(a) ("similar binary, metadata and/or
 * concept"). The review notes then named sibling apps and bundle IDs ("a sibling app from the same developer",
 * "not a copy … (Guideline 4.3)"), which invites the reviewer to compare the apps. The demo-account fields also
 * still held placeholders, so a reviewer could not get past sign-in (2.1(a)).
 *
 * Repo check (CI runs it before any iOS submit; `npm run check:store-copy`):
 *   - the review-notes section names no other AICOUNTLY app (name or bundle ID) and has no family / 4.3 wording;
 *   - the listing has no placeholder except the two sanctioned credential slots, `[DEMO_EMAIL]` and
 *     `[DEMO_PASSWORD]`, which stay in the repo (real credentials are never committed);
 *   - the review notes do contain both slots, so the credentials are entered in one known place.
 *
 * Final check (by hand, just before pasting into App Store Connect):
 *   node scripts/check-store-copy.mjs --final /path/outside/the/repo/review-notes.txt
 *   The file holds the exact notes to paste, with the real demo credentials filled in. No placeholder of any
 *   kind may remain. The file must live outside the repository so it is never committed.
 *
 * The app this listing belongs to is read from app.json (ios.bundleIdentifier `com.aicountly.<app>`).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** Every AICOUNTLY app that has had an App Store record, with the names a reviewer could read as that app. */
export const APPS = {
  books: { bundle: 'com.aicountly.books', names: ['Smart Books', 'AICOUNTLY Books', 'Books - Aicountly', 'Books app'] },
  billing: { bundle: 'com.aicountly.billing', names: ['AICOUNTLY Billing', 'Billing - Aicountly', 'Billing app', 'Smart Billing'] },
  inventory: { bundle: 'com.aicountly.inventory', names: ['AICOUNTLY Inventory', 'Inventory - Aicountly', 'Inventory app'] },
  notes: { bundle: 'com.aicountly.notes', names: ['AICOUNTLY Notes', 'Notes - Aicountly', 'Notes app'] },
  drive: { bundle: 'com.aicountly.drive', names: ['AICOUNTLY Drive', 'Drive - Aicountly', 'Drive app'] },
  // The October 2026 apps. Each one's listing describes only itself.
  sales: { bundle: 'com.aicountly.sales', names: ['AICOUNTLY Sales', 'Sales - Aicountly', 'Sales app'] },
  purchases: { bundle: 'com.aicountly.purchases', names: ['AICOUNTLY Purchases', 'Purchases - Aicountly', 'Purchases app'] },
  insights: { bundle: 'com.aicountly.insights', names: ['AICOUNTLY Insights', 'Insights - Aicountly', 'Insights app'] },
};

/** The two credential slots allowed in the committed listing. */
export const CREDENTIAL_SLOTS = ['[DEMO_EMAIL]', '[DEMO_PASSWORD]'];

/** Wording that presents the app as one of a family, or argues with 4.3, in the review notes. */
const FAMILY_WORDING = [
  { re: /\bsibling\b/i, why: 'calls another app a sibling' },
  { re: /\bsame developer\b/i, why: 'points the reviewer at other apps from the same developer' },
  { re: /guideline\s*4\.3/i, why: 'argues with Guideline 4.3' },
  { re: /\b4\.3\s*\(\s*[ab]\s*\)/i, why: 'argues with Guideline 4.3' },
  { re: /\bnot an? (?:copy|clone|duplicate)\b/i, why: '"not a copy" wording invites the comparison' },
  { re: /\bsuite\b/i, why: 'presents the app as part of a suite' },
  { re: /\bother AICOUNTLY apps?\b/i, why: 'mentions other AICOUNTLY apps' },
];

/** Placeholders other than the sanctioned credential slots. */
const PLACEHOLDERS = [
  /\[[A-Z][A-Z0-9 _-]{2,}\]/g, // [PROVIDE_TEST_EMAIL], [SAMPLE COMPANY NAME], [REVIEW_EMAIL] …
  /\bREPLACE_WITH_[A-Z0-9_]*/g,
  /\bPROVIDE_[A-Z0-9_]+/g,
  /\b(?:TODO|TBD|FIXME)\b/g,
  /<(?:demo|test|review)[ _-]?(?:email|password|account|user)>/gi,
];

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Patterns that name an AICOUNTLY app other than `own`. */
export function siblingPatterns(own) {
  if (!APPS[own]) throw new Error(`Unknown app "${own}". Known: ${Object.keys(APPS).join(', ')}`);
  const out = [];
  for (const [key, app] of Object.entries(APPS)) {
    if (key === own) continue;
    out.push({ re: new RegExp(escapeRe(app.bundle), 'i'), what: `bundle ID ${app.bundle}` });
    for (const name of app.names) out.push({ re: new RegExp(`\\b${escapeRe(name).replace(/ /g, '\\s+')}\\b`, 'i'), what: `"${name}"` });
  }
  return out;
}

/**
 * The review-notes section: from the first heading whose text contains "review notes" down to the next heading
 * of the same or a higher level. Returns 0-based [start, end) line indexes, or null.
 */
export function reviewNotesRange(lines) {
  let inFence = false;
  let start = -1;
  let level = 0;
  for (let i = 0; i < lines.length; i += 1) {
    if (/^\s*```/.test(lines[i])) inFence = !inFence;
    if (inFence) continue;
    const m = /^(#{1,6})\s+(.*)$/.exec(lines[i]);
    if (!m) continue;
    if (start < 0) {
      if (/review notes/i.test(m[2])) {
        start = i;
        level = m[1].length;
      }
    } else if (m[1].length <= level) {
      return [start, i];
    }
  }
  return start < 0 ? null : [start, lines.length];
}

/**
 * Checks one listing (or, with `final`, the exact text to paste).
 * @returns {{ errors: {line:number,msg:string}[], warnings: {line:number,msg:string}[] }}
 */
export function checkListing(text, { app, final = false } = {}) {
  const lines = text.split(/\r?\n/);
  const errors = [];
  const warnings = [];
  let range = reviewNotesRange(lines);
  if (!range) {
    if (final) range = [0, lines.length];
    else errors.push({ line: 1, msg: 'No "Review notes" heading found: the guard cannot tell which text goes to App Review.' });
  }
  const inNotes = (i) => !!range && i >= range[0] && i < range[1];
  const siblings = siblingPatterns(app);

  lines.forEach((line, i) => {
    const at = i + 1;
    for (const s of siblings) {
      if (!s.re.test(line)) continue;
      const msg = `names another AICOUNTLY app (${s.what})`;
      if (inNotes(i)) errors.push({ line: at, msg: `Review notes ${msg}. Describe only this app.` });
      else warnings.push({ line: at, msg: `Listing ${msg} outside the review notes.` });
    }
    if (inNotes(i)) {
      for (const f of FAMILY_WORDING) if (f.re.test(line)) errors.push({ line: at, msg: `Review notes ${f.why}.` });
    }
    const seen = []; // [from, to) spans already reported, so [PROVIDE_X] is not reported again as PROVIDE_X
    for (const re of PLACEHOLDERS) {
      for (const m of line.matchAll(re)) {
        const from = m.index;
        const to = from + m[0].length;
        if (seen.some(([a, b]) => from >= a && to <= b)) continue;
        seen.push([from, to]);
        if (!final && CREDENTIAL_SLOTS.includes(m[0])) continue;
        errors.push({ line: at, msg: `Placeholder ${m[0]} must be replaced before submitting.` });
      }
    }
  });

  if (final) {
    if (!/[^\s@`]+@[^\s@`]+\.[^\s@`]+/.test(text)) errors.push({ line: 1, msg: 'No demo e-mail address found in the text to paste.' });
  } else if (range) {
    const notes = lines.slice(range[0], range[1]).join('\n');
    for (const slot of CREDENTIAL_SLOTS) {
      if (!notes.includes(slot)) errors.push({ line: range[0] + 1, msg: `Review notes must carry the ${slot} slot (filled only in App Store Connect).` });
    }
  }
  return { errors, warnings };
}

/** `com.aicountly.<app>` from app.json. */
export function appFromConfig(appJsonPath) {
  const expo = JSON.parse(fs.readFileSync(appJsonPath, 'utf8')).expo || {};
  const m = /^com\.aicountly\.([a-z]+)$/.exec((expo.ios && expo.ios.bundleIdentifier) || '');
  if (!m || !APPS[m[1]]) throw new Error(`${appJsonPath}: ios.bundleIdentifier is not a known com.aicountly.<app> id`);
  return m[1];
}

function main(argv) {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const mobileDir = path.resolve(here, '..');
  const repoRoot = path.resolve(mobileDir, '..');
  const args = argv.slice(2);
  const finalIdx = args.indexOf('--final');
  const final = finalIdx >= 0;
  let file = path.join(mobileDir, 'store', 'app-store-listing.md');
  if (final) {
    const given = args[finalIdx + 1];
    if (!given) {
      console.error('Usage: node scripts/check-store-copy.mjs --final /path/outside/the/repo/review-notes.txt');
      return 2;
    }
    file = path.resolve(given);
    const rel = path.relative(repoRoot, file);
    if (!rel.startsWith('..') && !path.isAbsolute(rel)) {
      console.error(`Refusing ${file}: it is inside the repository. Keep the filled-in notes (real demo credentials) outside it.`);
      return 2;
    }
  }
  const app = appFromConfig(path.join(mobileDir, 'app.json'));
  const { errors, warnings } = checkListing(fs.readFileSync(file, 'utf8'), { app, final });
  const shown = final ? path.basename(file) : path.relative(repoRoot, file);
  const gha = process.env.GITHUB_ACTIONS === 'true' && !final;
  for (const w of warnings) console.log(gha ? `::warning file=${shown},line=${w.line}::${w.msg}` : `warning ${shown}:${w.line}  ${w.msg}`);
  for (const e of errors) console.error(gha ? `::error file=${shown},line=${e.line}::${e.msg}` : `error   ${shown}:${e.line}  ${e.msg}`);
  if (errors.length) {
    console.error(`\nStore copy check FAILED for ${app} (${errors.length} problem(s)). See the pre-submit checklist at the top of store/app-store-listing.md.`);
    return 1;
  }
  console.log(`Store copy check passed for ${app}${final ? ' (final text to paste)' : ''}${warnings.length ? ` with ${warnings.length} warning(s)` : ''}.`);
  return 0;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  process.exitCode = main(process.argv);
}
