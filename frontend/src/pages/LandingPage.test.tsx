import { act, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import { vi } from 'vitest'
import { tokenStore } from '../lib/api'
import { mockApi, renderApp } from '../test/utils'
import { LandingPage } from './LandingPage'

const config = (overrides = {}) => ({
  demo: true,
  registration: false,
  limits: { questions_per_user_per_day: 15, questions_per_day: 150, documents_per_user: 6 },
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
