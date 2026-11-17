import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, FileCode, GraduationCap, Printer } from 'lucide-react';
import { useApi } from '../hooks.js';
import { Alert, Button, ErrorAlert, Loading } from '../components/ui.jsx';
import { formatReference, money } from '../components/payments.jsx';
import { InvoiceStatusBadge, downloadInvoiceXml, invoiceApi } from '../components/invoicing.jsx';

const FORMS = { '01': 'Efectivo', '04': 'Tarjeta de crédito', '28': 'Tarjeta de débito', '03': 'Transferencia electrónica', '99': 'Por definir' };

/** Printed representation of a stamped CFDI (what the SAT calls "representación impresa"). */
export default function InvoiceView({ scope = 'student' }) {
  const { id } = useParams();
  const { data: inv, error, loading } = useApi(`${invoiceApi(scope)}/invoices/${id}`);
  const [xmlError, setXmlError] = useState(null);
  const back = scope === 'admin' ? '/admin/invoices' : '/student/payments';

  if (loading && !inv) return <Loading />;
  if (error) {
    return (
      <>
        <ErrorAlert error={error} />
        <Link to={back}><Button variant="secondary" icon={ArrowLeft}>Volver</Button></Link>
      </>
    );
  }

  const seals = inv.seals ?? {};
  const charge = inv.charge ?? {};

  return (
    <>
      <div className="mb-4 flex flex-wrap justify-between gap-2 print:hidden">
        <Link to={back}><Button variant="secondary" icon={ArrowLeft}>Volver</Button></Link>
        <div className="flex gap-2">
          <Button variant="secondary" icon={FileCode} onClick={() => downloadInvoiceXml(scope, inv).catch(setXmlError)}>Descargar XML</Button>
          <Button icon={Printer} onClick={() => window.print()}>Imprimir / guardar PDF</Button>
        </div>
      </div>
      <ErrorAlert error={xmlError} />
      {inv.status === 'cancelled' && <Alert tone="warning">Esta factura fue cancelada ante el SAT (motivo {inv.cancel_reason}).</Alert>}

      <article className="mx-auto max-w-4xl rounded-xl border border-slate-200 bg-white p-6 text-sm shadow-sm print:border-0 print:p-0 print:shadow-none sm:p-10">
        <header className="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:justify-between">
          <div className="flex gap-3">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white"><GraduationCap className="h-7 w-7" /></div>
            <div>
              <p className="font-semibold text-slate-900">{inv.issuer.name}</p>
              <p className="font-mono text-xs text-slate-600">RFC {inv.issuer.rfc}</p>
              <p className="text-xs text-slate-500">Régimen fiscal {inv.issuer.regime} · Lugar de expedición {inv.issuer.zip}</p>
            </div>
          </div>
          <div className="sm:text-right">
            <p className="text-lg font-semibold text-slate-900">Factura {inv.series}{inv.folio}</p>
            <p className="text-xs text-slate-500">CFDI 4.0 · Ingreso (I)</p>
            <div className="mt-1"><InvoiceStatusBadge invoice={inv} /></div>
          </div>
        </header>

        <section className="grid gap-4 border-b border-slate-200 py-5 sm:grid-cols-2">
          <div>
            <p className="text-xs font-semibold uppercase text-slate-400">Receptor</p>
            <p className="font-medium text-slate-800">{inv.receiver_name}</p>
            <p className="font-mono text-xs">RFC {inv.receiver_rfc}</p>
            <p className="text-xs text-slate-500">C.P. {inv.receiver_zip} · Régimen {inv.receiver_regime} · Uso CFDI {inv.cfdi_use}</p>
          </div>
          <div className="text-xs sm:text-right">
            <p className="font-semibold uppercase text-slate-400">Folio fiscal (UUID)</p>
            <p className="font-mono text-slate-800">{inv.uuid}</p>
            <p className="mt-1 text-slate-500">Certificación: {seals.certified_at ?? '—'}</p>
            <p className="text-slate-500">Forma de pago {inv.payment_form} ({FORMS[inv.payment_form] ?? '—'}) · Método PUE · MXN</p>
          </div>
        </section>

        <table className="mt-5 w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-400">
            <tr><th className="py-2">Concepto</th><th className="py-2">Clave</th><th className="py-2 text-right">Importe</th></tr>
          </thead>
          <tbody>
            <tr className="border-b border-slate-100">
              <td className="py-3">
                <p className="font-medium text-slate-800">{charge.concept}</p>
                <p className="font-mono text-xs text-slate-500">Referencia de pago {formatReference(charge.reference)}</p>
              </td>
              <td className="py-3 text-xs text-slate-500">1 · E48 Servicio</td>
              <td className="py-3 text-right tabular-nums">{money(inv.subtotal)}</td>
            </tr>
          </tbody>
        </table>
        <div className="ml-auto mt-3 w-full max-w-xs space-y-1 text-sm">
          <div className="flex justify-between"><span className="text-slate-500">Subtotal</span><span className="tabular-nums">{money(inv.subtotal)}</span></div>
          <div className="flex justify-between"><span className="text-slate-500">{Number(inv.tax) > 0 ? 'IVA trasladado' : 'IVA (exento)'}</span><span className="tabular-nums">{money(inv.tax)}</span></div>
          <div className="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold"><span>Total</span><span className="tabular-nums">{money(inv.total)}</span></div>
        </div>

        <section className="mt-8 flex flex-col gap-5 border-t border-slate-200 pt-5 sm:flex-row">
          {inv.qr_png && <img src={`data:image/png;base64,${inv.qr_png}`} alt="Código QR de verificación del SAT" className="h-36 w-36 shrink-0 self-center sm:self-start" />}
          <div className="min-w-0 flex-1 space-y-2 break-all font-mono text-[10px] leading-snug text-slate-600">
            <p><b className="font-sans text-slate-700">Sello digital del CFDI:</b> {seals.cfdi_seal ?? '—'}</p>
            <p><b className="font-sans text-slate-700">Sello del SAT:</b> {seals.sat_seal ?? '—'}</p>
            <p><b className="font-sans text-slate-700">Cadena original del complemento de certificación digital del SAT:</b> {inv.original_chain ?? '—'}</p>
            <p className="font-sans">No. certificado emisor {seals.issuer_certificate ?? '—'} · No. certificado SAT {seals.sat_certificate ?? '—'} · RFC PAC {seals.pac_rfc ?? '—'}</p>
          </div>
        </section>
        <p className="mt-6 text-center text-xs text-slate-400">Este documento es una representación impresa de un CFDI.</p>
      </article>
    </>
  );
}
