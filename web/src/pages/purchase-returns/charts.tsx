/**
 * Two charts, drawn from the data.
 *
 * There is no chart library in this fleet and adding one for two charts would
 * give Purchases a different visual language from Books and Inventory. These
 * are computed on every render: every coordinate below comes from a value, and
 * there is not a hard-coded path in the file.
 *
 * EACH CHART SHIPS WITH ITS FIGURES. A chart is an illustration; the table is
 * the data. The table is what a screen reader gets, what a reader who wants the
 * exact number gets, and what survives being printed — which is why the
 * drawings are `aria-hidden` and the caption states every value in prose.
 *
 * Money arrives as an exact decimal STRING. It is turned into a number only to
 * decide where to put a pixel, never to produce a figure that is displayed:
 * every label is the string the server formatted.
 */

import { useId, useState } from 'react'
import type { ReturnReasonSlice, ReturnTrendPoint } from './types'

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
  children: React.ReactNode
  table: React.ReactNode
}) {
  const [showTable, setShowTable] = useState(false)
  const tableId = useId()

  return (
    <figure className="pr-chart" aria-label={title}>
      <div aria-hidden="true" className="pr-chart-frame">
        {children}
      </div>
      <figcaption className="pr-sr-only">{summary}</figcaption>
      <div className="pr-chart__toggle">
        <button
          type="button"
          className="pr-btn pr-btn--quiet pr-btn--small"
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
// Trend — bars for how many, a line for how much
// ---------------------------------------------------------------------------

/**
 * Two series on one plot, because the question is whether they move together.
 *
 * Twelve returns worth ₹40,000 and twelve worth ₹4,00,000 are different months
 * and a count alone cannot tell them apart. The two scales are independent and
 * each is labelled in the legend, which is the honest way to draw a count
 * against a currency.
 */
export function ReturnsTrendChart({ points }: { points: ReturnTrendPoint[] }) {
  const [hover, setHover] = useState<number | null>(null)

  const width = 320
  const height = 150
  const padTop = 10
  const padBottom = 6
  const usable = height - padTop - padBottom

  const countMax = Math.max(...points.map((point) => point.count), 1)
  const valueMax = Math.max(...points.map((point) => px(point.value)), 1)

  const slot = width / Math.max(points.length, 1)
  const barWidth = Math.min(30, slot * 0.46)

  const x = (index: number) => slot * index + slot / 2
  const barY = (count: number) => padTop + usable - (count / countMax) * usable
  const lineY = (value: number) => padTop + usable - (value / valueMax) * usable

  const linePoints = points.map((point, index) => ({ x: x(index), y: lineY(px(point.value)) }))
  const linePath = linePoints
    .map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x.toFixed(2)},${point.y.toFixed(2)}`)
    .join(' ')

  const active = hover === null ? null : points[hover]

  return (
    <ChartFrame
      title="Returns trend"
      summary={`Returns over the last ${points.length} months. ${points
        .map((point) => `${point.label}: ${point.count} returns worth ${point.value_formatted}`)
        .join('. ')}.`}
      table={
        <table className="pr-figures">
          <caption className="pr-sr-only">Returns trend, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Month</th>
              <th scope="col" className="is-numeric">Returns</th>
              <th scope="col" className="is-numeric">Value</th>
            </tr>
          </thead>
          <tbody>
            {points.map((point) => (
              <tr key={point.month}>
                <th scope="row" style={{ fontWeight: 500 }}>{point.label}</th>
                <td className="is-numeric">{point.count}</td>
                <td className="is-numeric">{point.value_formatted}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <svg
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="none"
        role="presentation"
        className="pr-chart__plot"
        onMouseLeave={() => setHover(null)}
      >
        {[0, 0.25, 0.5, 0.75, 1].map((fraction) => (
          <line
            key={fraction}
            x1={0}
            x2={width}
            y1={padTop + usable * fraction}
            y2={padTop + usable * fraction}
            className="pr-chart__grid"
            vectorEffect="non-scaling-stroke"
          />
        ))}

        {points.map((point, index) => {
          const top = barY(point.count)

          return (
            <g key={point.month} onMouseEnter={() => setHover(index)}>
              {/* A full-height hit area, so the tooltip appears anywhere in the
                  month's column rather than only over a two-pixel bar. */}
              <rect x={slot * index} y={0} width={slot} height={height} fill="transparent" />
              <rect
                x={x(index) - barWidth / 2}
                y={point.count === 0 ? padTop + usable - 1 : top}
                width={barWidth}
                height={point.count === 0 ? 1 : Math.max(1, padTop + usable - top)}
                rx={3}
                className={hover === index ? 'pr-chart__bar is-active' : 'pr-chart__bar'}
              />
            </g>
          )
        })}

        {linePoints.length > 1 && <path d={linePath} className="pr-chart__line" vectorEffect="non-scaling-stroke" />}
        {linePoints.map((point, index) => (
          <circle
            key={points[index].month}
            cx={point.x}
            cy={point.y}
            r={hover === index ? 4 : 2.6}
            className="pr-chart__marker"
            vectorEffect="non-scaling-stroke"
          />
        ))}
      </svg>

      {active && (
        <div
          className="pr-chart__tip"
          style={{
            left: `${((hover ?? 0) + 0.5) * (100 / Math.max(points.length, 1))}%`,
            top: 4,
            transform: (hover ?? 0) > points.length / 2 ? 'translateX(-105%)' : 'translateX(5%)',
          }}
        >
          <strong>{active.label}</strong>
          {active.count} return{active.count === 1 ? '' : 's'}
          <br />
          {active.value_formatted}
        </div>
      )}

      {/* Both scales, stated. A count drawn against a currency with neither
          axis labelled is two lines the reader has to take on trust. */}
      <div className="pr-chart__scale">
        <span>{countMax} returns</span>
        <span>{points.reduce((top, point) => (px(point.value) > px(top.value) ? point : top), points[0])?.value_compact}</span>
      </div>

      <div className="pr-chart__axis">
        {points.map((point) => (
          <span key={point.month}>{point.short_label}</span>
        ))}
      </div>

      <div className="pr-legend">
        <span>
          <i style={{ background: '#9dc4f5' }} aria-hidden /> No. of returns
        </span>
        <span>
          <i style={{ background: '#0ca968' }} aria-hidden /> Return value (₹)
        </span>
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Donut — why the goods went back
// ---------------------------------------------------------------------------

/**
 * A restrained palette, and never the only thing carrying a label.
 *
 * Every legend entry states its reason and its share in words beside the
 * swatch, so the chart reads without the colours. Blue leads because it is this
 * screen's colour; the rest are chosen to stay apart in greyscale too.
 */
const SLICE_COLOURS = ['#1769e8', '#7956df', '#0ca968', '#ef8d18', '#e5484d', '#64748b'] as const
const SLICE_OTHER = '#b9c0cb'

function sliceColour(index: number): string {
  return SLICE_COLOURS[index] ?? SLICE_OTHER
}

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

export function ReasonsDonut({
  reasons,
  total,
  onPick,
}: {
  reasons: ReturnReasonSlice[]
  total: number
  onPick?: (reasonCode: string) => void
}) {
  // Six is the limit for part-to-whole at a glance; past that adjacent slices
  // blur and the table is the better answer. The tail is folded into one.
  const head = reasons.slice(0, 5)
  const tail = reasons.slice(5)
  const folded: ReturnReasonSlice[] =
    tail.length === 0
      ? head
      : [
          ...head,
          {
            reason_code: '__other',
            label: `${tail.length} other reason${tail.length === 1 ? '' : 's'}`,
            count: tail.reduce((sum, slice) => sum + slice.count, 0),
            value: '0',
            value_formatted: '',
            percentage: tail.reduce((sum, slice) => sum + px(slice.percentage), 0).toFixed(1),
          },
        ]

  const sum = folded.reduce((running, slice) => running + px(slice.percentage), 0)

  const size = 168
  const cx = size / 2
  const cy = size / 2
  const outer = 78
  const inner = 51
  const gap = 2 / (2 * Math.PI * outer)

  let cursor = 0
  const arcs = folded.map((slice, index) => {
    const turn = px(slice.percentage) / (sum || 1)
    const from = cursor + gap / 2
    const to = cursor + turn - gap / 2
    cursor += turn

    return { slice, index, from, to: Math.max(to, from + 0.0005) }
  })

  return (
    <ChartFrame
      title="Reasons for return"
      summary={`Reasons for return across ${total} returns. ${folded
        .map((slice) => `${slice.label}: ${slice.count} (${slice.percentage}%)`)
        .join('. ')}.`}
      table={
        <table className="pr-figures">
          <caption className="pr-sr-only">Reasons for return, as figures</caption>
          <thead>
            <tr>
              <th scope="col">Reason</th>
              <th scope="col" className="is-numeric">Returns</th>
              <th scope="col" className="is-numeric">Share</th>
              <th scope="col" className="is-numeric">Value</th>
            </tr>
          </thead>
          <tbody>
            {reasons.map((slice) => (
              <tr key={slice.reason_code}>
                <th scope="row" style={{ fontWeight: 500 }}>{slice.label}</th>
                <td className="is-numeric">{slice.count}</td>
                <td className="is-numeric">{slice.percentage}%</td>
                <td className="is-numeric">{slice.value_formatted}</td>
              </tr>
            ))}
          </tbody>
        </table>
      }
    >
      <div className="pr-donut">
        <svg viewBox={`0 0 ${size} ${size}`} width={size} height={size} role="img" aria-hidden="true">
          {arcs.map(({ slice, index, from, to }) => (
            <path key={slice.reason_code} d={arcPath(cx, cy, outer, inner, from, to)} fill={sliceColour(index)}>
              <title>{`${slice.label}: ${slice.count} (${slice.percentage}%)`}</title>
            </path>
          ))}
          <text x={cx} y={cy - 2} textAnchor="middle" className="pr-donut__value">
            {total}
          </text>
          <text x={cx} y={cy + 15} textAnchor="middle" className="pr-donut__label">
            {total === 1 ? 'Return' : 'Returns'}
          </text>
        </svg>

        {/* The legend is always present and every entry is directly labelled
            with its share, so identity never rests on colour alone. */}
        <ul className="pr-donut__legend">
          {folded.map((slice, index) => {
            const clickable = onPick !== undefined && slice.reason_code !== '__other' && slice.reason_code !== 'unspecified'

            const content = (
              <>
                <i style={{ background: sliceColour(index) }} aria-hidden />
                <span className="pr-donut__legend-name" title={slice.label}>
                  {slice.label}
                </span>
                <span className="pr-donut__legend-share">{slice.percentage}%</span>
              </>
            )

            // The row is always ONE element carrying the three-column grid —
            // a button when it filters, a span when it does not. Putting the
            // grid on the <li> as well made the button a single 10px cell and
            // squeezed every reason's name out of sight.
            return (
              <li key={slice.reason_code}>
                {clickable ? (
                  <button
                    type="button"
                    className="pr-donut__legend-row pr-donut__legend-btn"
                    onClick={() => onPick?.(slice.reason_code)}
                    title={`Show only returns for ${slice.label}`}
                  >
                    {content}
                  </button>
                ) : (
                  <span className="pr-donut__legend-row">{content}</span>
                )}
              </li>
            )
          })}
        </ul>
      </div>
    </ChartFrame>
  )
}
