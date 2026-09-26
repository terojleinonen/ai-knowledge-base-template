import { render, screen } from '@testing-library/react'
import { AnswerText } from './AnswerText'
import type { Source } from '../lib/types'

const source: Source = { index: 1, document_id: 1, document_title: 'Handbook', chunk_id: 1, excerpt: '…', score: 0.9 }

it('links known citations to their sources', () => {
  const { container } = render(<AnswerText content="You get 25 days [1]. Unknown [7]." sources={[source]} messageId={42} />)

  const link = screen.getByRole('link', { name: 'Source 1' })
  expect(link).toHaveAttribute('href', '#source-42-1')
  expect(screen.queryByRole('link', { name: 'Source 7' })).not.toBeInTheDocument()
  expect(container).toHaveTextContent('You get 25 days 1. Unknown [7].')
})
