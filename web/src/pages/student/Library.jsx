import { useEffect, useState } from 'react';
import { BookOpenText, History, Play, Square, Timer } from 'lucide-react';
import { api } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Loading, PageHeader, Table, Td } from '../../components/ui.jsx';
import { clock, human } from '../../components/membership.jsx';
import { TimeBalance } from '../../components/TimeBalance.jsx';
import { dateTime } from '../../components/payments.jsx';

/** Time module 2 — digital library used by the minute: open a session, the stopwatch runs, close it to pay the minutes. */
export default function Library() {
  const time = useApi('/me/time');
  const resources = useApi('/memberships/library/resources');
  const sessions = useApi('/me/library-sessions');
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);
  const [now, setNow] = useState(Date.now());

  const open = sessions.data?.open;

  useEffect(() => {
    if (!open) return;
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, [open]);

  // The session closes itself at its limit: refresh when the counter reaches 0.
  const elapsed = open ? Math.min(open.limit_seconds, Math.floor((now - new Date(open.started_at).getTime()) / 1000)) : 0;
  const remaining = open ? open.limit_seconds - elapsed : 0;
  useEffect(() => {
    if (open && remaining <= 0) {
      sessions.reload();
      time.reload();
    }
  }, [open, remaining <= 0]); // eslint-disable-line react-hooks/exhaustive-deps

  if ((time.loading && !time.data) || (resources.loading && !resources.data) || (sessions.loading && !sessions.data)) return <Loading />;

  const refresh = () => { sessions.reload(); time.reload(); };

  const start = async (code) => {
    setBusy(code);
    setError(null);
    setNotice(null);
    try {
      await api('/me/library-sessions', { method: 'POST', body: { resource: code } });
      setNow(Date.now());
      refresh();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  };

  const close = async () => {
    setBusy('close');
    setError(null);
    try {
      const s = await api(`/me/library-sessions/${open.id}/close`, { method: 'POST' });
      setNotice(`Sesión cerrada: se descontaron ${human(s.used_seconds)} (se cobra por minuto, redondeado hacia arriba).`);
      refresh();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <PageHeader title="Biblioteca digital" subtitle="Acceso a bases de datos académicas por minuto. Se cobra el tiempo que la sesión estuvo abierta." />
      <Alert tone="success">{notice}</Alert>
      <ErrorAlert error={error} />
      <div className="mb-6"><TimeBalance status={time.data?.modules.library} onChange={refresh} /></div>

      {open && (
        <section className="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
          <div className="flex items-center gap-4">
            <div className="rounded-full bg-emerald-600 p-3 text-white"><Timer className="h-6 w-6" /></div>
            <div>
              <p className="text-sm text-emerald-800">Sesión abierta en <b>{open.resource_name}</b> desde {dateTime(open.started_at)}</p>
              <p className="text-4xl font-semibold tabular-nums text-emerald-900">{clock(elapsed)}</p>
              <p className="text-xs text-emerald-700">Te quedan {clock(remaining)}; al llegar a 0 la sesión se cierra sola.</p>
            </div>
          </div>
          <Button variant="danger" icon={Square} loading={busy === 'close'} onClick={close}>Cerrar sesión</Button>
        </section>
      )}

      <Card title="Recursos" icon={BookOpenText} className="mb-6">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {(resources.data?.data ?? []).map((r) => (
            <div key={r.code} className="flex flex-col rounded-xl border border-slate-200 p-4">
              <p className="font-medium text-slate-800">{r.name}</p>
              <p className="mt-1 flex-1 text-xs text-slate-500">{r.description}</p>
              <Button className="mt-3" size="sm" icon={Play} disabled={!!open} loading={busy === r.code} onClick={() => start(r.code)}>Abrir sesión</Button>
            </div>
          ))}
        </div>
      </Card>

      <Card title="Historial de sesiones" icon={History} padding={false}>
        {!sessions.data?.data?.length ? <Empty icon={History} title="Sin sesiones" /> : (
          <Table head={['Recurso', 'Inicio', 'Fin', 'Minutos cobrados', 'Estado']}>
            {sessions.data.data.map((s) => (
              <tr key={s.id}>
                <Td>{s.resource_name}</Td>
                <Td className="whitespace-nowrap text-xs">{dateTime(s.started_at)}</Td>
                <Td className="whitespace-nowrap text-xs">{s.ended_at ? dateTime(s.ended_at) : '—'}</Td>
                <Td className="tabular-nums">{s.status === 'closed' ? human(s.used_seconds) : '—'}</Td>
                <Td>{s.status === 'open' ? <Badge tone="emerald">Abierta</Badge> : s.closed_by === 'limit' ? <Badge tone="amber">Cerrada al agotar el tiempo</Badge> : <Badge tone="slate">Cerrada</Badge>}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  );
}
