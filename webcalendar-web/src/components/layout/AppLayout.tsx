import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../auth/auth-context';
import { cn } from '../../lib/utils';
import { SearchBar } from '../search/SearchBar';
import { ThemeToggle } from '../theme/ThemeToggle';
import { LanguageSelector } from '../i18n/LanguageSelector';
import { useTenant } from '../../hooks/useTenant';
import { CustomHeader, CustomTrailer, CustomCssInjector } from './CustomHtmlInjector';

export function AppLayout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  const location = useLocation();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() =>
    localStorage.getItem('wctng_sidebar_collapsed') === 'true',
  );

  const toggleSidebar = () => {
    setSidebarCollapsed((prev) => {
      localStorage.setItem('wctng_sidebar_collapsed', String(!prev));
      return !prev;
    });
  };
  const { tenant } = useTenant();

  const mainNav = [
    { label: 'Calendar', href: '/', icon: '📅' },
    { label: 'Tasks', href: '/tasks', icon: '✅' },
    { label: 'Journals', href: '/journals', icon: '📓' },
    { label: 'Reports', href: '/reports', icon: '📊' },
  ];

  const settingsNav = [
    { label: 'Preferences', href: '/settings/preferences', icon: '⚙' },
    { label: 'Profile', href: '/settings/profile', icon: '👤' },
    { label: 'Notifications', href: '/settings/notifications', icon: '🔔' },
    { label: 'Sharing', href: '/settings/sharing', icon: '🔗' },
    { label: 'Subscriptions', href: '/settings/subscriptions', icon: '📡' },
    { label: 'Assistants', href: '/settings/assistants', icon: '🤝' },
    { label: 'Access', href: '/settings/access', icon: '🔒' },
    { label: 'API Tokens', href: '/settings/api-tokens', icon: '🔑' },
  ];

  const adminNav = user?.is_admin ? [
    { label: 'Dashboard', href: '/admin/dashboard', icon: '📊' },
    { label: 'Users', href: '/admin/users', icon: '👤' },
    { label: 'Categories', href: '/admin/categories', icon: '🏷' },
    { label: 'Groups', href: '/admin/groups', icon: '👥' },
    { label: 'Resources', href: '/admin/resources', icon: '🏢' },
    { label: 'Custom Fields', href: '/admin/custom-fields', icon: '📝' },
    { label: 'Webhooks', href: '/admin/webhooks', icon: '🔗' },
    { label: 'Activity Log', href: '/admin/activity-log', icon: '📋' },
    { label: 'Custom HTML/CSS', href: '/admin/custom-html', icon: '🎨' },
    { label: 'Settings', href: '/admin/settings', icon: '🔧' },
  ] : [];


  const renderNavLinks = (items: typeof mainNav, onClick?: () => void, collapsed = false) =>
    items.map((item) => (
      <Link
        key={item.href}
        to={item.href}
        onClick={onClick}
        title={collapsed ? item.label : undefined}
        className={cn(
          'flex items-center rounded-md text-sm font-medium transition-colors',
          collapsed ? 'justify-center px-2 py-2' : 'gap-2 px-3 py-1.5',
          location.pathname === item.href
            ? 'bg-accent text-accent-foreground'
            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        )}
      >
        <span className="text-xs">{item.icon}</span>
        {!collapsed && item.label}
      </Link>
    ));

  return (
    <div className="flex h-screen bg-background">
      {/* Desktop Sidebar */}
      <aside className={cn(
        'hidden flex-shrink-0 overflow-y-auto border-r border-border bg-card transition-all md:block',
        sidebarCollapsed ? 'w-14' : 'w-56',
      )}>
        <div className="flex h-14 items-center justify-between border-b border-border px-3">
          {!sidebarCollapsed && (
            <h1 className="text-lg font-semibold truncate">{tenant ? tenant.name : 'WebCalendar'}</h1>
          )}
          <button
            onClick={toggleSidebar}
            className="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
            title={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            {sidebarCollapsed ? '▶' : '◀'}
          </button>
        </div>
        <nav className="space-y-1 p-2">
          {renderNavLinks(mainNav, undefined, sidebarCollapsed)}
          {!sidebarCollapsed && (
            <div className="pt-2">
              <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/60">Settings</p>
              {renderNavLinks(settingsNav, undefined, sidebarCollapsed)}
            </div>
          )}
          {sidebarCollapsed && renderNavLinks(settingsNav, undefined, true)}
          {adminNav.length > 0 && !sidebarCollapsed && (
            <div className="pt-2">
              <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/60">Admin</p>
              {renderNavLinks(adminNav, undefined, sidebarCollapsed)}
            </div>
          )}
          {adminNav.length > 0 && sidebarCollapsed && renderNavLinks(adminNav, undefined, true)}
        </nav>
      </aside>

      {/* Mobile sidebar overlay */}
      {mobileMenuOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 md:hidden"
          onClick={() => setMobileMenuOpen(false)}
        >
          <aside
            className="h-full w-64 overflow-y-auto bg-card shadow-lg"
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
              {renderNavLinks(mainNav, () => setMobileMenuOpen(false))}
              <div className="pt-2">
                <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/60">Settings</p>
                {renderNavLinks(settingsNav, () => setMobileMenuOpen(false))}
              </div>
              {adminNav.length > 0 && (
                <div className="pt-2">
                  <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/60">Admin</p>
                  {renderNavLinks(adminNav, () => setMobileMenuOpen(false))}
                </div>
              )}
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
            <LanguageSelector />
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

        {/* Custom header */}
        <CustomHeader />
        <CustomCssInjector />

        {/* Page content */}
        <main className="flex-1 overflow-auto p-4 md:p-6">{children}</main>

        {/* Custom trailer */}
        <CustomTrailer />
      </div>
    </div>
  );
}
