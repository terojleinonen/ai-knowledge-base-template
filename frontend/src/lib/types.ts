export type DocumentStatus = 'pending' | 'processing' | 'ready' | 'failed'

export interface User {
  id: number
  name: string
  email: string
  created_at: string
}

export interface KbDocument {
  id: number
  title: string
  original_name: string
  mime_type: string
  size_bytes: number
  status: DocumentStatus
  error: string | null
  chunk_count: number
  processed_at: string | null
  created_at: string
  updated_at: string
}

export interface Source {
  index: number
  document_id: number
  document_title: string
  chunk_id: number
  excerpt: string
  score: number
}

export interface Message {
  id: number
  conversation_id: number
  role: 'user' | 'assistant'
  content: string
  sources: Source[]
  created_at: string
}

export interface Conversation {
  id: number
  title: string
  messages?: Message[]
  created_at: string
  updated_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

export interface AuthResponse {
  token: string
  user: User
}
