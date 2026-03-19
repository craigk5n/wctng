import { createContext, useCallback, useContext, useState } from 'react';

export interface ToastMessage {
  id: string;
  title: string;
  variant?: 'success' | 'error' | 'default';
}

interface ToastContextValue {
  toast: (msg: Omit<ToastMessage, 'id'>) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

const TOAST_DURATION = 3000;

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<ToastMessage[]>([]);

  const removeToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const toast = useCallback(
    (msg: Omit<ToastMessage, 'id'>) => {
      const id = Date.now().toString() + Math.random().toString(36).slice(2);
      const newToast: ToastMessage = { ...msg, id };
      setToasts((prev) => [...prev, newToast]);

      setTimeout(() => removeToast(id), TOAST_DURATION);
    },
    [removeToast],
  );

  const variantStyles: Record<string, string> = {
    success: 'bg-green-600 text-white',
    error: 'bg-destructive text-destructive-foreground',
    default: 'bg-primary text-primary-foreground',
  };

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}

      {/* Toast container */}
      <div className="fixed bottom-4 right-4 z-[100] flex flex-col gap-2" role="region" aria-live="assertive" aria-atomic="true" aria-label="Notifications">
        {toasts.map((t) => (
          <div
            key={t.id}
            role="alert"
            className={`animate-in slide-in-from-right fade-in rounded-md px-4 py-3 text-sm font-medium shadow-lg ${variantStyles[t.variant ?? 'default']}`}
          >
            {t.title}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastContextValue {
  const context = useContext(ToastContext);
  if (!context) {
    throw new Error('useToast must be used within a ToastProvider');
  }
  return context;
}
