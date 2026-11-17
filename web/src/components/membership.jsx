import { Link } from 'react-router-dom';
import { Crown, Gem, Lock, Medal, Shield } from 'lucide-react';
import { Badge } from './ui.jsx';

/** Visual identity of each plan (codes come from memberships-service). */
export const PLAN_STYLE = {
  basic: { icon: Shield, tone: 'slate', ring: 'ring-slate-200', text: 'text-slate-700', bg: 'bg-slate-50', solid: 'bg-slate-600' },
  bronze: { icon: Medal, tone: 'amber', ring: 'ring-amber-200', text: 'text-amber-700', bg: 'bg-amber-50', solid: 'bg-amber-600' },
  silver: { icon: Gem, tone: 'sky', ring: 'ring-sky-200', text: 'text-sky-700', bg: 'bg-sky-50', solid: 'bg-sky-600' },
  gold: { icon: Crown, tone: 'indigo', ring: 'ring-indigo-200', text: 'text-indigo-700', bg: 'bg-indigo-50', solid: 'bg-indigo-600' },
};

export const planStyle = (code) => PLAN_STYLE[code] ?? PLAN_STYLE.basic;

export function PlanBadge({ plan, className = '' }) {
  if (!plan) return null;
  const { icon: Icon, tone } = planStyle(plan.code);
  return (
    <span className={className}>
      <Badge tone={tone}>
        <Icon className="mr-1 h-3 w-3" /> {plan.name}
      </Badge>
    </span>
  );
}

export const credits = (n) => `${Number(n ?? 0).toLocaleString('es-MX')} créditos`;

/** Seconds → "1 d 2 h 5 min" (same format memberships-service uses). */
export function human(seconds) {
  const s = Math.max(0, Math.floor(seconds ?? 0));
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const parts = [d && `${d} d`, h && `${h} h`, m && `${m} min`].filter(Boolean);
  return parts.length ? parts.join(' ') : s > 0 ? 'menos de 1 min' : '0 min';
}

/** mm:ss or h:mm:ss for live counters. */
export function clock(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return h ? `${h}:${pad(m)}:${pad(sec)}` : `${pad(m)}:${pad(sec)}`;
}

/** Usage bar of a quota or time feature of the plan. */
export function UsageBar({ feature, label }) {
  if (!feature) return null;
  const isTime = feature.type === 'time';
  const total = isTime ? feature.included_seconds : feature.value;
  const used = feature.used ?? 0;
  const pct = feature.unlimited ? 8 : total ? Math.min(100, (used / total) * 100) : 0;

  let right;
  if (!feature.available) right = <span className="flex items-center gap-1 text-slate-400"><Lock className="h-3 w-3" /> No incluido</span>;
  else if (feature.unlimited) right = <span className="text-emerald-600">Ilimitado · {used} usados</span>;
  else if (isTime) right = <span>{human(feature.remaining)} de {human(total)}</span>;
  else right = <span>{feature.remaining} de {total} disponibles</span>;

  return (
    <div>
      <div className="mb-1 flex items-center justify-between gap-2 text-xs">
        <span className="font-medium text-slate-700">{label ?? feature.label}</span>
        <span className="tabular-nums text-slate-500">{right}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full rounded-full ${!feature.available ? '' : feature.unlimited ? 'bg-emerald-400' : pct >= 90 ? 'bg-rose-500' : pct >= 60 ? 'bg-amber-500' : 'bg-indigo-500'}`}
          style={{ width: `${feature.available ? pct : 0}%` }} />
      </div>
    </div>
  );
}

/** Box shown when the plan does not include a module. */
export function LockedModule({ title, message, upgradeTo }) {
  return (
    <div className="flex flex-col items-center rounded-xl border border-dashed border-indigo-200 bg-indigo-50/40 px-6 py-10 text-center">
      <div className="rounded-full bg-indigo-100 p-3 text-indigo-600"><Lock className="h-6 w-6" /></div>
      <p className="mt-3 font-semibold text-slate-800">{title}</p>
      <p className="mt-1 max-w-md text-sm text-slate-500">{message}</p>
      {upgradeTo?.length > 0 && <p className="mt-2 text-sm text-slate-600">Disponible en: <b>{upgradeTo.map((p) => p.name).join(', ')}</b></p>}
      <Link to="/student/membership" className="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Ver planes</Link>
    </div>
  );
}

/** Local datetime-local value (yyyy-MM-ddTHH:mm) → string the services parse in Mexico City time. */
export const toLocalString = (value) => (value ? value.replace('T', ' ') : '');

/** datetime-local value for N days from today at the given hour (default for booking forms). */
export function dayAt(daysAhead = 1, hour = 10) {
  const d = new Date(Date.now() + daysAhead * 86400_000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(hour)}:00`;
}
