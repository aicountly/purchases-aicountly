/**
 * The list's filter state, kept in the URL.
 *
 * Two things stop working when a filter lives only in component state: a
 * drill-down from a dashboard arrives and is ignored, and somebody who has
 * narrowed the list cannot send anyone the link. Both are the same bug. This is
 * `useUrlFilter` widened to a whole toolbar — one history entry per change
 * instead of one per key, so Back undoes "I chose the IT department" rather
 * than undoing it one query parameter at a time.
 *
 * Nothing sensitive goes in here. Ids the user already sees on screen, and the
 * words they typed to find a row.
 */

import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import type { RequisitionQuery } from '../../services/requisitions'
import { BUCKET_STATUSES, type Bucket } from './model'

export interface RequisitionFilters {
  q: string
  bucket: Bucket
  /** A stored status the tabs cannot express, arriving from a dashboard link. */
  status: string
  department: string
  dateFrom: string
  dateTo: string
  requiredByBefore: string
  priority: string
  minValue: string
  maxValue: string
  exception: string
  /** Only what the signed-in user raised. */
  mine: boolean
  page: number
  pageSize: number
}

export const PAGE_SIZES = [10, 25, 50, 100]

const BUCKETS: Bucket[] = ['all', 'draft', 'pending', 'approved', 'rejected']

/**
 * A page size from the URL is a number somebody can type.
 *
 * Anything outside the offered sizes becomes the default rather than being
 * passed to the API, which would otherwise be asked for `limit=100000` by
 * anyone who edited the address bar.
 */
function readPageSize(raw: string | null): number {
  const parsed = Number.parseInt(raw ?? '', 10)
  return PAGE_SIZES.includes(parsed) ? parsed : 10
}

function readDate(raw: string | null): string {
  return raw !== null && /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : ''
}

function readAmount(raw: string | null): string {
  if (raw === null || raw === '') return ''
  const parsed = Number(raw)
  return Number.isFinite(parsed) && parsed >= 0 ? String(parsed) : ''
}

export function useRequisitionFilters(): {
  filters: RequisitionFilters
  /** Apply a change. Anything that narrows the list returns to page 1. */
  update: (patch: Partial<RequisitionFilters>, options?: { replace?: boolean }) => void
  reset: () => void
  /** How many filters beyond the tab and the search box are on. */
  activeCount: number
} {
  const [params, setParams] = useSearchParams()

  const filters = useMemo<RequisitionFilters>(() => {
    const status = params.get('status') ?? ''
    const lower = status.toLowerCase()
    // `?status=pending` is a tab. `?status=APPROVAL_PENDING` is a dashboard
    // drill-down, and this product has been linking that way since before the
    // tabs existed — both have to keep working.
    const bucket = BUCKETS.includes(lower as Bucket) ? (lower as Bucket) : status === '' ? 'all' : 'all'

    return {
      q: params.get('q') ?? '',
      bucket,
      status: BUCKETS.includes(lower as Bucket) ? '' : status,
      department: params.get('department') ?? '',
      dateFrom: readDate(params.get('from')),
      dateTo: readDate(params.get('to')),
      requiredByBefore: readDate(params.get('needed_by')),
      priority: ['low', 'normal', 'high'].includes(params.get('priority') ?? '') ? (params.get('priority') as string) : '',
      minValue: readAmount(params.get('min')),
      maxValue: readAmount(params.get('max')),
      exception: ['emergency', 'single_source', 'non_preferred_vendor'].includes(params.get('exception') ?? '')
        ? (params.get('exception') as string)
        : '',
      mine: params.get('mine') === '1',
      page: Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1),
      pageSize: readPageSize(params.get('size')),
    }
  }, [params])

  const update = useCallback(
    (patch: Partial<RequisitionFilters>, options?: { replace?: boolean }) => {
      const next = { ...filters, ...patch }
      const updated = new URLSearchParams()

      const set = (key: string, value: string) => {
        if (value !== '') updated.set(key, value)
      }

      // The tab and a raw status are the same parameter: choosing a tab clears
      // a drill-down's status, which is what somebody pressing "All" means.
      if (patch.bucket !== undefined) next.status = ''
      set('status', next.status !== '' ? next.status : next.bucket === 'all' ? '' : next.bucket)
      set('q', next.q)
      set('department', next.department)
      set('from', next.dateFrom)
      set('to', next.dateTo)
      set('needed_by', next.requiredByBefore)
      set('priority', next.priority)
      set('min', next.minValue)
      set('max', next.maxValue)
      set('exception', next.exception)
      if (next.mine) updated.set('mine', '1')

      // Anything that changes WHAT is listed goes back to the first page —
      // otherwise a narrower filter lands on page 4 of a 2-page result and the
      // screen looks empty.
      const narrowed = Object.keys(patch).some((key) => key !== 'page' && key !== 'pageSize')
      const page = narrowed ? 1 : next.page
      if (page > 1) updated.set('page', String(page))
      if (next.pageSize !== 10) updated.set('size', String(next.pageSize))

      setParams(updated, { replace: options?.replace ?? false })
    },
    [filters, setParams],
  )

  const reset = useCallback(() => setParams(new URLSearchParams(), { replace: false }), [setParams])

  const activeCount = useMemo(() => {
    let count = 0
    if (filters.department) count++
    if (filters.dateFrom || filters.dateTo) count++
    if (filters.requiredByBefore) count++
    if (filters.priority) count++
    if (filters.minValue || filters.maxValue) count++
    if (filters.exception) count++
    if (filters.mine) count++
    return count
  }, [filters])

  return { filters, update, reset, activeCount }
}

/** The filter state as the API wants it. One place, so the two cannot drift. */
export function toQuery(filters: RequisitionFilters, requesterUuid?: string): RequisitionQuery {
  return {
    status: filters.status !== '' ? filters.status : filters.bucket === 'all' ? undefined : filters.bucket,
    q: filters.q,
    department: filters.department,
    priority: filters.priority,
    dateFrom: filters.dateFrom,
    dateTo: filters.dateTo,
    requiredByBefore: filters.requiredByBefore,
    minValue: filters.minValue,
    maxValue: filters.maxValue,
    exception: filters.exception,
    requesterUuid: filters.mine ? requesterUuid : undefined,
    page: filters.page,
    pageSize: filters.pageSize,
  }
}

/** Which statuses a bucket covers, for anything that has to decide locally. */
export function statusesFor(bucket: Bucket): string[] {
  return bucket === 'all' ? [] : BUCKET_STATUSES[bucket]
}
