import { APP_NAME } from '../config'
import { useAuth } from '../auth/AuthProvider'

/**
 * Shown only when the automatic portal jump did not happen: after a deliberate
 * sign-out, or when sign-in failed and bouncing back to the portal would just
 * repeat it. Signing in is the portal's job, so this screen only points at it.
 */
export default function SignIn() {
  const { message, signIn } = useAuth()

  return (
    <main className="screen">
      <div className="panel">
        <p className="eyebrow">AICOUNTLY {APP_NAME}</p>
        <p className="message">{message ?? 'You have been signed out.'}</p>
        <button type="button" className="button" onClick={signIn}>
          Sign in
        </button>
      </div>
    </main>
  )
}
