import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'

/**
 * The whole application, for now: a welcome message and a way out.
 *
 * Deliberately empty of navigation, cards and modules — features arrive when
 * the product does.
 */
export default function Dashboard() {
  const { signOut } = useAuth()

  return (
    <main className="screen">
      <AppLauncher />
      <div className="panel">
        <h1 className="welcome">Welcome to AICOUNTLY Pay, we are going live soon.</h1>
        <button type="button" className="button" onClick={signOut}>
          Log out
        </button>
      </div>
    </main>
  )
}
