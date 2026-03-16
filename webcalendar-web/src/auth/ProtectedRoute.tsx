import { Navigate } from 'react-router-dom';
import { useAuth } from './auth-context';

/**
 * Wrapper that redirects to /login if the user is not authenticated.
 */
export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuth();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
}
