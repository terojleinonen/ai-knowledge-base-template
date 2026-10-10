import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import type { Passage } from '../lib/types'

export function usePassage(chunkId: number | null) {
  return useQuery({
    queryKey: ['passages', chunkId],
    queryFn: () => api<{ data: Passage }>(`/passages/${chunkId}`).then((r) => r.data),
    enabled: chunkId !== null,
    staleTime: Infinity, // a passage never changes; reprocessing creates new ones
    retry: false,
  })
}
