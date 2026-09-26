import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useRef, useState } from 'react'
import { api, ApiError, apiFetch } from '../lib/api'
import { readSse } from '../lib/sse'
import type { Conversation, Message, Source } from '../lib/types'

export interface StreamingState {
  question: string
  text: string
  sources: Source[]
  phase: 'searching' | 'answering'
}

interface AskInput {
  question: string
  conversation_id?: number | null
}

/**
 * Stream an answer from POST /chat/stream. Resolves with the saved message,
 * or null if the stream was stopped or failed (see `error`).
 */
export function useStreamingAnswer() {
  const queryClient = useQueryClient()
  const [streaming, setStreaming] = useState<StreamingState | null>(null)
  const [error, setError] = useState<string | null>(null)
  const controller = useRef<AbortController | null>(null)

  const refresh = useCallback(
    async (conversationId: number | null | undefined) => {
      await queryClient.invalidateQueries({ queryKey: ['conversations'], exact: true })
      if (conversationId) {
        await queryClient.fetchQuery({
          queryKey: ['conversations', conversationId],
          queryFn: () => api<{ data: Conversation }>(`/conversations/${conversationId}`).then((r) => r.data),
          staleTime: 0,
        })
      }
    },
    [queryClient],
  )

  const ask = useCallback(
    async (input: AskInput, onDone?: (message: Message) => void): Promise<Message | null> => {
      controller.current?.abort()
      const abort = new AbortController()
      controller.current = abort

      setError(null)
      setStreaming({ question: input.question, text: '', sources: [], phase: 'searching' })

      try {
        const response = await apiFetch('/chat/stream', {
          method: 'POST',
          body: JSON.stringify(input),
          headers: { Accept: 'text/event-stream' },
          signal: abort.signal,
        })

        if (!response.body) throw new ApiError(0, 'Streaming is not supported by this browser.')

        for await (const { event, data } of readSse(response.body)) {
          const payload = JSON.parse(data)

          if (event === 'sources') {
            setStreaming((s) => s && { ...s, sources: payload.sources, phase: 'answering' })
          } else if (event === 'delta') {
            setStreaming((s) => s && { ...s, text: s.text + payload.text, phase: 'answering' })
          } else if (event === 'error') {
            throw new ApiError(500, payload.message)
          } else if (event === 'done') {
            const message = payload as Message
            await refresh(message.conversation_id)
            // Called before the streaming state is cleared, so navigation doesn't flash an empty chat.
            onDone?.(message)
            return message
          }
        }

        throw new ApiError(0, 'The answer was interrupted. Please try again.')
      } catch (e) {
        if (abort.signal.aborted) {
          // The server keeps the partial answer; show it once it has been saved.
          await refresh(input.conversation_id)
          return null
        }
        setError(e instanceof ApiError ? e.message : 'Something went wrong.')
        return null
      } finally {
        if (controller.current === abort) controller.current = null
        setStreaming(null)
      }
    },
    [refresh],
  )

  const stop = useCallback(() => controller.current?.abort(), [])

  return { ask, stop, streaming, error }
}
