import { useEffect, useRef, type ReactNode } from 'react'
import { usePassage } from '../hooks/passages'
import { ApiError } from '../lib/api'
import type { Source } from '../lib/types'
import { Spinner } from './ui'

/**
 * Side panel showing a cited passage highlighted within the text around it.
 * Closes with Escape, the close button or a click outside; focus returns to the citation.
 */
export function PassagePanel({ source, onClose }: { source: Source; onClose: () => void }) {
  const passage = usePassage(source.chunk_id)
  const closeButton = useRef<HTMLButtonElement>(null)
  const highlight = useRef<HTMLElement | null>(null)
  const setHighlight = (element: HTMLElement | null) => {
    highlight.current = element
  }

  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null
    closeButton.current?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      opener?.focus?.()
    }
  }, [onClose])

  useEffect(() => {
    highlight.current?.scrollIntoView?.({ block: 'center' })
  }, [passage.data])

  const gone = passage.error instanceof ApiError && passage.error.status === 404
  const highlights = source.highlights ?? []

  return (
    <div className="fixed inset-0 z-40 flex justify-end">
      <div className="absolute inset-0 bg-slate-900/20" onClick={onClose} aria-hidden="true" />
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="passage-title"
        className="relative flex h-full w-full max-w-xl flex-col bg-white shadow-xl"
      >
        <header className="flex items-start gap-3 border-b border-slate-200 px-5 py-4">
          <span className="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded bg-indigo-100 text-xs font-semibold text-indigo-700">
            {source.index}
          </span>
          <div className="min-w-0 flex-1">
            <h2 id="passage-title" className="truncate font-semibold text-slate-900">
              {source.document_title}
            </h2>
            {passage.data && (
              <p className="text-xs text-slate-500">
                Passage {passage.data.position + 1} of {passage.data.total}
                {highlights.length > 0 && ' · highlighted: what the answer relies on'}
              </p>
            )}
          </div>
          <button
            ref={closeButton}
            type="button"
            onClick={onClose}
            className="-m-1 rounded-lg p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-indigo-600"
            aria-label="Close"
          >
            <svg viewBox="0 0 24 24" className="size-5" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M6 6l12 12M18 6 6 18" strokeLinecap="round" />
            </svg>
          </button>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4 text-sm leading-relaxed whitespace-pre-wrap text-slate-500">
          {passage.isPending && (
            <div className="flex justify-center py-12 text-slate-400" role="status" aria-label="Loading passage">
              <Spinner className="size-6" />
            </div>
          )}

          {passage.isError && (
            <div className="space-y-3">
              <p className="text-slate-700">
                {gone
                  ? 'This passage is no longer available: its document was deleted or processed again. The saved excerpt:'
                  : `The passage could not be loaded (${passage.error.message}). The saved excerpt:`}
              </p>
              <mark className="block rounded-lg bg-amber-50 px-3 py-2 text-slate-800 ring-1 ring-amber-200">{source.excerpt}</mark>
            </div>
          )}

          {passage.data && (
            <>
              {passage.data.position > passage.data.before.length && <p className="mb-3 text-center text-slate-400">…</p>}
              {passage.data.before.map((text, i) => (
                <p key={`b${i}`} className="mb-3">
                  {text}
                </p>
              ))}
              {highlights.length > 0 ? (
                <div
                  ref={setHighlight}
                  className="mb-3 rounded-lg bg-amber-50/60 px-3 py-2 text-slate-700 ring-1 ring-amber-200"
                  aria-label="Cited passage"
                >
                  {withHighlights(passage.data.content, highlights)}
                </div>
              ) : (
                <mark
                  ref={setHighlight}
                  className="mb-3 block rounded-lg bg-amber-50 px-3 py-2 text-slate-900 ring-1 ring-amber-200"
                  aria-label="Cited passage"
                >
                  {passage.data.content}
                </mark>
              )}
              {passage.data.after.map((text, i) => (
                <p key={`a${i}`} className="mb-3">
                  {text}
                </p>
              ))}
              {passage.data.position + passage.data.after.length < passage.data.total - 1 && (
                <p className="text-center text-slate-400">…</p>
              )}
            </>
          )}
        </div>
      </section>
    </div>
  )
}

/** The passage with each supporting sentence marked; text the server didn't match stays plain. */
function withHighlights(content: string, highlights: string[]): ReactNode[] {
  const ranges = highlights
    .map((h) => [content.indexOf(h), content.indexOf(h) + h.length] as const)
    .filter(([start]) => start >= 0)
    .sort((a, b) => a[0] - b[0])

  const nodes: ReactNode[] = []
  let cursor = 0

  for (const [start, end] of ranges) {
    if (start < cursor) continue // overlaps the previous highlight
    if (start > cursor) nodes.push(content.slice(cursor, start))
    nodes.push(
      <mark key={start} className="rounded bg-amber-200 px-0.5 text-slate-900">
        {content.slice(start, end)}
      </mark>,
    )
    cursor = end
  }

  nodes.push(content.slice(cursor))
  return nodes
}
