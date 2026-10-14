import { useEffect } from 'react'
import { dismissRestrictionToast, useCompanyRestriction, useRestrictionToast } from './companyLifecycle'
import './lifecycle.css'

/**
 * The read-only banner for a company whose subscription to this application lapsed, and the toast a
 * refused write (HTTP 423 COMPANY_RESTRICTED) raises. Viewing and exporting keep working; the banner
 * says so. Rendered once, above the page content, by the app shell.
 */
export function CompanyReadOnlyBanner({ cmpId }: { cmpId: number | null | undefined }) {
  const restriction = useCompanyRestriction(cmpId)
  const toast = useRestrictionToast()

  useEffect(() => {
    if (!toast) return
    const id = window.setTimeout(() => dismissRestrictionToast(toast.id), 8000)
    return () => window.clearTimeout(id)
  }, [toast])

  return (
    <>
      {restriction && (
        <div className="aic-lifecycle-banner" role="status" data-lifecycle={restriction.lifecycle}>
          <strong>Read-only company.</strong> <span>{restriction.message}</span>
        </div>
      )}
      {toast && (
        <div className="aic-lifecycle-toast" role="alert">
          <span>{toast.message}</span>
          <button type="button" aria-label="Dismiss" onClick={() => dismissRestrictionToast(toast.id)}>
            ×
          </button>
        </div>
      )}
    </>
  )
}
