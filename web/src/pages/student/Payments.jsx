import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Banknote, CreditCard, FileText, Landmark, Printer, Receipt, Wallet } from 'lucide-react';
import { api, fieldError } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Button, Card, Empty, ErrorAlert, Field, Input, Loading, Modal, PageHeader, Stat, Table, Td } from '../../components/ui.jsx';
import { PaymentResult, PaymentStatusBadge, dateTime, formatReference, money } from '../../components/payments.jsx';

/** Student: request paperwork, get a payment reference and pay it (card, cash; transfer not available yet). */
export default function StudentPayments() {
  const types = useApi('/payments/document-types');
  const charges = useApi('/me/charges');
  const [creating, setCreating] = useState(null);
  const [error, setError] = useState(null);
  const [paying, setPaying] = useState(null);
  const [generated, setGenerated] = useState(null);

  if ((types.loading && !types.data) || (charges.loading && !charges.data)) return <Loading />;

  const generate = async (type) => {
    setCreating(type.id);
    setError(null);
    try {
      const charge = await api('/me/charges', { method: 'POST', body: { document_type_id: type.id } });
      setGenerated(charge);
      charges.reload();
    } catch (e) {
      setError(e);
    } finally {
      setCreating(null);
    }
  };

  const list = charges.data?.data ?? [];
  const summary = charges.data?.summary;

  return (
    <>
      <PageHeader title="Trámites y pagos" subtitle="Solicita tu papelería, genera tu referencia y págala con tarjeta o en efectivo." />
      <ErrorAlert error={types.error ?? charges.error ?? error} />

      {summary && (
        <div className="mb-6 grid gap-4 sm:grid-cols-3">
          <Stat label="Por pagar" value={money(summary.pending_total)} icon={Wallet} tone="amber" />
          <Stat label="Pagado" value={money(summary.approved_total)} icon={Receipt} tone="emerald" />
          <Stat label="Referencias" value={list.length} icon={FileText} />
        </div>
      )}

      <Card title="Solicitar documento" icon={FileText} className="mb-6">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {(types.data?.data ?? []).map((t) => (
            <div key={t.id} className="flex flex-col rounded-lg border border-slate-200 p-4">
              <p className="font-medium text-slate-800">{t.name}</p>
              <p className="mt-1 flex-1 text-xs text-slate-500">{t.description}</p>
              <div className="mt-3 flex items-center justify-between gap-2">
                <span className="text-lg font-semibold tabular-nums text-slate-800">{money(t.price)}</span>
                <Button size="sm" loading={creating === t.id} onClick={() => generate(t)}>Generar referencia</Button>
              </div>
            </div>
          ))}
        </div>
      </Card>

      <Card title="Mis referencias" icon={Receipt} padding={false}>
        {list.length === 0 ? (
          <Empty icon={Receipt} title="Aún no tienes referencias" message="Elige un documento arriba para generar tu referencia de pago." />
        ) : (
          <Table head={['Referencia', 'Documento', { label: 'Monto', className: 'text-right' }, 'Vence', 'Método', 'Estado', '']}>
            {list.map((c) => (
              <tr key={c.id} className="hover:bg-slate-50">
                <Td className="font-mono text-xs">{formatReference(c.reference)}</Td>
                <Td>{c.concept}</Td>
                <Td className="text-right tabular-nums">{money(c.amount)}</Td>
                <Td className="whitespace-nowrap text-xs">{dateTime(c.expires_at)}</Td>
                <Td>{c.method_label ?? <span className="text-slate-400">—</span>}</Td>
                <Td><PaymentStatusBadge charge={c} /></Td>
                <Td className="text-right">
                  {c.status === 'pending' && !c.is_expired && (
                    <Button size="sm" icon={CreditCard} onClick={() => setPaying(c)}>{c.payment_method === 'cash' ? 'Ver / pagar' : 'Pagar'}</Button>
                  )}
                  {c.status === 'approved' && (
                    <Link to={`/student/documents/${c.id}`}>
                      <Button size="sm" variant="secondary" icon={Printer}>Ver documento</Button>
                    </Link>
                  )}
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Modal open={!!generated} title="Referencia generada" onClose={() => setGenerated(null)}
        footer={<><Button variant="secondary" onClick={() => setGenerated(null)}>Pagar después</Button><Button onClick={() => { setPaying(generated); setGenerated(null); }}>Pagar ahora</Button></>}>
        {generated && (
          <div className="text-center">
            <p className="text-sm text-slate-500">{generated.concept}</p>
            <p className="mt-2 font-mono text-2xl font-semibold tracking-wider text-slate-800">{formatReference(generated.reference)}</p>
            <p className="mt-2 text-lg font-semibold">{money(generated.amount)}</p>
            <p className="mt-1 text-xs text-slate-500">Vigente hasta el {dateTime(generated.expires_at)}</p>
          </div>
        )}
      </Modal>

      {paying && <PayModal charge={paying} onClose={() => setPaying(null)} onDone={() => charges.reload()} />}
    </>
  );
}

const METHODS = [
  { value: 'card', label: 'Tarjeta', icon: CreditCard },
  { value: 'cash', label: 'Efectivo', icon: Banknote },
  { value: 'transfer', label: 'Transferencia', icon: Landmark },
];

const EMPTY_CARD = { number: '', holder_name: '', exp: '', cvv: '' };

function PayModal({ charge, onClose, onDone }) {
  const [method, setMethod] = useState(charge.payment_method ?? 'card');
  const [card, setCard] = useState(EMPTY_CARD);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [result, setResult] = useState(null);

  const set = (k) => (e) => setCard((c) => ({ ...c, [k]: e.target.value }));

  const submit = async (e) => {
    e?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const [mm, yy] = card.exp.split('/').map((s) => s.trim());
      const body = method === 'card'
        ? { method, card: { number: card.number, holder_name: card.holder_name, exp_month: Number(mm), exp_year: yy?.length === 2 ? 2000 + Number(yy) : Number(yy), cvv: card.cvv } }
        : { method };
      const res = await api(`/me/charges/${charge.id}/pay`, { method: 'POST', body });
      setResult(res);
      onDone();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  if (result) {
    return (
      <Modal open title="Resultado del pago" onClose={onClose} footer={<Button onClick={onClose}>Entendido</Button>}>
        <PaymentResult result={result.result} message={result.message}>
          <p className="font-mono text-sm">Referencia {formatReference(result.charge.reference)} · {money(result.charge.amount)}</p>
          {result.result === 'rejected' && (
            <Button className="mt-3" variant="secondary" onClick={() => { setResult(null); setCard(EMPTY_CARD); }}>Intentar con otra tarjeta</Button>
          )}
        </PaymentResult>
      </Modal>
    );
  }

  return (
    <Modal open title="Pagar referencia" onClose={onClose}>
      <div className="mb-4 rounded-lg bg-slate-50 px-4 py-3 text-sm">
        <div className="flex justify-between gap-3"><span className="text-slate-500">{charge.concept}</span><span className="font-semibold">{money(charge.amount)}</span></div>
        <p className="mt-1 font-mono text-xs text-slate-500">Ref. {formatReference(charge.reference)} · vence {dateTime(charge.expires_at)}</p>
      </div>

      <div className="mb-4 grid grid-cols-3 gap-2">
        {METHODS.map(({ value, label, icon: Icon }) => (
          <button key={value} type="button" onClick={() => { setMethod(value); setError(null); }}
            className={`flex flex-col items-center gap-1 rounded-lg border px-2 py-3 text-sm font-medium transition ${method === value ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
            <Icon className="h-5 w-5" /> {label}
          </button>
        ))}
      </div>

      <ErrorAlert error={error && !Object.keys(error.errors ?? {}).length ? error : null} />

      {method === 'transfer' && (
        <Alert tone="warning">El pago por transferencia no está disponible por el momento. Elige tarjeta o efectivo.</Alert>
      )}

      {method === 'cash' && (
        <>
          <Alert tone="warning">
            Acude a la caja de Control Escolar con tu referencia <b className="font-mono">{formatReference(charge.reference)}</b> y paga <b>{money(charge.amount)}</b> antes del {dateTime(charge.expires_at)}.
            Tu referencia quedará <b>pendiente</b> hasta que Control Escolar reciba el efectivo.
          </Alert>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>Cancelar</Button>
            <Button icon={Banknote} loading={busy} onClick={submit}>Pagaré en efectivo</Button>
          </div>
        </>
      )}

      {method === 'card' && (
        <form onSubmit={submit} className="space-y-3">
          <Field label="Número de tarjeta" error={fieldError(error, 'card.number')}>
            <Input inputMode="numeric" autoComplete="off" placeholder="4111 1111 1111 1111" value={card.number} onChange={set('number')} required />
          </Field>
          <Field label="Nombre del titular" error={fieldError(error, 'card.holder_name')}>
            <Input autoComplete="off" value={card.holder_name} onChange={set('holder_name')} required />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Vencimiento (MM/AA)" error={fieldError(error, 'card.exp_month') ?? fieldError(error, 'card.exp_year')}>
              <Input placeholder="12/30" autoComplete="off" value={card.exp} onChange={set('exp')} required />
            </Field>
            <Field label="CVV" error={fieldError(error, 'card.cvv')}>
              <Input type="password" inputMode="numeric" maxLength={4} autoComplete="off" value={card.cvv} onChange={set('cvv')} required />
            </Field>
          </div>
          <p className="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">
            Modo de prueba (banco local): <span className="font-mono">4111 1111 1111 1111</span>, vence 12/30, CVV 123.
            Para ver un rechazo usa <span className="font-mono">4000 0000 0000 0002</span> (fondos insuficientes).
          </p>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="secondary" onClick={onClose}>Cancelar</Button>
            <Button type="submit" icon={CreditCard} loading={busy}>Pagar {money(charge.amount)}</Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
