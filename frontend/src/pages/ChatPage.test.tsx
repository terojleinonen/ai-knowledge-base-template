import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import { mockApi, renderApp } from '../test/utils'
import { ChatPage } from './ChatPage'

const answer = {
  id: 2,
  conversation_id: 7,
  role: 'assistant',
  content: 'You get 25 vacation days [1].',
  sources: [{ index: 1, document_id: 3, document_title: 'Handbook', chunk_id: 9, excerpt: 'Vacation policy…', score: 0.82 }],
  created_at: '2026-01-01T00:00:00Z',
}

it('asks a question and shows the cited answer', async () => {
  let asked = false

  mockApi((url, init) => {
    if (url.endsWith('/documents?per_page=100')) return { body: { data: [{ id: 3, status: 'ready' }] } }
    if (url.endsWith('/chat') && init.method === 'POST') {
      asked = true
      return { status: 201, body: { data: answer } }
    }
    if (url.endsWith('/conversations/7') && asked) {
      return {
        body: {
          data: {
            id: 7,
            title: 'Vacation',
            messages: [{ id: 1, conversation_id: 7, role: 'user', content: 'How many vacation days?', sources: [], created_at: '' }, answer],
          },
        },
      }
    }
    return undefined
  })

  renderApp(
    <Routes>
      <Route path="/app/chat" element={<ChatPage />} />
      <Route path="/app/chat/:id" element={<ChatPage />} />
    </Routes>,
    { route: '/app/chat' },
  )

  await userEvent.type(screen.getByLabelText('Your question'), 'How many vacation days?{Enter}')

  expect(await screen.findByText(/You get 25 vacation days/)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Source 1' })).toBeInTheDocument()
  expect(screen.getByText('How many vacation days?')).toBeInTheDocument()
  expect(screen.getByText('Handbook')).toBeInTheDocument()
})
