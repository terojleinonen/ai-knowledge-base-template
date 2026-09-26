import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import { tokenStore } from '../lib/api'
import { mockApi, renderApp } from '../test/utils'
import { AuthPage } from './AuthPage'

function renderLogin() {
  return renderApp(
    <Routes>
      <Route path="/login" element={<AuthPage mode="login" />} />
      <Route path="/app" element={<p>Dashboard</p>} />
    </Routes>,
    { route: '/login' },
  )
}

it('signs in and stores the token', async () => {
  const fetchMock = mockApi((url) =>
    url.endsWith('/auth/login') ? { body: { token: 'abc', user: { id: 1, name: 'Ada', email: 'ada@example.com' } } } : undefined,
  )

  renderLogin()
  await userEvent.type(screen.getByLabelText('Email'), 'ada@example.com')
  await userEvent.type(screen.getByLabelText('Password'), 'secret-password')
  await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

  expect(await screen.findByText('Dashboard')).toBeInTheDocument()
  expect(tokenStore.get()).toBe('abc')
  expect(JSON.parse(fetchMock.mock.calls[0][1]!.body as string)).toEqual({ email: 'ada@example.com', password: 'secret-password' })
})

it('shows validation errors next to fields', async () => {
  mockApi(() => ({ status: 422, body: { message: 'Invalid', errors: { email: ['These credentials do not match our records.'] } } }))

  renderLogin()
  await userEvent.type(screen.getByLabelText('Email'), 'ada@example.com')
  await userEvent.type(screen.getByLabelText('Password'), 'wrong')
  await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))

  await waitFor(() => expect(screen.getByText('These credentials do not match our records.')).toBeInTheDocument())
  expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
  expect(tokenStore.get()).toBeNull()
})
