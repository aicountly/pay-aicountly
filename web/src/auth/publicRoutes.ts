/**
 * Routes a stranger is allowed to open.
 *
 * This lives on its own because TWO places have to agree about it and they run
 * at different times. `AuthProvider` mounts above the router and starts a
 * sign-in the moment it finds no token; `App` decides which tree to render
 * after that. A guard in only the second of those is no guard at all — the
 * redirect has already left.
 *
 * Getting this wrong has one specific cost: a customer who clicks a payment
 * link lands on the AICOUNTLY login page in front of an invoice they were
 * asked to pay, which is the fastest way to lose the payment. The token in the
 * URL is their credential and the only one they will ever have.
 */
export function isPublicRoute(pathname: string): boolean {
  return pathname.startsWith('/pay/p/')
}

/** The same question, asked before React Router exists. */
export function isPublicLocation(): boolean {
  return isPublicRoute(window.location.pathname)
}
