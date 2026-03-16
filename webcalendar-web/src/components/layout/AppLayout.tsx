import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../auth/auth-context';
import { cn } from '../../lib/utils';
import { SearchBar } from '../search/SearchBar';
import { ThemeToggle } from '../theme/ThemeToggle';
import { useTenant } from '../../hooks/useTenant';

export function AppLayout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const location = useLocation();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const { tenant } = useTenant();

  const navItems = [
    { label: 'Calendar', href: '/', icon: '📅' },
    { label: 'Tasks', href: '/tasks', icon: '✅' },
    { label: 'Journals', href: '/journals', icon: '📓' },
    { label: 'Settings', href: '/settings/preferences', icon: '⚙' },
    { label: 'Access', href: '/settings/access', icon: '🔒' },
  ];

  if (user?.is_admin) {
    navItems.push({ label: 'Users', href: '/admin/users', icon: '👤' });
    navItems.push({ label: 'Categories', href: '/admin/categories', icon: '🏷' });
    navItems.push({ label: 'Groups', href: '/admin/groups', icon: '👥' });
  }

  return (
    <div className="flex h-screen bg-background">
      {/* Desktop Sidebar */}
      <aside className="hidden w-56 flex-shrink-0 border-r border-border bg-card md:block">
        <div className="flex h-14 items-center border-b border-border px-4">
          <h1 className="text-lg font-semibold">{tenant ? tenant.name : 'WebCalendar'}</h1>
        </div>
        <nav className="space-y-1 p-3">
          {navItems.map((item) => (
            <Link
              key={item.href}
              to={item.href}
              className={cn(
                'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                location.pathname === item.href
                  ? 'bg-accent text-accent-foreground'
                  : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
              )}
            >
              <span>{item.icon}</span>
              {item.label}
            </Link>
          ))}
        </nav>
      </aside>

      {/* Mobile sidebar overlay */}
      {mobileMenuOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 md:hidden"
          onClick={() => setMobileMenuOpen(false)}
        >
          <aside
            className="h-full w-64 bg-card shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex h-14 items-center justify-between border-b border-border px-4">
              <h1 className="text-lg font-semibold">{tenant ? tenant.name : 'WebCalendar'}</h1>
              <button
                onClick={() => setMobileMenuOpen(false)}
                className="rounded-md p-1 text-muted-foreground hover:bg-accent"
                aria-label="Close menu"
              >
                ✕
              </button>
            </div>
            <nav className="space-y-1 p-3">
              {navItems.map((item) => (
                <Link
                  key={item.href}
                  to={item.href}
                  onClick={() => setMobileMenuOpen(false)}
                  className={cn(
                    'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    location.pathname === item.href
                      ? 'bg-accent text-accent-foreground'
                      : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                  )}
                >
                  <span>{item.icon}</span>
                  {item.label}
                </Link>
              ))}
            </nav>
            <div className="border-t border-border p-3">
              <button
                onClick={() => { setMobileMenuOpen(false); logout(); }}
                className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent"
              >
                Log out
              </button>
            </div>
          </aside>
        </div>
      )}

      {/* Main content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Header */}
        <header className="flex h-14 items-center justify-between border-b border-border px-4 md:px-6">
          <div className="flex items-center gap-2 md:hidden">
            <button
              onClick={() => setMobileMenuOpen(true)}
              className="inline-flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
              aria-label="Open menu"
            >
              <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M3 5h14M3 10h14M3 15h14" />
              </svg>
            </button>
            <h1 className="text-lg font-semibold">{tenant ? tenant.name : 'WebCalendar'}</h1>
          </div>

          <div className="hidden md:block">
            <SearchBar />
          </div>

          <div className="flex items-center gap-3">
            <ThemeToggle />
            {user && (
              <span className="hidden text-sm text-muted-foreground sm:inline">
                {user.login}
              </span>
            )}
            <button
              onClick={logout}
              className="hidden items-center rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground md:inline-flex md:h-8"
            >
              Log out
            </button>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-auto p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
