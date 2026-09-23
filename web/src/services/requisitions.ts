/**
 * The Requisitions screen's own service layer.
 *
 * One place that knows the endpoint names and the query-string spelling, so the
 * page speaks in the words a buyer uses — "department", "from", "to" — and the
 * mapping to `v1/requisitions?...` lives here. When the API renames something,
 * this file changes and the screen does not.
 *
 * Company, branch and financial year are NOT passed here: `services/api` puts
 * them on every scoped call from the context the shell already holds. A screen
 * that passed its own ids would be a screen that can disagree with the header.
 */

import { api, type ListResponse, type ItemResponse, type QueryParams } from './api'
import type { RequisitionRow, RequisitionSummary } from './types'

/** Everything the list screen can narrow by. Every field is optional. */
export interface RequisitionQuery {
  /** A stored status (APPROVAL_PENDING) or a tab name (pending). */
  status?: string
  q?: string
  department?: string
  priority?: string
  dateFrom?: string
  dateTo?: string
  requiredByBefore?: string
  minValue?: string
  maxValue?: string
  /** emergency | single_source | non_preferred_vendor */
  exception?: string
  /** Only what this user raised. */
  requesterUuid?: string
  page?: number
  pageSize?: number
  sort?: string
  order?: 'asc' | 'desc'
}

function toParams(query: RequisitionQuery): QueryParams {
  const params: QueryParams = {
    status: query.status || undefined,
    q: query.q || undefined,
    department: query.department || undefined,
    priority: query.priority || undefined,
    date_from: query.dateFrom || undefined,
    date_to: query.dateTo || undefined,
    required_by_before: query.requiredByBefore || undefined,
    min_value: query.minValue || undefined,
    max_value: query.maxValue || undefined,
    exception: query.exception || undefined,
    requester_uuid: query.requesterUuid || undefined,
  }

  if (query.sort) params.sort = query.sort
  if (query.order) params.order = query.order

  return params
}

export const requisitions = {
  /** One page of the list. Paged on the server — never "fetch all, filter here". */
  list(query: RequisitionQuery, signal?: AbortSignal): Promise<ListResponse<RequisitionRow>> {
    const pageSize = query.pageSize ?? 10
    const page = Math.max(1, query.page ?? 1)

    return api.list<RequisitionRow>(
      'v1/requisitions',
      { ...toParams(query), limit: pageSize, offset: (page - 1) * pageSize },
      signal,
    )
  },

  /**
   * The figures above the table.
   *
   * Counted over everything the filters match rather than over the page on
   * screen, which is the whole reason it is a second call: "12 requisitions"
   * must not become "10 requisitions" because somebody chose 10 per page.
   *
   * The status filter is deliberately left off — the tab counts come from here,
   * so the summary has to see every status at once.
   */
  summary(query: RequisitionQuery, signal?: AbortSignal): Promise<ItemResponse<RequisitionSummary>> {
    const { status: _ignored, ...rest } = query

    return api.one<RequisitionSummary>('v1/requisitions/summary', toParams(rest), signal)
  },

  /** The filtered list as a CSV file, fetched with the session's bearer token. */
  export(query: RequisitionQuery): Promise<void> {
    const stamp = new Date().toISOString().slice(0, 10)

    return api.download('v1/requisitions/export', `requisitions-${stamp}.csv`, toParams(query))
  },
}
