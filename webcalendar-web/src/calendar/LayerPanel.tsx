import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface ApiLayer {
  id: number;
  source_user: string;
  color: string;
  show_duplicates: boolean;
}

export interface LayerVisibility {
  id: number;
  source_user: string;
  color: string;
  visible: boolean;
}

interface LayerPanelProps {
  onLayersChange: (layers: LayerVisibility[]) => void;
}

export function LayerPanel({ onLayersChange }: LayerPanelProps) {
  const [layers, setLayers] = useState<LayerVisibility[]>([]);
  const [newUser, setNewUser] = useState('');
  const [newColor, setNewColor] = useState('#3788d8');
  const { toast } = useToast();

  const fetchLayers = useCallback(async () => {
    const { data } = await apiFetch<ApiLayer[]>('/layers');
    if (data) {
      setLayers((prev) => {
        // Preserve visibility state for existing layers
        const prevMap = new Map(prev.map((l) => [l.id, l.visible]));
        return data.map((l) => ({
          id: l.id,
          source_user: l.source_user,
          color: l.color,
          visible: prevMap.get(l.id) ?? true,
        }));
      });
    }
  }, []);

  useEffect(() => {
    void fetchLayers();
  }, [fetchLayers]);

  // Notify parent whenever layers change
  useEffect(() => {
    onLayersChange(layers);
  }, [layers, onLayersChange]);

  const toggleVisibility = (id: number) => {
    setLayers((prev) =>
      prev.map((l) => (l.id === id ? { ...l, visible: !l.visible } : l)),
    );
  };

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault();
    const username = newUser.trim();
    if (!username) return;

    const { error } = await apiFetch('/layers', {
      method: 'POST',
      body: JSON.stringify({ source_user: username, color: newColor }),
    });

    if (!error) {
      toast({ title: `Layer for "${username}" added`, variant: 'success' });
      setNewUser('');
      setNewColor('#3788d8');
      void fetchLayers();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  const handleRemove = async (layer: LayerVisibility) => {
    const { error } = await apiFetch(`/layers/${layer.id}`, { method: 'DELETE' });

    if (!error) {
      toast({ title: `Layer for "${layer.source_user}" removed`, variant: 'success' });
      void fetchLayers();
    } else {
      toast({ title: error.message, variant: 'error' });
    }
  };

  return (
    <div className="border-t border-border p-3">
      <h3 className="text-xs font-semibold uppercase text-muted-foreground">Layers</h3>

      {/* Layer list */}
      <div className="mt-2 space-y-1">
        {layers.map((layer) => (
          <div key={layer.id} className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={layer.visible}
              onChange={() => toggleVisibility(layer.id)}
              className="h-3.5 w-3.5 rounded border-input"
            />
            <span
              className="h-3 w-3 rounded-full"
              style={{ backgroundColor: layer.color }}
            />
            <span className="flex-1 truncate text-sm">{layer.source_user}</span>
            <button
              onClick={() => void handleRemove(layer)}
              className="rounded px-1 text-xs text-destructive hover:bg-destructive/10"
              aria-label={`Remove ${layer.source_user}`}
            >
              ✕
            </button>
          </div>
        ))}
      </div>

      {/* Add layer form */}
      <form onSubmit={handleAdd} className="mt-2 flex items-center gap-1">
        <input
          type="text"
          value={newUser}
          onChange={(e) => setNewUser(e.target.value)}
          placeholder="Username"
          className="h-7 flex-1 rounded border border-input bg-background px-2 text-xs"
        />
        <input
          type="color"
          value={newColor}
          onChange={(e) => setNewColor(e.target.value)}
          className="h-7 w-7 cursor-pointer rounded border border-input"
        />
        <button
          type="submit"
          className="h-7 rounded bg-primary px-2 text-xs text-primary-foreground hover:bg-primary/90"
        >
          Add
        </button>
      </form>
    </div>
  );
}
