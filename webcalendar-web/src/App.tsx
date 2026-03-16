import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './auth/AuthProvider';
import { LoginPage } from './auth/LoginPage';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { AppLayout } from './components/layout/AppLayout';
import { CalendarPage } from './calendar/CalendarPage';
import { UserManagement } from './admin/UserManagement';
import { CategoryManagement } from './admin/CategoryManagement';
import { NotFound } from './pages/NotFound';

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route
          path="/"
          element={
            <ProtectedRoute>
              <AppLayout>
                <CalendarPage />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/calendar"
          element={<Navigate to="/" replace />}
        />
        <Route
          path="/admin/users"
          element={
            <ProtectedRoute>
              <AppLayout>
                <UserManagement />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/admin/categories"
          element={
            <ProtectedRoute>
              <AppLayout>
                <CategoryManagement />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="*"
          element={
            <ProtectedRoute>
              <AppLayout>
                <NotFound />
              </AppLayout>
            </ProtectedRoute>
          }
        />
      </Routes>
    </AuthProvider>
  );
}
