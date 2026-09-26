import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import { mockApi, renderApp, sse } from '../test/utils'
import { ChatPage } from './ChatPage'

const source = { index: 1, document_id: 3, document_title: 'Handbook', chunk_id: 9, excerpt: 'Vacation policy…', score: 0.82 }
const answer = {
  id: 2,
  conversation_id: 7,
  role: 'assistant',
  content: 'You get 25 vacation days [1].',
  sources: [source],
  created_at: '2026-01-01T00:00:00Z',
}
const userMessage = { id: 1, conversation_id: 7, role: 'user', content: 'How many vacation days?', sources: [], created_at: '' }

function renderChat() {
  return renderApp(
    <Routes>
      <Route path="/app/chat" element={<ChatPage />} />
      <Route path="/app/chat/:id" element={<ChatPage />} />
    </Routes>,
    { route: '/app/chat' },
  )
}

it('streams an answer and then shows the saved conversation', async () => {
  let streamed = false

  const fetchMock = mockApi((url, init) => {
    if (url.endsWith('/documents?per_page=100')) return { body: { data: [{ id: 3, status: 'ready' }] } }
    if (url.endsWith('/conversations')) return { body: { data: [], meta: {} } }
    if (url.endsWith('/chat/stream') && init.method === 'POST') {
      streamed = true
      return {
        stream: [
          sse([['sources', { sources: [source] }]]),
          sse([['delta', { text: 'You get 25 ' }]]),
          sse([['delta', { text: 'vacation days [1].' }]]),
          sse([['done', answer]]),
        ],
      }
    }
    if (url.endsWith('/conversations/7') && streamed) {
      return { body: { data: { id: 7, title: 'Vacation', messages: [userMessage, answer] } } }
    }
    return undefined
  })

  renderChat()
  await userEvent.type(screen.getByLabelText('Your question'), 'How many vacation days?{Enter}')

  expect(await screen.findByText('Handbook')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Source 1' })).toHaveAttribute('href', '#source-2-1')
  expect(screen.getByText('How many vacation days?')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Ask' })).toBeInTheDocument()

  const [, init] = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/chat/stream'))!
  expect(new Headers(init!.headers).get('Accept')).toBe('text/event-stream')
  expect(JSON.parse(init!.body as string)).toEqual({ question: 'How many vacation days?', conversation_id: null })
})

it('shows provider errors and restores the question', async () => {
  mockApi((url) => {
    if (url.endsWith('/documents?per_page=100')) return { body: { data: [] } }
    if (url.endsWith('/chat/stream')) {
      return { stream: [sse([['sources', { sources: [source] }], ['error', { message: 'The AI provider failed to answer.' }]])] }
    }
    return undefined
  })

  renderChat()
  await userEvent.type(screen.getByLabelText('Your question'), 'Anything?{Enter}')

  expect(await screen.findByRole('alert')).toHaveTextContent('The AI provider failed to answer.')
  await waitFor(() => expect(screen.getByLabelText('Your question')).toHaveValue('Anything?'))
})

it('shows validation errors from the API', async () => {
  mockApi((url) => {
    if (url.endsWith('/documents?per_page=100')) return { body: { data: [] } }
    if (url.endsWith('/chat/stream')) return { status: 429, body: { message: 'Too Many Attempts.' } }
    return undefined
  })

  renderChat()
  await userEvent.type(screen.getByLabelText('Your question'), 'Hello there{Enter}')

  expect(await screen.findByRole('alert')).toHaveTextContent('Too many requests')
})
