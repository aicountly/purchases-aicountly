/**
 * "Ask AI Pulse" (Administration → Access): the AI Pulse thread Connect pins first, in the widget's
 * company scope, with the question in its composer for the person to send (connect-aicountly
 * docs/EMBED_SDK.md, `open({ pulse: { prompt } })`). Without Connect, AI Pulse's own app in a new
 * tab through the portal's SSO jump — never Purchases' own spend engine, and never a dead button.
 *
 * connect.ts is loaded through Vite's SSR module graph (extensionless imports, import.meta.env),
 * under a minimal fake browser.
 */
import { after, afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'

const tabs: unknown[][] = []
const g = globalThis as unknown as Record<string, unknown>
const fakeWindow: Record<string, unknown> = {
  location: { origin: 'https://purchase.aicountly.com', protocol: 'https:', hostname: 'purchase.aicountly.com', pathname: '/', search: '', hash: '' },
  open: (...args: unknown[]) => {
    tabs.push(args)
    return null
  },
}
g.window = fakeWindow

function fakeWidget() {
  const opened: unknown[] = []
  const widget = {
    init: async () => true,
    update: () => {},
    open: (request: unknown) => void opened.push(request),
    close: () => {},
    destroy: () => {},
    isReady: () => true,
    version: '1',
  }
  return { widget, opened }
}

const server = await createServer({
  root: join(dirname(fileURLToPath(import.meta.url)), '../..'),
  server: { middlewareMode: true, hmr: false, watch: null },
  appType: 'custom',
  logLevel: 'error',
})
const connect = await server.ssrLoadModule('/src/services/connect.ts')

afterEach(() => {
  connect.setConnectReady(false)
  delete fakeWindow.AICountlyChat
  tabs.length = 0
})

after(async () => {
  await server.close()
})

describe('askAiPulse', () => {
  it('with Connect up, opens the pinned Pulse thread with the question in its composer — not sent', () => {
    const { widget, opened } = fakeWidget()
    fakeWindow.AICountlyChat = widget
    connect.setConnectReady(true)

    assert.equal(connect.askAiPulse('Which profiles should my buyers get?'), 'widget')
    assert.deepEqual(opened, [{ pulse: { prompt: 'Which profiles should my buyers get?' } }])
    assert.deepEqual(tabs, [])
  })

  it("without Connect, opens AI Pulse's own app in a new tab through the SSO jump, with no token in the link", () => {
    fakeWindow.AICountlyChat = fakeWidget().widget // loaded, but never mounted
    assert.equal(connect.askAiPulse('Which profiles should my buyers get?'), 'tab')
    delete fakeWindow.AICountlyChat // and no Connect on the page at all
    assert.equal(connect.askAiPulse('Which profiles should my buyers get?'), 'tab')

    assert.equal(tabs.length, 2)
    for (const [href, target, features] of tabs as [string, string, string][]) {
      const link = new URL(href)
      assert.equal(`${link.origin}${link.pathname}`, 'https://my.aicountly.com/login/authentication_jump/buddy')
      assert.equal(link.searchParams.get('returnUrl'), 'https://pulse.aicountly.com/auth/callback')
      assert.deepEqual([...link.searchParams.keys()], ['returnUrl'])
      assert.equal(target, '_blank')
      assert.match(features, /noopener/)
    }
  })

  it('still gets the person to AI Pulse when the widget throws', () => {
    const { widget } = fakeWidget()
    widget.open = () => {
      throw new Error('widget broke')
    }
    fakeWindow.AICountlyChat = widget
    connect.setConnectReady(true)

    assert.equal(connect.askAiPulse('Which profiles should my buyers get?'), 'tab')
    assert.equal(tabs.length, 1)
  })

  it('never asks a Connect loader that is not Embed SDK v1', () => {
    const { widget, opened } = fakeWidget()
    fakeWindow.AICountlyChat = { ...widget, version: undefined } // Connect's earlier loader: no version, no init() answer
    connect.setConnectReady(true)

    assert.equal(connect.askAiPulse('Which profiles should my buyers get?'), 'tab')
    assert.deepEqual(opened, [])
  })
})
