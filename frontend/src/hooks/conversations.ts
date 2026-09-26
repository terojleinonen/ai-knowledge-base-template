import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../lib/api'
import type { Conversation, Paginated } from '../lib/types'

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

export function useDeleteConversation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api<void>(`/conversations/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['conversations'] }),
  })
}
