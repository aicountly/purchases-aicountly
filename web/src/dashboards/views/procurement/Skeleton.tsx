/**
 * The workspace while it is still loading.
 *
 * Shaped like the screen it is standing in for, so nothing jumps when the
 * figures arrive and a reader can already tell what is coming. Four grey
 * rectangles the size of a wall tell them only that something is broken.
 */

export function ProcurementSkeleton() {
  return (
    <div className="purchase-dashboard-grid" aria-busy="true" aria-label="Loading the procurement workspace">
      <section className="purchase-panel purchase-span-all" aria-hidden>
        <header className="purchase-panel__header">
          <div style={{ width: '100%' }}>
            <span className="purchase-skeleton purchase-skeleton--line" style={{ width: 160, height: 13 }} />
            <span className="purchase-skeleton purchase-skeleton--line" style={{ width: 260, height: 9, marginTop: 8 }} />
          </div>
        </header>
        <div className="purchase-flow purchase-flow--skeleton">
          {Array.from({ length: 6 }, (_, index) => (
            <div key={index} className="purchase-flow__stage" style={{ pointerEvents: 'none' }}>
              <span className="purchase-skeleton purchase-skeleton--icon" style={{ width: 30, height: 30 }} />
              <span className="purchase-skeleton purchase-skeleton--line" style={{ width: '70%', marginTop: 8 }} />
              <span className="purchase-skeleton purchase-skeleton--value" style={{ width: 46, marginTop: 6 }} />
              <span className="purchase-skeleton purchase-skeleton--line" style={{ width: '55%', marginTop: 8 }} />
            </div>
          ))}
        </div>
      </section>

      <div className="purchase-primary-column">
        <div className="purchase-analytics-grid">
          {Array.from({ length: 3 }, (_, index) => (
            <section key={index} className="purchase-panel" aria-hidden>
              <header className="purchase-panel__header">
                <span className="purchase-skeleton purchase-skeleton--line" style={{ width: 130, height: 12 }} />
              </header>
              <div className="purchase-panel__body">
                <span className="purchase-skeleton" style={{ display: 'block', height: 140, borderRadius: 12 }} />
              </div>
            </section>
          ))}
        </div>

        <section className="purchase-panel" aria-hidden>
          <header className="purchase-panel__header">
            <span className="purchase-skeleton purchase-skeleton--line" style={{ width: 200, height: 12 }} />
          </header>
          <div className="purchase-panel__body" style={{ display: 'grid', gap: 12 }}>
            {Array.from({ length: 5 }, (_, index) => (
              <span key={index} className="purchase-skeleton purchase-skeleton--line" style={{ height: 14 }} />
            ))}
          </div>
        </section>
      </div>

      <aside className="purchase-insights-column">
        <section className="purchase-panel" aria-hidden>
          <header className="purchase-panel__header">
            <span className="purchase-skeleton purchase-skeleton--line" style={{ width: 150, height: 12 }} />
          </header>
          <div className="purchase-insight-list">
            {Array.from({ length: 5 }, (_, index) => (
              <span key={index} className="purchase-skeleton" style={{ display: 'block', height: 62, borderRadius: 11 }} />
            ))}
          </div>
        </section>
      </aside>
    </div>
  )
}
