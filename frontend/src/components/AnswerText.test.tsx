import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { plainText } from '../lib/markdown'
import { AnswerText } from './AnswerText'
import type { Source } from '../lib/types'

const source = (index: number): Source => ({ index, document_id: 1, document_title: 'Handbook', chunk_id: index, excerpt: '…', score: 0.9 })

it('turns known citations into buttons that open their source', async () => {
  const onCite = vi.fn()
  const { container } = render(<AnswerText content="You get 25 days [1]. Unknown [7]." sources={[source(1)]} onCite={onCite} />)

  await userEvent.click(screen.getByRole('button', { name: 'Source 1' }))
  expect(onCite).toHaveBeenCalledWith(source(1))
  expect(screen.queryByRole('button', { name: 'Source 7' })).not.toBeInTheDocument()
  expect(container).toHaveTextContent('You get 25 days 1. Unknown [7].')
})

it('shows citations as plain badges without onCite', () => {
  render(<AnswerText content="You get 25 days [1]." sources={[source(1)]} />)

  expect(screen.queryByRole('button')).not.toBeInTheDocument()
  expect(screen.getByLabelText('Source 1')).toHaveTextContent('1')
})

it('renders the Markdown that models produce', () => {
  // Real claude-opus-5-5 answer shape.
  const content = [
    '**Who and how fast**',
    '',
    '- Report lost equipment to **IT within 24 hours** [2].',
    '- Laptops must use *full-disk encryption* (`FileVault`) [1].',
    '',
    'Full-time employees earn **30 days per year** [1].',
  ].join('\n')
  const { container } = render(<AnswerText content={content} sources={[source(1), source(2)]} onCite={() => {}} />)

  expect(container.querySelectorAll('li')).toHaveLength(2)
  expect(screen.getByText('IT within 24 hours').tagName).toBe('STRONG')
  expect(screen.getByText('full-disk encryption').tagName).toBe('EM')
  expect(screen.getByText('FileVault').tagName).toBe('CODE')
  expect(screen.getByText('Who and how fast')).toHaveClass('font-semibold')
  expect(container).not.toHaveTextContent('**')
  expect(screen.getAllByRole('button', { name: 'Source 1' })).toHaveLength(2)
})

it('handles grouped citations and keeps snake_case intact', () => {
  render(<AnswerText content="Set the max_upload_size value [1, 2]." sources={[source(1), source(2)]} onCite={() => {}} />)

  expect(screen.getByText(/max_upload_size/)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Source 2' })).toBeInTheDocument()
})

it('never renders HTML from the answer', () => {
  const { container } = render(<AnswerText content={'<img src=x onerror="alert(1)"> **<b>bold</b>**'} sources={[]} onCite={() => {}} />)

  expect(container.querySelector('img')).toBeNull()
  expect(container.querySelector('b')).toBeNull()
  expect(container).toHaveTextContent('<img src=x onerror="alert(1)">')
})

it('strips Markdown from source excerpts', () => {
  expect(plainText('# Northwind Labs Employee Handbook ## 1. Working hours Core **hours** are `10:00`')).toBe(
    'Northwind Labs Employee Handbook 1. Working hours Core hours are 10:00',
  )
})
