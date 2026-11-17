import { useState } from 'react';
import { FileText, Search } from 'lucide-react';
import { useApi } from '../../hooks.js';
import { Card, Empty, ErrorAlert, Input, Loading, PageHeader, Select } from '../../components/ui.jsx';
import { CancelInvoiceModal, InvoiceTable } from '../../components/invoicing.jsx';

const STATUS_FILTER = [
  { value: 'stamped', label: 'Timbradas' },
  { value: 'cancelled', label: 'Canceladas' },
  { value: 'failed', label: 'Con error' },
];

/** Control Escolar: every CFDI issued, with XML download, printed version and cancellation. */
export default function AdminInvoices() {
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const [cancelling, setCancelling] = useState(null);
  const list = useApi('/payments/invoices', { query: { status, q } });
  const rows = list.data?.data ?? [];

  return (
    <>
      <PageHeader title="Facturas" subtitle="CFDI 4.0 timbrados por el PAC (TimbraCFDI) desde el servicio de pagos." />
      <Card padding={false}>
        <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:justify-end">
          <div className="sm:w-40"><Select value={status} onChange={(e) => setStatus(e.target.value)} options={STATUS_FILTER} placeholder="Todas" /></div>
          <div className="relative sm:w-64">
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <Input className="pl-9" placeholder="UUID, RFC, nombre o referencia" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
        </div>
        <ErrorAlert error={list.error} />
        {list.loading && !list.data ? (
          <Loading />
        ) : rows.length === 0 ? (
          <Empty icon={FileText} title="No hay facturas en esta vista" message="Las facturas se emiten desde una referencia aprobada (Pagos y referencias → Revisar → Facturar)." />
        ) : (
          <InvoiceTable invoices={rows} scope="admin" showStudent onCancel={setCancelling} />
        )}
      </Card>
      {cancelling && <CancelInvoiceModal invoice={cancelling} onClose={() => setCancelling(null)} onDone={list.reload} />}
    </>
  );
}
