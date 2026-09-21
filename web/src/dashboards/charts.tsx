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
  tone?: 'brand' | 'muted' | 'warning' | 'danger'
  onOpen?: () => void
}

export function BarChart({
  title,
  unitLabel,
  data,
}: {
  title: string
  unitLabel: string
  data: BarDatum[]
}) {
  const max = data.reduce((highest, row) => Math.max(highest, px(row.value)), 0)

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
      <div style={{ display: 'grid', gap: 10 }} role="presentation">
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
                  : 'var(--purchase-brand)'

          return (
            <div
              key={row.id}
              style={{
                display: 'grid',
                gridTemplateColumns: 'minmax(90px, 30%) minmax(0, 1fr) auto',
                alignItems: 'center',
                gap: 10,
                fontSize: 12,
              }}
            >
              <span
                style={{ color: 'var(--purchase-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                title={row.label}
              >
                {row.onOpen ? (
                  <button type="button" className="purchase-table__link" onClick={row.onOpen}>
                    {row.label}
                  </button>
                ) : (
                  row.label
                )}
              </span>

              <span style={{ display: 'block', height: 14, borderRadius: 4, background: '#eef2ee', overflow: 'hidden' }}>
                <span style={{ display: 'block', width: `${width}%`, height: '100%', background: fill }} />
              </span>

              <span style={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600, whiteSpace: 'nowrap' }}>
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
}: {
  title: string
  unitLabel: string
  points: SeriesPoint[]
}) {
  const width = 100
  const height = 34
  const top = 3
  const usable = height - top - 5

  const values = points.map((point) => px(point.value))
  const max = Math.max(...values, 0)
  const min = Math.min(...values, 0)
  const span = max - min || 1

  const coords = points.map((point, index) => ({
    x: points.length === 1 ? width / 2 : (index / (points.length - 1)) * width,
    y: top + usable - ((px(point.value) - min) / span) * usable,
    projected: point.projected === true,
  }))

  const actual = coords.filter((coord) => !coord.projected)
  const projected = coords.filter((coord) => coord.projected)
  const path = (list: typeof coords) => list.map((coord, index) => `${index === 0 ? 'M' : 'L'}${coord.x.toFixed(2)},${coord.y.toFixed(2)}`).join(' ')

  const areaPath =
    actual.length > 1
      ? `${path(actual)} L${actual[actual.length - 1].x.toFixed(2)},${height} L${actual[0].x.toFixed(2)},${height} Z`
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
      <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="presentation" style={{ height: 220 }}>
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
// Sparkline — the history behind one KPI
// ---------------------------------------------------------------------------

/**
 * A 30px line under a metric.
 *
 * It is decoration and is marked as such: the figure above it is the answer,
 * and the accessible summary states the range the line covers rather than
 * asking a screen-reader user to imagine a shape. There is no axis, no grid and
 * no tooltip, because at this size all three are noise.
 */
export function Sparkline({ points, label }: { points: SeriesPoint[]; label: string }) {
  if (points.length < 2) return <div className="purchase-spark" aria-hidden="true" />

  const width = 100
  const height = 26
  const values = points.map((point) => px(point.value))
  const max = Math.max(...values)
  const min = Math.min(...values, 0)
  const span = max - min || 1

  const coords = points.map((point, index) => ({
    x: (index / (points.length - 1)) * width,
    y: height - ((px(point.value) - min) / span) * (height - 2) - 1,
  }))

  const line = coords.map((coord, index) => `${index === 0 ? 'M' : 'L'}${coord.x.toFixed(2)},${coord.y.toFixed(2)}`).join(' ')
  const area = `${line} L${width},${height} L0,${height} Z`
  const last = coords[coords.length - 1]

  return (
    <div className="purchase-spark">
      <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="img" aria-label={label}>
        <path d={area} className="purchase-chart__area" />
        <path d={line} className="purchase-chart__line" vectorEffect="non-scaling-stroke" />
        {/* The most recent point, marked: a line whose end a reader has to hunt
            for is a line that gets read backwards. */}
        <circle cx={last.x} cy={last.y} r={1.6} className="purchase-chart__point" vectorEffect="non-scaling-stroke" />
      </svg>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Grouped columns — this period against the last
// ---------------------------------------------------------------------------

export interface GroupedDatum {
  key: string
  label: string
  value: string
  formatted: string
  previousValue: string | null
  previousFormatted: string | null
  previousLabel: string | null
}

/**
 * Two bars per bucket, drawn as HTML rather than SVG.
 *
 * A grid of flex columns reflows at any width without recomputing a viewBox,
 * and — the reason that matters — the label under each bucket is real text that
 * wraps, ellipsises and scales with the browser's font size. SVG text does none
 * of those things, which is why an SVG chart is the first thing to become
 * unreadable at 200% zoom.
 */
export function GroupedColumns({
  title,
  data,
  currentLabel,
  previousLabel,
  unitLabel,
}: {
  title: string
  data: GroupedDatum[]
  currentLabel: string
  previousLabel: string
  unitLabel: string
}) {
  const max = data.reduce(
    (highest, row) => Math.max(highest, px(row.value), row.previousValue === null ? 0 : px(row.previousValue)),
    0,
  )
  const hasPrevious = data.some((row) => row.previousValue !== null)
  // Every label when there are few buckets; every Nth when there are many.
  // Thirty-one labels in a 560px panel are thirty-one ellipses, which tell the
  // reader less than eight readable dates and the first and last anchored.
  const labelStep = Math.max(1, Math.ceil(data.length / 8))
  const labelled = (index: number) => index === 0 || index === data.length - 1 || index % labelStep === 0
  // A bucket worth something real but tiny still gets a visible sliver, so it
  // reads differently from a bucket with nothing in it.
  const heightOf = (value: string) => (max === 0 ? 0 : Math.max((px(value) / max) * 100, px(value) > 0 ? 1.5 : 0))

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${data
        .map((row) => `${row.label}: ${row.formatted}${row.previousFormatted ? `, against ${row.previousFormatted} in ${row.previousLabel}` : ''}`)
        .join('. ')}.`}
      table={
        <table className="purchase-table">
          <caption className="purchase-sr-only">{title}, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Period</th>
              <th scope="col" className="is-numeric">{currentLabel}</th>
              {hasPrevious && <th scope="col" className="is-numeric">{previousLabel}</th>}
            </tr>
          </thead>
          <tbody>
            {data.map((row) => (
              <tr key={row.key}>
                <th scope="row" style={{ fontWeight: 500 }}>{row.label}</th>
                <td className="is-numeric">{row.formatted}</td>
                {hasPrevious && (
                  <td className="is-numeric">
                    {row.previousFormatted ?? '—'}
                    {row.previousLabel && <span className="purchase-table__sub">{row.previousLabel}</span>}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <div className="purchase-columns" role="presentation">
        <div className="purchase-columns__plot">
          {/* Four gridlines, behind the bars. Fewer than a reader needs to
              judge a height, and more would be a cage. */}
          {[0, 25, 50, 75, 100].map((fraction) => (
            <span key={fraction} className="purchase-columns__grid" style={{ bottom: `${fraction}%` }} />
          ))}

          {data.map((row, index) => (
            <div key={row.key} className="purchase-columns__bucket">
              <div className="purchase-columns__bars">
                <span
                  className="purchase-columns__bar is-current"
                  style={{ height: `${heightOf(row.value)}%` }}
                  title={`${row.label}: ${row.formatted}`}
                />
                {row.previousValue !== null && (
                  <span
                    className="purchase-columns__bar is-previous"
                    style={{ height: `${heightOf(row.previousValue)}%` }}
                    title={`${row.previousLabel}: ${row.previousFormatted}`}
                  />
                )}
              </div>
              {/* The bucket keeps its label slot either way, so the bars all
                  stand on the same baseline whether their date is printed. */}
              <span className="purchase-columns__label" title={row.label}>
                {labelled(index) ? row.label : ''}
              </span>
            </div>
          ))}
        </div>
      </div>

      <p className="purchase-legend" aria-hidden="true">
        <span className="purchase-legend__item">
          <span className="purchase-legend__swatch is-current" /> {currentLabel}
        </span>
        {hasPrevious && (
          <span className="purchase-legend__item">
            <span className="purchase-legend__swatch is-previous" /> {previousLabel}
          </span>
        )}
        <span className="purchase-legend__unit">{unitLabel}</span>
      </p>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Ageing columns — an amount above each bucket
// ---------------------------------------------------------------------------

export interface BucketDatum {
  id: string
  label: string
  value: string
  formatted: string
  compact: string
  sharePc: string | null
  tone: 'neutral' | 'warning' | 'danger'
  onOpen?: () => void
}

/**
 * The ageing buckets, worst on the right.
 *
 * Each column carries its amount above the bar, because the question a reader
 * has here is "how much is over ninety days", not "which bar is tallest" — and
 * a bar chart that makes you estimate the number from a height is a bar chart
 * that gets photographed and argued about.
 */
export function BucketColumns({ title, data }: { title: string; data: BucketDatum[] }) {
  const max = data.reduce((highest, row) => Math.max(highest, px(row.value)), 0)

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${data.map((row) => `${row.label}: ${row.formatted}`).join('. ')}.`}
      table={
        <table className="purchase-table">
          <caption className="purchase-sr-only">{title}, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Bucket</th>
              <th scope="col" className="is-numeric">Amount</th>
              <th scope="col" className="is-numeric">Share</th>
            </tr>
          </thead>
          <tbody>
            {data.map((row) => (
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
      <div className="purchase-buckets" role="presentation">
        {data.map((row) => {
          const height = max === 0 ? 0 : Math.max((px(row.value) / max) * 100, px(row.value) > 0 ? 3 : 0)
          const body = (
            <>
              <span className="purchase-buckets__amount">{row.compact}</span>
              <span className="purchase-buckets__track">
                <span className={`purchase-buckets__fill is-${row.tone}`} style={{ height: `${height}%` }} />
              </span>
              <span className="purchase-buckets__label">{row.label}</span>
            </>
          )

          return row.onOpen ? (
            <button key={row.id} type="button" className="purchase-buckets__bucket" onClick={row.onOpen}>
              {body}
              <span className="purchase-sr-only">
                Open {row.label}: {row.formatted}
              </span>
            </button>
          ) : (
            <div key={row.id} className="purchase-buckets__bucket" title={`${row.label}: ${row.formatted}`}>
              {body}
            </div>
          )
        })}
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Donut — the split of one total
// ---------------------------------------------------------------------------

export interface SliceDatum {
  id: string
  label: string
  formatted: string
  compact: string
  sharePc: string | null
}

/**
 * A donut, drawn with one stroked circle per slice.
 *
 * `stroke-dasharray` on a shared circle keeps every slice a single element with
 * no arc arithmetic and no path strings to get wrong — the geometry is one
 * number per slice, its share, which is the number the legend prints too.
 *
 * The palette is deliberately a run of greens down to a neutral. A rainbow
 * would imply the categories are of different kinds; they are the same kind in
 * different amounts, and a sequence says that.
 */
export function Donut({
  title,
  centreValue,
  centreLabel,
  data,
  onSliceOpen,
}: {
  title: string
  centreValue: string
  centreLabel: string
  data: SliceDatum[]
  onSliceOpen?: (slice: SliceDatum) => void
}) {
  const palette = ['#187b12', '#25b003', '#4fc233', '#8ad976', '#b9e8ad', '#dcefd4', '#cfd8d1']
  const radius = 15.915  // circumference 100, so a share IS the dash length
  let consumed = 0

  const slices = data.map((slice, index) => {
    const share = Math.max(px(slice.sharePc), 0)
    const offset = consumed
    consumed += share
    return { slice, share, offset, colour: palette[Math.min(index, palette.length - 1)] }
  })

  return (
    <ChartFrame
      title={title}
      summary={`${title}. Total ${centreValue}. ${data.map((row) => `${row.label}: ${row.formatted}${row.sharePc ? `, ${row.sharePc}%` : ''}`).join('. ')}.`}
      table={
        <table className="purchase-table">
          <caption className="purchase-sr-only">{title}, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Category</th>
              <th scope="col" className="is-numeric">Value</th>
              <th scope="col" className="is-numeric">Share</th>
            </tr>
          </thead>
          <tbody>
            {data.map((row) => (
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
      <div className="purchase-donut">
        <div className="purchase-donut__ring">
          <svg viewBox="0 0 42 42" role="presentation">
            <circle cx="21" cy="21" r={radius} className="purchase-donut__track" />
            {slices.map(({ slice, share, offset, colour }) => (
              <circle
                key={slice.id}
                cx="21"
                cy="21"
                r={radius}
                fill="transparent"
                stroke={colour}
                strokeWidth="5.5"
                strokeDasharray={`${share} ${100 - share}`}
                // -25 puts the first slice at twelve o'clock rather than at
                // three, which is where a reader starts.
                strokeDashoffset={25 - offset}
              >
                <title>{`${slice.label}: ${slice.formatted}`}</title>
              </circle>
            ))}
          </svg>
          <div className="purchase-donut__centre">
            <strong>{centreValue}</strong>
            <span>{centreLabel}</span>
          </div>
        </div>

        <ul className="purchase-donut__legend">
          {slices.map(({ slice, colour }) => (
            <li key={slice.id}>
              <span className="purchase-donut__swatch" style={{ background: colour }} aria-hidden />
              <span className="purchase-donut__name" title={slice.label}>
                {onSliceOpen ? (
                  <button type="button" className="purchase-table__link" onClick={() => onSliceOpen(slice)}>
                    {slice.label}
                  </button>
                ) : (
                  slice.label
                )}
              </span>
              <span className="purchase-donut__share">{slice.sharePc === null ? '—' : `${slice.sharePc}%`}</span>
            </li>
          ))}
        </ul>
      </div>
    </ChartFrame>
  )
}
