import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../lib/api'
import type { KbDocument, Paginated } from '../lib/types'

const KEY = ['documents']

export function useDocuments() {
  return useQuery({
    queryKey: KEY,
    queryFn: () => api<Paginated<KbDocument>>('/documents?per_page=100'),
    // Poll while anything is still being ingested.
    refetchInterval: (query) =>
      query.state.data?.data.some((d) => d.status === 'pending' || d.status === 'processing') ? 2000 : false,
  })
}

export function useUploadDocument() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (file: File) => {
      const body = new FormData()
      body.append('file', file)
      return api<{ data: KbDocument }>('/documents', { method: 'POST', body })
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteDocument() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api<void>(`/documents/${id}`, { method: 'DELETE' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useReprocessDocument() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => api<{ data: KbDocument }>(`/documents/${id}/reprocess`, { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}
