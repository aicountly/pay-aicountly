import { useEffect } from 'react'
import { useAuth } from './auth/AuthProvider'
import Dashboard from './pages/Dashboard'
import SignIn from './pages/SignIn'
import { initAnalytics, trackPageView } from './utils/analytics'
import './App.css'

initAnalytics()

/**
 * Login → Dashboard. There is no router because there are no routes: the portal
 * callback lands on /auth/callback, which the SPA history fallback serves with
 * this same document, and AuthProvider consumes the token at boot.
 */
export default function App() {
  const { status } = useAuth()

  useEffect(() => {
    if (status === 'authenticated') trackPageView('/dashboard', 'Dashboard')
    else if (status === 'signed-out') trackPageView('/sign-in', 'Sign in')
  }, [status])

  if (status === 'authenticated') return <Dashboard />
  if (status === 'signed-out') return <SignIn />

  return (
    <main className="screen">
      <div className="panel">
        <p className="message">Signing you in…</p>
      </div>
    </main>
  )
}
