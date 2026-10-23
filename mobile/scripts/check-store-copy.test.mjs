/**
 * The App Store copy guard (scripts/check-store-copy.mjs) refuses what got the AICOUNTLY apps flagged under 4.3(a)
 * and 2.1(a), and passes the committed listing.
 * Run: node --test scripts/check-store-copy.test.mjs   (npm run test:store-copy)
 * Not picked up by jest (its default testMatch only takes .js/.ts files).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { APPS, appFromConfig, checkListing, reviewNotesRange } from './check-store-copy.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const app = appFromConfig(path.join(here, '..', 'app.json'));
const other = Object.keys(APPS).find((k) => k !== app);

const listing = (notes, rest = '') =>
  `# Listing\n\n## Description\n\nWhat the app does.${rest}\n\n## App Review notes\n\n${notes}\n\n## Screenshots\n\nSix screens.\n`;
const okNotes = 'Sign in with [DEMO_EMAIL] / [DEMO_PASSWORD] (email + password).';
const msgs = (r) => r.errors.map((e) => e.msg).join('\n');

test('the committed listing passes the repo check', () => {
  const text = fs.readFileSync(path.join(here, '..', 'store', 'app-store-listing.md'), 'utf8');
  const r = checkListing(text, { app });
  assert.deepEqual(r.errors, [], msgs(r));
});

test('the committed review notes describe only this app', () => {
  const text = fs.readFileSync(path.join(here, '..', 'store', 'app-store-listing.md'), 'utf8');
  const lines = text.split(/\r?\n/);
  const range = reviewNotesRange(lines);
  assert.ok(range, 'review notes heading present');
  const notes = lines.slice(range[0], range[1]).join('\n');
  for (const [key, a] of Object.entries(APPS)) {
    if (key === app) continue;
    assert.ok(!notes.toLowerCase().includes(a.bundle), `notes mention ${a.bundle}`);
  }
});

test('a clean listing with the two credential slots passes', () => {
  assert.deepEqual(checkListing(listing(okNotes), { app }).errors, []);
});

test('another app named in the review notes fails; elsewhere it only warns', () => {
  const name = APPS[other].names[0];
  const inNotes = checkListing(listing(`${okNotes}\nWorks with ${name}.`), { app });
  assert.match(msgs(inNotes), /names another AICOUNTLY app/);
  const outside = checkListing(listing(okNotes, ` Works with ${name}.`), { app });
  assert.deepEqual(outside.errors, []);
  assert.equal(outside.warnings.length, 1);
});

test("another app's bundle ID in the review notes fails", () => {
  const r = checkListing(listing(`${okNotes}\nSee ${APPS[other].bundle}.`), { app });
  assert.match(msgs(r), /bundle ID/);
});

test('the own app name and bundle ID are fine', () => {
  const own = APPS[app];
  const r = checkListing(listing(`${okNotes}\n${own.names[0]} (${own.bundle}) is the app.`), { app });
  assert.deepEqual(r.errors, []);
});

test('family and 4.3 wording fails', () => {
  for (const line of [
    'It is a sibling app from the same developer.',
    'This is not a copy of anything (Guideline 4.3).',
    'One product of the AICOUNTLY suite.',
    'Like the other AICOUNTLY apps, it uses one account.',
    'Reviewed under 4.3(a) before.',
  ]) {
    const r = checkListing(listing(`${okNotes}\n${line}`), { app });
    assert.ok(r.errors.length > 0, line);
  }
});

test('old and non-uniform placeholders fail anywhere in the listing', () => {
  for (const p of ['[PROVIDE_TEST_EMAIL]', '[REVIEW_PASSWORD]', 'REPLACE_WITH_DEMO_EMAIL', '[SAMPLE COMPANY NAME]', 'TODO']) {
    const r = checkListing(listing(okNotes, ` Contact: ${p}.`), { app });
    assert.match(msgs(r), /Placeholder/, p);
  }
});

test('a placeholder is reported once, not again for the text inside its brackets', () => {
  const r = checkListing(listing(okNotes, ' Email: [PROVIDE_TEST_EMAIL].'), { app });
  assert.equal(r.errors.length, 1, msgs(r));
});

test('review notes without the credential slots fail the repo check', () => {
  const r = checkListing(listing('Sign in with the demo account.'), { app });
  assert.match(msgs(r), /\[DEMO_EMAIL\]/);
  assert.match(msgs(r), /\[DEMO_PASSWORD\]/);
});

test('no review notes heading fails the repo check', () => {
  const r = checkListing('# Listing\n\nNothing here.\n', { app });
  assert.match(msgs(r), /No "Review notes" heading/);
});

test('final check: the credential slots must be filled', () => {
  const r = checkListing(okNotes, { app, final: true });
  assert.match(msgs(r), /\[DEMO_EMAIL\]/);
  const filled = checkListing('Sign in with reviewer@example.com / s3cret-Pass (email + password).', { app, final: true });
  assert.deepEqual(filled.errors, []);
});

test('the review notes section ends at the next heading of the same level', () => {
  const lines = listing(okNotes).split('\n');
  const [start, end] = reviewNotesRange(lines);
  assert.match(lines[start], /App Review notes/);
  assert.match(lines[end], /## Screenshots/);
});
