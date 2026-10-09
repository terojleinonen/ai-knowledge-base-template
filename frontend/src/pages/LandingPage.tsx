import { Link } from 'react-router'
import productChat from '../assets/product-chat.jpg'
import { useAuth } from '../auth/useAuth'
import { DemoButton } from '../components/DemoButton'
import { Logo } from '../components/ui'
import { useAppConfig } from '../hooks/config'

const features = [
  { title: 'Bring your documents', body: 'Upload PDF, Word, Markdown and text files. They are parsed, chunked and indexed in the background.' },
  { title: 'Answers with citations', body: 'Every answer is grounded in your content and links back to the exact passages it used.' },
  { title: 'Private by default', body: 'Each account only ever searches its own documents. Run fully local models with Ollama if you prefer.' },
]

export function LandingPage() {
  const { user } = useAuth()
  const config = useAppConfig()
  const registration = config.data?.registration ?? true
  const demo = config.data?.demo ?? false

  return (
    <div className="min-h-full bg-white">
      <header className="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-6">
        <Logo />
        <nav className="flex items-center gap-2 text-sm font-semibold">
          {user ? (
            <Link to="/app" className="rounded-lg bg-indigo-600 px-3.5 py-2 text-white hover:bg-indigo-500">
              Open app
            </Link>
          ) : (
            <>
              <Link to="/login" className="rounded-lg px-3.5 py-2 text-slate-700 hover:bg-slate-100">
                Sign in
              </Link>
              {registration && (
                <Link to="/register" className="rounded-lg bg-indigo-600 px-3.5 py-2 text-white hover:bg-indigo-500">
                  Get started
                </Link>
              )}
            </>
          )}
        </nav>
      </header>

      <main>
        <section className="mx-auto max-w-6xl px-4 pt-12 pb-16 text-center sm:px-6 sm:pt-20">
          <h1 className="mx-auto max-w-3xl text-4xl font-bold tracking-tight text-balance text-slate-900 sm:text-6xl">
            Ask your documents anything.
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-lg text-pretty text-slate-600">
            Turn handbooks, specs and research into a searchable knowledge base that answers questions in plain
            language — with sources you can check.
          </p>
          <div className="mt-10 flex flex-col items-center gap-3">
            {user ? (
              <Link to="/app" className="rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Open your knowledge base
              </Link>
            ) : demo ? (
              <>
                <DemoButton className="max-w-md" />
                <p className="text-sm text-slate-500">No sign-up needed. You get a private copy of sample documents for 24 hours.</p>
              </>
            ) : (
              registration && (
                <Link to="/register" className="rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                  Create a free account
                </Link>
              )
            )}
          </div>
          <img
            src={productChat}
            width={2000}
            height={1264}
            decoding="async"
            alt="Knowledge Base answering a question about vacation days, with numbered citations and the source passages it used"
            className="mx-auto mt-16 h-auto w-full max-w-5xl rounded-xl shadow-2xl ring-1 ring-slate-900/10"
          />
        </section>

        <section className="border-t border-slate-100 bg-slate-50">
          <div className="mx-auto grid max-w-6xl gap-8 px-4 py-16 sm:grid-cols-3 sm:px-6">
            {features.map((f) => (
              <div key={f.title}>
                <h2 className="font-semibold text-slate-900">{f.title}</h2>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">{f.body}</p>
              </div>
            ))}
          </div>
        </section>
      </main>
    </div>
  )
}
