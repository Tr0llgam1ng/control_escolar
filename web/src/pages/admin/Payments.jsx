import { useEffect, useState } from 'react';
import { Banknote, BellDot, CircleCheck, CircleX, Clock, CreditCard, Eye, Hourglass, Landmark, Lock, Receipt, Search, Wallet } from 'lucide-react';
import { api, fieldError } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Field, Input, Loading, Modal, PageHeader, Select, Stat, Table, Td } from '../../components/ui.jsx';
import { PaymentStatusBadge, dateTime, formatReference, money } from '../../components/payments.jsx';

const TABS = [
  { key: 'new', label: 'Nuevas', query: { new: 1 } },
  { key: 'pending', label: 'Pendientes', query: { status: 'pending', expired: 0 } },
  { key: 'expired', label: 'Caducadas sin pago', query: { expired: 1 } },
  { key: 'approved', label: 'Aprobadas', query: { status: 'approved' } },
  { key: 'rejected', label: 'Rechazadas', query: { status: 'rejected' } },
  { key: 'all', label: 'Todas', query: {} },
];

const METHOD_FILTER = [
  { value: 'card', label: 'Tarjeta' },
  { value: 'cash', label: 'Efectivo' },
  { value: 'none', label: 'Sin elegir' },
];

/** Control Escolar: check new references, verify the payment method and set the status. */
export default function AdminPayments() {
  const [tab, setTab] = useState('new');
  const [method, setMethod] = useState('');
  const [q, setQ] = useState('');
  const [selected, setSelected] = useState(null);

  const query = { ...TABS.find((t) => t.key === tab).query, method, q };
  const list = useApi('/payments/charges', { query });
  const summary = useApi('/payments/charges/summary');

  const refresh = () => {
    list.reload();
    summary.reload();
  };

  const s = summary.data;
  const rows = list.data?.data ?? [];

  return (
    <>
      <PageHeader title="Pagos y referencias" subtitle="Servicio 4 (pagos) + Servicio 2 (alumnos), compuestos por el gateway." />

      {s && (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <Stat label="Nuevas por revisar" value={s.new} icon={BellDot} tone="indigo" />
          <Stat label="Pendientes vigentes" value={s.pending} icon={Clock} tone="amber" />
          <Stat label="Caducadas sin pago" value={s.expired} icon={Hourglass} tone="rose" />
          <Stat label="Aprobadas" value={s.approved} icon={CircleCheck} tone="emerald" hint={`${money(s.collected)} recaudado`} />
          <Stat label="Rechazadas" value={s.rejected} icon={CircleX} tone="sky" />
        </div>
      )}

      <Card padding={false}>
        <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex flex-wrap gap-1">
            {TABS.map((t) => (
              <button key={t.key} onClick={() => setTab(t.key)}
                className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${tab === t.key ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>
                {t.label}
                {t.key === 'new' && s?.new > 0 && <span className="ml-1.5 rounded-full bg-rose-500 px-1.5 text-xs text-white">{s.new}</span>}
              </button>
            ))}
          </div>
          <div className="flex gap-2">
            <div className="w-36"><Select value={method} onChange={(e) => setMethod(e.target.value)} options={METHOD_FILTER} placeholder="Cualquier método" /></div>
            <div className="relative w-48">
              <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
              <Input className="pl-9" placeholder="Referencia" value={q} onChange={(e) => setQ(e.target.value)} />
            </div>
          </div>
        </div>

        <ErrorAlert error={list.error} />
        {list.loading && !list.data ? (
          <Loading />
        ) : rows.length === 0 ? (
          <Empty icon={Receipt} title="No hay referencias en esta vista" />
        ) : (
          <Table head={['Referencia', 'Alumno', 'Documento', { label: 'Monto', className: 'text-right' }, 'Método', 'Vence', 'Estado', '']}>
            {rows.map((c) => (
              <tr key={c.id} className="hover:bg-slate-50">
                <Td className="font-mono text-xs">
                  {formatReference(c.reference)}
                  {c.is_new && <Badge tone="indigo">Nueva</Badge>}
                </Td>
                <Td>
                  <p className="font-medium">{c.student_name ?? `Alumno #${c.student_id}`}</p>
                  {c.student_enrollment_number && <p className="text-xs text-slate-400">{c.student_enrollment_number}</p>}
                </Td>
                <Td>{c.concept}</Td>
                <Td className="text-right tabular-nums">{money(c.amount)}</Td>
                <Td>{c.method_label ?? <span className="text-slate-400">Sin elegir</span>}</Td>
                <Td className="whitespace-nowrap text-xs">{dateTime(c.expires_at)}</Td>
                <Td><PaymentStatusBadge charge={c} /></Td>
                <Td className="text-right"><Button size="sm" variant="secondary" icon={Eye} onClick={() => setSelected(c.id)}>Revisar</Button></Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      {selected && <ReviewModal id={selected} onClose={() => setSelected(null)} onChanged={refresh} />}
    </>
  );
}

const METHOD_TABS = [
  { value: 'card', label: 'Tarjeta', icon: CreditCard },
  { value: 'cash', label: 'Efectivo', icon: Banknote },
  { value: 'transfer', label: 'Transferencia', icon: Landmark },
];

const ACTION_LABELS = {
  created: 'Referencia generada',
  card_declined: 'Tarjeta rechazada',
  card_approved: 'Pago con tarjeta aprobado',
  cash_selected: 'El alumno eligió efectivo',
  cash_received: 'Efectivo recibido en caja',
  status_changed: 'Cambio de estado',
  reviewed: 'Revisada por Control Escolar',
};

function ReviewModal({ id, onClose, onChanged }) {
  const { data: loaded, error: loadError, loading } = useApi(`/payments/charges/${id}`);
  const [charge, setCharge] = useState(null);
  const [tab, setTab] = useState('card');
  const [received, setReceived] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  useEffect(() => {
    if (!loaded) return;
    setCharge(loaded);
  }, [loaded]);

  // The student's choice locks the tab (e.g. after cash is registered the reference becomes "cash").
  useEffect(() => {
    if (charge) setTab(charge.payment_method ?? 'cash');
  }, [charge?.payment_method]); // eslint-disable-line react-hooks/exhaustive-deps

  if (loading || !charge) {
    return <Modal open title="Revisar referencia" onClose={onClose}>{loadError ? <ErrorAlert error={loadError} /> : <Loading />}</Modal>;
  }

  const run = async (key, path, body, method = 'POST') => {
    setBusy(key);
    setError(null);
    setNotice(null);
    try {
      const res = await api(path, { method, body });
      setCharge(res.charge ?? res);
      setNotice(res.message ?? 'Cambios guardados.');
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  };

  const tx = charge.transactions ?? [];
  const cardTx = tx.filter((t) => t.method === 'card');
  const cashTx = tx.find((t) => t.method === 'cash' && t.status === 'approved');
  const paid = tx.some((t) => t.status === 'approved');
  const alive = !charge.is_expired;
  const lockedMethod = charge.payment_method;

  return (
    <Modal open wide title="Revisar referencia" onClose={onClose}>
      <div className="mb-4 grid gap-3 rounded-lg bg-slate-50 p-4 text-sm sm:grid-cols-2">
        <div>
          <p className="font-mono text-base font-semibold">{formatReference(charge.reference)}</p>
          <p className="text-slate-500">{charge.concept} · <b className="text-slate-700">{money(charge.amount)}</b></p>
        </div>
        <div className="sm:text-right">
          <p className="font-medium">{charge.student_name ?? `Alumno #${charge.student_id}`} <span className="text-xs text-slate-400">{charge.student_enrollment_number}</span></p>
          <p className="text-xs text-slate-500">Generada {dateTime(charge.created_at)} · {alive ? 'vence' : 'caducó'} {dateTime(charge.expires_at)}</p>
          <div className="mt-1 flex gap-1 sm:justify-end"><PaymentStatusBadge charge={charge} />{charge.is_new && <Badge tone="indigo">Nueva</Badge>}</div>
        </div>
      </div>

      {notice && <Alert tone="success">{notice}</Alert>}
      <ErrorAlert error={error} />

      <h3 className="mb-2 text-sm font-semibold text-slate-700">1. Método de pago {charge.method_label ? <span className="font-normal text-slate-500">(el alumno eligió {charge.method_label.toLowerCase()})</span> : <span className="font-normal text-slate-500">(el alumno aún no elige)</span>}</h3>
      <div className="mb-3 flex flex-wrap items-center gap-1">
        {METHOD_TABS.map(({ value, label, icon: Icon }) => (
          <button key={value} onClick={() => setTab(value)} disabled={!!lockedMethod && value !== lockedMethod}
            className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40 ${tab === value ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'text-slate-600 hover:bg-slate-100 disabled:hover:bg-transparent'}`}>
            <Icon className="h-4 w-4" /> {label}
            {lockedMethod === value && <Lock className="h-3.5 w-3.5" />}
          </button>
        ))}
        {lockedMethod && <span className="ml-1 text-xs text-slate-400">El método lo elige el alumno; no se puede cambiar.</span>}
      </div>

      <div className="mb-5 rounded-lg border border-slate-200 p-4">
        {tab === 'transfer' && <Alert tone="warning">El pago por transferencia no está disponible por el momento.</Alert>}

        {tab === 'card' && (cardTx.length === 0 ? (
          <p className="text-sm text-slate-500">No hay intentos de pago con tarjeta para esta referencia.</p>
        ) : (
          <>
            {cardTx.some((t) => t.status === 'approved')
              ? <Alert tone="success">Tarjeta validada por el banco y cargo aplicado: el dinero ya está reflejado en el sistema.</Alert>
              : <Alert tone="warning">Ningún intento con tarjeta fue aprobado; el dinero no se ha recibido.</Alert>}
            <Table head={['Fecha', 'Tarjeta', 'Resultado', 'Autorización', { label: 'Cargo', className: 'text-right' }]}>
              {cardTx.map((t) => (
                <tr key={t.id}>
                  <Td className="text-xs">{dateTime(t.created_at)}</Td>
                  <Td className="text-xs">{t.card_brand ?? '—'} •••• {t.card_last4}</Td>
                  <Td>{t.status === 'approved' ? <Badge tone="emerald">Aprobado</Badge> : <><Badge tone="rose">Rechazado</Badge><p className="mt-0.5 text-xs text-slate-500">{t.decline_reason}</p></>}</Td>
                  <Td className="font-mono text-xs">{t.authorization_code ?? '—'}</Td>
                  <Td className="text-right tabular-nums">{t.status === 'approved' ? money(t.amount) : '—'}</Td>
                </tr>
              ))}
            </Table>
          </>
        ))}

        {tab === 'cash' && (cashTx ? (
          <Alert tone="success">
            Efectivo recibido el {dateTime(cashTx.created_at)}: {money(cashTx.amount_received)} (total {money(cashTx.amount)}, cambio {money(cashTx.change_given)}).
          </Alert>
        ) : paid ? (
          <p className="text-sm text-slate-500">La referencia ya se pagó con otro método.</p>
        ) : !alive || charge.status !== 'pending' ? (
          <Alert tone="warning">La referencia caducó o ya no está pendiente: no se puede recibir efectivo.</Alert>
        ) : (
          <form className="flex flex-col gap-3 sm:flex-row sm:items-end" onSubmit={(e) => { e.preventDefault(); run('cash', `/payments/charges/${charge.id}/cash`, { amount_received: received }); }}>
            <Field className="flex-1" label={`Cantidad recibida (total ${money(charge.amount)})`} error={fieldError(error, 'amount_received')}>
              <Input inputMode="decimal" placeholder={charge.amount} value={received} onChange={(e) => setReceived(e.target.value)} required />
            </Field>
            <Button type="submit" icon={Wallet} loading={busy === 'cash'}>Verificar y registrar pago</Button>
          </form>
        ))}
      </div>

      <h3 className="mb-2 text-sm font-semibold text-slate-700">2. Estado de la referencia</h3>
      <div className="mb-3 grid gap-2 sm:grid-cols-3">
        <StatusOption tone="emerald" icon={CircleCheck} title="Aprobado" text="Se comprobó el pago de manera exitosa." enabled={paid}
          active={charge.status === 'approved'} loading={busy === 'approved'} onClick={() => run('approved', `/payments/charges/${charge.id}/status`, { status: 'approved', reason: reason || undefined }, 'PATCH')} />
        <StatusOption tone="amber" icon={Clock} title="Pendiente" text="Aún no paga, pero la referencia sigue viva." enabled={!paid && alive}
          active={charge.status === 'pending' && alive} loading={busy === 'pending'} onClick={() => run('pending', `/payments/charges/${charge.id}/status`, { status: 'pending', reason: reason || undefined }, 'PATCH')} />
        <StatusOption tone="rose" icon={CircleX} title="Rechazado" text="No pagó de ninguna manera y la referencia caducó." enabled={!paid && !alive}
          active={charge.status === 'rejected'} loading={busy === 'rejected'} onClick={() => run('rejected', `/payments/charges/${charge.id}/status`, { status: 'rejected', reason: reason || undefined }, 'PATCH')} />
      </div>
      <Field label="Nota (opcional)" className="mb-5">
        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ej. el alumno no se presentó a pagar" />
      </Field>

      <h3 className="mb-2 text-sm font-semibold text-slate-700">Historial</h3>
      <ol className="space-y-1 text-xs text-slate-600">
        {(charge.audit_logs ?? []).map((l) => (
          <li key={l.id} className="flex gap-2">
            <span className="w-36 shrink-0 text-slate-400">{dateTime(l.created_at)}</span>
            <span>
              {ACTION_LABELS[l.action] ?? l.action}
              {l.action === 'status_changed' && ` → ${({ approved: 'Aprobado', pending: 'Pendiente', rejected: 'Rechazado' })[l.to_status]}`}
              {l.reason && <span className="text-slate-400"> · {l.reason}</span>}
            </span>
          </li>
        ))}
      </ol>
    </Modal>
  );
}

function StatusOption({ tone, icon: Icon, title, text, enabled, active, loading, onClick }) {
  const tones = {
    emerald: 'border-emerald-300 bg-emerald-50 text-emerald-800',
    amber: 'border-amber-300 bg-amber-50 text-amber-800',
    rose: 'border-rose-300 bg-rose-50 text-rose-800',
  };
  return (
    <button type="button" disabled={!enabled || loading} onClick={onClick}
      className={`rounded-lg border p-3 text-left transition disabled:cursor-not-allowed disabled:opacity-40 ${active ? tones[tone] + ' ring-2 ring-offset-1 ring-current' : 'border-slate-200 hover:bg-slate-50'}`}>
      <p className="flex items-center gap-1.5 text-sm font-semibold"><Icon className="h-4 w-4" /> {title}</p>
      <p className="mt-1 text-xs opacity-80">{text}</p>
    </button>
  );
}
