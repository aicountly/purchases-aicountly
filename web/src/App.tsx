import { lazy, Suspense, useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthProvider'
import { PurchasesProvider, usePurchases } from './context/PurchasesContext'
import { AppShell } from './shell/AppShell'
import SignIn from './pages/SignIn'
import PurchaseDashboards from './dashboards'
import { RequisitionDetail, RequisitionEditor, RequisitionsList } from './pages/Requisitions'
import { RfqDetail, RfqEditor, RfqList } from './pages/Sourcing'
import { PurchaseOrderDetail, PurchaseOrderEditor, PurchaseOrderList } from './pages/PurchaseOrders'
import { BillDetail, BillEditor, BillsList } from './pages/Bills'
import { ReturnDetail, ReturnsList } from './pages/Returns'
import Claims from './pages/Claims'
import Suppliers from './pages/Suppliers'
import Approvals from './pages/Approvals'
import Settings from './pages/Settings'
import { Notice } from './ui'
import { initAnalytics, trackPageView } from './utils/analytics'
import './App.css'

initAnalytics()

/**
 * Access is loaded on demand.
 *
 * It is an administration screen most people open once, and it carries the
 * whole permission catalogue, its own stylesheet and a dozen icons nobody else
 * needs — 50kB that was being paid for on every first load of the dashboard.
 * It is the only route split this way because it is the only one that earns it.
 */
const Access = lazy(() => import('./pages/access'))

function PageViews() {
  const location = useLocation()
  useEffect(() => {
    trackPageView(location.pathname, document.title)
  }, [location.pathname])

  return null
}

/**
 * Nothing renders until a company is chosen.
 *
 * Every endpoint in this API is company-scoped, so a screen without a scope
 * would be a screen full of 400s. Asking once, up front, is kinder than that.
 */
function RequireScope({ children }: { children: React.ReactNode }) {
  const { scope, session, loading, error } = usePurchases()

  if (!scope) {
    return (
      <Notice tone="info" title="Choose a company">
        Pick the company and financial year to work in, using the selector at the top of the page.
      </Notice>
    )
  }
  if (loading && !session) return <p style={{ color: 'var(--muted)' }}>Opening…</p>
  if (error) return <Notice tone="danger" title="Could not open that company">{error}</Notice>

  return <>{children}</>
}

export default function App() {
  const { status } = useAuth()

  if (status === 'signed-out') return <SignIn />

  if (status !== 'authenticated') {
    return (
      <main className="screen">
        <div className="panel">
          <p className="message">Signing you in…</p>
        </div>
      </main>
    )
  }

  return (
    <PurchasesProvider>
      <BrowserRouter>
        <PageViews />
        <Routes>
          <Route element={<AppShell />}>
            {/* The dashboard is five screens behind one route. The view is in
                the path so a link to one is a link to that one, and Back moves
                between them rather than out of the product. */}
            <Route index element={<Navigate to="/dashboard/overview" replace />} />
            <Route path="dashboard" element={<Navigate to="/dashboard/overview" replace />} />
            <Route path="dashboard/:view" element={<RequireScope><PurchaseDashboards /></RequireScope>} />

            <Route path="requisitions">
              <Route index element={<RequireScope><RequisitionsList /></RequireScope>} />
              <Route path="new" element={<RequireScope><RequisitionEditor /></RequireScope>} />
              <Route path=":id" element={<RequireScope><RequisitionDetail /></RequireScope>} />
            </Route>

            <Route path="rfqs">
              <Route index element={<RequireScope><RfqList /></RequireScope>} />
              <Route path="new" element={<RequireScope><RfqEditor /></RequireScope>} />
              <Route path=":id" element={<RequireScope><RfqDetail /></RequireScope>} />
            </Route>

            <Route path="purchase-orders">
              <Route index element={<RequireScope><PurchaseOrderList /></RequireScope>} />
              <Route path="new" element={<RequireScope><PurchaseOrderEditor /></RequireScope>} />
              <Route path=":id" element={<RequireScope><PurchaseOrderDetail /></RequireScope>} />
            </Route>

            <Route path="bills">
              <Route index element={<RequireScope><BillsList /></RequireScope>} />
              <Route path="new" element={<RequireScope><BillEditor /></RequireScope>} />
              <Route path=":id" element={<RequireScope><BillDetail /></RequireScope>} />
            </Route>

            <Route path="returns">
              <Route index element={<RequireScope><ReturnsList /></RequireScope>} />
              <Route path=":id" element={<RequireScope><ReturnDetail /></RequireScope>} />
            </Route>

            <Route path="claims" element={<RequireScope><Claims /></RequireScope>} />
            <Route path="suppliers" element={<RequireScope><Suppliers /></RequireScope>} />
            <Route path="approvals" element={<RequireScope><Approvals /></RequireScope>} />
            <Route path="settings" element={<RequireScope><Settings /></RequireScope>} />
            <Route
              path="access"
              element={
                <RequireScope>
                  <Suspense fallback={<p style={{ color: 'var(--muted)' }}>Opening…</p>}>
                    <Access />
                  </Suspense>
                </RequireScope>
              }
            />

            {/* The portal callback lands here once AuthProvider has consumed the token. */}
            <Route path="auth/callback" element={<Navigate to="/" replace />} />
            <Route path="*" element={<Notice tone="warning">That page does not exist.</Notice>} />
          </Route>
        </Routes>
      </BrowserRouter>
    </PurchasesProvider>
  )
}
