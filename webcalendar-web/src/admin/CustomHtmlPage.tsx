import { useCallback, useEffect, useState } from 'react';
import { apiFetch } from '../api/client';
import { useToast } from '../components/toast/ToastProvider';

interface CustomHtmlConfig {
  header_html: string;
  trailer_html: string;
  custom_css: string;
}

export function CustomHtmlPage() {
  const [config, setConfig] = useState<CustomHtmlConfig>({
    header_html: '',
    trailer_html: '',
    custom_css: '',
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showPreview, setShowPreview] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    void (async () => {
      const { data } = await apiFetch<CustomHtmlConfig>('/admin/custom-html');
      if (data) setConfig(data);
      setLoading(false);
    })();
  }, []);

  const handleSave = useCallback(async () => {
    setSaving(true);
    const { data, error } = await apiFetch<CustomHtmlConfig>('/admin/custom-html', {
      method: 'PUT',
      body: JSON.stringify(config),
    });
    setSaving(false);

    if (data && !error) {
      setConfig(data);
      toast({ title: 'Custom HTML/CSS saved', variant: 'success' });
    } else {
      toast({ title: 'Failed to save', variant: 'error' });
    }
  }, [config, toast]);

  if (loading) {
    return <p className="text-muted-foreground">Loading...</p>;
  }

  return (
    <div>
      <h2 className="text-2xl font-bold">Custom Header, Trailer &amp; CSS</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Add custom HTML to the page header and footer, and custom CSS for branding.
        Scripts and iframes are stripped for security.
      </p>

      <div className="mt-6 max-w-2xl space-y-6">
        <div>
          <label htmlFor="header-html" className="block text-sm font-medium">
            Header HTML
          </label>
          <p className="text-xs text-muted-foreground mb-1">
            Displayed at the top of every page. Supports links, images, divs, nav elements.
          </p>
          <textarea
            id="header-html"
            rows={5}
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
            value={config.header_html}
            onChange={(e) => setConfig((prev) => ({ ...prev, header_html: e.target.value }))}
            placeholder='<div class="my-header">My Calendar</div>'
          />
        </div>

        <div>
          <label htmlFor="trailer-html" className="block text-sm font-medium">
            Trailer / Footer HTML
          </label>
          <p className="text-xs text-muted-foreground mb-1">
            Displayed at the bottom of every page.
          </p>
          <textarea
            id="trailer-html"
            rows={5}
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
            value={config.trailer_html}
            onChange={(e) => setConfig((prev) => ({ ...prev, trailer_html: e.target.value }))}
            placeholder='<footer>© 2026 My Organization</footer>'
          />
        </div>

        <div>
          <label htmlFor="custom-css" className="block text-sm font-medium">
            Custom CSS
          </label>
          <p className="text-xs text-muted-foreground mb-1">
            Custom styles applied globally. Use class selectors for specificity.
          </p>
          <textarea
            id="custom-css"
            rows={8}
            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
            value={config.custom_css}
            onChange={(e) => setConfig((prev) => ({ ...prev, custom_css: e.target.value }))}
            placeholder={'.my-header { background: #3788d8; color: white; padding: 1rem; }'}
          />
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={() => void handleSave()}
            disabled={saving}
            className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {saving ? 'Saving...' : 'Save Changes'}
          </button>
          <button
            onClick={() => setShowPreview(!showPreview)}
            className="inline-flex h-9 items-center rounded-md border border-input px-4 text-sm font-medium hover:bg-accent"
          >
            {showPreview ? 'Hide Preview' : 'Show Preview'}
          </button>
        </div>

        {showPreview && (
          <div className="rounded-lg border border-border p-4 space-y-4" data-testid="custom-html-preview">
            <h3 className="text-sm font-medium text-muted-foreground">Preview</h3>

            {config.custom_css && (
              <style>{config.custom_css}</style>
            )}

            {config.header_html && (
              <div className="rounded border border-dashed border-blue-300 p-2">
                <span className="text-xs text-blue-500 block mb-1">Header</span>
                <div dangerouslySetInnerHTML={{ __html: config.header_html }} />
              </div>
            )}

            <div className="rounded border border-dashed border-gray-300 p-2">
              <span className="text-xs text-gray-400">[ Page content ]</span>
            </div>

            {config.trailer_html && (
              <div className="rounded border border-dashed border-blue-300 p-2">
                <span className="text-xs text-blue-500 block mb-1">Trailer</span>
                <div dangerouslySetInnerHTML={{ __html: config.trailer_html }} />
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
