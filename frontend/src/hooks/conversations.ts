import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, json } from '../lib/api'
import type { Conversation, Message, Paginated } from '../lib/types'

export function useConversations() {
  return useQuery({
    queryKey: ['conversations'],
    queryFn: () => api<Paginated<Conversation>>('/conversations'),
  })
}

export function useConversation(id: number | null) {
  return useQuery({
    queryKey: ['conversations', id],
    queryFn: () => api<{ data: Conversation }>(`/conversations/${id}`).then((r) => r.data),
    enabled: id !== null,
  })
}

export function useAsk() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (input: { question: string; conversation_id?: number | null }) =>
      api<{ data: Message }>('/chat', json(input)).then((r) => r.data),
    onSuccess: (message) => {
      qc.invalidateQueries({ queryKey: ['conversations'] })
      qc.invalidateQueries({ queryKey: ['conversations', message.conversation_id] })
    },
  })
}

export function useDeleteConversation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api<void>(`/conversations/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['conversations'] }),
  })
}
