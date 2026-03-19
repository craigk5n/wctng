import { useEffect, useState } from 'react';
import { apiFetch } from '../api/client';

interface SecurityCheck {
  category: string;
  name: string;
  status: 'pass' | 'warn' | 'fail' | 'info';
  detail: string;
}

interface AuditResult {
  checks: SecurityCheck[];
  summary: { pass: number; warn: number; fail: number; total: number };
}

const STATUS_STYLES: Record<string, { bg: string; text: string; label: string }> = {
  pass: { bg: 'bg-green-100 dark:bg-green-900/30', text: 'text-green-800 dark:text-green-300', label: 'Pass' },
  warn: { bg: 'bg-yellow-100 dark:bg-yellow-900/30', text: 'text-yellow-800 dark:text-yellow-300', label: 'Warning' },
  fail: { bg: 'bg-red-100 dark:bg-red-900/30', text: 'text-red-800 dark:text-red-300', label: 'Fail' },
  info: { bg: 'bg-blue-100 dark:bg-blue-900/30', text: 'text-blue-800 dark:text-blue-300', label: 'Info' },
};

export function SecurityAuditPage() {
  const [result, setResult] = useState<AuditResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    void (async () => {
      const { data, error: err } = await apiFetch<AuditResult>('/admin/security-audit');
      if (data) {
        setResult(data);
      } else {
        setError(err?.message ?? 'Failed to load security audit');
      }
      setLoading(false);
    })();
  }, []);

  if (loading) return <p className="text-muted-foreground">Running security checks...</p>;
  if (error) return <p className="text-destructive">{error}</p>;
  if (!result) return null;

  // Group checks by category
  const categories = new Map<string, SecurityCheck[]>();
  for (const check of result.checks) {
    const list = categories.get(check.category) ?? [];
    list.push(check);
    categories.set(check.category, list);
  }

  return (
    <div>
      <h2 className="text-2xl font-bold">Security Audit</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Checks your installation for common security issues and misconfigurations.
      </p>

      {/* Summary cards */}
      <div className="mt-4 flex gap-3">
        <SummaryCard count={result.summary.pass} label="Pass" color="green" />
        <SummaryCard count={result.summary.warn} label="Warnings" color="yellow" />
        <SummaryCard count={result.summary.fail} label="Failures" color="red" />
      </div>

      {/* Checks by category */}
      <div className="mt-6 space-y-6">
        {Array.from(categories.entries()).map(([category, checks]) => (
          <div key={category}>
            <h3 className="text-lg font-semibold">{category}</h3>
            <div className="mt-2 overflow-hidden rounded-lg border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/30 text-xs font-medium text-muted-foreground">
                    <th className="px-4 py-2 text-left">Check</th>
                    <th className="px-4 py-2 text-left w-24">Status</th>
                    <th className="px-4 py-2 text-left">Details</th>
                  </tr>
                </thead>
                <tbody>
                  {checks.map((check, i) => {
                    const style = STATUS_STYLES[check.status] ?? STATUS_STYLES.info;
                    return (
                      <tr key={i} className="border-b border-border/50">
                        <td className="px-4 py-2.5 font-medium">{check.name}</td>
                        <td className="px-4 py-2.5">
                          <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${style.bg} ${style.text}`}>
                            {style.label}
                          </span>
                        </td>
                        <td className="px-4 py-2.5 text-muted-foreground">{check.detail}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function SummaryCard({ count, label, color }: { count: number; label: string; color: string }) {
  const colorMap: Record<string, string> = {
    green: 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300',
    yellow: 'border-yellow-300 bg-yellow-50 text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300',
    red: 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300',
  };

  return (
    <div className={`rounded-lg border px-4 py-3 ${colorMap[color] ?? ''}`}>
      <p className="text-2xl font-bold">{count}</p>
      <p className="text-xs font-medium">{label}</p>
    </div>
  );
}
