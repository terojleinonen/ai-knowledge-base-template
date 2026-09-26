import { useRef, useState, type DragEvent } from 'react'
import { Link } from 'react-router'
import { Alert, Button, Spinner } from '../components/ui'
import { useDeleteDocument, useDocuments, useReprocessDocument, useUploadDocument } from '../hooks/documents'
import { ApiError } from '../lib/api'
import type { DocumentStatus, KbDocument } from '../lib/types'

const ACCEPT = '.pdf,.docx,.txt,.md'

const statusStyles: Record<DocumentStatus, string> = {
  pending: 'bg-slate-100 text-slate-700',
  processing: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  ready: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
}

function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 ** 2).toFixed(1)} MB`
}

export function DocumentsPage() {
  const documents = useDocuments()
  const upload = useUploadDocument()
  const remove = useDeleteDocument()
  const reprocess = useReprocessDocument()
  const input = useRef<HTMLInputElement>(null)
  const [errors, setErrors] = useState<string[]>([])
  const [dragging, setDragging] = useState(false)

  async function uploadFiles(files: FileList | null) {
    if (!files?.length) return
    setErrors([])

    for (const file of Array.from(files)) {
      try {
        await upload.mutateAsync(file)
      } catch (e) {
        const message = e instanceof ApiError ? (e.field('file') ?? e.message) : 'Upload failed.'
        setErrors((prev) => [...prev, `${file.name}: ${message}`])
      }
    }

    if (input.current) input.current.value = ''
  }

  function onDrop(event: DragEvent) {
    event.preventDefault()
    setDragging(false)
    void uploadFiles(event.dataTransfer.files)
  }

  const docs = documents.data?.data ?? []
  const readyCount = docs.filter((d) => d.status === 'ready').length

  return (
    <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Documents</h1>
          <p className="mt-1 text-sm text-slate-600">Upload files to make them searchable. Processing takes a few seconds per document.</p>
        </div>
        {readyCount > 0 && (
          <Link to="/app/chat" className="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
            Ask a question
          </Link>
        )}
      </div>

      <div
        onDragOver={(e) => {
          e.preventDefault()
          setDragging(true)
        }}
        onDragLeave={() => setDragging(false)}
        onDrop={onDrop}
        className={`mt-6 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition ${dragging ? 'border-indigo-500 bg-indigo-50' : 'border-slate-300 bg-white'}`}
      >
        <p className="text-sm text-slate-600">Drag and drop files here, or</p>
        <Button variant="secondary" className="mt-3" onClick={() => input.current?.click()} disabled={upload.isPending}>
          {upload.isPending && <Spinner />}
          Choose files
        </Button>
        <input
          ref={input}
          type="file"
          accept={ACCEPT}
          multiple
          className="sr-only"
          aria-label="Upload documents"
          onChange={(e) => void uploadFiles(e.target.files)}
        />
        <p className="mt-3 text-xs text-slate-500">PDF, DOCX, TXT or Markdown · up to 20 MB</p>
      </div>

      {errors.length > 0 && (
        <div className="mt-4">
          <Alert>
            <ul className="list-inside list-disc">
              {errors.map((e) => (
                <li key={e}>{e}</li>
              ))}
            </ul>
          </Alert>
        </div>
      )}

      <section className="mt-8" aria-labelledby="library-heading">
        <h2 id="library-heading" className="sr-only">
          Library
        </h2>

        {documents.isPending && (
          <div className="flex justify-center py-12 text-slate-400">
            <Spinner className="size-6" />
          </div>
        )}
        {documents.isError && <Alert>{documents.error.message}</Alert>}
        {documents.isSuccess && docs.length === 0 && (
          <p className="py-12 text-center text-sm text-slate-500">No documents yet. Upload your first file to get started.</p>
        )}

        {docs.length > 0 && (
          <ul className="divide-y divide-slate-200 overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            {docs.map((doc) => (
              <DocumentRow
                key={doc.id}
                doc={doc}
                onDelete={() => {
                  if (confirm(`Delete "${doc.title}"? This cannot be undone.`)) remove.mutate(doc.id)
                }}
                onRetry={() => reprocess.mutate(doc.id)}
              />
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function DocumentRow({ doc, onDelete, onRetry }: { doc: KbDocument; onDelete: () => void; onRetry: () => void }) {
  return (
    <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-5">
      <div className="min-w-0 flex-1">
        <p className="truncate font-medium text-slate-900" title={doc.original_name}>
          {doc.title}
        </p>
        <p className="mt-0.5 text-xs text-slate-500">
          {doc.original_name} · {formatBytes(doc.size_bytes)}
          {doc.status === 'ready' && ` · ${doc.chunk_count} passages`}
        </p>
        {doc.error && <p className="mt-1 text-xs text-red-600">{doc.error}</p>}
      </div>
      <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-xs font-medium capitalize ring-1 ring-inset ring-transparent ${statusStyles[doc.status]}`}>
        {(doc.status === 'pending' || doc.status === 'processing') && <Spinner className="size-3" />}
        {doc.status}
      </span>
      <div className="flex gap-1">
        {doc.status === 'failed' && (
          <Button variant="ghost" onClick={onRetry}>
            Retry
          </Button>
        )}
        <Button variant="danger" onClick={onDelete} aria-label={`Delete ${doc.title}`}>
          Delete
        </Button>
      </div>
    </li>
  )
}
