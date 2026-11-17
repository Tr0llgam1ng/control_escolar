import { useState } from 'react';
import { CalendarPlus, Coins, GraduationCap, MessagesSquare, Percent, Ticket, X } from 'lucide-react';
import { api, fieldError } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Field, Input, Loading, PageHeader, Select, Stat, Table, Td } from '../../components/ui.jsx';
import { LockedModule, credits, dayAt, toLocalString } from '../../components/membership.jsx';
import { dateTime } from '../../components/payments.jsx';

export const TUTORING_TONE = { requested: 'amber', confirmed: 'sky', completed: 'emerald', rejected: 'rose', cancelled: 'slate' };

/** Credits module 1 — tutoring with a teacher, paid with credits (discount and quota depend on the plan). */
export default function Tutoring() {
  const quote = useApi('/me/tutoring/quote');
  const options = useApi('/me/tutoring/options');
  const sessions = useApi('/me/tutoring-sessions');
  const [form, setForm] = useState({ teacher_id: '', subject: '', topic: '', scheduled_at: dayAt(2, 10) });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  if ((quote.loading && !quote.data) || (sessions.loading && !sessions.data)) return <Loading />;
  if (quote.error) return <ErrorAlert error={quote.error} />;

  const q = quote.data;
  const fromCourses = options.data?.from_courses ?? [];
  const teachers = options.data?.teachers ?? [];
  const refresh = () => { quote.reload(); sessions.reload(); };

  const pickCourse = (e) => {
    const c = fromCourses[Number(e.target.value)];
    if (c) setForm((f) => ({ ...f, teacher_id: String(c.teacher_id), subject: c.subject ?? f.subject }));
  };
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const s = await api('/me/tutoring-sessions', { method: 'POST', body: { ...form, teacher_id: Number(form.teacher_id), scheduled_at: toLocalString(form.scheduled_at) } });
      setNotice(`Asesoría solicitada con ${s.teacher_name} el ${dateTime(s.scheduled_at)} por ${credits(s.credits_cost)}. El profesor debe confirmarla.`);
      setForm((f) => ({ ...f, topic: '' }));
      refresh();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (s) => {
    setError(null);
    try {
      const res = await api(`/me/tutoring-sessions/${s.id}/cancel`, { method: 'POST' });
      setNotice(res.refunded ? `Cancelada; se te devolvieron ${credits(res.credits_cost)}.` : `Cancelada con menos de ${q.rules.refund_hours} h de anticipación: sin reembolso.`);
      refresh();
    } catch (err) {
      setError(err);
    }
  };

  return (
    <>
      <PageHeader title="Asesorías con profesor" subtitle="Módulo con créditos: agenda una sesión de 1 hora con un profesor." />
      <Alert tone="success">{notice}</Alert>
      <ErrorAlert error={error && !Object.keys(error.errors ?? {}).length ? error : null} />

      {!q.sessions.available ? (
        <LockedModule title="Tu plan no incluye asesorías" message="Las asesorías con profesor se pagan con créditos y están disponibles desde el plan Bronze." upgradeTo={q.upgrade_to} />
      ) : (
        <>
          <div className="mb-6 grid gap-4 sm:grid-cols-3">
            <Stat label="Costo por asesoría" value={credits(q.cost)} icon={Coins} tone="amber" hint={q.discount_pct ? `Precio base ${q.base_cost}, ${q.discount_pct}% de descuento por tu plan` : 'Sin descuento en tu plan'} />
            <Stat label="Descuento de tu plan" value={`${q.discount_pct}%`} icon={Percent} tone="emerald" />
            <Stat label="Disponibles en el ciclo" value={q.sessions.unlimited ? 'Ilimitadas' : q.sessions.remaining} icon={Ticket} hint={q.sessions.unlimited ? null : `de ${q.sessions.value}`} />
          </div>

          <Card title="Solicitar asesoría" icon={CalendarPlus} className="mb-6">
            <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
              {fromCourses.length > 0 && (
                <Field label="De mis clases actuales" className="sm:col-span-2">
                  <Select placeholder="Elegir materia y profesor…" onChange={pickCourse} options={fromCourses.map((c, i) => ({ value: i, label: `${c.subject} — ${c.teacher_name}` }))} />
                </Field>
              )}
              <Field label="Profesor" error={fieldError(error, 'teacher_id')}>
                <Select placeholder="Selecciona…" value={form.teacher_id} onChange={set('teacher_id')} options={teachers.map((t) => ({ value: String(t.id), label: t.name }))} required />
              </Field>
              <Field label="Materia" error={fieldError(error, 'subject')}>
                <Input value={form.subject} onChange={set('subject')} required maxLength={120} />
              </Field>
              <Field label="Tema a revisar" className="sm:col-span-2" error={fieldError(error, 'topic')}>
                <Input value={form.topic} onChange={set('topic')} placeholder="Ej. Normalización hasta 3FN" required maxLength={255} />
              </Field>
              <Field label="Fecha y hora" hint={`De ${q.rules.opens}:00 a ${q.rules.closes}:00, con ${q.rules.min_hours_ahead} h de anticipación`} error={fieldError(error, 'scheduled_at')}>
                <Input type="datetime-local" step={1800} value={form.scheduled_at} onChange={set('scheduled_at')} required />
              </Field>
              <div className="flex items-end justify-end">
                <Button type="submit" icon={MessagesSquare} loading={busy}>Solicitar por {credits(q.cost)}</Button>
              </div>
            </form>
            <p className="mt-3 text-xs text-slate-500">Si el profesor la rechaza se te devuelven los créditos. Si la cancelas con {q.rules.refund_hours} h o más de anticipación, también.</p>
          </Card>
        </>
      )}

      <Card title="Mis asesorías" icon={GraduationCap} padding={false}>
        {!sessions.data?.data?.length ? <Empty icon={GraduationCap} title="Sin asesorías" /> : (
          <Table head={['Fecha', 'Profesor', 'Materia / tema', { label: 'Créditos', className: 'text-right' }, 'Estado', '']}>
            {sessions.data.data.map((s) => (
              <tr key={s.id}>
                <Td className="whitespace-nowrap">{dateTime(s.scheduled_at)}</Td>
                <Td>{s.teacher_name}</Td>
                <Td><p className="font-medium">{s.subject}</p><p className="text-xs text-slate-500">{s.topic}</p>{s.teacher_notes && <p className="mt-1 text-xs text-indigo-600">Profesor: {s.teacher_notes}</p>}</Td>
                <Td className="text-right tabular-nums">{s.credits_cost}{s.refunded && <p className="text-xs text-emerald-600">reembolsado</p>}</Td>
                <Td><Badge tone={TUTORING_TONE[s.status]}>{s.status_label}</Badge></Td>
                <Td className="text-right">
                  {['requested', 'confirmed'].includes(s.status) && new Date(s.scheduled_at) > new Date() && (
                    <Button size="sm" variant="ghost-danger" icon={X} onClick={() => cancel(s)}>Cancelar</Button>
                  )}
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  );
}
