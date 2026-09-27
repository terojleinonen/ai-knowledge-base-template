import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { useAuth } from '../auth/useAuth'
import { DemoButton } from '../components/DemoButton'
import { Alert, Button, Field, Logo, Spinner } from '../components/ui'
import { useAppConfig } from '../hooks/config'
import { ApiError } from '../lib/api'

export function AuthPage({ mode }: { mode: 'login' | 'register' }) {
  const { login, register } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState<ApiError | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const isRegister = mode === 'register'
  const config = useAppConfig()
  const registration = config.data?.registration ?? true

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const value = (key: string) => String(form.get(key) ?? '')

    setSubmitting(true)
    setError(null)

    try {
      if (isRegister) {
        await register(value('name'), value('email'), value('password'), value('password_confirmation'))
      } else {
        await login(value('email'), value('password'))
      }
      navigate((location.state as { from?: string } | null)?.from ?? '/app', { replace: true })
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Something went wrong.'))
    } finally {
      setSubmitting(false)
    }
  }

  const hasFieldErrors = error && Object.keys(error.errors).length > 0

  return (
    <div className="flex min-h-full flex-col items-center justify-center px-4 py-12">
      <Link to="/">
        <Logo />
      </Link>
      <div className="mt-8 w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <h1 className="text-xl font-semibold text-slate-900">{isRegister ? 'Create your account' : 'Sign in'}</h1>

        <form className="mt-6 space-y-4" onSubmit={onSubmit} noValidate>
          {error && !hasFieldErrors && <Alert>{error.message}</Alert>}

          {isRegister && <Field label="Name" name="name" autoComplete="name" required error={error?.field('name')} />}
          <Field label="Email" name="email" type="email" autoComplete="email" required error={error?.field('email')} />
          <Field
            label="Password"
            name="password"
            type="password"
            autoComplete={isRegister ? 'new-password' : 'current-password'}
            required
            error={error?.field('password')}
          />
          {isRegister && (
            <Field label="Confirm password" name="password_confirmation" type="password" autoComplete="new-password" required />
          )}

          <Button type="submit" className="w-full" disabled={submitting}>
            {submitting && <Spinner />}
            {isRegister ? 'Create account' : 'Sign in'}
          </Button>
        </form>
      </div>

      {(isRegister || registration) && (
        <p className="mt-6 text-sm text-slate-600">
          {isRegister ? 'Already have an account? ' : 'New here? '}
          <Link to={isRegister ? '/login' : '/register'} className="font-semibold text-indigo-600 hover:text-indigo-500">
            {isRegister ? 'Sign in' : 'Create an account'}
          </Link>
        </p>
      )}

      {config.data?.demo && (
        <div className="mt-6 flex flex-col items-center">
          <p className="mb-3 text-sm text-slate-600">Just looking around?</p>
          <DemoButton className="text-center" />
        </div>
      )}
    </div>
  )
}
