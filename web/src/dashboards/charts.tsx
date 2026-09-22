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
// The categorical palette
//
// Validated, not chosen by eye. `scripts/validate_palette.js` on a white card
// surface: lightness band, chroma floor, CVD separation and normal-vision
// separation on adjacent pairs, and 3:1 contrast — all pass at four slots.
// Earlier candidates drawn straight from the brand (green / teal / amber / red)
// failed twice over: teal read as grey below the chroma floor, and amber beside
// red measured ΔE 1.6 under deuteranopia, which is two series nobody with that
// vision could tell apart.
//
// Slot 1 is the brand green, so the leading series is on brand and the rest are
// stepped away from it rather than around it. Assigned in FIXED ORDER and never
// cycled: colour follows the entity, so filtering a series out never repaints
// the ones that remain. Past four, the tail folds into "Other" in neutral grey
// rather than becoming a fifth hue.
// ---------------------------------------------------------------------------

export const SERIES_COLOURS = ['#187b12', '#2a78d6', '#eb6834', '#4a3aa7'] as const
export const SERIES_OTHER = '#b9c0bb'

export function seriesColour(index: number): string {
  return SERIES_COLOURS[index] ?? SERIES_OTHER
}

// ---------------------------------------------------------------------------
// Donut — part of a whole, at a glance
// ---------------------------------------------------------------------------

export interface DonutSegment {
  id: string
  label: string
  /** Share as an exact decimal string, 0–100. */
  share: string | null
  formatted: string
}

/** Polar point on the ring, in SVG coordinates with 12 o'clock as zero. */
function ringPoint(cx: number, cy: number, r: number, turn: number): [number, number] {
  const angle = (turn - 0.25) * 2 * Math.PI
  return [cx + r * Math.cos(angle), cy + r * Math.sin(angle)]
}

function arcPath(cx: number, cy: number, outer: number, inner: number, from: number, to: number): string {
  const large = to - from > 0.5 ? 1 : 0
  const [ox1, oy1] = ringPoint(cx, cy, outer, from)
  const [ox2, oy2] = ringPoint(cx, cy, outer, to)
  const [ix2, iy2] = ringPoint(cx, cy, inner, to)
  const [ix1, iy1] = ringPoint(cx, cy, inner, from)
  return [
    `M ${ox1} ${oy1}`,
    `A ${outer} ${outer} 0 ${large} 1 ${ox2} ${oy2}`,
    `L ${ix2} ${iy2}`,
    `A ${inner} ${inner} 0 ${large} 0 ${ix1} ${iy1}`,
    'Z',
  ].join(' ')
}

export function DonutChart({
  title,
  summary,
  segments,
  centreLabel,
  centreValue,
}: {
  title: string
  summary: string
  segments: DonutSegment[]
  centreLabel: string
  centreValue: string
}) {
  // Six is the limit for part-to-whole at a glance; past that adjacent slices
  // blur and the table is the better answer. The caller folds the tail.
  const shown = segments.slice(0, 6)
  const total = shown.reduce((sum, segment) => sum + px(segment.share), 0)

  if (total <= 0) {
    return <p className="purchase-empty">Nothing to divide up yet.</p>
  }

  const size = 208
  const cx = size / 2
  const cy = size / 2
  const outer = 96
  const inner = 62
  // A 2px surface gap between fills, not a stroke around them.
  const gap = 2 / (2 * Math.PI * outer)

  let cursor = 0
  const arcs = shown.map((segment, index) => {
    const turn = px(segment.share) / total
    const from = cursor + gap / 2
    const to = cursor + turn - gap / 2
    cursor += turn
    return { segment, index, from, to: Math.max(to, from + 0.0005) }
  })

  return (
    <ChartFrame
      title={title}
      summary={summary}
      table={
        <table className="purchase-table">
          <thead>
            <tr>
              <th scope="col">{centreLabel}</th>
              <th scope="col">Share</th>
              <th scope="col">Value</th>
            </tr>
          </thead>
          <tbody>
            {shown.map((segment) => (
              <tr key={segment.id}>
                <td>{segment.label}</td>
                <td className="purchase-table__num">{segment.share === null ? '—' : `${segment.share}%`}</td>
                <td className="purchase-table__num">{segment.formatted}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <div className="purchase-donut">
        <svg viewBox={`0 0 ${size} ${size}`} width={size} height={size} role="img" aria-hidden="true">
          {arcs.map(({ segment, index, from, to }) => (
            <path key={segment.id} d={arcPath(cx, cy, outer, inner, from, to)} fill={seriesColour(index)}>
              <title>{`${segment.label}: ${segment.formatted}${segment.share === null ? '' : ` (${segment.share}%)`}`}</title>
            </path>
          ))}
          {/* The hole is 124px across. A formatted rupee total can be twenty
              characters, so the size steps down with the string rather than
              letting it run out over the ring. */}
          <text
            x={cx}
            y={cy - 4}
            textAnchor="middle"
            className="purchase-donut__value"
            style={{ fontSize: centreValue.length > 12 ? 12 : centreValue.length > 9 ? 14 : 18 }}
          >
            {centreValue}
          </text>
          <text x={cx} y={cy + 16} textAnchor="middle" className="purchase-donut__label">
            {centreLabel}
          </text>
        </svg>

        {/* The legend is always present and every entry is directly labelled
            with its share, so identity never rests on colour alone. */}
        <ul className="purchase-donut__legend">
          {shown.map((segment, index) => (
            <li key={segment.id}>
              <i style={{ background: seriesColour(index) }} aria-hidden />
              <span className="purchase-donut__legend-name" title={segment.label}>
                {segment.label}
              </span>
              <span className="purchase-donut__legend-share">
                {segment.share === null ? '—' : `${segment.share}%`}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Sparkline — the shape behind a single rate, in a table cell
// ---------------------------------------------------------------------------

export interface SparkPoint {
  period: string
  /** Null when the month had too few deliveries to rate. */
  value: string | null
  sample: number
}

/**
 * A rate says where a supplier is; this says which way they are going.
 *
 * 86% improving and 86% collapsing read identically as a number and need
 * different conversations. Months with too small a sample are a GAP, never a
 * point joined through: a line that dives to zero on one late delivery is a
 * drawing, not a trend.
 */
export function Sparkline({ points, label }: { points: SparkPoint[]; label: string }) {
  const rated = points.filter((point) => point.value !== null)
  if (rated.length < 2) {
    return (
      <span className="purchase-soft" style={{ fontSize: '0.76rem' }}>
        Not enough months yet
      </span>
    )
  }

  const width = 78
  const height = 24
  const values = rated.map((point) => px(point.value))
  const min = Math.min(...values, 0)
  const max = Math.max(...values, 100)
  const span = max - min || 1

  const x = (index: number) => (points.length === 1 ? width / 2 : (index / (points.length - 1)) * width)
  const y = (value: number) => height - ((value - min) / span) * height

  // Breaks at the gaps rather than joining across them.
  const runs: string[] = []
  let current: string[] = []
  points.forEach((point, index) => {
    if (point.value === null) {
      if (current.length > 1) runs.push(current.join(' '))
      current = []
      return
    }
    current.push(`${current.length === 0 ? 'M' : 'L'} ${x(index).toFixed(1)} ${y(px(point.value)).toFixed(1)}`)
  })
  if (current.length > 1) runs.push(current.join(' '))

  const lastIndex = points.reduce((last, point, index) => (point.value === null ? last : index), 0)
  const first = px(rated[0].value)
  const last = px(rated[rated.length - 1].value)
  const tone = last > first ? '#187b12' : last < first ? '#c0392b' : '#6b7280'

  return (
    <svg
      className="purchase-spark"
      viewBox={`0 0 ${width} ${height}`}
      width={width}
      height={height}
      role="img"
      aria-label={`${label}: ${rated.map((point) => `${point.period} ${point.value}%`).join(', ')}`}
    >
      {runs.map((run, index) => (
        <path key={index} d={run} fill="none" stroke={tone} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
      ))}
      <circle cx={x(lastIndex)} cy={y(last)} r={2.75} fill={tone} />
    </svg>
  )
}

// ---------------------------------------------------------------------------
// Indexed lines — several price paths on one honest axis
// ---------------------------------------------------------------------------

export interface IndexSeries {
  id: string
  label: string
  points: { period: string; index: string | null; formatted: string }[]
}

/**
 * Several items' price paths, indexed to 100 at their first month.
 *
 * ONE AXIS, ALWAYS. Steel per tonne and wire per coil cannot share a rupee
 * scale, and giving each its own y-axis is the single worst thing a chart can
 * do: the alignment between two scales is arbitrary, so the picture invents a
 * relationship that is not in the data. Indexing is the honest way to put them
 * on one scale — and the indexing is done server-side in exact decimal, so the
 * numbers in the tooltip and the table are not floating-point artefacts.
 */
export function IndexLineChart({
  title,
  summary,
  series,
  baseLabel,
}: {
  title: string
  summary: string
  series: IndexSeries[]
  baseLabel: string
}) {
  // Past four the tail folds rather than becoming a fifth hue.
  const shown = series.slice(0, 4)
  const periods = Array.from(new Set(shown.flatMap((s) => s.points.map((p) => p.period)))).sort()

  if (periods.length < 2 || shown.length === 0) {
    return <p className="purchase-empty">Not enough months to draw a path yet.</p>
  }

  // Drawn near its rendered size. A 560-wide viewBox stretched across a
  // 1100px card scales every label with it, and the axis ends up in display
  // type — the numbers get bigger the wider the screen, which is nobody's
  // intention.
  const width = 720
  const height = 236
  const padLeft = 44
  const padRight = 58 // room for the direct label at the endpoint
  const padTop = 12
  const padBottom = 26

  const all = shown.flatMap((s) => s.points.map((p) => px(p.index)).filter((v) => v > 0))
  const rawMin = Math.min(...all, 100)
  const rawMax = Math.max(...all, 100)
  // A little air, and always including 100 so the base reads as the baseline.
  const min = Math.floor((rawMin - 4) / 5) * 5
  const max = Math.ceil((rawMax + 4) / 5) * 5
  const span = max - min || 1

  const x = (period: string) =>
    padLeft + (periods.indexOf(period) / (periods.length - 1)) * (width - padLeft - padRight)
  const y = (value: number) => padTop + (1 - (value - min) / span) * (height - padTop - padBottom)

  const ticks = [min, min + span / 2, max]
  const monthLabel = (period: string) => {
    const [, month] = period.split('-')
    return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(month)] ?? period
  }

  return (
    <ChartFrame
      title={title}
      summary={summary}
      table={
        <table className="purchase-table">
          <thead>
            <tr>
              <th scope="col">Item</th>
              {periods.map((period) => (
                <th key={period} scope="col">
                  {monthLabel(period)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {shown.map((s) => (
              <tr key={s.id}>
                <td>{s.label}</td>
                {periods.map((period) => {
                  const point = s.points.find((p) => p.period === period)
                  return (
                    <td key={period} className="purchase-table__num">
                      {point === undefined ? '—' : `${point.index ?? '—'} (${point.formatted})`}
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <svg
        viewBox={`0 0 ${width} ${height}`}
        className="purchase-chart purchase-chart--line"
        role="img"
        aria-hidden="true"
      >
        {/* Solid hairlines, one shade off the surface. Dashing a gridline makes
            it read as a threshold when it is just a grid. */}
        {ticks.map((tick) => (
          <g key={tick}>
            <line x1={padLeft} x2={width - padRight} y1={y(tick)} y2={y(tick)} className="purchase-chart__grid" />
            <text x={padLeft - 8} y={y(tick) + 4} textAnchor="end" className="purchase-chart__tick">
              {Math.round(tick)}
            </text>
          </g>
        ))}

        {/* 100 is the base, so it gets the one emphasised rule on the plot. */}
        {min < 100 && max > 100 && (
          <line x1={padLeft} x2={width - padRight} y1={y(100)} y2={y(100)} className="purchase-chart__baseline" />
        )}

        {periods.map((period) => (
          <text key={period} x={x(period)} y={height - 8} textAnchor="middle" className="purchase-chart__tick">
            {monthLabel(period)}
          </text>
        ))}

        {shown.map((s, index) => {
          const drawable = s.points.filter((p) => p.index !== null)
          if (drawable.length < 2) return null
          const d = drawable
            .map((p, i) => `${i === 0 ? 'M' : 'L'} ${x(p.period).toFixed(1)} ${y(px(p.index)).toFixed(1)}`)
            .join(' ')
          const last = drawable[drawable.length - 1]
          return (
            <g key={s.id}>
              <path d={d} fill="none" stroke={seriesColour(index)} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
              {drawable.map((p) => (
                <circle
                  key={p.period}
                  cx={x(p.period)}
                  cy={y(px(p.index))}
                  r={4}
                  fill={seriesColour(index)}
                  stroke="#fff"
                  strokeWidth={2}
                >
                  <title>{`${s.label} · ${monthLabel(p.period)}: index ${p.index} (${p.formatted})`}</title>
                </circle>
              ))}
              {/* Direct-labelled at the endpoint only. A number on every point
                  is chaos and goes unread. */}
              <text
                x={x(last.period) + 9}
                y={y(px(last.index)) + 4}
                className="purchase-chart__endlabel"
                fill={seriesColour(index)}
              >
                {last.index}
              </text>
            </g>
          )
        })}
      </svg>

      <ul className="purchase-legend" style={{ listStyle: 'none', padding: 0, margin: '0.35rem 0 0' }}>
        {shown.map((s, index) => (
          <li key={s.id}>
            <span>
              <i style={{ background: seriesColour(index) }} aria-hidden />
              {s.label}
            </span>
          </li>
        ))}
      </ul>
      <p className="purchase-soft" style={{ fontSize: '0.76rem', textAlign: 'center', margin: '0.3rem 0 0' }}>
        {baseLabel}
      </p>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Segmented bar — one rule split by outcome
// ---------------------------------------------------------------------------

export interface MatchSegment {
  id: string
  label: string
  count: number
  tone: 'good' | 'warn' | 'bad'
}

/**
 * How a set of documents came out, as one bar and three counts.
 *
 * The tones here are STATUS, not series: matched, needs review and missing are
 * states, not identities, so they take the status palette and each carries its
 * own label and count beside it. Colour never does the work alone.
 */
export function SegmentedBar({ segments, onOpen }: { segments: MatchSegment[]; onOpen?: (id: string) => void }) {
  const total = segments.reduce((sum, segment) => sum + segment.count, 0)

  if (total === 0) {
    return <p className="purchase-empty">Nothing has been matched in this period yet.</p>
  }

  const share = (count: number) => ((count / total) * 100).toFixed(count === 0 ? 0 : 1).replace(/\.0$/, '')

  return (
    <div>
      <div className="purchase-segmentbar" role="img" aria-label={segments.map((s) => `${s.label} ${s.count}`).join(', ')}>
        {segments
          .filter((segment) => segment.count > 0)
          .map((segment) => (
            <span
              key={segment.id}
              className={`is-${segment.tone}`}
              style={{ width: `${(segment.count / total) * 100}%` }}
            />
          ))}
      </div>

      <div className="purchase-segments">
        {segments.map((segment) => (
          <div key={segment.id} className="purchase-segment">
            <strong className={`purchase-segment__count is-${segment.tone}`}>{segment.count}</strong>
            {onOpen !== undefined && segment.count > 0 ? (
              <button type="button" className="purchase-table__link" onClick={() => onOpen(segment.id)}>
                {segment.label}
              </button>
            ) : (
              <span>{segment.label}</span>
            )}
            <span className="purchase-soft" style={{ display: 'block', fontSize: '0.78rem' }}>
              {share(segment.count)}%
            </span>
          </div>
        ))}
      </div>
    </div>
  )
}
