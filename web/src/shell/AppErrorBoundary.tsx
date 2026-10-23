import { Component } from 'react'
import type { ErrorInfo, ReactNode } from 'react'
import { Button } from '../ui'
import { errorView, newErrorId } from './errorBoundary'

interface Props {
  children: ReactNode
  /** A change (the path) clears a caught error, so navigating elsewhere recovers without a reload. */
  resetKey?: string
  /** Where "Go to the dashboard" leads. Omitted outside the router, where only a reload helps. */
  homeHref?: string
}

interface State {
  error: unknown
  errorId: string | null
}

/**
 * The app-level error boundary (launch-readiness P1-P6). A screen that throws while rendering (a
 * field missing from an answer, a build newer or older than the API mid-deploy) used to unmount the
 * whole app: a white page and no way back but a reload. This shows what to do instead — Reload, the
 * dashboard, and a reference to quote to support — and logs the error and its component stack to the
 * console under that reference. The message and stack are never shown to the person.
 *
 * Mounted twice: around the whole app (main.tsx), and around the routed screen inside the shell
 * (AppShell), where a new path clears it so the sidebar keeps working.
 */
export class AppErrorBoundary extends Component<Props, State> {
  state: State = { error: null, errorId: null }

  static getDerivedStateFromError(error: unknown): State {
    return { error, errorId: newErrorId() }
  }

  componentDidUpdate(prev: Props): void {
    if (this.state.errorId !== null && prev.resetKey !== this.props.resetKey) {
      this.setState({ error: null, errorId: null })
    }
  }

  componentDidCatch(error: unknown, info: ErrorInfo): void {
    console.error(`[purchases] ${this.state.errorId ?? 'PUR-?'}: a screen failed to render`, error, info.componentStack)
  }

  render() {
    if (this.state.errorId === null) return this.props.children
    const view = errorView(this.state.error, this.state.errorId)
    return (
      <div role="alert" className="app-error" style={{ maxWidth: '36rem', margin: '3rem auto', padding: '1.5rem', border: '1px solid var(--border, #dfe3df)', borderRadius: '12px', background: 'var(--surface, #fff)' }}>
        <h1 style={{ margin: 0, fontSize: '1.15rem' }}>{view.title}</h1>
        <p style={{ margin: '0.6rem 0 0', color: 'var(--muted, #5f6b62)', lineHeight: 1.5 }}>{view.body}</p>
        <p style={{ margin: '0.8rem 0 0', fontSize: '0.85rem' }}>
          Reference <code style={{ userSelect: 'all' }}>{view.reference}</code>
        </p>
        <div style={{ display: 'flex', gap: '0.5rem', marginTop: '1.1rem' }}>
          <Button tone="primary" onClick={() => window.location.reload()}>
            Reload
          </Button>
          {this.props.homeHref ? (
            <a href={this.props.homeHref} style={{ alignSelf: 'center', color: 'var(--accent)', fontWeight: 500 }}>
              Go to the dashboard
            </a>
          ) : null}
        </div>
      </div>
    )
  }
}
