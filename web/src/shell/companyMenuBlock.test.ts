/**
 * The account menu's Company block.
 *
 * Purchases is company-scoped — every requisition, order, bill and claim hangs off `cmp_id` — so
 * the block is mandatory, in Books' order, and the company picker in the top bar is explicitly
 * NOT a reason to leave it out (AICOUNTLY_ACCOUNT_MENU.md §3):
 *
 *   Switch company → Recent (up to three, newest first, each with its id) → Edit company →
 *   Manage branches
 *
 * The recent list is asserted against the store it comes from; the block's presence and order are
 * asserted against the shell's source, as `companyAccessStyles.test.ts` asserts the entry's
 * imports — the menu needs a browser and three contexts to render, and the thing that regresses
 * here is an entry dropped or moved.
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { MENU_RECENT_LIMIT, recentCompaniesForMenu, type RecentEntry } from '../company/recentCompanies.ts'

const shell = readFileSync(new URL('./AppShell.tsx', import.meta.url), 'utf8')
const launcher = readFileSync(new URL('../services/appLauncher.ts', import.meta.url), 'utf8')

const row = (cmpId: number, name: string, at: number): RecentEntry => ({ cmpId, name, at })

describe('the Company block', () => {
  it('offers every entry Purchases can perform, in Books’ order', () => {
    const at = (label: string) => {
      const index = shell.indexOf(`<span>${label}</span>`)
      assert.ok(index > 0, `the menu offers ${label}`)
      return index
    }

    const company = shell.indexOf('<p className="app-shell__account-section">Company</p>')
    assert.ok(company > 0, 'the block is labelled Company')
    assert.ok(at('Switch company') > company, 'Switch company is the first entry — the floor')
    assert.ok(shell.indexOf('<p className="app-shell__account-section">Recent</p>') > at('Switch company'))
    assert.ok(at('Edit company') > at('Switch company'), 'Edit company after the recent list')
    assert.ok(at('Manage branches') > at('Edit company'), 'Manage branches last')
  })

  it('sits between the accounts and the portal links, never after them', () => {
    const accounts = shell.indexOf('<span>Use another account</span>')
    const company = shell.indexOf('<p className="app-shell__account-section">Company</p>')
    const links = shell.indexOf('<span>Manage profile</span>')
    assert.ok(accounts > 0 && company > accounts, 'company follows the accounts block')
    assert.ok(links > company, 'and comes before Manage profile / Manage Account')
  })

  it('opens the company and its branches in Manage, in a new tab', () => {
    // Manage owns both; this product stores cmp_id and bo_id as bare references.
    assert.ok(shell.includes("window.open(manageCompanyEditUrl(scope?.cmp_id), '_blank', 'noopener,noreferrer')"))
    assert.ok(shell.includes("window.open(manageBranchesUrl(scope?.cmp_id), '_blank', 'noopener,noreferrer')"))
    assert.ok(shell.includes('Opens this company in Manage in a new tab'))
    assert.ok(shell.includes("company’s branches in Manage in a new tab") || shell.includes("company's branches in Manage in a new tab"))
  })

  it('switches company through the picker this product already has', () => {
    assert.ok(shell.includes("navigate('/choose-company')"))
  })

  it('builds the Manage company and branch URLs from the company in scope', () => {
    assert.ok(launcher.includes("return `/company/${encodeURIComponent(String(cmpId))}/edit`"))
    assert.ok(launcher.includes("return `/branch/${encodeURIComponent(String(cmpId))}`"))
    // No company open yet: the company list, never `/company/undefined/edit`.
    assert.ok(launcher.includes('manageCompanyListPath()'))
  })
})

describe('the recent companies it lists', () => {
  it('shows at most three, most recent first', () => {
    const rows = [
      row(30, 'Northwind Traders', 4),
      row(31, 'Acme Supplies', 3),
      row(32, 'Blue Ridge Foods', 2),
      row(33, 'Older Co', 1),
    ]

    assert.equal(MENU_RECENT_LIMIT, 3)
    assert.deepEqual(
      recentCompaniesForMenu(rows).map((r) => [r.cmpId, r.name]),
      [[30, 'Northwind Traders'], [31, 'Acme Supplies'], [32, 'Blue Ridge Foods']],
    )
  })

  it('carries each company’s id, so two companies named alike are told apart', () => {
    const [first] = recentCompaniesForMenu([row(30, 'Acme', 2), row(31, 'Acme', 1)])
    assert.equal(first.cmpId, 30)
    assert.ok(shell.includes('<CompanyIdTag id={row.cmpId} />'), 'and the menu shows it')
  })

  it('is simply absent when nothing has been opened yet — the rest of the block stays', () => {
    assert.deepEqual(recentCompaniesForMenu([]), [])
    assert.ok(shell.includes('{recent.length > 0 && ('), 'the recent rows are the only conditional part')
  })
})
