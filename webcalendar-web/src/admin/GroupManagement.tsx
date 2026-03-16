import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface Group {
  id: number;
  name: string;
  owner: string;
}

interface GroupDetail extends Group {
  members: string[];
}

export function GroupManagement() {
  const [groups, setGroups] = useState<Group[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [newName, setNewName] = useState('');
  const [isCreating, setIsCreating] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState<GroupDetail | null>(null);
  const [newMember, setNewMember] = useState('');
  const { toast } = useToast();

  const fetchGroups = useCallback(async () => {
    setIsLoading(true);
    const { data } = await apiFetch<Group[]>('/groups');
    setGroups(data ?? []);
    setIsLoading(false);
  }, []);

  useEffect(() => {
    void fetchGroups();
  }, [fetchGroups]);

  const fetchGroupDetail = useCallback(async (id: number) => {
    const { data } = await apiFetch<GroupDetail>(`/groups/${id}`);
    if (data) {
      setSelectedGroup(data);
    }
  }, []);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newName.trim()) return;
    setIsCreating(true);

    const { error } = await apiFetch('/groups', {
      method: 'POST',
      body: JSON.stringify({ name: newName.trim() }),
    });

    setIsCreating(false);

    if (!error) {
      toast({ title: `Group "${newName}" created`, variant: 'success' });
      setNewName('');
      setShowCreateForm(false);
      void fetchGroups();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleDelete = async (group: Group) => {
    const { error } = await apiFetch(`/groups/${group.id}`, { method: 'DELETE' });

    if (!error) {
      toast({ title: `Group "${group.name}" deleted`, variant: 'success' });
      if (selectedGroup?.id === group.id) {
        setSelectedGroup(null);
      }
      void fetchGroups();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleAddMember = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedGroup || !newMember.trim()) return;

    const { error } = await apiFetch(`/groups/${selectedGroup.id}/members`, {
      method: 'POST',
      body: JSON.stringify({ users: [newMember.trim()] }),
    });

    if (!error) {
      toast({ title: `Member "${newMember}" added`, variant: 'success' });
      setNewMember('');
      void fetchGroupDetail(selectedGroup.id);
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleRemoveMember = async (login: string) => {
    if (!selectedGroup) return;

    const { error } = await apiFetch(`/groups/${selectedGroup.id}/members/${login}`, {
      method: 'DELETE',
    });

    if (!error) {
      toast({ title: `Member "${login}" removed`, variant: 'success' });
      void fetchGroupDetail(selectedGroup.id);
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Groups</h2>
        <button
          onClick={() => setShowCreateForm(!showCreateForm)}
          className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          {showCreateForm ? 'Cancel' : '+ New Group'}
        </button>
      </div>

      {/* Create Form */}
      {showCreateForm && (
        <form onSubmit={handleCreate} className="mt-4 flex items-end gap-3 rounded-lg border border-border p-4">
          <div className="flex-1 space-y-1">
            <label htmlFor="group-name" className="text-sm font-medium">Name</label>
            <input
              id="group-name"
              type="text"
              required
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              placeholder="Group name"
            />
          </div>
          <button
            type="submit"
            disabled={isCreating}
            className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {isCreating ? 'Creating...' : 'Create'}
          </button>
        </form>
      )}

      <div className="mt-6 flex gap-6">
        {/* Group List */}
        <div className="flex-1 space-y-2">
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading...</p>
          ) : groups.length === 0 ? (
            <p className="text-sm text-muted-foreground">No groups yet. Create one to get started.</p>
          ) : (
            groups.map((group) => (
              <div
                key={group.id}
                className={`flex items-center justify-between rounded-lg border px-4 py-3 cursor-pointer transition-colors ${
                  selectedGroup?.id === group.id
                    ? 'border-primary bg-accent'
                    : 'border-border hover:bg-muted/30'
                }`}
              >
                <button
                  type="button"
                  className="flex-1 text-left"
                  onClick={() => void fetchGroupDetail(group.id)}
                >
                  <span className="font-medium">{group.name}</span>
                  <span className="ml-2 text-xs text-muted-foreground">owner: {group.owner}</span>
                </button>
                <button
                  onClick={() => void handleDelete(group)}
                  className="rounded px-2 py-1 text-xs text-destructive hover:bg-destructive/10"
                >
                  Delete
                </button>
              </div>
            ))
          )}
        </div>

        {/* Member Panel */}
        {selectedGroup && (
          <div className="w-72 rounded-lg border border-border p-4">
            <h3 className="text-lg font-semibold">{selectedGroup.name}</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              {selectedGroup.members.length} member{selectedGroup.members.length !== 1 ? 's' : ''}
            </p>

            {/* Add member form */}
            <form onSubmit={handleAddMember} className="mt-3 flex gap-2">
              <input
                type="text"
                value={newMember}
                onChange={(e) => setNewMember(e.target.value)}
                placeholder="Add member username"
                className="flex h-8 flex-1 rounded-md border border-input bg-background px-2 text-sm"
              />
              <button
                type="submit"
                className="inline-flex h-8 items-center rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground hover:bg-primary/90"
              >
                Add
              </button>
            </form>

            {/* Member list */}
            <ul className="mt-3 space-y-1">
              {selectedGroup.members.map((login) => (
                <li
                  key={login}
                  className="flex items-center justify-between rounded px-2 py-1.5 text-sm hover:bg-muted/30"
                >
                  <span>{login}</span>
                  <button
                    onClick={() => void handleRemoveMember(login)}
                    className="rounded px-1.5 py-0.5 text-xs text-destructive hover:bg-destructive/10"
                    aria-label={`Remove ${login}`}
                  >
                    ✕
                  </button>
                </li>
              ))}
              {selectedGroup.members.length === 0 && (
                <li className="px-2 py-1.5 text-xs text-muted-foreground">No members yet</li>
              )}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}
