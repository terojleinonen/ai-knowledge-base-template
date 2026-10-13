import { screen } from '@testing-library/react'
import { Route, Routes } from 'react-router'
import { tokenStore } from '../lib/api'
import { mockApi, renderApp } from '../test/utils'
import { AppLayout } from './AppLayout'

it('lists conversations in the sidebar and in a folding list for phones', async () => {
  tokenStore.set('token')
  mockApi((url) => {
    if (url.endsWith('/auth/me')) return { body: { data: { id: 1, name: 'Ada', email: 'ada@example.com', is_guest: false } } }
    if (url.endsWith('/conversations')) return { body: { data: [{ id: 7, title: 'Vacation days' }], meta: {} } }
    return undefined
  })

  renderApp(
    <Routes>
      <Route path="/app" element={<AppLayout />} />
    </Routes>,
    { route: '/app' },
  )

  expect(await screen.findByText('Conversations (1)')).toBeInTheDocument() // the phone list's summary
  expect(screen.getAllByRole('link', { name: 'Vacation days', hidden: true })).toHaveLength(2) // sidebar + phone list
  tokenStore.set(null)
})

it('shows demo guests a link to the source code', async () => {
  tokenStore.set('guest-token')
  mockApi((url) => {
    if (url.endsWith('/auth/me')) return { body: { data: { id: 9, name: 'Guest', email: 'g@kb-demo.invalid', is_guest: true } } }
    if (url.endsWith('/config')) return { body: { demo: true, registration: false, limits: {}, turnstile_site_key: null, source_url: 'https://github.com/example/kb' } }
    if (url.endsWith('/conversations')) return { body: { data: [], meta: {} } }
    return undefined
  })

  renderApp(
    <Routes>
      <Route path="/app" element={<AppLayout />} />
    </Routes>,
    { route: '/app' },
  )

  expect(await screen.findByRole('link', { name: 'View the source on GitHub' })).toHaveAttribute('href', 'https://github.com/example/kb')
  tokenStore.set(null)
})
