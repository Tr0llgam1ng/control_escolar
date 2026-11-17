import { useState } from 'react';
import { Check, CheckCheck, MessagesSquare, X } from 'lucide-react';
import { api } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Field, Input, Loading, Modal, PageHeader, Table, Td } from '../../components/ui.jsx';
import { dateTime } from '../../components/payments.jsx';
import { TUTORING_TONE } from '../student/Tutoring.jsx';

/** Teacher: tutoring sessions students asked me for (memberships credits module). */
export default function TutoringRequests() {
  const { data, error, loading, reload } = useApi('/me/tutoring-requests');
  const [deciding, setDeciding] = useState(null);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState(null);
  const [notice, setNotice] = useState(null);

  if (loading && !data) return <Loading />;

  const decide = async () => {
    setBusy(true);
    setActionError(null);
    try {
      const s = await api(`/me/tutoring-requests/${deciding.session.id}`, { method: 'PATCH', body: { status: deciding.status, notes: notes || null } });
      setNotice(`Asesoría de ${s.student_name ?? 'alumno'}: ${s.status_label}.${s.refunded ? ' Se le devolvieron sus créditos.' : ''}`);
      setDeciding(null);
      setNotes('');
      reload();
    } catch (e) {
      setActionError(e);
    } finally {
      setBusy(false);
    }
  };

  const ask = (session, status) => { setDeciding({ session, status }); setNotes(''); setActionError(null); };
  const rows = data?.data ?? [];
  const LABEL = { confirmed: 'Confirmar', rejected: 'Rechazar', completed: 'Marcar como impartida' };

  return (
    <>
      <PageHeader title="Asesorías" subtitle="Solicitudes de alumnos con membresía. Si rechazas una, el alumno recupera sus créditos." />
      <Alert tone="success">{notice}</Alert>
      <ErrorAlert error={error} />

      <Card title="Solicitudes" icon={MessagesSquare} padding={false}>
        {!rows.length ? <Empty icon={MessagesSquare} title="Sin solicitudes" message="Cuando un alumno te pida una asesoría aparecerá aquí." /> : (
          <Table head={['Fecha', 'Alumno', 'Materia / tema', 'Estado', '']}>
            {rows.map((s) => (
              <tr key={s.id}>
                <Td className="whitespace-nowrap">{dateTime(s.scheduled_at)}<p className="text-xs text-slate-400">{s.duration_minutes} min</p></Td>
                <Td>{s.student_name ?? `Usuario ${s.user_id}`}</Td>
                <Td><p className="font-medium">{s.subject}</p><p className="text-xs text-slate-500">{s.topic}</p></Td>
                <Td><Badge tone={TUTORING_TONE[s.status]}>{s.status_label}</Badge></Td>
                <Td className="text-right">
                  <div className="flex justify-end gap-1">
                    {s.status === 'requested' && <Button size="sm" icon={Check} onClick={() => ask(s, 'confirmed')}>Confirmar</Button>}
                    {s.status === 'confirmed' && new Date(s.scheduled_at) <= new Date() && <Button size="sm" icon={CheckCheck} onClick={() => ask(s, 'completed')}>Impartida</Button>}
                    {['requested', 'confirmed'].includes(s.status) && <Button size="sm" variant="ghost-danger" icon={X} onClick={() => ask(s, 'rejected')}>Rechazar</Button>}
                  </div>
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Modal open={!!deciding} title={deciding ? LABEL[deciding.status] : ''} onClose={() => setDeciding(null)}
        footer={<><Button variant="secondary" onClick={() => setDeciding(null)}>Cancelar</Button><Button variant={deciding?.status === 'rejected' ? 'danger' : 'primary'} loading={busy} onClick={decide}>{deciding ? LABEL[deciding.status] : ''}</Button></>}>
        {deciding && (
          <div className="space-y-3">
            <ErrorAlert error={actionError} />
            <p className="text-sm text-slate-600">{deciding.session.subject} · {deciding.session.student_name} · {dateTime(deciding.session.scheduled_at)}</p>
            <Field label="Nota para el alumno (opcional)">
              <Input value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={255} placeholder={deciding.status === 'confirmed' ? 'Ej. Nos vemos en el cubículo 3' : ''} />
            </Field>
          </div>
        )}
      </Modal>
    </>
  );
}
