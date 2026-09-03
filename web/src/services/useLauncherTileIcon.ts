import { useCallback, useEffect, useState } from 'react'
import { rememberIconChoice } from './appLauncher'
import type { LauncherTile } from './appLauncher'

/**
 * Resolves the icon a launcher tile shows, without an old→new flash.
 *
 * `app.tileIconUrl` comes from the choice this browser persisted last time, so
 * the first paint is already the right art and needs no network round trip. The
 * manifest check that runs afterwards only replaces it when the icon actually
 * changed, and only once the replacement has decoded.
 */
export function useLauncherTileIcon(app: LauncherTile): { src: string; onError: () => void } {
  const [src, setSrc] = useState(app.tileIconUrl)

  useEffect(() => {
    if (!app.iconChecked) return undefined

    const target = app.remoteIconUrl || app.localIconUrl
    if (!target || target === src) return undefined

    let active = true
    const img = new Image()
    img.decoding = 'async'
    img.onload = () => {
      if (!active) return
      setSrc(target)
      rememberIconChoice(app.id, app.remoteIconUrl ? 'remote' : 'local', app.iconVersion)
    }
    img.onerror = () => {
      if (active) rememberIconChoice(app.id, 'local', 0)
    }
    img.src = target

    return () => {
      active = false
      img.onload = null
      img.onerror = null
    }
  }, [app.id, app.iconChecked, app.iconVersion, app.localIconUrl, app.remoteIconUrl, src])

  const onError = useCallback(() => {
    // The bundled tile is the last resort; when that is missing too (a product
    // whose assets have not shipped yet) the caller falls back to initials.
    setSrc((current) => {
      if (current === '') return ''
      return current === app.localIconUrl ? '' : app.localIconUrl
    })
    rememberIconChoice(app.id, 'local', 0)
  }, [app.id, app.localIconUrl])

  return { src, onError }
}
