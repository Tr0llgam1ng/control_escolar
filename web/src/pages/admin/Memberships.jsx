import { useState } from 'react';
import { BadgeCheck, CalendarClock, Coins, History, Layers, Package, Pencil, Plus, Users, Wallet } from 'lucide-react';
import { api, fieldError } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Badge, Button, Card, Empty, ErrorAlert, Field, Input, Loading, Modal, PageHeader, Select, Stat, Table, Td } from '../../components/ui.jsx';
import { PlanBadge, credits, human, planStyle } from '../../components/membership.jsx';
import { dateTime, formatReference, money } from '../../components/payments.jsx';
import { TUTORING_TONE } from '../student/Tutoring.jsx';

const TABS = [
  { key: 'plans', label: 'Planes', icon: Layers },
  { key: 'members', label: 'Miembros y monederos', icon: Users },
  { key: 'purchases', label: 'Compras de créditos', icon: Package },
  { key: 'ledger', label: 'Movimientos', icon: History },
  { key: 'modules', label: 'Asesorías y cubículos', icon: CalendarClock },
];

/** Control Escolar: memberships — plans and their limits, wallets, purchases in review and the ledger. */
export default function AdminMemberships() {
  const [tab, setTab] = useState('plans');
  const summary = useApi('/memberships/summary');
  const s = summary.data;

  return (
    <>
      <PageHeader title="Membresías" subtitle="Planes Bronze / Silver / Gold, créditos y módulos especiales (memberships-service)." />
      <ErrorAlert error={summary.error} />
      {s && (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Stat label="Suscripciones activas" value={s.active_subscriptions} icon={BadgeCheck} hint={s.members_by_plan.filter((p) => p.members !== null).map((p) => `${p.name}: ${p.members}`).join(' · ')} />
          <Stat label="Créditos en monederos" value={s.credits_in_wallets.toLocaleString('es-MX')} icon={Wallet} tone="emerald" />
          <Stat label="Dinero cambiado a créditos" value={money(s.money_collected)} icon={Coins} tone="amber" hint={`${s.credits_sold.toLocaleString('es-MX')} créditos emitidos`} />
          <Stat label="Compras en revisión" value={s.purchases_in_review} icon={Package} tone={s.purchases_in_review ? 'rose' : 'sky'} hint={`${s.purchases_pending} pendientes de pago · ${s.credits_spent_month} créditos gastados este mes`} />
        </div>
      )}

      <div className="mb-6 flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1">
        {TABS.map(({ key, label, icon: Icon }) => (
          <button key={key} type="button" onClick={() => setTab(key)}
            className={`flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition ${tab === key ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-800'}`}>
            <Icon className="h-4 w-4" /> {label}
          </button>
        ))}
      </div>

      {tab === 'plans' && <PlansTab />}
      {tab === 'members' && <MembersTab onChange={summary.reload} />}
      {tab === 'purchases' && <PurchasesTab onChange={summary.reload} />}
      {tab === 'ledger' && <LedgerTab />}
      {tab === 'modules' && <ModulesTab />}
    </>
  );
}

// ------------------------------------------------------------------ plans

function PlansTab() {
  const { data, error, loading, reload } = useApi('/memberships/plans');
  const [editing, setEditing] = useState(null);
  if (loading && !data) return <Loading />;
  const plans = data?.data ?? [];
  const keys = plans[0]?.features.map((f) => f.key) ?? [];

  return (
    <>
      <ErrorAlert error={error} />
      <Card title="Matriz de planes" icon={Layers} padding={false}>
        <Table head={['Función', ...plans.map((p) => ({ label: <span className="flex items-center justify-between gap-2">{p.name}<button type="button" onClick={() => setEditing(p)} className="rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-700" aria-label={`Editar ${p.name}`}><Pencil className="h-3.5 w-3.5" /></button></span> }))]}>
          <tr className="bg-slate-50/60">
            <Td className="font-medium">Precio / duración</Td>
            {plans.map((p) => <Td key={p.code} className="whitespace-nowrap">{p.price_credits ? credits(p.price_credits) : 'Gratis'} · {p.duration_days} días{!p.is_active && <Badge tone="rose">Inactivo</Badge>}</Td>)}
          </tr>
          {keys.map((k) => {
            const label = plans[0].features.find((f) => f.key === k);
            return (
              <tr key={k}>
                <Td><p className="text-sm">{label.label}</p><p className="text-xs text-slate-400">{label.group}</p></Td>
                {plans.map((p) => {
                  const f = p.features.find((x) => x.key === k);
                  return <Td key={p.code} className={f.available ? 'text-slate-800' : 'text-slate-400'}>{f.display}</Td>;
                })}
              </tr>
            );
          })}
        </Table>
      </Card>
      {editing && <PlanEditor plan={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />}
    </>
  );
}

function PlanEditor({ plan, onClose, onSaved }) {
  const [form, setForm] = useState({
    name: plan.name, description: plan.description ?? '', price_credits: plan.price_credits, duration_days: plan.duration_days, is_active: plan.is_active,
    features: Object.fromEntries(plan.features.map((f) => [f.key, { enabled: f.enabled, value: f.value === null ? '' : String(f.value) }])),
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const setFeature = (key, patch) => setForm((f) => ({ ...f, features: { ...f.features, [key]: { ...f.features[key], ...patch } } }));

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await api(`/memberships/plans/${plan.id}`, {
        method: 'PUT',
        body: {
          name: form.name, description: form.description || null, price_credits: Number(form.price_credits), duration_days: Number(form.duration_days), is_active: form.is_active,
          features: Object.fromEntries(Object.entries(form.features).map(([k, v]) => [k, { enabled: v.enabled, value: v.value === '' ? null : Number(v.value) }])),
        },
      });
      onSaved();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const hint = { quota: 'Vacío = ilimitado', percent: 'Porcentaje', credits: 'Créditos', number: 'Límite' };

  return (
    <Modal open wide title={`Editar plan ${plan.name}`} onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Cancelar</Button><Button loading={busy} onClick={save}>Guardar</Button></>}>
      <ErrorAlert error={error && !Object.keys(error.errors ?? {}).length ? error : null} />
      <div className="mb-4 grid gap-3 sm:grid-cols-4">
        <Field label="Nombre" error={fieldError(error, 'name')}><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
        <Field label="Precio (créditos)" error={fieldError(error, 'price_credits')}><Input type="number" min={0} disabled={plan.code === 'basic'} value={form.price_credits} onChange={(e) => setForm({ ...form, price_credits: e.target.value })} /></Field>
        <Field label="Duración (días)" error={fieldError(error, 'duration_days')}><Input type="number" min={1} value={form.duration_days} onChange={(e) => setForm({ ...form, duration_days: e.target.value })} /></Field>
        <Field label="Estado">
          <Select disabled={plan.code === 'basic'} value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })} options={[{ value: '1', label: 'Activo' }, { value: '0', label: 'Inactivo' }]} />
        </Field>
        <Field label="Descripción" className="sm:col-span-4"><Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
      </div>
      <div className="max-h-[50vh] space-y-2 overflow-y-auto pr-1">
        {plan.features.map((f) => {
          const v = form.features[f.key];
          return (
            <div key={f.key} className="grid items-center gap-2 rounded-lg border border-slate-100 px-3 py-2 sm:grid-cols-[1fr_auto_8rem]">
              <div><p className="text-sm text-slate-700">{f.label}</p><p className="text-xs text-slate-400">{f.group}</p></div>
              <label className="flex items-center gap-2 text-xs text-slate-600">
                <input type="checkbox" checked={v.enabled} onChange={(e) => setFeature(f.key, { enabled: e.target.checked })} /> Incluido
              </label>
              {f.type === 'flag' ? <span className="text-xs text-slate-400">Sí / no</span> : (
                <Input type="number" min={0} value={v.value} placeholder={f.type === 'quota' ? 'Ilimitado' : ''} onChange={(e) => setFeature(f.key, { value: e.target.value })}
                  title={f.type === 'time' ? (f.unit === 'hours' ? 'Horas por ciclo' : 'Minutos por ciclo') : hint[f.type]} />
              )}
            </div>
          );
        })}
      </div>
      <p className="mt-3 text-xs text-slate-500">Usos por ciclo: vacío = ilimitado, 0 = no incluido. Tiempo: horas (cubículos) o minutos (biblioteca) por ciclo.</p>
    </Modal>
  );
}

// ------------------------------------------------------------------ members

function MembersTab({ onChange }) {
  const wallets = useApi('/memberships/wallets');
  const subs = useApi('/memberships/subscriptions', { query: { status: 'active' } });
  const [adjusting, setAdjusting] = useState(null);

  if ((wallets.loading && !wallets.data) || (subs.loading && !subs.data)) return <Loading />;

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <Card title="Monederos" icon={Wallet} padding={false}>
        <ErrorAlert error={wallets.error} />
        {!wallets.data?.data?.length ? <Empty icon={Wallet} title="Nadie tiene créditos todavía" /> : (
          <Table head={['Alumno', 'Plan', { label: 'Saldo', className: 'text-right' }, '']}>
            {wallets.data.data.map((w) => (
              <tr key={w.user_id}>
                <Td><p className="font-medium">{w.user_name ?? `Usuario ${w.user_id}`}</p><p className="text-xs text-slate-400">{w.user_email}</p></Td>
                <Td><PlanBadge plan={w.plan} />{w.plan_ends_at && <p className="mt-1 text-xs text-slate-400">hasta {dateTime(w.plan_ends_at)}{w.auto_renew === false && ' · sin renovación'}</p>}</Td>
                <Td className="text-right font-semibold tabular-nums">{w.balance.toLocaleString('es-MX')}</Td>
                <Td className="text-right"><Button size="sm" variant="secondary" icon={Plus} onClick={() => setAdjusting(w)}>Ajustar</Button></Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card title="Suscripciones vigentes" icon={BadgeCheck} padding={false}>
        {!subs.data?.data?.length ? <Empty icon={BadgeCheck} title="Sin suscripciones de pago" /> : (
          <Table head={['Alumno', 'Plan', 'Vence', 'Restante']}>
            {subs.data.data.map((s) => (
              <tr key={s.id}>
                <Td>{s.user_name ?? `Usuario ${s.user_id}`}</Td>
                <Td><PlanBadge plan={s.plan} /></Td>
                <Td className="whitespace-nowrap text-xs">{dateTime(s.ends_at)}{!s.auto_renew && <p className="text-amber-600">No se renueva</p>}</Td>
                <Td className="tabular-nums">{s.remaining.human}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      {adjusting && <AdjustModal wallet={adjusting} onClose={() => setAdjusting(null)} onDone={() => { setAdjusting(null); wallets.reload(); onChange(); }} />}
    </div>
  );
}

function AdjustModal({ wallet, onClose, onDone }) {
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      await api(`/memberships/wallets/${wallet.user_id}/adjust`, { method: 'POST', body: { amount: Number(amount), reason } });
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open title={`Ajustar créditos · ${wallet.user_name ?? wallet.user_id}`} onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Cancelar</Button><Button loading={busy} onClick={save}>Registrar ajuste</Button></>}>
      <ErrorAlert error={error && !Object.keys(error.errors ?? {}).length ? error : null} />
      <p className="mb-3 text-sm text-slate-600">Saldo actual: <b>{credits(wallet.balance)}</b>. Usa un número negativo para descontar. Queda en el historial con tu usuario.</p>
      <div className="grid gap-3">
        <Field label="Créditos (±)" error={fieldError(error, 'amount')}><Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="Ej. 100 o -50" /></Field>
        <Field label="Motivo" error={fieldError(error, 'reason')}><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ej. Beca, corrección de pago" /></Field>
      </div>
    </Modal>
  );
}

// ------------------------------------------------------------------ purchases

const PURCHASE_TONE = { pending: 'amber', credited: 'emerald', review: 'sky', rejected: 'rose' };

function PurchasesTab({ onChange }) {
  const [status, setStatus] = useState('');
  const { data, error, loading, reload } = useApi('/memberships/purchases', { query: { status } });
  const [resolving, setResolving] = useState(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState(null);

  const resolve = async (action) => {
    setBusy(true);
    setActionError(null);
    try {
      await api(`/memberships/purchases/${resolving.id}/resolve`, { method: 'POST', body: { action, reason } });
      setResolving(null);
      setReason('');
      reload();
      onChange();
    } catch (e) {
      setActionError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Compras de créditos" icon={Package} padding={false}
      actions={<Select value={status} onChange={(e) => setStatus(e.target.value)} placeholder="Todas" options={Object.entries({ review: 'En revisión', pending: 'Pendientes de pago', credited: 'Acreditadas', rejected: 'Rechazadas' }).map(([value, label]) => ({ value, label }))} />}>
      <ErrorAlert error={error} />
      {loading && !data ? <Loading /> : !data?.data?.length ? <Empty icon={Package} title="Sin compras" /> : (
        <Table head={['Fecha', 'Alumno', 'Referencia', 'Paquete', { label: 'Créditos', className: 'text-right' }, 'Estado', '']}>
          {data.data.map((p) => (
            <tr key={p.id}>
              <Td className="whitespace-nowrap text-xs">{dateTime(p.created_at)}</Td>
              <Td>{p.user_name ?? `Usuario ${p.user_id}`}</Td>
              <Td className="font-mono text-xs">{formatReference(p.reference)}</Td>
              <Td>{p.pack?.name}<p className="text-xs text-slate-400">{money(p.amount)} pagado · precio {money(p.pack?.price)}</p></Td>
              <Td className="text-right tabular-nums">{p.total_credits}</Td>
              <Td><Badge tone={PURCHASE_TONE[p.status]}>{p.status_label}</Badge>{p.status_reason && <p className="mt-1 max-w-56 text-xs text-slate-500">{p.status_reason}</p>}</Td>
              <Td className="text-right">{p.status === 'review' && <Button size="sm" onClick={() => { setResolving(p); setActionError(null); }}>Resolver</Button>}</Td>
            </tr>
          ))}
        </Table>
      )}
      <Modal open={!!resolving} title="Resolver compra en revisión" onClose={() => setResolving(null)}
        footer={<><Button variant="danger" loading={busy} onClick={() => resolve('reject')}>Rechazar</Button><Button loading={busy} onClick={() => resolve('credit')}>Acreditar créditos</Button></>}>
        {resolving && (
          <div className="space-y-3 text-sm">
            <ErrorAlert error={actionError && !Object.keys(actionError.errors ?? {}).length ? actionError : null} />
            <p className="text-slate-600">{resolving.status_reason}</p>
            <Field label="Motivo de la decisión" error={fieldError(actionError, 'reason')}><Input value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
          </div>
        )}
      </Modal>
    </Card>
  );
}

// ------------------------------------------------------------------ ledger

function LedgerTab() {
  const [type, setType] = useState('');
  const { data, error, loading } = useApi('/memberships/transactions', { query: { type } });
  return (
    <Card title="Movimientos de créditos (auditoría)" icon={History} padding={false}
      actions={<Select value={type} onChange={(e) => setType(e.target.value)} placeholder="Todos" options={Object.entries({ purchase: 'Compras', bonus: 'Regalos', spend: 'Consumos', refund: 'Reembolsos', adjustment: 'Ajustes' }).map(([value, label]) => ({ value, label }))} />}>
      <ErrorAlert error={error} />
      {loading && !data ? <Loading /> : !data?.data?.length ? <Empty icon={History} title="Sin movimientos" /> : (
        <Table head={['Fecha', 'Alumno', 'Concepto', 'Registró', { label: 'Créditos', className: 'text-right' }, { label: 'Saldo', className: 'text-right' }]}>
          {data.data.map((t) => (
            <tr key={t.id}>
              <Td className="whitespace-nowrap text-xs">{dateTime(t.created_at)}</Td>
              <Td>{t.user_name ?? `Usuario ${t.user_id}`}</Td>
              <Td><p>{t.concept}</p><p className="text-xs text-slate-400">{t.type_label}</p></Td>
              <Td className="text-xs text-slate-500">{t.actor_type === 'user' ? `Usuario ${t.actor_id}` : t.actor_id}</Td>
              <Td className={`text-right font-semibold tabular-nums ${t.amount > 0 ? 'text-emerald-600' : 'text-rose-600'}`}>{t.amount > 0 ? '+' : ''}{t.amount}</Td>
              <Td className="text-right tabular-nums text-slate-500">{t.balance_after}</Td>
            </tr>
          ))}
        </Table>
      )}
    </Card>
  );
}

// ------------------------------------------------------------------ modules

function ModulesTab() {
  const tutoring = useApi('/memberships/tutoring-sessions');
  const bookings = useApi('/memberships/room-bookings');
  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <Card title="Asesorías (créditos)" icon={Users} padding={false}>
        <ErrorAlert error={tutoring.error} />
        {!tutoring.data?.data?.length ? <Empty icon={Users} title="Sin asesorías" /> : (
          <Table head={['Fecha', 'Alumno → profesor', 'Materia', 'Créditos', 'Estado']}>
            {tutoring.data.data.map((s) => (
              <tr key={s.id}>
                <Td className="whitespace-nowrap text-xs">{dateTime(s.scheduled_at)}</Td>
                <Td className="text-xs">{s.student_name ?? s.user_name}<br />→ {s.teacher_name}</Td>
                <Td>{s.subject}</Td>
                <Td className="tabular-nums">{s.credits_cost}</Td>
                <Td><Badge tone={TUTORING_TONE[s.status]}>{s.status_label}</Badge></Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      <Card title="Reservaciones de cubículos (horas)" icon={CalendarClock} padding={false}>
        <ErrorAlert error={bookings.error} />
        {!bookings.data?.data?.length ? <Empty icon={CalendarClock} title="Sin reservaciones" /> : (
          <Table head={['Inicio', 'Cubículo', 'Alumno', 'Duración', 'Estado']}>
            {bookings.data.data.map((b) => (
              <tr key={b.id}>
                <Td className="whitespace-nowrap text-xs">{dateTime(b.starts_at)}</Td>
                <Td>{b.room?.name}</Td>
                <Td>{b.user_name ?? `Usuario ${b.user_id}`}</Td>
                <Td>{human(b.minutes * 60)}</Td>
                <Td>{b.status === 'cancelled' ? <Badge tone="slate">Cancelada</Badge> : <Badge tone={planStyle('silver').tone}>Reservada</Badge>}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </div>
  );
}
