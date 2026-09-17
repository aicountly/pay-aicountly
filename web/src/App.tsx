import { useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthProvider'
import { PayProvider } from './context/PayContext'
import { PayShell, ShellStyles } from './shell/PayShell'
import Overview from './dashboards/Overview'
import Collections from './dashboards/Collections'
import Gateways from './dashboards/Gateways'
import SettlementsDashboard from './dashboards/Settlements'
import PayPulse from './dashboards/PayPulse'
import { PaymentDetail, PaymentsList } from './pages/Payments'
import { RequestDetail, RequestsList } from './pages/Requests'
import { NewRequest, RecordExternal } from './pages/NewRequest'
import { Customers, Links, Mandates, Reconciliation, Refunds, SettlementsList } from './pages/Modules'
import { Gateways as GatewaysPage } from './pages/Gateways'
import { Developers } from './pages/Developers'
import { Settings } from './pages/Settings'
import { Checkout } from './pages/Checkout'
import SignIn from './pages/SignIn'
import { initAnalytics, trackPageView } from './utils/analytics'
import { isPublicRoute } from './auth/publicRoutes'
import './App.css'

initAnalytics()

export default function App() {
  return (
    <BrowserRouter>
      <ShellStyles />
      <Router />
    </BrowserRouter>
  )
}

function Router() {
  const location = useLocation()
  const publicRoute = isPublicRoute(location.pathname)

  if (publicRoute) {
    return (
      <Routes>
        <Route path="/pay/p/:token" element={<Checkout />} />
      </Routes>
    )
  }

  return <AuthenticatedApp />
}

function AuthenticatedApp() {
  const { status } = useAuth()
  const location = useLocation()

  useEffect(() => {
    if (status === 'authenticated') trackPageView(location.pathname, document.title)
    else if (status === 'signed-out') trackPageView('/sign-in', 'Sign in')
  }, [status, location.pathname])

  if (status === 'signed-out') return <SignIn />

  if (status === 'loading') {
    return (
      <main className="screen">
        <div className="panel">
          <p className="message">Signing you in…</p>
        </div>
      </main>
    )
  }

  return (
    <PayProvider>
      <Routes>
        <Route element={<PayShell />}>
          {/* The five dashboards. Each is its own route with its own endpoint
              and its own permission — not five tabs on one giant page. */}
          <Route index element={<Overview />} />
          <Route path="dashboards/collections" element={<Collections />} />
          <Route path="dashboards/gateways" element={<Gateways />} />
          <Route path="dashboards/settlements" element={<SettlementsDashboard />} />
          <Route path="dashboards/pay-pulse" element={<PayPulse />} />

          <Route path="payments" element={<PaymentsList />} />
          <Route path="payments/record-external" element={<RecordExternal />} />
          <Route path="payments/:id" element={<PaymentDetail />} />

          <Route path="requests" element={<RequestsList />} />
          <Route path="requests/new" element={<NewRequest />} />
          <Route path="requests/:id" element={<RequestDetail />} />

          <Route path="links" element={<Links />} />
          <Route path="customers" element={<Customers />} />
          <Route path="customers/:id" element={<Customers />} />
          <Route path="mandates" element={<Mandates />} />
          <Route path="refunds" element={<Refunds />} />
          <Route path="settlements" element={<SettlementsList />} />
          <Route path="reconciliation" element={<Reconciliation />} />
          <Route path="gateways" element={<GatewaysPage />} />
          <Route path="developers" element={<Developers />} />
          <Route path="settings" element={<Settings />} />

          {/* The portal's callback lands here after AuthProvider has consumed
              the token, so it must go somewhere rather than 404. */}
          <Route path="auth/callback" element={<Navigate to="/" replace />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </PayProvider>
  )
}
