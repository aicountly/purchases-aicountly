/**
 * Charts, drawn from the data.
 *
 * There is no chart library anywhere in this fleet, and adding one to this
 * product alone would give Purchases a different visual language from Books and
 * Inventory for the sake of three chart types. These are computed from the
 * numbers on every render — every coordinate below comes from a value, and
 * there is not a hard-coded path anywhere in the file.
 *
 * Each chart ships with a table of the same figures, because a chart is an
 * illustration and the table is the data. The table is what a screen reader
 * gets, what a reader who wants the exact value gets, and what survives being
 * printed.
 *
 * Values arrive as exact decimal STRINGS. They are converted to numbers only to
 * decide where to put a pixel, and never to produce a figure that is displayed:
 * the labels are the server's formatted strings.
 */

import { useId, useState, type ReactNode } from 'react'

/** For geometry only. Never call this on a value that will be shown. */
function px(value: string | null | undefined): number {
  const parsed = Number.parseFloat(value ?? '0')
  return Number.isFinite(parsed) ? parsed : 0
}

function ChartFrame({
  title,
  summary,
  children,
  table,
}: {
  title: string
  summary: string
  children: ReactNode
  table: ReactNode
}) {
  const [showTable, setShowTable] = useState(false)
  const tableId = useId()

  return (
    // The drawing is decoration: the figcaption states every value in prose,
    // and the toggle below reveals the same figures as a table for everyone.
    // Rendering the table twice — once here, once hidden for screen readers —
    // would hand assistive technology a table the toggle does not control.
    <figure className="purchase-chart" style={{ margin: 0 }} aria-label={title}>
      <div aria-hidden="true">{children}</div>
      <figcaption className="purchase-sr-only">{summary}</figcaption>
      <div className="purchase-chart__toggle">
        <button
          type="button"
          className="purchase-button purchase-button--quiet"
          aria-expanded={showTable}
          aria-controls={tableId}
          onClick={() => setShowTable((open) => !open)}
        >
          {showTable ? 'Hide the figures' : 'Show the figures'}
        </button>
      </div>
      <div id={tableId} hidden={!showTable}>
        {table}
      </div>
    </figure>
  )
}

// ---------------------------------------------------------------------------
// Horizontal bars — ageing buckets, supplier spend
// ---------------------------------------------------------------------------

export interface BarDatum {
  id: string
  label: string
  value: string
  formatted: string
  tone?: 'brand' | 'muted' | 'warning' | 'danger' | 'success'
  /** What the bar is made of — the sample behind a rate, for the tooltip. */
  hint?: string
  onOpen?: () => void
}

export function BarChart({
  title,
  unitLabel,
  data,
  /**
   * A fixed top of the scale, for bars that are already a percentage.
   *
   * Without it the widest bar fills the track whatever it is worth, which is
   * right for spend and wrong for a rate: 61% on-time would be drawn as a full
   * bar simply because nobody did better that month.
   */
  scaleMax,
  compact = false,
}: {
  title: string
  unitLabel: string
  data: BarDatum[]
  scaleMax?: number
  compact?: boolean
}) {
  const max = scaleMax ?? data.reduce((highest, row) => Math.max(highest, px(row.value)), 0)

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${data.map((row) => `${row.label}: ${row.formatted}`).join('. ')}.`}
      table={
        <table className="purchase-table">
          <caption className="purchase-sr-only">{title}, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Band</th>
              <th scope="col" className="is-numeric">{unitLabel}</th>
            </tr>
          </thead>
          <tbody>
            {data.map((row) => (
              <tr key={row.id}>
                <th scope="row" style={{ fontWeight: 500 }}>{row.label}</th>
                <td className="is-numeric">{row.formatted}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      {/* A three-column grid rather than labels floated over the bars: an
          overlay has to guess where the text ends, and guesses wrong the first
          time a supplier has a long name. */}
      <div style={{ display: 'grid', gap: compact ? 9 : 10 }} role="presentation">
        {data.map((row) => {
          // A band with a real but tiny amount still gets a visible sliver, so
          // it reads differently from one with nothing in it.
          const width = max === 0 ? 0 : Math.max((px(row.value) / max) * 100, px(row.value) > 0 ? 1.5 : 0)
          const fill =
            row.tone === 'danger'
              ? '#e08573'
              : row.tone === 'warning'
                ? '#e8b54a'
                : row.tone === 'muted'
                  ? '#cfe6c7'
                  : row.tone === 'success'
                    ? 'var(--purchase-action)'
                    : 'var(--purchase-brand)'

          return (
            <div
              key={row.id}
              style={{
                display: 'grid',
                gridTemplateColumns: compact ? 'minmax(88px, 42%) minmax(0, 1fr) 36px' : 'minmax(90px, 30%) minmax(0, 1fr) auto',
                alignItems: 'center',
                gap: compact ? 8 : 10,
                fontSize: compact ? 11 : 12,
              }}
            >
              <span
                style={{ color: 'var(--purchase-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                title={row.hint ?? row.label}
              >
                {row.onOpen ? (
                  <button type="button" className="purchase-table__link" onClick={row.onOpen}>
                    {row.label}
                  </button>
                ) : (
                  row.label
                )}
              </span>

              <span
                style={{
                  display: 'block',
                  height: compact ? 9 : 14,
                  borderRadius: 999,
                  background: '#eef2ee',
                  overflow: 'hidden',
                }}
              >
                <span
                  className="purchase-bar__fill"
                  style={{ display: 'block', width: `${width}%`, height: '100%', background: fill }}
                />
              </span>

              <span
                style={{
                  fontVariantNumeric: 'tabular-nums',
                  fontWeight: 700,
                  whiteSpace: 'nowrap',
                  textAlign: compact ? 'right' : 'left',
                }}
              >
                {row.formatted}
              </span>
            </div>
          )
        })}
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Line / area — a trend, with an optional projected tail
// ---------------------------------------------------------------------------

export interface SeriesPoint {
  label: string
  value: string
  formatted: string
  projected?: boolean
}

export function TrendChart({
  title,
  unitLabel,
  points,
  height = 220,
}: {
  title: string
  unitLabel: string
  points: SeriesPoint[]
  height?: number
}) {
  const width = 100
  const box = 34
  const top = 3
  const usable = box - top - 5

  const values = points.map((point) => px(point.value))
  const max = Math.max(...values, 0)
  const min = Math.min(...values, 0)
  const span = max - min || 1

  const coords = points.map((point, index) => ({
    x: points.length === 1 ? width / 2 : (index / (points.length - 1)) * width,
    y: top + usable - ((px(point.value) - min) / span) * usable,
    projected: point.projected === true,
  }))

  if (points.length === 1) {
    return (
      <div className="purchase-chart__single">
        <strong>{points[0].formatted}</strong>
        <span>{points[0].label}</span>
        <p>One period in this range — there is nothing to trend against yet.</p>
      </div>
    )
  }

  const actual = coords.filter((coord) => !coord.projected)
  const projected = coords.filter((coord) => coord.projected)
  const path = (list: typeof coords) => list.map((coord, index) => `${index === 0 ? 'M' : 'L'}${coord.x.toFixed(2)},${coord.y.toFixed(2)}`).join(' ')

  const areaPath =
    actual.length > 1
      ? `${path(actual)} L${actual[actual.length - 1].x.toFixed(2)},${box} L${actual[0].x.toFixed(2)},${box} Z`
      : ''

  // The projected segment starts at the last actual point so the two do not
  // appear as unconnected series.
  const projectedPath = projected.length > 0 && actual.length > 0 ? path([actual[actual.length - 1], ...projected]) : path(projected)

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${points.map((point) => `${point.label}: ${point.formatted}${point.projected ? ' (projected)' : ''}`).join('. ')}.`}
      table={
        <table className="purchase-table">
          <caption className="purchase-sr-only">{title}, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Period</th>
              <th scope="col" className="is-numeric">{unitLabel}</th>
              <th scope="col">Basis</th>
            </tr>
          </thead>
          <tbody>
            {points.map((point) => (
              <tr key={point.label}>
                <th scope="row" style={{ fontWeight: 500 }}>{point.label}</th>
                <td className="is-numeric">{point.formatted}</td>
                <td>{point.projected ? 'Projected' : 'Actual'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <svg viewBox={`0 0 ${width} ${box}`} preserveAspectRatio="none" role="presentation" style={{ height }}>
        {[0, 0.25, 0.5, 0.75, 1].map((fraction) => (
          <line
            key={fraction}
            x1={0}
            x2={width}
            y1={top + usable * fraction}
            y2={top + usable * fraction}
            className="purchase-chart__grid"
            vectorEffect="non-scaling-stroke"
          />
        ))}
        {areaPath !== '' && <path d={areaPath} className="purchase-chart__area" />}
        {actual.length > 1 && <path d={path(actual)} className="purchase-chart__line" vectorEffect="non-scaling-stroke" />}
        {projectedPath !== '' && (
          <path d={projectedPath} className="purchase-chart__line purchase-chart__line--forecast" vectorEffect="non-scaling-stroke" />
        )}
      </svg>

      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: 'var(--purchase-muted)', marginTop: 4 }}>
        <span>{points[0]?.label}</span>
        <span>{points[points.length - 1]?.label}</span>
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Share — concentration
// ---------------------------------------------------------------------------

export interface ShareDatum {
  id: string
  label: string
  formatted: string
  sharePc: string | null
  onOpen?: () => void
}

/**
 * A stacked share bar rather than a pie.
 *
 * Reading two similar pie slices against each other is a known losing game, and
 * a share of a stated total is exactly what a stacked bar is for.
 */
export function ShareBar({
  title,
  totalLabel,
  data,
  others,
}: {
  title: string
  totalLabel: string
  data: ShareDatum[]
  others?: { formatted: string; sharePc: string | null }
}) {
  const segments = [
    ...data.map((row, index) => ({ ...row, shade: index })),
    ...(others && px(others.sharePc) > 0
      ? [{ id: '__others', label: 'All other suppliers', formatted: others.formatted, sharePc: others.sharePc, shade: data.length, onOpen: undefined }]
      : []),
  ]

  const palette = ['#25b003', '#187b12', '#4fc233', '#8ad976', '#b9e8ad', '#d9d9d9']

  return (
    <ChartFrame
      title={title}
      summary={`${title}. Base: ${totalLabel}. ${segments.map((row) => `${row.label}: ${row.formatted}${row.sharePc ? `, ${row.sharePc}%` : ''}`).join('. ')}.`}
      table={
        <table className="purchase-table">
          <caption className="purchase-sr-only">{title}, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Supplier</th>
              <th scope="col" className="is-numeric">Value</th>
              <th scope="col" className="is-numeric">Share</th>
            </tr>
          </thead>
          <tbody>
            {segments.map((row) => (
              <tr key={row.id}>
                <th scope="row" style={{ fontWeight: 500 }}>{row.label}</th>
                <td className="is-numeric">{row.formatted}</td>
                <td className="is-numeric">{row.sharePc === null ? '—' : `${row.sharePc}%`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <div
        style={{ display: 'flex', width: '100%', height: 22, borderRadius: 6, overflow: 'hidden', background: '#eef2ee' }}
        role="presentation"
      >
        {segments.map((row, index) => (
          <div
            key={row.id}
            title={`${row.label}: ${row.formatted}`}
            style={{
              width: `${Math.max(px(row.sharePc), 0)}%`,
              background: palette[Math.min(index, palette.length - 1)],
            }}
          />
        ))}
      </div>

      <ul style={{ listStyle: 'none', margin: '12px 0 0', padding: 0, display: 'grid', gap: 8 }}>
        {segments.map((row, index) => (
          <li key={row.id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12 }}>
            <span
              aria-hidden
              style={{
                width: 9,
                height: 9,
                borderRadius: 2,
                flexShrink: 0,
                background: palette[Math.min(index, palette.length - 1)],
              }}
            />
            <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {row.onOpen ? (
                <button type="button" className="purchase-table__link" onClick={row.onOpen}>
                  {row.label}
                </button>
              ) : (
                row.label
              )}
            </span>
            <span style={{ marginLeft: 'auto', fontVariantNumeric: 'tabular-nums' }}>
              {row.sharePc === null ? '—' : `${row.sharePc}%`}
            </span>
            <span style={{ width: 96, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--purchase-muted)' }}>
              {row.formatted}
            </span>
          </li>
        ))}
      </ul>
      <p className="purchase-muted" style={{ fontSize: 11, margin: '10px 0 0' }}>
        Base: {totalLabel}
      </p>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Ring — a small number of named parts of a stated total
// ---------------------------------------------------------------------------

export interface RingDatum {
  id: string
  label: string
  formatted: string
  sharePc: string | null
  onOpen?: () => void
}

/**
 * A ring, with every share printed next to its name.
 *
 * The objection to a pie is that it asks a reader to judge two similar slices
 * by eye, and they cannot. That objection does not apply here: no slice is ever
 * read off the drawing, because each one carries its own percentage in the
 * legend and the base is printed in the middle. The ring is doing one job a
 * stacked bar does less well — showing at a glance whether the spend sits in
 * one place or is spread across six.
 */
export function DonutChart({
  title,
  centreLabel,
  centreValue,
  data,
  others,
}: {
  title: string
  centreLabel: string
  centreValue: string
  data: RingDatum[]
  others?: { formatted: string; sharePc: string | null }
}) {
  const segments = [
    ...data,
    ...(others && px(others.sharePc) > 0
      ? [{ id: '__others', label: 'Other centres', formatted: others.formatted, sharePc: others.sharePc }]
      : []),
  ]

  const palette = ['#187b12', '#25b003', '#5cc93f', '#96dd82', '#c3ecb7', '#d9e2db']

  // One circle per segment, each rotated to start where the last one ended.
  // The circumference is 100 so a share is its own dash length and nothing has
  // to be converted into degrees.
  const radius = 15.9155
  let offset = 0
  const arcs = segments.map((row, index) => {
    const share = Math.max(px(row.sharePc), 0)
    const arc = { key: row.id, share, offset, colour: palette[Math.min(index, palette.length - 1)] }
    offset += share
    return arc
  })

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${centreLabel}: ${centreValue}. ${segments
        .map((row) => `${row.label}: ${row.formatted}${row.sharePc === null ? '' : `, ${row.sharePc}%`}`)
        .join('. ')}.`}
      table={
        <table className="purchase-table">
          <caption className="purchase-sr-only">{title}, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Material centre</th>
              <th scope="col" className="is-numeric">Value</th>
              <th scope="col" className="is-numeric">Share</th>
            </tr>
          </thead>
          <tbody>
            {segments.map((row) => (
              <tr key={row.id}>
                <th scope="row" style={{ fontWeight: 500 }}>{row.label}</th>
                <td className="is-numeric">{row.formatted}</td>
                <td className="is-numeric">{row.sharePc === null ? '—' : `${row.sharePc}%`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <div className="purchase-ring">
        <div className="purchase-ring__figure">
          <svg viewBox="0 0 42 42" role="presentation">
            <circle cx="21" cy="21" r={radius} className="purchase-ring__track" />
            {arcs.map((arc) => (
              <circle
                key={arc.key}
                cx="21"
                cy="21"
                r={radius}
                fill="none"
                stroke={arc.colour}
                strokeWidth="5.4"
                strokeDasharray={`${arc.share} ${100 - arc.share}`}
                // -25 puts the first segment at twelve o'clock.
                strokeDashoffset={25 - arc.offset}
              />
            ))}
          </svg>
          <div className="purchase-ring__centre">
            <strong>{centreValue}</strong>
            <span>{centreLabel}</span>
          </div>
        </div>

        <ul className="purchase-ring__legend">
          {segments.map((row, index) => (
            <li key={row.id}>
              <span
                aria-hidden
                className="purchase-ring__swatch"
                style={{ background: palette[Math.min(index, palette.length - 1)] }}
              />
              <span className="purchase-ring__name" title={`${row.label}: ${row.formatted}`}>
                {'onOpen' in row && row.onOpen ? (
                  <button type="button" className="purchase-table__link" onClick={row.onOpen}>
                    {row.label}
                  </button>
                ) : (
                  row.label
                )}
              </span>
              <span className="purchase-ring__share">{row.sharePc === null ? '—' : `${row.sharePc}%`}</span>
            </li>
          ))}
        </ul>
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Spark — the two figures a comparison is made of
// ---------------------------------------------------------------------------

/**
 * The bars beside a metric.
 *
 * Two bars, and only when the server actually sent a previous figure: this is
 * the comparison the card already states in words, drawn. There is no filler
 * series here and never a shape invented to make a card look busier — a
 * decorative sparkline on a dashboard is a chart of nothing, and a reader who
 * finds out is right to stop trusting the rest of the screen.
 */
export function SparkBars({ points, tone }: { points: { label: string; value: string }[]; tone: string }) {
  if (points.length < 2) return null

  const max = points.reduce((highest, point) => Math.max(highest, Math.abs(px(point.value))), 0)
  if (max === 0) return null

  return (
    <span className={`purchase-spark purchase-spark--${tone.replace('is-', '')}`} aria-hidden>
      {points.map((point, index) => (
        <span
          key={point.label}
          style={{ height: `${Math.max((Math.abs(px(point.value)) / max) * 100, 8)}%` }}
          data-current={index === points.length - 1 ? 'true' : undefined}
        />
      ))}
    </span>
  )
}
