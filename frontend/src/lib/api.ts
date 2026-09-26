const BASE_URL = (import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api').replace(/\/$/, '')
const TOKEN_KEY = 'kb.token'

export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

export const tokenStore = {
  get: (): string | null => {
    try {
      return localStorage.getItem(TOKEN_KEY)
    } catch {
      return null
    }
  },
  set: (token: string | null) => {
    try {
      if (token) localStorage.setItem(TOKEN_KEY, token)
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* storage unavailable: session-only auth */
    }
  },
}

let onUnauthorized: (() => void) | null = null

export function setUnauthorizedHandler(handler: (() => void) | null) {
  onUnauthorized = handler
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')

  if (init.body && !(init.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }

  const token = tokenStore.get()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  let response: Response
  try {
    response = await fetch(`${BASE_URL}${path}`, { ...init, headers })
  } catch {
    throw new ApiError(0, 'Could not reach the server. Is the API running?')
  }

  if (response.status === 204) return undefined as T

  const body = await response.json().catch(() => ({}))

  if (!response.ok) {
    if (response.status === 401 && token) {
      tokenStore.set(null)
      onUnauthorized?.()
    }

    const message =
      response.status === 429
        ? 'Too many requests. Please wait a moment and try again.'
        : (body.message ?? `Request failed (${response.status})`)

    throw new ApiError(response.status, message, body.errors ?? {})
  }

  return body as T
}

export const json = (data: unknown): RequestInit => ({ method: 'POST', body: JSON.stringify(data) })
