import { Link, useLocation, useNavigate } from 'react-router-dom';
import { clearControlAuth, getControlUser } from './control-auth';
import { cn } from '../lib/utils';

const navItems = [
  { label: 'Tenants', href: '/control', icon: '🏢' },
  { label: 'Stats', href: '/control/stats', icon: '📊' },
];

export function ControlLayout({ children }: { children: React.ReactNode }) {
  const location = useLocation();
  const navigate = useNavigate();
  const user = getControlUser();

  const handleLogout = () => {
    clearControlAuth();
    navigate('/control/login');
  };

  return (
    <div className="flex h-screen bg-background">
      {/* Sidebar */}
      <aside className="w-56 flex-shrink-0 border-r border-border bg-card">
        <div className="flex h-14 items-center border-b border-border px-4">
          <h1 className="text-lg font-semibold">Control Panel</h1>
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
        <header className="flex h-14 items-center justify-end border-b border-border px-6">
          {user && (
            <span className="mr-3 text-sm text-muted-foreground">{user.username}</span>
          )}
          <button
            onClick={handleLogout}
            className="inline-flex h-8 items-center rounded-md px-3 text-sm font-medium text-muted-foreground hover:bg-accent"
          >
            Log out
          </button>
        </header>
        <main className="flex-1 overflow-auto p-6">{children}</main>
      </div>
    </div>
  );
}
