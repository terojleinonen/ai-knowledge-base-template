import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import type { AppConfig } from '../lib/types'

/** Public server settings (demo mode, registration). Also wakes a sleeping free-tier server early. */
export function useAppConfig() {
  return useQuery({
    queryKey: ['config'],
    queryFn: () => api<AppConfig>('/config'),
    staleTime: Infinity,
    retry: 2,
  })
}
