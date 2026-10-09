import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { useAuth } from '../auth/useAuth'
import { useAppConfig } from '../hooks/config'
import { ApiError } from '../lib/api'
import { loadTurnstile, type TurnstileApi } from '../lib/turnstile'
import { Alert, Button, Spinner } from './ui'

/**
 * Starts a guest session. With Turnstile configured, a bot check runs first; its widget
 * only becomes visible when Cloudflare wants an interaction. Also explains the wait when a
 * sleeping free-tier server is waking up.
 */
export function DemoButton({ className = '' }: { className?: string }) {
  const { startDemo } = useAuth()
  const navigate = useNavigate()
  const siteKey = useAppConfig().data?.turnstile_site_key ?? null
  const [pending, setPending] = useState(false)
  const [slow, setSlow] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [token, setToken] = useState<string | null>(null)
  const container = useRef<HTMLDivElement>(null)
  const widget = useRef<{ api: TurnstileApi; id: string } | null>(null)

  useEffect(() => {
    if (!siteKey || !container.current) return
    let cancelled = false

    loadTurnstile()
      .then((api) => {
        if (cancelled || !container.current) return
        const id = api.render(container.current, {
          sitekey: siteKey,
          action: 'guest',
          appearance: 'interaction-only',
          callback: (t: string) => {
            setToken(t)
            setError(null) // Turnstile retries on its own; clear an earlier error once it succeeds
          },
          'expired-callback': () => setToken(null),
          'error-callback': () => {
            setToken(null)
            setError('The human check failed to load. Please reload the page and try again.')
          },
        })
        widget.current = { api, id }
      })
      .catch(() => setError('The human check could not be loaded. Check your connection or content blocker, then reload.'))

    return () => {
      cancelled = true
      if (widget.current) widget.current.api.remove(widget.current.id)
      widget.current = null
    }
  }, [siteKey])

  useEffect(() => {
    if (!pending) return
    const timer = setTimeout(() => setSlow(true), 3000)
    return () => clearTimeout(timer)
  }, [pending])

  const verifying = siteKey !== null && token === null

  async function start() {
    setPending(true)
    setSlow(false)
    setError(null)
    try {
      await startDemo(token)
      navigate('/app/chat')
    } catch (e) {
      setError(e instanceof ApiError ? (e.field('turnstile_token') ?? e.message) : 'Could not start the demo.')
      // Tokens are single-use: get a fresh one for the next attempt.
      setToken(null)
      if (widget.current) widget.current.api.reset(widget.current.id)
    } finally {
      setPending(false)
    }
  }

  return (
    <div className={className}>
      <Button onClick={start} disabled={pending || verifying} className="px-5 py-3">
        {(pending || verifying) && <Spinner />}
        {verifying && !pending ? 'Checking your browser…' : 'Try the live demo'}
      </Button>
      <div ref={container} className="mt-3 flex justify-center empty:hidden" />
      {pending && slow && (
        <p className="mt-3 text-sm text-slate-500" role="status">
          Waking up the server. It runs on a free tier that sleeps when idle, so this can take up to a minute.
        </p>
      )}
      {error && (
        <div className="mt-3">
          <Alert>{error}</Alert>
        </div>
      )}
    </div>
  )
}
