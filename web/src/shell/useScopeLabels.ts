/**
 * The names behind the three ids in the top bar.
 *
 * Read from Manage on the request that draws them, never stored. Manage owns
 * companies, branches and financial years; a name copied into this product is
 * a name that stays wrong after somebody corrects it over there.
 *
 * Failure is quiet on purpose. If Manage cannot be reached the ids still work
 * and every scoped screen reports it properly — a red banner in the header for
 * a cosmetic lookup would be the loudest possible way to say the least useful
 * thing.
 *
 * The years and branches come back too, for the top bar's selectors — the same
 * companyinfo answer, so switching year or branch costs no second request.
 */

import { useEffect, useState } from 'react'
import { fetchCompanyInfo } from '../services/manage'
import type { BranchOption, FyOption } from '../services/manage'
import type { CompanyScope } from '../services/api'

export interface ScopeLabels {
  company: string | null
  branch: string | null
  fy: string | null
  /** The company's years and branches, for switching; empty until Manage answers. */
  fyList: FyOption[]
  branches: BranchOption[]
}

const EMPTY: ScopeLabels = { company: null, branch: null, fy: null, fyList: [], branches: [] }

export function useScopeLabels(scope: CompanyScope | null): ScopeLabels {
  const [labels, setLabels] = useState<ScopeLabels>(EMPTY)

  useEffect(() => {
    if (!scope) {
      setLabels(EMPTY)
      return
    }

    let cancelled = false
    fetchCompanyInfo(scope.cmp_id)
      .then((info) => {
        if (cancelled) return
        const branch = scope.bo_id > 0 ? info.branches.find((b) => b.boId === scope.bo_id) : undefined
        setLabels({
          company: info.name || null,
          branch: scope.bo_id > 0 ? (branch?.name ?? `Branch ${scope.bo_id}`) : 'All branches',
          fy: info.fyList.find((f) => f.fyId === scope.fy_id)?.label ?? null,
          fyList: info.fyList,
          branches: info.branches,
        })
      })
      .catch(() => {
        if (!cancelled) setLabels(EMPTY)
      })

    return () => {
      cancelled = true
    }
  }, [scope?.cmp_id, scope?.fy_id, scope?.bo_id])

  return labels
}
