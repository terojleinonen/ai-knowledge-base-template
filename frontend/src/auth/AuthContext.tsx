import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, json, setUnauthorizedHandler, tokenStore } from '../lib/api'
import type { AuthResponse, User } from '../lib/types'
import { AuthContext, type AuthState } from './useAuth'

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(() => tokenStore.get() !== null)

  const reset = useCallback(() => {
    tokenStore.set(null)
    setUser(null)
    queryClient.clear()
  }, [queryClient])

  useEffect(() => {
    setUnauthorizedHandler(reset)
    return () => setUnauthorizedHandler(null)
  }, [reset])

  useEffect(() => {
    if (!tokenStore.get()) return

    api<{ data: User }>('/auth/me')
      .then((res) => setUser(res.data))
      .catch(() => reset())
      .finally(() => setLoading(false))
  }, [reset])

  const authenticate = useCallback((res: AuthResponse) => {
    tokenStore.set(res.token)
    setUser(res.user)
  }, [])

  const value = useMemo<AuthState>(
    () => ({
      user,
      loading,
      login: async (email, password) => authenticate(await api<AuthResponse>('/auth/login', json({ email, password }))),
      register: async (name, email, password, password_confirmation) =>
        authenticate(await api<AuthResponse>('/auth/register', json({ name, email, password, password_confirmation }))),
      startDemo: async () => authenticate(await api<AuthResponse>('/auth/guest', { method: 'POST' })),
      logout: async () => {
        await api('/auth/logout', { method: 'POST' }).catch(() => undefined)
        reset()
      },
    }),
    [user, loading, authenticate, reset],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
