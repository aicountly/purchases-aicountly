/**
 * What the screen looks like while it is being fetched.
 *
 * Shaped like the page it is about to become — header, navigator, five cards,
 * three rail cards — so nothing jumps when the data lands. A centred spinner
 * would be less work and would move every element on the page twice.
 */

export function ProfileSkeleton() {
  return (
    <div className="pp-container" aria-busy="true" aria-live="polite">
      <span className="pp-sr-only">Loading purchase profile settings…</span>

      <div className="pp-heading">
        <div style={{ flex: 1 }}>
          <div className="pp-skeleton" style={{ height: 14, width: 180, marginBottom: 18 }} />
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            <div className="pp-skeleton" style={{ width: 48, height: 48, borderRadius: 13 }} />
            <div style={{ flex: 1 }}>
              <div className="pp-skeleton" style={{ height: 24, width: 220 }} />
              <div className="pp-skeleton" style={{ height: 13, width: 'min(420px, 70%)', marginTop: 10 }} />
            </div>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <div className="pp-skeleton" style={{ height: 40, width: 128, borderRadius: 9 }} />
          <div className="pp-skeleton" style={{ height: 40, width: 118, borderRadius: 9 }} />
        </div>
      </div>

      <div className="pp-progress" aria-hidden>
        {[0, 1, 2, 3, 4].map((step) => (
          <div className="pp-progress__step" key={step}>
            <div className="pp-skeleton" style={{ width: 34, height: 34, borderRadius: '50%', flex: '0 0 34px' }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div className="pp-skeleton" style={{ height: 11, width: '72%' }} />
              <div className="pp-skeleton" style={{ height: 9, width: '52%', marginTop: 6 }} />
            </div>
          </div>
        ))}
      </div>

      <div className="pp-layout" aria-hidden>
        <div className="pp-main">
          {[148, 172, 196, 170, 132].map((height, index) => (
            <div className="pp-card" key={index}>
              <div className="pp-card__header">
                <div className="pp-skeleton" style={{ width: 40, height: 40, borderRadius: 11, flex: '0 0 40px' }} />
                <div style={{ flex: 1 }}>
                  <div className="pp-skeleton" style={{ height: 12, width: 190 }} />
                  <div className="pp-skeleton" style={{ height: 10, width: 290, marginTop: 7 }} />
                </div>
              </div>
              <div className="pp-card__body">
                <div className="pp-skeleton" style={{ height: height - 80 }} />
              </div>
            </div>
          ))}
        </div>

        <div className="pp-rail">
          {[210, 132, 220].map((height, index) => (
            <div className="pp-card" key={index} style={{ padding: 16 }}>
              <div className="pp-skeleton" style={{ height: 12, width: 130, marginBottom: 14 }} />
              <div className="pp-skeleton" style={{ height: height - 60 }} />
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
