/**
 * What the app-level error boundary (AppErrorBoundary) tells the person, kept pure so node:test can
 * check it: a short reference they can quote to support, and words that never carry the error's
 * message or stack (a stack is for the console, where support can ask for it — launch-readiness P1-P6).
 */

/** A reference for one failure: PUR-<time base36>-<4 random>, upper-case, quotable over the phone. */
export function newErrorId(now: number = Date.now(), random: () => number = Math.random): string {
  const time = Math.floor(now).toString(36).toUpperCase()
  let tail = ''
  for (let i = 0; i < 4; i++) {
    tail += Math.floor(random() * 36).toString(36).toUpperCase()
  }
  return `PUR-${time}-${tail}`
}

export interface ErrorView {
  title: string
  body: string
  reference: string
}

/**
 * The fallback's words. A chunk that failed to load (a deploy replaced the build while the tab was
 * open) is said plainly, because Reload is exactly what fixes it; anything else is "this screen".
 * The error itself is used only to choose between the two, never shown.
 */
export function errorView(error: unknown, errorId: string): ErrorView {
  const name = error instanceof Error ? error.name : ''
  const message = error instanceof Error ? error.message : String(error ?? '')
  const staleBuild = name === 'ChunkLoadError' || /dynamically imported module|Importing a module script failed|Failed to fetch dynamically/i.test(message)
  return {
    title: staleBuild ? 'Purchases was updated' : 'This screen could not be shown',
    body: staleBuild
      ? 'A newer version was released while this page was open. Reload to continue; nothing was lost.'
      : 'Nothing was saved or changed by this. Reload the page to try again, or go back to the dashboard. If it keeps happening, tell support the reference below.',
    reference: errorId,
  }
}
