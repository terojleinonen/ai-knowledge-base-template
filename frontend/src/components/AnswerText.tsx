import type { ReactNode } from 'react'
import type { Source } from '../lib/types'

/**
 * Renders an answer: a small, safe Markdown subset (paragraphs, headings, bullet and
 * numbered lists, **bold**, *italic*, `code`; no _underscore_ italics, which would mangle snake_case) with [n] citation markers turned into
 * links to their sources. No HTML is ever injected.
 */
export function AnswerText({ content, sources, messageId }: { content: string; sources: Source[]; messageId: number }) {
  const known = new Set(sources.map((s) => s.index))
  const inline = (text: string, key: string) => renderInline(text, key, known, messageId)

  return <div className="space-y-3 leading-relaxed">{blocks(content).map((block, i) => renderBlock(block, `b${i}`, inline))}</div>
}

type Block =
  | { type: 'paragraph'; lines: string[] }
  | { type: 'heading'; text: string }
  | { type: 'list'; ordered: boolean; items: string[] }

const BULLET = /^\s*[-*•]\s+(.*)$/
const NUMBERED = /^\s*\d+[.)]\s+(.*)$/
const HEADING = /^\s*#{1,6}\s+(.*)$/
// A line that is only bold text, as models often write headings: "**Encryption**" or "**Who and how fast:**"
const BOLD_HEADING = /^\s*\*\*([^*]+)\*\*:?\s*$/

function blocks(content: string): Block[] {
  const result: Block[] = []

  for (const line of content.replace(/\r\n?/g, '\n').split('\n')) {
    const last = result[result.length - 1]
    const bullet = BULLET.exec(line)
    const numbered = bullet ? null : NUMBERED.exec(line)
    const heading = HEADING.exec(line) ?? BOLD_HEADING.exec(line)

    if (line.trim() === '') {
      result.push({ type: 'paragraph', lines: [] }) // paragraph break
    } else if (bullet || numbered) {
      const ordered = numbered !== null
      const item = (bullet ?? numbered)![1]
      if (last?.type === 'list' && last.ordered === ordered) last.items.push(item)
      else result.push({ type: 'list', ordered, items: [item] })
    } else if (heading) {
      result.push({ type: 'heading', text: heading[1] })
    } else if (last?.type === 'paragraph') {
      last.lines.push(line)
    } else {
      result.push({ type: 'paragraph', lines: [line] })
    }
  }

  return result.filter((b) => b.type !== 'paragraph' || b.lines.length > 0)
}

function renderBlock(block: Block, key: string, inline: (text: string, key: string) => ReactNode[]): ReactNode {
  switch (block.type) {
    case 'heading':
      return (
        <p key={key} className="font-semibold text-slate-900">
          {inline(block.text, key)}
        </p>
      )
    case 'list': {
      const Tag = block.ordered ? 'ol' : 'ul'
      return (
        <Tag key={key} className={`space-y-1 pl-5 ${block.ordered ? 'list-decimal' : 'list-disc'}`}>
          {block.items.map((item, i) => (
            <li key={i}>{inline(item, `${key}i${i}`)}</li>
          ))}
        </Tag>
      )
    }
    case 'paragraph':
      return (
        <p key={key}>
          {block.lines.flatMap((line, i) => [...(i > 0 ? [<br key={`${key}br${i}`} />] : []), ...inline(line, `${key}l${i}`)])}
        </p>
      )
  }
}

const INLINE = /(\[\d+(?:\s*,\s*\d+)*\]|\*\*[^*]+\*\*|`[^`]+`|\*[^*\s][^*]*\*)/g

function renderInline(text: string, key: string, known: Set<number>, messageId: number): ReactNode[] {
  return text.split(INLINE).flatMap((part, i): ReactNode[] => {
    const k = `${key}-${i}`

    if (part === '') return []

    const citation = /^\[(\d+(?:\s*,\s*\d+)*)\]$/.exec(part)
    if (citation) {
      const numbers = citation[1].split(',').map((n) => Number(n.trim()))
      if (numbers.every((n) => known.has(n))) {
        return numbers.map((n) => (
          <a
            key={`${k}-${n}`}
            href={`#source-${messageId}-${n}`}
            className="mx-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded bg-indigo-100 px-1 align-text-top text-xs font-semibold text-indigo-700 hover:bg-indigo-200"
            aria-label={`Source ${n}`}
          >
            {n}
          </a>
        ))
      }
      return [part]
    }

    if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
      return [<strong key={k}>{renderInline(part.slice(2, -2), k, known, messageId)}</strong>]
    }
    if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
      return [
        <code key={k} className="rounded bg-slate-100 px-1 py-0.5 text-[0.9em]">
          {part.slice(1, -1)}
        </code>,
      ]
    }
    if (part.startsWith('*') && part.endsWith('*') && part.length > 2) {
      return [<em key={k}>{renderInline(part.slice(1, -1), k, known, messageId)}</em>]
    }

    return [part]
  })
}
