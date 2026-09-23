/**
 * The workspace state, in the URL.
 *
 * Every filter, the view, the sort and the page live in the query string, for
 * the two reasons this product already keeps list filters there: a drill-down
 * from a dashboard or an insight arrives as a link and is honoured, and a buyer
 * who has narrowed the register to six returns can send somebody that register.
 *
 * ONE PLACE READS AND WRITES IT. The register, the cards, the charts and the
 * export all take the same object, so they cannot end up describing different
 * periods.
 */

import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

export type ReturnsView = 'list' | 'calendar' | 'kanban'

export const VIEWS: ReturnsView[] = ['list', 'calendar', 'kanban']

export type SortKey = 'return_date' | 'return_no' | 'status' | 'return_value' | 'supplier' | 'created_at'

export interface ReturnsFilters {
  q: string
  status: string
  supplier_id: string
  reason: string
  from: string
  to: string
  supplier_credit: string
  inventory: string
  books: string
  min_value: string
  max_value: string
  po_id: string
  created_by: string
}

export const EMPTY_FILTERS: ReturnsFilters = {
  q: '',
  status: '',
  supplier_id: '',
  reason: '',
  from: '',
  to: '',
  supplier_credit: '',
  inventory: '',
  books: '',
  min_value: '',
  max_value: '',
  po_id: '',
  created_by: '',
}

/**
 * The filters that live behind "More filters" rather than on the bar itself.
 *
 * `created_by` is deliberately NOT here. It has no control in the panel — it
 * arrives from a link — and counting it would put a number on the button with
 * nothing behind it to explain the number. It still shows as a chip, and the
 * chip still removes it.
 */
export const ADVANCED_KEYS: Array<keyof ReturnsFilters> = [
  'reason',
  'supplier_credit',
  'inventory',
  'books',
  'min_value',
  'max_value',
  'po_id',
]

export const PAGE_SIZE = 25

/**
 * Six months back, to today.
 *
 * The register opens on a period rather than on everything, because a period is
 * what makes the comparison on the cards mean anything — and six months is what
 * the trend chart draws, so the two agree from the first paint. "All time"
 * remains one click away in the date menu, and honestly reports no comparison.
 */
export function defaultRange(today = new Date()): { from: string; to: string } {
  const end = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const start = new Date(end.getFullYear(), end.getMonth() - 5, 1)

  return { from: iso(start), to: iso(end) }
}

export function iso(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${month}-${day}`
}

export interface DateRangePreset {
  id: string
  label: string
  range: () => { from: string; to: string }
}

export const DATE_PRESETS: DateRangePreset[] = [
  {
    id: 'this-month',
    label: 'This month',
    range: () => {
      const now = new Date()
      return { from: iso(new Date(now.getFullYear(), now.getMonth(), 1)), to: iso(now) }
    },
  },
  {
    id: 'last-30',
    label: 'Last 30 days',
    range: () => {
      const now = new Date()
      const start = new Date(now)
      start.setDate(start.getDate() - 29)
      return { from: iso(start), to: iso(now) }
    },
  },
  {
    id: 'last-3-months',
    label: 'Last 3 months',
    range: () => {
      const now = new Date()
      return { from: iso(new Date(now.getFullYear(), now.getMonth() - 2, 1)), to: iso(now) }
    },
  },
  { id: 'last-6-months', label: 'Last 6 months', range: () => defaultRange() },
  {
    id: 'last-12-months',
    label: 'Last 12 months',
    range: () => {
      const now = new Date()
      return { from: iso(new Date(now.getFullYear(), now.getMonth() - 11, 1)), to: iso(now) }
    },
  },
  { id: 'all', label: 'All time', range: () => ({ from: '', to: '' }) },
]

export interface WorkspaceState {
  filters: ReturnsFilters
  view: ReturnsView
  page: number
  sort: SortKey
  order: 'asc' | 'desc'
  /** The return whose detail is open, from the URL so the panel is linkable. */
  openId: number | null
}

/**
 * Read the whole workspace out of the query string, and write it back.
 *
 * `replace` is used for changes a person makes many of in a row — typing in the
 * search box — so Back does not step through forty keystrokes. A filter or a
 * view change is its own history entry, because Back undoing one of those is
 * exactly what somebody expects.
 */
export function useWorkspaceState(): {
  state: WorkspaceState
  isFiltered: boolean
  advancedCount: number
  setFilters: (next: Partial<ReturnsFilters>, options?: { replace?: boolean }) => void
  setView: (view: ReturnsView) => void
  setPage: (page: number) => void
  setSort: (sort: SortKey) => void
  open: (id: number | null) => void
  reset: () => void
} {
  const [params, setParams] = useSearchParams()

  const state = useMemo<WorkspaceState>(() => {
    const filters = { ...EMPTY_FILTERS }
    for (const key of Object.keys(EMPTY_FILTERS) as Array<keyof ReturnsFilters>) {
      filters[key] = params.get(key) ?? ''
    }

    // No dates in the URL at all means this is a first visit rather than a
    // deliberate "all time", which is spelled `range=all`.
    if (filters.from === '' && filters.to === '' && params.get('range') !== 'all') {
      const fallback = defaultRange()
      filters.from = fallback.from
      filters.to = fallback.to
    }

    const view = params.get('view')
    const sort = params.get('sort')
    const page = Number.parseInt(params.get('page') ?? '1', 10)
    const openId = Number.parseInt(params.get('open') ?? '', 10)

    return {
      filters,
      view: VIEWS.includes(view as ReturnsView) ? (view as ReturnsView) : 'list',
      page: Number.isFinite(page) && page > 0 ? page : 1,
      sort: (['return_date', 'return_no', 'status', 'return_value', 'supplier', 'created_at'] as SortKey[]).includes(sort as SortKey)
        ? (sort as SortKey)
        : 'return_date',
      order: params.get('order') === 'asc' ? 'asc' : 'desc',
      openId: Number.isFinite(openId) && openId > 0 ? openId : null,
    }
  }, [params])

  const write = useCallback(
    (mutate: (next: URLSearchParams) => void, replace = false) => {
      const next = new URLSearchParams(params)
      mutate(next)
      setParams(next, { replace })
    },
    [params, setParams],
  )

  const setFilters = useCallback(
    (changes: Partial<ReturnsFilters>, options?: { replace?: boolean }) => {
      write((next) => {
        for (const [key, value] of Object.entries(changes)) {
          if (value === undefined) continue
          if (value === '') next.delete(key)
          else next.set(key, value)
        }

        // "All time" has to be written down. Without it, empty dates read as a
        // first visit and the default six months would come straight back.
        if ('from' in changes || 'to' in changes) {
          const cleared = (changes.from ?? '') === '' && (changes.to ?? '') === ''
          if (cleared) next.set('range', 'all')
          else next.delete('range')
        }

        // Any change to what is being filtered starts again at the first page.
        // Page 4 of a narrower result is a page that does not exist.
        next.delete('page')
      }, options?.replace)
    },
    [write],
  )

  const advancedCount = useMemo(
    () => ADVANCED_KEYS.filter((key) => state.filters[key] !== '').length,
    [state.filters],
  )

  const isFiltered = useMemo(() => {
    const fallback = defaultRange()
    // "All time" is the widest window there is, so it cannot be why a register
    // is empty. Counting it as a filter would offer "clear your filters" to
    // somebody who has just asked to see everything.
    const allTime = state.filters.from === '' && state.filters.to === ''
    const datesChanged = !allTime && (state.filters.from !== fallback.from || state.filters.to !== fallback.to)

    return (
      advancedCount > 0 ||
      state.filters.q !== '' ||
      state.filters.status !== '' ||
      state.filters.supplier_id !== '' ||
      state.filters.created_by !== '' ||
      datesChanged
    )
  }, [state.filters, advancedCount])

  return {
    state,
    isFiltered,
    advancedCount,
    setFilters,
    setView: useCallback((view: ReturnsView) => write((next) => {
      if (view === 'list') next.delete('view')
      else next.set('view', view)
      next.delete('page')
    }), [write]),
    setPage: useCallback((page: number) => write((next) => {
      if (page <= 1) next.delete('page')
      else next.set('page', String(page))
    }), [write]),
    setSort: useCallback((sort: SortKey) => write((next) => {
      // The same column again turns the sort around; a different one starts
      // newest-or-largest first, which is what a person means by "sort by this".
      const current = next.get('sort') ?? 'return_date'
      const order = next.get('order') === 'asc' ? 'asc' : 'desc'
      if (current === sort) next.set('order', order === 'asc' ? 'desc' : 'asc')
      else next.set('order', 'desc')
      if (sort === 'return_date') next.delete('sort')
      else next.set('sort', sort)
      next.delete('page')
    }), [write]),
    open: useCallback((id: number | null) => write((next) => {
      if (id === null) next.delete('open')
      else next.set('open', String(id))
    }), [write]),
    reset: useCallback(() => write((next) => {
      for (const key of Object.keys(EMPTY_FILTERS)) next.delete(key)
      next.delete('range')
      next.delete('page')
    }), [write]),
  }
}

/** The filters as the API reads them. Empty values are left out entirely. */
export function toQuery(filters: ReturnsFilters): Record<string, string | number | undefined> {
  return {
    q: filters.q || undefined,
    status: filters.status || undefined,
    supplier_account_id: filters.supplier_id || undefined,
    reason: filters.reason || undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
    supplier_credit: filters.supplier_credit || undefined,
    inventory: filters.inventory || undefined,
    books: filters.books || undefined,
    min_value: filters.min_value || undefined,
    max_value: filters.max_value || undefined,
    po_id: filters.po_id || undefined,
    created_by: filters.created_by || undefined,
  }
}

/**
 * An insight's filters, translated into workspace filters.
 *
 * The server names them the way the API reads them; the URL spells two of them
 * differently. Mapping here is what stops half the links using one spelling.
 */
export function fromInsight(filters: Record<string, string>): Partial<ReturnsFilters> {
  const next: Partial<ReturnsFilters> = {}
  for (const [key, value] of Object.entries(filters)) {
    if (key === 'supplier_id' || key === 'supplier_account_id') next.supplier_id = value
    else if (key in EMPTY_FILTERS) next[key as keyof ReturnsFilters] = value
  }

  return next
}
