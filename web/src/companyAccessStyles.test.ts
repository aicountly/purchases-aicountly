import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

// company-access 1.3.1+ ships its styles as a static stylesheet. The entry imports it once, after Purchases' own
// CSS, so the build serves it as a same-origin file; while it is applied, the shared screens (Users & Access, the
// company directory, the restricted-company dialog) inject no <style> element, which a Content-Security-Policy
// without 'unsafe-inline' refuses. styles.ts knows it is applied by the stylesheet's :root marker.
const entry = readFileSync(new URL('./main.tsx', import.meta.url), 'utf8')
const stylesheet = readFileSync(new URL('./company-access/company-access.css', import.meta.url), 'utf8')
const styles = readFileSync(new URL('./company-access/styles.ts', import.meta.url), 'utf8')

describe('company-access styles', () => {
  it('are imported by the entry, once, after the product stylesheet', () => {
    const own = entry.indexOf("import './index.css'")
    assert.ok(own >= 0, 'the entry imports index.css')
    assert.ok(entry.indexOf("import './company-access/company-access.css'") > own, 'and company-access.css after it')
    assert.equal(entry.match(/company-access\/company-access\.css/g)?.length, 1, 'exactly once')
  })

  it('carry the marker that tells the package not to inject its <style>', () => {
    assert.ok(stylesheet.includes(':root{--aic-ca-stylesheet:1}'))
    assert.ok(styles.includes("const STYLESHEET_MARKER = '--aic-ca-stylesheet'"))
  })
})
