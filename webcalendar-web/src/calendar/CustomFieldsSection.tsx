import { useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface FieldDef {
  id: number;
  name: string;
  field_type: string;
  required: boolean;
  options: string[];
}

interface CustomFieldsSectionProps {
  values: Record<string, string>;
  onChange: (values: Record<string, string>) => void;
}

export function CustomFieldsSection({ values, onChange }: CustomFieldsSectionProps) {
  const [fields, setFields] = useState<FieldDef[]>([]);

  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<FieldDef[]>('/custom-fields');
      setFields(data ?? []);
    })();
  }, []);

  if (fields.length === 0) return null;

  const handleChange = (name: string, value: string) => {
    onChange({ ...values, [name]: value });
  };

  return (
    <div className="space-y-3">
      <span className="text-sm font-medium">Custom Fields</span>
      {fields.map((field) => (
        <div key={field.id} className="space-y-1">
          <label htmlFor={`cf-${field.id}`} className="text-xs font-medium text-muted-foreground">
            {field.name}
            {field.required && <span className="text-destructive"> *</span>}
          </label>

          {field.field_type === 'select' ? (
            <select
              id={`cf-${field.id}`}
              value={values[field.name] ?? ''}
              onChange={(e) => handleChange(field.name, e.target.value)}
              required={field.required}
              className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="">Select...</option>
              {field.options.map((opt) => (
                <option key={opt} value={opt}>{opt}</option>
              ))}
            </select>
          ) : field.field_type === 'checkbox' ? (
            <div className="flex items-center gap-2">
              <input
                id={`cf-${field.id}`}
                type="checkbox"
                checked={values[field.name] === 'true'}
                onChange={(e) => handleChange(field.name, e.target.checked ? 'true' : 'false')}
                className="h-4 w-4"
              />
              <label htmlFor={`cf-${field.id}`} className="text-sm">{field.name}</label>
            </div>
          ) : (
            <input
              id={`cf-${field.id}`}
              type={field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : 'text'}
              value={values[field.name] ?? ''}
              onChange={(e) => handleChange(field.name, e.target.value)}
              required={field.required}
              className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
          )}
        </div>
      ))}
    </div>
  );
}
