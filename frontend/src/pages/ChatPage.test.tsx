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
  expect(screen.getByRole('button', { name: 'Source 1' })).toBeInTheDocument()
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

it('shows daily limit messages from the server as-is', async () => {
  mockApi((url) => {
    if (url.endsWith('/documents?per_page=100')) return { body: { data: [] } }
    if (url.endsWith('/chat/stream')) {
      return { status: 429, body: { message: "You've reached today's limit of 15 questions. Please come back tomorrow." } }
    }
    return undefined
  })

  renderChat()
  await userEvent.type(screen.getByLabelText('Your question'), 'Hello there{Enter}')

  expect(await screen.findByRole('alert')).toHaveTextContent("You've reached today's limit of 15 questions.")
})

const passage = {
  chunk_id: 9,
  document: { id: 3, title: 'Handbook' },
  position: 4,
  total: 12,
  before: ['Working hours are flexible.'],
  content: 'Vacation policy: employees get 25 vacation days.',
  after: ['Sick leave needs a certificate.'],
}

function renderConversation(passageResponse: { body?: unknown; status?: number }) {
  mockApi((url) => {
    if (url.endsWith('/documents?per_page=100')) return { body: { data: [{ id: 3, status: 'ready' }] } }
    if (url.endsWith('/conversations/7')) return { body: { data: { id: 7, title: 'Vacation', messages: [userMessage, answer] } } }
    if (url.endsWith('/passages/9')) return passageResponse
    return undefined
  })

  return renderApp(
    <Routes>
      <Route path="/app/chat/:id" element={<ChatPage />} />
    </Routes>,
    { route: '/app/chat/7' },
  )
}

it('opens a cited passage in context and closes it with Escape', async () => {
  renderConversation({ body: { data: passage } })

  const citation = await screen.findByRole('button', { name: 'Source 1' })
  await userEvent.click(citation)

  const dialog = await screen.findByRole('dialog', { name: 'Handbook' })
  expect(await screen.findByLabelText('Cited passage')).toHaveTextContent('Vacation policy: employees get 25 vacation days.')
  expect(dialog).toHaveTextContent('Working hours are flexible.')
  expect(dialog).toHaveTextContent('Sick leave needs a certificate.')
  expect(dialog).toHaveTextContent('Passage 5 of 12')
  expect(screen.getByRole('button', { name: 'Close' })).toHaveFocus()

  await userEvent.keyboard('{Escape}')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(citation).toHaveFocus()
})

it('opens a passage from the source list too', async () => {
  renderConversation({ body: { data: passage } })

  await userEvent.click(await screen.findByText('1 sources'))
  await userEvent.click(screen.getByRole('button', { name: 'Read in context' }))

  expect(await screen.findByLabelText('Cited passage')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Close' }))
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('falls back to the saved excerpt when the passage is gone', async () => {
  renderConversation({ status: 404, body: { message: 'Not found.' } })

  await userEvent.click(await screen.findByRole('button', { name: 'Source 1' }))

  expect(await screen.findByText(/no longer available/)).toBeInTheDocument()
  expect(screen.getByRole('dialog')).toHaveTextContent('Vacation policy…')
})
