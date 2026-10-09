import { act, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import { afterEach, beforeEach, describe, vi } from 'vitest'
import { tokenStore } from '../lib/api'
import { mockApi, renderApp } from '../test/utils'
import { LandingPage } from './LandingPage'

const config = (overrides = {}) => ({
  demo: true,
  registration: false,
  limits: { questions_per_user_per_day: 15, questions_per_day: 150, documents_per_user: 6 },
  turnstile_site_key: null,
  ...overrides,
})

function renderLanding() {
  return renderApp(
    <Routes>
      <Route path="/" element={<LandingPage />} />
      <Route path="/app/chat" element={<p>Chat screen</p>} />
    </Routes>,
  )
}

it('starts a guest demo session', async () => {
  const fetchMock = mockApi((url, init) => {
    if (url.endsWith('/config')) return { body: config() }
    if (url.endsWith('/auth/guest') && init.method === 'POST') {
      return { status: 201, body: { token: 'guest-token', user: { id: 9, name: 'Guest', email: 'g@kb-demo.invalid', is_guest: true } } }
    }
    return undefined
  })

  renderLanding()
  await userEvent.click(await screen.findByRole('button', { name: 'Try the live demo' }))

  expect(await screen.findByText('Chat screen')).toBeInTheDocument()
  expect(tokenStore.get()).toBe('guest-token')
  expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/auth/guest'))).toBe(true)
})

it('hides sign-up when registration is disabled', async () => {
  mockApi((url) => (url.endsWith('/config') ? { body: config() } : undefined))

  renderLanding()

  expect(await screen.findByRole('button', { name: 'Try the live demo' })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Get started' })).not.toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Sign in' })).toBeInTheDocument()
})

it('shows sign-up and no demo when the demo is off', async () => {
  mockApi((url) => (url.endsWith('/config') ? { body: config({ demo: false, registration: true }) } : undefined))

  renderLanding()

  expect(await screen.findByRole('link', { name: 'Create a free account' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Try the live demo' })).not.toBeInTheDocument()
})

it('explains the wait while a sleeping server wakes up', async () => {
  let release: (() => void) | undefined
  mockApi((url) => (url.endsWith('/config') ? { body: config() } : undefined))
  const slowFetch = vi.fn(
    (input: RequestInfo | URL) =>
      new Promise<Response>((resolve) => {
        if (String(input).endsWith('/config')) {
          resolve(new Response(JSON.stringify(config()), { status: 200 }))
          return
        }
        release = () => resolve(new Response(JSON.stringify({ message: 'Server error' }), { status: 500 }))
      }),
  )
  vi.stubGlobal('fetch', slowFetch)

  renderLanding()
  const button = await screen.findByRole('button', { name: 'Try the live demo' })

  vi.useFakeTimers({ shouldAdvanceTime: true })
  await userEvent.click(button)
  expect(screen.queryByText(/Waking up the server/)).not.toBeInTheDocument()

  await act(() => vi.advanceTimersByTimeAsync(3500))
  expect(screen.getByText(/Waking up the server/)).toBeInTheDocument()

  await act(async () => release?.())
  expect(await screen.findByRole('alert')).toHaveTextContent('Server error')
  vi.useRealTimers()
})

describe('with Turnstile bot protection', () => {
  let callbacks: Record<string, (token?: string) => void> = {}
  const turnstile = {
    render: vi.fn((_el: HTMLElement, options: Record<string, unknown>) => {
      callbacks = options as typeof callbacks
      return 'widget-1'
    }),
    reset: vi.fn(),
    remove: vi.fn(),
  }

  beforeEach(() => {
    callbacks = {}
    turnstile.render.mockClear()
    turnstile.reset.mockClear()
    window.turnstile = turnstile
  })
  afterEach(() => {
    delete window.turnstile
  })

  it('waits for the check, then sends its token when starting the demo', async () => {
    const fetchMock = mockApi((url, init) => {
      if (url.endsWith('/config')) return { body: config({ turnstile_site_key: 'site-key' }) }
      if (url.endsWith('/auth/guest') && init.method === 'POST') {
        return { status: 201, body: { token: 'guest-token', user: { id: 9, name: 'Guest', email: 'g@kb-demo.invalid', is_guest: true } } }
      }
      return undefined
    })

    renderLanding()
    const button = await screen.findByRole('button', { name: 'Checking your browser…' })
    expect(button).toBeDisabled()
    expect(turnstile.render).toHaveBeenCalledWith(expect.any(HTMLElement), expect.objectContaining({ sitekey: 'site-key', action: 'guest', appearance: 'interaction-only' }))

    act(() => callbacks.callback('turnstile-token'))
    await userEvent.click(await screen.findByRole('button', { name: 'Try the live demo' }))

    expect(await screen.findByText('Chat screen')).toBeInTheDocument()
    const [, init] = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/guest'))!
    expect(JSON.parse(init!.body as string)).toEqual({ turnstile_token: 'turnstile-token' })
  })

  it('shows a failed check and fetches a fresh token', async () => {
    mockApi((url) => {
      if (url.endsWith('/config')) return { body: config({ turnstile_site_key: 'site-key' }) }
      if (url.endsWith('/auth/guest')) {
        return { status: 422, body: { message: 'Invalid', errors: { turnstile_token: ['Please complete the human check and try again.'] } } }
      }
      return undefined
    })

    renderLanding()
    await screen.findByRole('button', { name: 'Checking your browser…' })
    act(() => callbacks.callback('spent-token'))
    await userEvent.click(await screen.findByRole('button', { name: 'Try the live demo' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Please complete the human check and try again.')
    expect(turnstile.reset).toHaveBeenCalledWith('widget-1')
    expect(screen.getByRole('button', { name: 'Checking your browser…' })).toBeDisabled() // waits for the new token
  })

  it('explains a check that never finishes', async () => {
    mockApi((url) => (url.endsWith('/config') ? { body: config({ turnstile_site_key: 'site-key' }) } : undefined))
    vi.useFakeTimers({ shouldAdvanceTime: true }) // before render: the timer starts with the check

    renderLanding()
    await screen.findByRole('button', { name: 'Checking your browser…' })

    await act(() => vi.advanceTimersByTimeAsync(14_000))
    expect(screen.queryByText(/Still checking your browser/)).not.toBeInTheDocument()

    await act(() => vi.advanceTimersByTimeAsync(2_000))
    expect(screen.getByText(/Still checking your browser/)).toHaveTextContent('allow challenges.cloudflare.com')

    act(() => callbacks.callback('late-token'))
    expect(screen.queryByText(/Still checking your browser/)).not.toBeInTheDocument()
    vi.useRealTimers()
  })

  it('clears a widget error once Turnstile recovers on its own', async () => {
    mockApi((url) => (url.endsWith('/config') ? { body: config({ turnstile_site_key: 'site-key' }) } : undefined))

    renderLanding()
    await screen.findByRole('button', { name: 'Checking your browser…' })
    act(() => callbacks['error-callback']())
    expect(await screen.findByRole('alert')).toHaveTextContent('The human check failed to load')

    act(() => callbacks.callback('fresh-token'))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
