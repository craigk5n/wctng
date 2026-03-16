import { Navigate } from 'react-router-dom';
import { isControlAuthenticated } from './control-auth';

export function ControlProtectedRoute({ children }: { children: React.ReactNode }) {
  if (!isControlAuthenticated()) {
    return <Navigate to="/control/login" replace />;
  }

  return <>{children}</>;
}
