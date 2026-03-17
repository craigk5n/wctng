import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useAuth } from '../auth/auth-context';
import { useToast } from '../components/toast/ToastProvider';

interface UserProfile {
  login: string;
  firstname: string;
  lastname: string;
  email: string;
  is_admin: boolean;
}

export function ProfileSettings() {
  const { user } = useAuth();
  const { toast } = useToast();
  const login = user?.login ?? '';

  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [email, setEmail] = useState('');
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [loading, setLoading] = useState(true);
  const [savingProfile, setSavingProfile] = useState(false);
  const [savingPassword, setSavingPassword] = useState(false);

  useEffect(() => {
    if (!login) return;
    void (async () => {
      const { data } = await apiFetch<UserProfile>(`/users/${login}`);
      if (data) {
        setFirstName(data.firstname);
        setLastName(data.lastname);
        setEmail(data.email);
      }
      setLoading(false);
    })();
  }, [login]);

  const handleSaveProfile = useCallback(async () => {
    if (!login) return;
    setSavingProfile(true);

    const { error } = await apiFetch(`/users/${login}`, {
      method: 'PUT',
      body: JSON.stringify({
        firstname: firstName,
        lastname: lastName,
        email,
      }),
    });

    setSavingProfile(false);
    if (!error) {
      toast({ title: 'Profile updated', variant: 'success' });
    } else {
      toast({ title: error.message || 'Failed to update profile', variant: 'error' });
    }
  }, [login, firstName, lastName, email, toast]);

  const handleChangePassword = useCallback(async () => {
    if (!login) return;
    if (newPassword !== confirmPassword) {
      toast({ title: 'Passwords do not match', variant: 'error' });
      return;
    }
    if (newPassword.length < 6) {
      toast({ title: 'Password must be at least 6 characters', variant: 'error' });
      return;
    }

    setSavingPassword(true);

    const { error } = await apiFetch(`/users/${login}/password`, {
      method: 'PUT',
      body: JSON.stringify({
        current_password: currentPassword,
        new_password: newPassword,
      }),
    });

    setSavingPassword(false);
    if (!error) {
      toast({ title: 'Password changed', variant: 'success' });
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } else {
      toast({ title: error.message || 'Failed to change password', variant: 'error' });
    }
  }, [login, currentPassword, newPassword, confirmPassword, toast]);

  if (loading) {
    return <p className="text-muted-foreground">Loading...</p>;
  }

  return (
    <div className="space-y-8">
      <div>
        <h2 className="text-2xl font-bold">Profile</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Update your account information.
        </p>
      </div>

      {/* Profile info */}
      <div className="max-w-lg space-y-4">
        <div className="space-y-2">
          <label className="text-sm font-medium text-muted-foreground">Username</label>
          <p className="text-sm font-medium">{login}</p>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <label htmlFor="profile-first" className="text-sm font-medium">First Name</label>
            <input
              id="profile-first"
              type="text"
              value={firstName}
              onChange={(e) => setFirstName(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            />
          </div>
          <div className="space-y-2">
            <label htmlFor="profile-last" className="text-sm font-medium">Last Name</label>
            <input
              id="profile-last"
              type="text"
              value={lastName}
              onChange={(e) => setLastName(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            />
          </div>
        </div>

        <div className="space-y-2">
          <label htmlFor="profile-email" className="text-sm font-medium">Email</label>
          <input
            id="profile-email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          />
        </div>

        <button
          onClick={handleSaveProfile}
          disabled={savingProfile}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
        >
          {savingProfile ? 'Saving...' : 'Save Profile'}
        </button>
      </div>

      {/* Password change */}
      <div className="max-w-lg space-y-4 border-t pt-6">
        <h3 className="text-lg font-semibold">Change Password</h3>

        <div className="space-y-2">
          <label htmlFor="current-pw" className="text-sm font-medium">Current Password</label>
          <input
            id="current-pw"
            type="password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          />
        </div>

        <div className="space-y-2">
          <label htmlFor="new-pw" className="text-sm font-medium">New Password</label>
          <input
            id="new-pw"
            type="password"
            value={newPassword}
            onChange={(e) => setNewPassword(e.target.value)}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          />
        </div>

        <div className="space-y-2">
          <label htmlFor="confirm-pw" className="text-sm font-medium">Confirm New Password</label>
          <input
            id="confirm-pw"
            type="password"
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
          />
        </div>

        <button
          onClick={handleChangePassword}
          disabled={savingPassword || !currentPassword || !newPassword}
          className="inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent disabled:opacity-50"
        >
          {savingPassword ? 'Changing...' : 'Change Password'}
        </button>
      </div>
    </div>
  );
}
