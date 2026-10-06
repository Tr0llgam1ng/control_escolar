import { CircleCheck, LoaderCircle, TriangleAlert, X } from 'lucide-react';
import { useEffect } from 'react';

const cx = (...c) => c.filter(Boolean).join(' ');

export function Button({ variant = 'primary', size = 'md', icon: Icon, loading, className, children, ...props }) {
  const variants = {
    primary: 'bg-indigo-600 text-white hover:bg-indigo-500 shadow-sm',
    secondary: 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 shadow-sm',
    danger: 'bg-rose-600 text-white hover:bg-rose-500 shadow-sm',
    ghost: 'text-slate-600 hover:bg-slate-100',
    'ghost-danger': 'text-rose-600 hover:bg-rose-50',
  };
  const sizes = { sm: 'px-2.5 py-1.5 text-xs', md: 'px-3.5 py-2 text-sm' };
  return (
    <button
      type="button"
      disabled={loading || props.disabled}
      className={cx('inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition disabled:opacity-50 disabled:cursor-not-allowed', variants[variant], sizes[size], className)}
      {...props}
    >
      {loading ? <LoaderCircle className="h-4 w-4 animate-spin" /> : Icon && <Icon className="h-4 w-4" />}
      {children}
    </button>
  );
}

export function Field({ label, error, hint, className, children }) {
  return (
    <label className={cx('block', className)}>
      {label && <span className="mb-1 block text-sm font-medium text-slate-700">{label}</span>}
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-slate-400">{hint}</span>}
      {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
    </label>
  );
}

const inputClass = 'block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20';

export function Input({ className, ...props }) {
  return <input className={cx(inputClass, className)} {...props} />;
}

export function Select({ options = [], placeholder, className, ...props }) {
  return (
    <select className={cx(inputClass, className)} {...props}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

export function Card({ title, icon: Icon, actions, padding = true, className, children }) {
  return (
    <section className={cx('rounded-xl border border-slate-200 bg-white shadow-sm', className)}>
      {(title || actions) && (
        <header className="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
          <h3 className="flex items-center gap-2 font-semibold text-slate-800">
            {Icon && <Icon className="h-5 w-5 text-indigo-500" />}
            {title}
          </h3>
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </header>
      )}
      <div className={padding ? 'p-5' : ''}>{children}</div>
    </section>
  );
}

export function Stat({ label, value, icon: Icon, tone = 'indigo', hint }) {
  const tones = {
    indigo: 'bg-indigo-50 text-indigo-600',
    emerald: 'bg-emerald-50 text-emerald-600',
    amber: 'bg-amber-50 text-amber-600',
    rose: 'bg-rose-50 text-rose-600',
    sky: 'bg-sky-50 text-sky-600',
  };
  return (
    <div className="flex items-start gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className={cx('rounded-lg p-2.5', tones[tone])}>{Icon && <Icon className="h-6 w-6" />}</div>
      <div className="min-w-0">
        <p className="text-sm text-slate-500">{label}</p>
        <p className="text-2xl font-semibold tabular-nums text-slate-800">{value}</p>
        {hint && <p className="mt-0.5 text-xs text-slate-400">{hint}</p>}
      </div>
    </div>
  );
}

export function Badge({ tone = 'slate', children }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    indigo: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    amber: 'bg-amber-50 text-amber-700 ring-amber-200',
    rose: 'bg-rose-50 text-rose-700 ring-rose-200',
    sky: 'bg-sky-50 text-sky-700 ring-sky-200',
  };
  return <span className={cx('inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone])}>{children}</span>;
}

const STATUS = {
  active: ['Activa', 'emerald'],
  inactive: ['No activa', 'slate'],
  in_progress: ['En curso', 'sky'],
  approved: ['Aprobada', 'emerald'],
  failed: ['No aprobada', 'rose'],
};
export function StatusBadge({ status, labels }) {
  const [label, tone] = STATUS[status] ?? [status, 'slate'];
  return <Badge tone={tone}>{labels?.[status] ?? label}</Badge>;
}

export function Grade({ value, passing = 7 }) {
  if (value === null || value === undefined || value === '') return <span className="text-slate-300">—</span>;
  const n = Number(value);
  return <span className={cx('font-semibold tabular-nums', n >= passing ? 'text-emerald-600' : 'text-rose-600')}>{n.toFixed(1)}</span>;
}

export function PageHeader({ title, subtitle, children }) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        <h1 className="truncate text-2xl font-semibold text-slate-900">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
    </div>
  );
}

export function Empty({ icon: Icon, title, message, children }) {
  return (
    <div className="flex flex-col items-center justify-center px-4 py-10 text-center">
      {Icon && (
        <div className="rounded-full bg-slate-100 p-3 text-slate-400">
          <Icon className="h-7 w-7" />
        </div>
      )}
      <p className="mt-3 font-medium text-slate-700">{title}</p>
      {message && <p className="mt-1 max-w-md text-sm text-slate-500">{message}</p>}
      {children && <div className="mt-4">{children}</div>}
    </div>
  );
}

export function Loading() {
  return (
    <div className="flex items-center justify-center py-16 text-slate-400">
      <LoaderCircle className="h-6 w-6 animate-spin" />
    </div>
  );
}

export function Alert({ tone = 'error', children }) {
  if (!children) return null;
  const styles = tone === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : tone === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800';
  const Icon = tone === 'success' ? CircleCheck : TriangleAlert;
  return (
    <div className={cx('mb-4 flex items-start gap-3 rounded-lg border px-4 py-3 text-sm', styles)}>
      <Icon className="h-5 w-5 shrink-0" />
      <div className="flex-1">{children}</div>
    </div>
  );
}

export function ErrorAlert({ error }) {
  if (!error) return null;
  return (
    <Alert>
      {error.message}
      {error.service && <span className="ml-1 text-xs opacity-70">(servicio: {error.service})</span>}
    </Alert>
  );
}

export function Modal({ open, title, onClose, children, footer, wide }) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:items-center" onMouseDown={onClose}>
      <div className={cx('w-full rounded-2xl bg-white shadow-xl', wide ? 'max-w-3xl' : 'max-w-lg')} onMouseDown={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
          <h2 className="text-lg font-semibold text-slate-800">{title}</h2>
          <button type="button" onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar">
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="px-6 py-5">{children}</div>
        {footer && <div className="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">{footer}</div>}
      </div>
    </div>
  );
}

export function Table({ head, children }) {
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            {head.map((h, i) => (
              <th key={i} className={cx('whitespace-nowrap px-4 py-3', h?.className)}>
                {h?.label ?? h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">{children}</tbody>
      </table>
    </div>
  );
}

export const Td = ({ className, children, ...props }) => (
  <td className={cx('px-4 py-3 align-middle text-slate-700', className)} {...props}>
    {children}
  </td>
);
