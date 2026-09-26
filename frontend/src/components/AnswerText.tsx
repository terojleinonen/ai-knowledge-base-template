import type { Source } from '../lib/types'

/** Renders answer text, turning [n] citation markers into links to their sources. */
export function AnswerText({ content, sources, messageId }: { content: string; sources: Source[]; messageId: number }) {
  const known = new Set(sources.map((s) => s.index))
  const parts = content.split(/(\[\d+\])/g)

  return (
    <p className="whitespace-pre-wrap leading-relaxed">
      {parts.map((part, i) => {
        const match = /^\[(\d+)\]$/.exec(part)
        const index = match ? Number(match[1]) : null

        if (index !== null && known.has(index)) {
          return (
            <a
              key={i}
              href={`#source-${messageId}-${index}`}
              className="mx-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded bg-indigo-100 px-1 align-text-top text-xs font-semibold text-indigo-700 hover:bg-indigo-200"
              aria-label={`Source ${index}`}
            >
              {index}
            </a>
          )
        }

        return <span key={i}>{part}</span>
      })}
    </p>
  )
}
