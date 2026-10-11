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
