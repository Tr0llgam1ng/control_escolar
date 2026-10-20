import { CircleCheck, CircleX, Clock } from 'lucide-react';
import { Badge } from './ui.jsx';

export const money = (value) =>
  Number(value ?? 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });

export const dateTime = (value) =>
  value ? new Date(value).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '—';

/** 13-digit reference shown in groups so it is easy to dictate: 1234 5678 9012 3 */
export const formatReference = (ref) => (ref ?? '').replace(/(\d{4})(?=\d)/g, '$1 ');

const STATUS = {
  pending: ['Pendiente', 'amber'],
  approved: ['Aprobado', 'emerald'],
  rejected: ['Rechazado', 'rose'],
};

export function PaymentStatusBadge({ charge }) {
  if (charge.status === 'pending' && charge.is_expired) return <Badge tone="rose">Caducada sin pago</Badge>;
  const [label, tone] = STATUS[charge.status] ?? [charge.status, 'slate'];
  return <Badge tone={tone}>{label}</Badge>;
}

const RESULT = {
  approved: { icon: CircleCheck, title: 'Pago aprobado', box: 'border-emerald-200 bg-emerald-50 text-emerald-800', iconClass: 'text-emerald-600' },
  rejected: { icon: CircleX, title: 'Pago rechazado', box: 'border-rose-200 bg-rose-50 text-rose-800', iconClass: 'text-rose-600' },
  pending: { icon: Clock, title: 'Pago pendiente', box: 'border-amber-200 bg-amber-50 text-amber-800', iconClass: 'text-amber-600' },
};

/** Alert shown to the student when a payment finishes: approved, rejected or pending. */
export function PaymentResult({ result, message, children }) {
  const r = RESULT[result] ?? RESULT.pending;
  const Icon = r.icon;
  return (
    <div role="alert" className={`flex flex-col items-center rounded-xl border px-5 py-6 text-center ${r.box}`}>
      <Icon className={`h-12 w-12 ${r.iconClass}`} />
      <p className="mt-3 text-lg font-semibold">{r.title}</p>
      <p className="mt-1 text-sm">{message}</p>
      {children && <div className="mt-4 w-full">{children}</div>}
    </div>
  );
}
