import { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './auth/AuthProvider';
import { LoginPage } from './auth/LoginPage';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { AppLayout } from './components/layout/AppLayout';
import { CalendarPage } from './calendar/CalendarPage';

// Lazy-loaded pages for code splitting
const UserManagement = lazy(() => import('./admin/UserManagement').then(m => ({ default: m.UserManagement })));
const CategoryManagement = lazy(() => import('./admin/CategoryManagement').then(m => ({ default: m.CategoryManagement })));
const GroupManagement = lazy(() => import('./admin/GroupManagement').then(m => ({ default: m.GroupManagement })));
const WebhookManagement = lazy(() => import('./admin/WebhookManagement').then(m => ({ default: m.WebhookManagement })));
const ActivityLogPage = lazy(() => import('./admin/ActivityLogPage').then(m => ({ default: m.ActivityLogPage })));
const CustomFieldsPage = lazy(() => import('./admin/CustomFieldsPage').then(m => ({ default: m.CustomFieldsPage })));
const AdminSettingsPage = lazy(() => import('./admin/AdminSettingsPage').then(m => ({ default: m.AdminSettingsPage })));
const ResourceManagement = lazy(() => import('./admin/ResourceManagement').then(m => ({ default: m.ResourceManagement })));
const PreferencesPage = lazy(() => import('./settings/PreferencesPage').then(m => ({ default: m.PreferencesPage })));
const AccessSettings = lazy(() => import('./settings/AccessSettings').then(m => ({ default: m.AccessSettings })));
const AuthSettings = lazy(() => import('./settings/AuthSettings').then(m => ({ default: m.AuthSettings })));
const NotificationSettings = lazy(() => import('./settings/NotificationSettings').then(m => ({ default: m.NotificationSettings })));
const AssistantSettings = lazy(() => import('./settings/AssistantSettings').then(m => ({ default: m.AssistantSettings })));
const ProfileSettings = lazy(() => import('./settings/ProfileSettings').then(m => ({ default: m.ProfileSettings })));
const SubscriptionSettings = lazy(() => import('./settings/SubscriptionSettings').then(m => ({ default: m.SubscriptionSettings })));
const ApiTokenSettings = lazy(() => import('./settings/ApiTokenSettings').then(m => ({ default: m.ApiTokenSettings })));
const TasksPage = lazy(() => import('./tasks/TasksPage').then(m => ({ default: m.TasksPage })));
const JournalsPage = lazy(() => import('./journals/JournalsPage').then(m => ({ default: m.JournalsPage })));
const ReportsPage = lazy(() => import('./reports/ReportsPage').then(m => ({ default: m.ReportsPage })));
const NotFound = lazy(() => import('./pages/NotFound').then(m => ({ default: m.NotFound })));
const ControlLoginPage = lazy(() => import('./control/ControlLoginPage').then(m => ({ default: m.ControlLoginPage })));
const ControlProtectedRoute = lazy(() => import('./control/ControlProtectedRoute').then(m => ({ default: m.ControlProtectedRoute })));
const ControlLayout = lazy(() => import('./control/ControlLayout').then(m => ({ default: m.ControlLayout })));
const TenantsPage = lazy(() => import('./control/TenantsPage').then(m => ({ default: m.TenantsPage })));
const StatsPage = lazy(() => import('./control/StatsPage').then(m => ({ default: m.StatsPage })));
const TenantDetailPage = lazy(() => import('./control/TenantDetailPage').then(m => ({ default: m.TenantDetailPage })));
const SetupWizard = lazy(() => import('./setup/SetupWizard').then(m => ({ default: m.SetupWizard })));
const PollVotingPage = lazy(() => import('./polls/PollVotingPage').then(m => ({ default: m.PollVotingPage })));
const PublicCalendarPage = lazy(() => import('./public/PublicCalendarPage').then(m => ({ default: m.PublicCalendarPage })));
const EmbedCalendarPage = lazy(() => import('./public/EmbedCalendarPage').then(m => ({ default: m.EmbedCalendarPage })));
const BookingPage = lazy(() => import('./public/BookingPage').then(m => ({ default: m.BookingPage })));
const ShareSettings = lazy(() => import('./settings/ShareSettings').then(m => ({ default: m.ShareSettings })));

function LoadingFallback() {
  return <div className="flex h-screen items-center justify-center text-muted-foreground">Loading...</div>;
}

export default function App() {
  return (
    <AuthProvider>
      <Suspense fallback={<LoadingFallback />}>
        <Routes>
          <Route path="/setup" element={<SetupWizard />} />
          <Route path="/login" element={<LoginPage />} />
          {/* Public calendar — no auth required */}
          <Route path="/public/:username" element={<PublicCalendarPage />} />
          <Route path="/public/embed/:token" element={<EmbedCalendarPage />} />
          <Route path="/book/:username" element={<BookingPage />} />
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
            path="/admin/webhooks"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <WebhookManagement />
                </AppLayout>
              </ProtectedRoute>
            }
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
            path="/admin/custom-fields"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <CustomFieldsPage />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/settings"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <AdminSettingsPage />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/resources"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <ResourceManagement />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/admin/activity-log"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <ActivityLogPage />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/settings/notifications"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <NotificationSettings />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/settings/authentication"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <AuthSettings />
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
            path="/settings/sharing"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <ShareSettings />
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
            path="/settings/assistants"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <AssistantSettings />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/settings/profile"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <ProfileSettings />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/settings/subscriptions"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <SubscriptionSettings />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/settings/api-tokens"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <ApiTokenSettings />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/polls/:id"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <PollVotingPage />
                </AppLayout>
              </ProtectedRoute>
            }
          />
          <Route
            path="/reports"
            element={
              <ProtectedRoute>
                <AppLayout>
                  <ReportsPage />
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
            path="/control/tenants/:slug"
            element={
              <ControlProtectedRoute>
                <ControlLayout>
                  <TenantDetailPage />
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
      </Suspense>
    </AuthProvider>
  );
}
