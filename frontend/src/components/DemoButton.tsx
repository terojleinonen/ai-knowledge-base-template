import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { useAuth } from '../auth/useAuth'
import { ApiError } from '../lib/api'
import { Alert, Button, Spinner } from './ui'

/** Starts a guest session. Explains the wait when a sleeping free-tier server is waking up. */
export function DemoButton({ className = '' }: { className?: string }) {
  const { startDemo } = useAuth()
  const navigate = useNavigate()
  const [pending, setPending] = useState(false)
  const [slow, setSlow] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!pending) return
    const timer = setTimeout(() => setSlow(true), 3000)
    return () => clearTimeout(timer)
  }, [pending])

  async function start() {
    setPending(true)
    setSlow(false)
    setError(null)
    try {
      await startDemo()
      navigate('/app/chat')
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Could not start the demo.')
    } finally {
      setPending(false)
    }
  }

  return (
    <div className={className}>
      <Button onClick={start} disabled={pending} className="px-5 py-3">
        {pending && <Spinner />}
        Try the live demo
      </Button>
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
