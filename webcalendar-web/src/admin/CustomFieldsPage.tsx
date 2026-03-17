import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface CustomField {
  id: number;
  name: string;
  field_type: string;
  required: boolean;
  sort_order: number;
  options: string[];
}

const FIELD_TYPES = ['text', 'number', 'date', 'select', 'checkbox'];

export function CustomFieldsPage() {
  const [fields, setFields] = useState<CustomField[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [newName, setNewName] = useState('');
  const [newType, setNewType] = useState('text');
  const [newRequired, setNewRequired] = useState(false);
  const [newOptions, setNewOptions] = useState('');
  const [saving, setSaving] = useState(false);

  const fetchFields = useCallback(async () => {
    const { data } = await apiFetch<CustomField[]>('/admin/custom-fields');
    setFields(data ?? []);
    setLoading(false);
  }, []);

  useEffect(() => {
    void fetchFields();
  }, [fetchFields]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newName.trim()) return;
    setSaving(true);

    const body: Record<string, unknown> = {
      name: newName.trim(),
      field_type: newType,
      required: newRequired,
      sort_order: fields.length,
    };

    if (newType === 'select' && newOptions.trim()) {
      body.options = newOptions.split(',').map((o) => o.trim()).filter(Boolean);
    }

    const { error } = await apiFetch('/admin/custom-fields', {
      method: 'POST',
      body: JSON.stringify(body),
    });

    setSaving(false);
    if (!error) {
      setNewName('');
      setNewType('text');
      setNewRequired(false);
      setNewOptions('');
      setShowCreate(false);
      void fetchFields();
    }
  };

  const handleDelete = async (id: number, name: string) => {
    if (!confirm(`Delete custom field "${name}"? This will remove all stored values for this field.`)) return;
    await apiFetch(`/admin/custom-fields/${id}`, { method: 'DELETE' });
    void fetchFields();
  };

  return (
    <div>
      <div className="flex items-center justify-between">
        <h2 className="text-2xl font-bold">Custom Fields</h2>
        <button
          onClick={() => setShowCreate(!showCreate)}
          className="rounded bg-primary px-4 py-2 text-sm text-primary-foreground hover:bg-primary/90"
        >
          Add Field
        </button>
      </div>

      <p className="mt-2 text-sm text-muted-foreground">
        Define custom fields that appear on event create/edit forms.
      </p>

      {/* Create form */}
      {showCreate && (
        <form onSubmit={handleCreate} className="mt-4 space-y-3 rounded-lg border p-4">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <label htmlFor="cf-name" className="text-sm font-medium">Field Name</label>
              <input
                id="cf-name"
                type="text"
                required
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                placeholder="e.g. Department"
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="cf-type" className="text-sm font-medium">Field Type</label>
              <select
                id="cf-type"
                value={newType}
                onChange={(e) => setNewType(e.target.value)}
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              >
                {FIELD_TYPES.map((t) => (
                  <option key={t} value={t}>{t}</option>
                ))}
              </select>
            </div>
          </div>

          {newType === 'select' && (
            <div className="space-y-1">
              <label htmlFor="cf-options" className="text-sm font-medium">Options (comma-separated)</label>
              <input
                id="cf-options"
                type="text"
                value={newOptions}
                onChange={(e) => setNewOptions(e.target.value)}
                className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                placeholder="Option 1, Option 2, Option 3"
              />
            </div>
          )}

          <div className="flex items-center gap-2">
            <input
              id="cf-required"
              type="checkbox"
              checked={newRequired}
              onChange={(e) => setNewRequired(e.target.checked)}
              className="h-4 w-4"
            />
            <label htmlFor="cf-required" className="text-sm">Required</label>
          </div>

          <div className="flex gap-2">
            <button
              type="submit"
              disabled={saving}
              className="rounded bg-primary px-4 py-1.5 text-sm text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
            >
              {saving ? 'Saving...' : 'Create'}
            </button>
            <button
              type="button"
              onClick={() => setShowCreate(false)}
              className="rounded border px-4 py-1.5 text-sm hover:bg-accent"
            >
              Cancel
            </button>
          </div>
        </form>
      )}

      {/* Field list */}
      <div className="mt-4">
        {loading && <p className="text-sm text-muted-foreground">Loading...</p>}

        {!loading && fields.length === 0 && (
          <p className="text-sm text-muted-foreground">No custom fields defined yet.</p>
        )}

        {fields.length > 0 && (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                <th className="pb-2 pr-4">Name</th>
                <th className="pb-2 pr-4">Type</th>
                <th className="pb-2 pr-4">Required</th>
                <th className="pb-2 pr-4">Options</th>
                <th className="pb-2"></th>
              </tr>
            </thead>
            <tbody>
              {fields.map((field) => (
                <tr key={field.id} className="border-b border-border/50">
                  <td className="py-2 pr-4 font-medium">{field.name}</td>
                  <td className="py-2 pr-4">
                    <span className="rounded bg-muted px-2 py-0.5 text-xs">{field.field_type}</span>
                  </td>
                  <td className="py-2 pr-4 text-xs">
                    {field.required ? 'Yes' : 'No'}
                  </td>
                  <td className="py-2 pr-4 text-xs text-muted-foreground">
                    {field.options.length > 0 ? field.options.join(', ') : '—'}
                  </td>
                  <td className="py-2 text-right">
                    <button
                      onClick={() => void handleDelete(field.id, field.name)}
                      className="rounded border border-destructive px-2 py-0.5 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground"
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
