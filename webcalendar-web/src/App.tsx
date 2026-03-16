import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './auth/AuthProvider';
import { LoginPage } from './auth/LoginPage';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { AppLayout } from './components/layout/AppLayout';
import { CalendarPage } from './calendar/CalendarPage';
import { UserManagement } from './admin/UserManagement';
import { CategoryManagement } from './admin/CategoryManagement';
import { GroupManagement } from './admin/GroupManagement';
import { PreferencesPage } from './settings/PreferencesPage';
import { AccessSettings } from './settings/AccessSettings';
import { TasksPage } from './tasks/TasksPage';
import { JournalsPage } from './journals/JournalsPage';
import { NotFound } from './pages/NotFound';
import { ControlLoginPage } from './control/ControlLoginPage';
import { ControlProtectedRoute } from './control/ControlProtectedRoute';
import { ControlLayout } from './control/ControlLayout';
import { TenantsPage } from './control/TenantsPage';
import { StatsPage } from './control/StatsPage';

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
          path="/admin/groups"
          element={
            <ProtectedRoute>
              <AppLayout>
                <GroupManagement />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/preferences"
          element={
            <ProtectedRoute>
              <AppLayout>
                <PreferencesPage />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings/access"
          element={
            <ProtectedRoute>
              <AppLayout>
                <AccessSettings />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/tasks"
          element={
            <ProtectedRoute>
              <AppLayout>
                <TasksPage />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/journals"
          element={
            <ProtectedRoute>
              <AppLayout>
                <JournalsPage />
              </AppLayout>
            </ProtectedRoute>
          }
        />
        {/* Control Plane */}
        <Route path="/control/login" element={<ControlLoginPage />} />
        <Route
          path="/control"
          element={
            <ControlProtectedRoute>
              <ControlLayout>
                <TenantsPage />
              </ControlLayout>
            </ControlProtectedRoute>
          }
        />
        <Route
          path="/control/stats"
          element={
            <ControlProtectedRoute>
              <ControlLayout>
                <StatsPage />
              </ControlLayout>
            </ControlProtectedRoute>
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
