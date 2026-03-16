import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../auth/auth-context';
import { cn } from '../../lib/utils';
import { SearchBar } from '../search/SearchBar';
import { ThemeToggle } from '../theme/ThemeToggle';

export function AppLayout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const location = useLocation();

  const navItems = [
    { label: 'Calendar', href: '/', icon: '📅' },
    { label: 'Settings', href: '/settings/preferences', icon: '⚙' },
  ];

  if (user?.is_admin) {
    navItems.push({ label: 'Users', href: '/admin/users', icon: '👤' });
    navItems.push({ label: 'Categories', href: '/admin/categories', icon: '🏷' });
  }

  return (
    <div className="flex h-screen bg-background">
      {/* Sidebar */}
      <aside className="hidden w-56 flex-shrink-0 border-r border-border bg-card md:block">
        <div className="flex h-14 items-center border-b border-border px-4">
          <h1 className="text-lg font-semibold">WebCalendar</h1>
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

      {/* Main content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Header */}
        <header className="flex h-14 items-center justify-between border-b border-border px-4 md:px-6">
          <div className="flex items-center gap-2 md:hidden">
            <h1 className="text-lg font-semibold">WebCalendar</h1>
          </div>

          {/* Mobile nav */}
          <nav className="flex gap-3 md:hidden">
            {navItems.map((item) => (
              <Link
                key={item.href}
                to={item.href}
                className={cn(
                  'text-sm font-medium',
                  location.pathname === item.href
                    ? 'text-foreground'
                    : 'text-muted-foreground',
                )}
              >
                {item.label}
              </Link>
            ))}
          </nav>

          <div className="hidden md:block">
            <SearchBar />
          </div>

          <div className="flex items-center gap-3">
            <ThemeToggle />
            {user && (
              <span className="text-sm text-muted-foreground">
                {user.login}
              </span>
            )}
            <button
              onClick={logout}
              className="inline-flex h-8 items-center rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
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
