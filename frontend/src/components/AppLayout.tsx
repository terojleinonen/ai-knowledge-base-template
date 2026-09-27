import { NavLink, Outlet, useNavigate } from 'react-router'
import { useAuth } from '../auth/useAuth'
import { useConversations, useDeleteConversation } from '../hooks/conversations'
import { Button, Logo } from './ui'

export function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const conversations = useConversations()
  const deleteConversation = useDeleteConversation()

  const linkClass = ({ isActive }: { isActive: boolean }) =>
    `block truncate rounded-lg px-3 py-2 text-sm ${isActive ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-700 hover:bg-slate-100'}`

  return (
    <div className="flex h-full flex-col md:flex-row">
      <aside className="flex shrink-0 flex-col border-b border-slate-200 bg-white md:h-full md:w-72 md:border-r md:border-b-0">
        <div className="flex items-center justify-between px-4 py-4">
          <Logo />
        </div>

        <nav className="space-y-1 px-3" aria-label="Main">
          <NavLink to="/app" end className={linkClass}>
            Documents
          </NavLink>
          <NavLink to="/app/chat" end className={linkClass}>
            New chat
          </NavLink>
        </nav>

        <div className="mt-6 hidden min-h-0 flex-1 flex-col md:flex">
          <h2 className="px-6 text-xs font-semibold tracking-wide text-slate-500 uppercase">Conversations</h2>
          <ul className="mt-2 min-h-0 flex-1 space-y-0.5 overflow-y-auto px-3 pb-3">
            {conversations.data?.data.map((c) => (
              <li key={c.id} className="group flex items-center">
                <NavLink to={`/app/chat/${c.id}`} className={(s) => `${linkClass(s)} flex-1`} title={c.title}>
                  {c.title}
                </NavLink>
                <button
                  type="button"
                  className="ml-1 hidden rounded p-1 text-slate-400 hover:text-red-600 group-hover:block"
                  aria-label={`Delete conversation ${c.title}`}
                  onClick={async () => {
                    await deleteConversation.mutateAsync(c.id)
                    navigate('/app/chat')
                  }}
                >
                  ×
                </button>
              </li>
            ))}
            {conversations.data?.data.length === 0 && <li className="px-3 text-sm text-slate-400">No conversations yet</li>}
          </ul>
        </div>

        <div className="flex items-center justify-between gap-2 border-t border-slate-200 px-4 py-3">
          <span className="truncate text-sm text-slate-600" title={user?.is_guest ? undefined : user?.email}>
            {user?.is_guest ? 'Demo guest' : user?.name}
          </span>
          <Button variant="ghost" onClick={() => logout().then(() => navigate('/'))}>
            {user?.is_guest ? 'Leave demo' : 'Sign out'}
          </Button>
        </div>
      </aside>

      <main className="flex min-h-0 flex-1 flex-col">
        {user?.is_guest && (
          <p className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-center text-sm text-amber-800">
            You're using a temporary demo account with sample documents. It's deleted after 24 hours.
          </p>
        )}
        <div className="min-h-0 flex-1 overflow-y-auto">
          <Outlet />
        </div>
      </main>
    </div>
  )
}
