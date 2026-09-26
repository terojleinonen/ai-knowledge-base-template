import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router'
import { vi } from 'vitest'
import { AuthProvider } from '../auth/AuthContext'

type Handler = (
  url: string,
  init: RequestInit,
) => { status?: number; body?: unknown; stream?: string[]; headers?: Record<string, string> } | undefined

/** A response body that delivers the given chunks one at a time, like a network stream. */
export function chunkedBody(chunks: string[]): ReadableStream<Uint8Array> {
  const encoder = new TextEncoder()
  let i = 0
  return new ReadableStream({
    pull(controller) {
      if (i < chunks.length) controller.enqueue(encoder.encode(chunks[i++]))
      else controller.close()
    },
  })
}

export function sse(events: Array<[string, unknown]>): string {
  return events.map(([event, data]) => `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`).join('')
}

/** Stub fetch with a simple router: return undefined for unmatched calls to get a 404. */
export function mockApi(handler: Handler) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = String(input)
    const res = handler(url, init) ?? { status: 404, body: { message: 'Not found' } }
    const status = res.status ?? 200
    if (res.stream) {
      return new Response(chunkedBody(res.stream), { status, headers: { 'Content-Type': 'text/event-stream' } })
    }
    return new Response(status === 204 ? null : JSON.stringify(res.body ?? {}), {
      status,
      headers: { 'Content-Type': 'application/json', ...res.headers },
    })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

export function renderApp(ui: ReactElement, { route = '/' } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[route]}>
        <AuthProvider>{ui}</AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
