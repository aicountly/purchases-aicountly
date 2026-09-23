/**
 * The financial year behind `scope.fy_id`, with its real start and end dates.
 *
 * Manage owns financial years. A screen that needs to say "01 Apr 2026 –
 * 31 Mar 2027" asks Manage for the dates on the request that draws them rather
 * than deriving them from the month — the Indian April-to-March year is the
 * common case, not the rule, and a year whose dates were assumed is a year that
 * stays wrong for every company that does it differently.
 *
 * Failure is quiet, like `useScopeLabels`: with no answer the screen says
 * "Financial year" and every date filter still works by hand.
 */

import { useEffect, useState } from 'react'
import { fetchCompanyInfo, type FyOption } from '../services/manage'
import type { CompanyScope } from '../services/api'

export function useFinancialYear(scope: CompanyScope | null): FyOption | null {
  const [fy, setFy] = useState<FyOption | null>(null)

  useEffect(() => {
    if (!scope) {
      setFy(null)
      return
    }

    let cancelled = false
    fetchCompanyInfo(scope.cmp_id)
      .then((info) => {
        if (cancelled) return
        setFy(info.fyList.find((option) => option.fyId === scope.fy_id) ?? null)
      })
      .catch(() => {
        if (!cancelled) setFy(null)
      })

    return () => {
      cancelled = true
    }
  }, [scope?.cmp_id, scope?.fy_id])

  return fy
}
