import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { AnswerText } from '../components/AnswerText'
import { Alert, Button, Spinner } from '../components/ui'
import { useConversation } from '../hooks/conversations'
import { useDocuments } from '../hooks/documents'
import { useStreamingAnswer, type StreamingState } from '../hooks/useStreamingAnswer'
import type { Message, Source } from '../lib/types'

export function ChatPage() {
  const params = useParams()
  const conversationId = params.id ? Number(params.id) : null
  const navigate = useNavigate()
  const conversation = useConversation(conversationId)
  const documents = useDocuments()
  const { ask, stop, streaming, error } = useStreamingAnswer()
  const [question, setQuestion] = useState('')
  const bottom = useRef<HTMLDivElement>(null)

  const messages = conversationId ? (conversation.data?.messages ?? []) : []
  const hasReadyDocs = documents.data?.data.some((d) => d.status === 'ready') ?? true
  const busy = streaming !== null

  useEffect(() => {
    bottom.current?.scrollIntoView?.({ behavior: 'smooth', block: 'end' })
  }, [messages.length, streaming?.text.length, streaming?.phase])

  async function submit(event?: FormEvent) {
    event?.preventDefault()
    const text = question.trim()
    if (text.length < 2 || busy) return

    setQuestion('')

    const answer = await ask({ question: text, conversation_id: conversationId }, (message) => {
      if (message.conversation_id !== conversationId) navigate(`/app/chat/${message.conversation_id}`)
    })

    if (!answer) setQuestion((current) => current || text)
  }

  function onKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault()
      void submit()
    }
  }

  return (
    <div className="flex h-full flex-col">
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6">
          {messages.length === 0 && !streaming && (
            <div className="py-16 text-center">
              <h1 className="text-2xl font-semibold tracking-tight">What would you like to know?</h1>
              <p className="mt-2 text-sm text-slate-600">Answers are based only on your uploaded documents, with citations.</p>
              {!hasReadyDocs && (
                <p className="mt-4 text-sm text-amber-700">
                  You have no processed documents yet.{' '}
                  <Link to="/app" className="font-semibold underline">
                    Upload one first
                  </Link>
                  .
                </p>
              )}
            </div>
          )}

          {conversation.isPending && conversationId !== null && (
            <div className="flex justify-center py-12 text-slate-400">
              <Spinner className="size-6" />
            </div>
          )}
          {conversation.isError && <Alert>{conversation.error.message}</Alert>}

          {messages.map((m) => (
            <ChatMessage key={m.id} message={m} />
          ))}

          {streaming && <StreamingMessage state={streaming} />}

          {error && <Alert>{error}</Alert>}
          <div ref={bottom} />
        </div>
      </div>

      <form onSubmit={submit} className="border-t border-slate-200 bg-white">
        <div className="mx-auto flex max-w-3xl items-end gap-3 px-4 py-4 sm:px-6">
          <label htmlFor="question" className="sr-only">
            Your question
          </label>
          <textarea
            id="question"
            rows={1}
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            onKeyDown={onKeyDown}
            maxLength={2000}
            placeholder="Ask a question about your documents…"
            className="max-h-40 min-h-11 flex-1 resize-none rounded-xl border-0 px-4 py-2.5 ring-1 ring-slate-300 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-indigo-600 sm:text-sm"
          />
          {busy ? (
            <Button type="button" variant="secondary" className="h-11" onClick={stop}>
              Stop
            </Button>
          ) : (
            <Button type="submit" className="h-11" disabled={question.trim().length < 2}>
              Ask
            </Button>
          )}
        </div>
      </form>
    </div>
  )
}

function UserBubble({ content }: { content: string }) {
  return (
    <div className="flex justify-end">
      <p className="max-w-[85%] rounded-2xl rounded-br-sm bg-indigo-600 px-4 py-2.5 whitespace-pre-wrap text-white">{content}</p>
    </div>
  )
}

function StreamingMessage({ state }: { state: StreamingState }) {
  return (
    <>
      <UserBubble content={state.question} />
      {state.text === '' ? (
        <div className="flex items-center gap-2 text-sm text-slate-500" role="status">
          <Spinner /> {state.phase === 'searching' ? 'Searching your documents…' : 'Writing an answer…'}
        </div>
      ) : (
        <article className="rounded-2xl bg-white px-5 py-4 shadow-xs ring-1 ring-slate-200" aria-live="polite" aria-busy="true">
          <AnswerText content={state.text} sources={state.sources} messageId={0} />
          <span className="ml-0.5 inline-block h-4 w-1.5 animate-pulse bg-indigo-500 align-text-bottom" aria-hidden="true" />
        </article>
      )}
    </>
  )
}

function ChatMessage({ message }: { message: Message }) {
  if (message.role === 'user') return <UserBubble content={message.content} />

  return (
    <article className="rounded-2xl bg-white px-5 py-4 shadow-xs ring-1 ring-slate-200">
      <AnswerText content={message.content} sources={message.sources} messageId={message.id} />
      {message.sources.length > 0 && <SourceList sources={message.sources} messageId={message.id} />}
    </article>
  )
}

function SourceList({ sources, messageId }: { sources: Source[]; messageId: number }) {
  return (
    <details className="mt-4 border-t border-slate-100 pt-3">
      <summary className="cursor-pointer text-xs font-semibold tracking-wide text-slate-500 uppercase">{sources.length} sources</summary>
      <ol className="mt-3 space-y-3">
        {sources.map((s) => (
          <li key={s.index} id={`source-${messageId}-${s.index}`} className="scroll-mt-4 text-sm">
            <p className="font-medium text-slate-800">
              <span className="mr-1.5 inline-flex size-5 items-center justify-center rounded bg-indigo-100 text-xs font-semibold text-indigo-700">
                {s.index}
              </span>
              {s.document_title}
              <span className="ml-2 text-xs font-normal text-slate-400">relevance {Math.round(s.score * 100)}%</span>
            </p>
            <p className="mt-1 text-slate-600">{s.excerpt}</p>
          </li>
        ))}
      </ol>
    </details>
  )
}
