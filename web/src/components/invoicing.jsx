import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, FileCode, Printer, Receipt } from 'lucide-react';
import { api, downloadFile, fieldError } from '../api.js';
import { useApi } from '../hooks.js';
import { Alert, Badge, Button, ErrorAlert, Field, Input, Loading, Modal, Select, Table, Td } from './ui.jsx';
import { dateTime, formatReference, money } from './payments.jsx';

/** Student routes live under /me, admin routes under /payments (both go to payments-service through the gateway). */
export const invoiceApi = (scope) => (scope === 'admin' ? '/payments' : '/me');
export const invoicePage = (scope, id) => (scope === 'admin' ? `/admin/invoices/${id}` : `/student/invoices/${id}`);

const STATUS = { stamped: ['Timbrada', 'emerald'], cancelled: ['Cancelada', 'slate'], failed: ['Error al timbrar', 'rose'] };

export function InvoiceStatusBadge({ invoice }) {
  const [label, tone] = STATUS[invoice.status] ?? [invoice.status, 'slate'];
  return <Badge tone={tone}>{label}</Badge>;
}

export const invoiceFilename = (inv) => `${inv.series}${inv.folio ?? ''}_${inv.uuid ?? inv.id}.xml`;

export function downloadInvoiceXml(scope, inv) {
  return downloadFile(`${invoiceApi(scope)}/invoices/${inv.id}/xml`, invoiceFilename(inv));
}

const EMPTY = { rfc: '', name: '', zip: '', regime: '', cfdi_use: '' };

/**
 * Invoice of a paid reference: shows the invoices it already has and, if none is valid,
 * the fiscal data form. The CFDI is built and stamped by payments-service through the PAC.
 */
export function InvoiceModal({ charge, scope = 'student', onClose, onChanged }) {
  const base = invoiceApi(scope);
  const catalogs = useApi('/payments/invoicing/catalogs');
  const info = useApi(`${base}/charges/${charge.id}/invoice`);
  const [form, setForm] = useState(EMPTY);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  // Prefill with the fiscal data used last time.
  useEffect(() => {
    const last = info.data?.last_receiver;
    if (last) setForm((f) => (f.rfc ? f : { ...EMPTY, ...last }));
  }, [info.data]);

  if (!catalogs.data || !info.data) {
    return (
      <Modal open wide title="Factura electrónica" onClose={onClose}>
        {catalogs.error || info.error ? <ErrorAlert error={catalogs.error ?? info.error} /> : <Loading />}
      </Modal>
    );
  }

  const cat = catalogs.data;
  const rfc = form.rfc.trim().toUpperCase();
  const generic = rfc === cat.generic_rfc;
  const moral = rfc.length === 12;
  const regimes = cat.regimes.filter((r) => (rfc.length < 12 ? true : moral ? r.moral : r.fisica) && r.code !== '616');
  const uses = cat.uses.filter((u) => !form.regime || u.regimes.includes(form.regime));

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const body = generic ? { rfc, name: form.name } : { ...form, rfc };
      const res = await api(`${base}/charges/${charge.id}/invoice`, { method: 'POST', body });
      setNotice(res.message);
      info.reload();
      onChanged?.();
    } catch (err) {
      setError(err);
      info.reload(); // a failed attempt is listed too
    } finally {
      setBusy(false);
    }
  };

  const invoices = info.data.invoices;

  return (
    <Modal open wide title="Factura electrónica (CFDI 4.0)" onClose={onClose}>
      <div className="mb-4 flex flex-wrap justify-between gap-2 rounded-lg bg-slate-50 px-4 py-3 text-sm">
        <div>
          <p className="font-medium text-slate-800">{charge.concept}</p>
          <p className="font-mono text-xs text-slate-500">Ref. {formatReference(charge.reference)}</p>
        </div>
        <p className="text-lg font-semibold tabular-nums">{money(charge.amount)}</p>
      </div>

      {cat.issuer?.test_environment && (
        <Alert tone="warning">Ambiente de pruebas del PAC: las facturas no tienen validez fiscal. Receptor de prueba: RFC <b className="font-mono">EKU9003173C9</b>, ESCUELA KEMPER URGATE, C.P. 42501, régimen 603, uso G03.</Alert>
      )}
      {notice && <Alert tone="success">{notice}</Alert>}

      {invoices.length > 0 && (
        <div className="mb-5">
          <InvoiceTable invoices={invoices} scope={scope} />
        </div>
      )}

      {info.data.can_invoice ? (
        <form onSubmit={submit} className="space-y-3">
          <h3 className="text-sm font-semibold text-slate-700">Datos fiscales del receptor</h3>
          <p className="text-xs text-slate-500">Captúralos tal como aparecen en la Constancia de Situación Fiscal. Si no tienes RFC usa <button type="button" className="font-mono text-indigo-600 underline" onClick={() => setForm((f) => ({ ...f, rfc: cat.generic_rfc }))}>{cat.generic_rfc}</button> (público en general).</p>
          <ErrorAlert error={error && !Object.keys(error.errors ?? {}).length ? error : null} />
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="RFC" error={fieldError(error, 'rfc')}>
              <Input className="font-mono uppercase" maxLength={13} value={form.rfc} onChange={set('rfc')} required />
            </Field>
            <Field label="Nombre o razón social" error={fieldError(error, 'name')} hint={moral ? 'Sin el régimen societario (ej. sin "SA DE CV").' : undefined}>
              <Input value={form.name} onChange={set('name')} required />
            </Field>
          </div>
          {generic ? (
            <p className="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">Con el RFC genérico se usa régimen 616 (sin obligaciones fiscales), uso S01 (sin efectos fiscales) y el código postal de la escuela.</p>
          ) : (
            <div className="grid gap-3 sm:grid-cols-3">
              <Field label="Código postal fiscal" error={fieldError(error, 'zip')}>
                <Input inputMode="numeric" maxLength={5} value={form.zip} onChange={set('zip')} required />
              </Field>
              <Field label="Régimen fiscal" error={fieldError(error, 'regime')}>
                <Select value={form.regime} onChange={(e) => setForm((f) => ({ ...f, regime: e.target.value, cfdi_use: '' }))} required placeholder="Elige…"
                  options={regimes.map((r) => ({ value: r.code, label: `${r.code} · ${r.name}` }))} />
              </Field>
              <Field label="Uso del CFDI" error={fieldError(error, 'cfdi_use')}>
                <Select value={form.cfdi_use} onChange={set('cfdi_use')} required placeholder="Elige…"
                  options={uses.map((u) => ({ value: u.code, label: `${u.code} · ${u.name}` }))} />
              </Field>
            </div>
          )}
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="secondary" onClick={onClose}>Cerrar</Button>
            <Button type="submit" icon={Receipt} loading={busy}>Timbrar factura</Button>
          </div>
        </form>
      ) : (
        <div className="flex justify-end"><Button variant="secondary" onClick={onClose}>Cerrar</Button></div>
      )}
    </Modal>
  );
}

export function InvoiceTable({ invoices, scope, onCancel, showStudent }) {
  const [error, setError] = useState(null);
  const xml = (inv) => downloadInvoiceXml(scope, inv).catch(setError);

  return (
    <>
      <ErrorAlert error={error} />
      <Table head={['Folio', ...(showStudent ? ['Alumno'] : []), 'Receptor', { label: 'Total', className: 'text-right' }, ...(showStudent ? ['Fecha'] : []), 'Estado', '']}>
        {invoices.map((inv) => (
          <tr key={inv.id} className="hover:bg-slate-50">
            <Td>
              <p className="font-medium">{inv.series}{inv.folio}</p>
              {inv.uuid && <p className="font-mono text-[11px] text-slate-400">{inv.uuid}</p>}
              {inv.charge?.reference && <p className="font-mono text-[11px] text-slate-400">Ref. {formatReference(inv.charge.reference)}</p>}
            </Td>
            {showStudent && (
              <Td>
                <p>{inv.student_name ?? `Alumno #${inv.student_id}`}</p>
                {inv.student_enrollment_number && <p className="text-xs text-slate-400">{inv.student_enrollment_number}</p>}
              </Td>
            )}
            <Td>
              <p className="font-mono text-xs">{inv.receiver_rfc}</p>
              <p className="text-xs text-slate-500">{inv.receiver_name}</p>
            </Td>
            <Td className="text-right tabular-nums">{money(inv.total)}</Td>
            {showStudent && <Td className="whitespace-nowrap text-xs">{dateTime(inv.stamped_at ?? inv.created_at)}</Td>}
            <Td>
              <InvoiceStatusBadge invoice={inv} />
              {inv.status === 'failed' && <p className="mt-1 max-w-xs text-xs text-rose-600">{inv.error_code && <b>[{inv.error_code}] </b>}{inv.error_message}</p>}
              {inv.status === 'cancelled' && <p className="mt-1 text-xs text-slate-500">Motivo {inv.cancel_reason} · {dateTime(inv.cancelled_at)}</p>}
            </Td>
            <Td className="whitespace-nowrap text-right">
              {inv.status !== 'failed' && (
                <div className="flex justify-end gap-1">
                  <Link to={invoicePage(scope, inv.id)}><Button size="sm" variant="secondary" icon={Printer}>Ver</Button></Link>
                  <Button size="sm" variant="secondary" icon={FileCode} onClick={() => xml(inv)}>XML</Button>
                  {onCancel && inv.status === 'stamped' && <Button size="sm" variant="ghost-danger" icon={Ban} onClick={() => onCancel(inv)}>Cancelar</Button>}
                </div>
              )}
            </Td>
          </tr>
        ))}
      </Table>
    </>
  );
}

/** Admin: cancellation request to the SAT through the PAC. */
export function CancelInvoiceModal({ invoice, onClose, onDone }) {
  const catalogs = useApi('/payments/invoicing/catalogs');
  const [reason, setReason] = useState('02');
  const [replacement, setReplacement] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api(`/payments/invoices/${invoice.id}/cancel`, { method: 'POST', body: { reason, replacement_uuid: reason === '01' ? replacement : undefined } });
      onDone();
      onClose();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open title={`Cancelar factura ${invoice.series}${invoice.folio}`} onClose={onClose}>
      <form onSubmit={submit} className="space-y-3">
        <p className="text-sm text-slate-600">Folio fiscal <span className="font-mono text-xs">{invoice.uuid}</span> · {money(invoice.total)}</p>
        <ErrorAlert error={error && !Object.keys(error.errors ?? {}).length ? error : null} />
        <Field label="Motivo de cancelación (SAT)" error={fieldError(error, 'reason')}>
          <Select value={reason} onChange={(e) => setReason(e.target.value)}
            options={(catalogs.data?.cancel_reasons ?? []).map((r) => ({ value: r.code, label: `${r.code} · ${r.name}` }))} />
        </Field>
        {reason === '01' && (
          <Field label="Folio fiscal (UUID) de la factura que la sustituye" error={fieldError(error, 'replacement_uuid')}>
            <Input className="font-mono" value={replacement} onChange={(e) => setReplacement(e.target.value)} required />
          </Field>
        )}
        <Alert tone="warning">La cancelación se envía al SAT y no se puede deshacer. Después podrás emitir una nueva factura para la misma referencia.</Alert>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>Volver</Button>
          <Button type="submit" variant="danger" icon={Ban} loading={busy}>Cancelar factura</Button>
        </div>
      </form>
    </Modal>
  );
}
