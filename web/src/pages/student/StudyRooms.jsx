import { useMemo, useState } from 'react';
import { CalendarPlus, DoorOpen, Users, X } from 'lucide-react';
import { api, fieldError } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Field, Input, Loading, PageHeader, Select, Table, Td } from '../../components/ui.jsx';
import { LockedModule } from '../../components/membership.jsx';
import { TimeBalance } from '../../components/TimeBalance.jsx';
import { dateTime } from '../../components/payments.jsx';

const pad = (n) => String(n).padStart(2, '0');
const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const hhmm = (value) => new Date(value).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false });

/** Time module 1 — study rooms booked by the hour (hours included in the plan + extra hours). */
export default function StudyRooms() {
  const membership = useApi('/me/membership');
  const time = useApi('/me/time');
  const rooms = useApi('/memberships/study-rooms');
  const bookings = useApi('/me/room-bookings');

  const tomorrow = isoDate(new Date(Date.now() + 86400_000));
  const [form, setForm] = useState({ study_room_id: '', date: tomorrow, start: '10:00', minutes: '60' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const roomId = form.study_room_id || rooms.data?.data?.[0]?.id;
  const busySlots = useApi(roomId && form.date ? `/memberships/study-rooms/${roomId}/bookings` : null, { query: { date: form.date } });

  const cfg = rooms.data;
  const slots = useMemo(() => {
    if (!cfg) return [];
    const out = [];
    for (let h = cfg.opens; h < cfg.closes; h++) for (let m = 0; m < 60; m += cfg.slot_minutes) out.push(`${pad(h)}:${pad(m)}`);
    return out;
  }, [cfg]);

  if ((membership.loading && !membership.data) || (rooms.loading && !rooms.data) || (time.loading && !time.data)) return <Loading />;

  const max = membership.data?.features['study_rooms.max_hours'];
  const advance = membership.data?.features['study_rooms.advance_days'];
  const refresh = () => { time.reload(); bookings.reload(); busySlots.reload(); membership.reload(); };

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const book = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await api('/me/room-bookings', { method: 'POST', body: { study_room_id: Number(roomId), starts_at: `${form.date} ${form.start}`, minutes: Number(form.minutes) } });
      setNotice(`Reservaste ${res.room.name} el ${dateTime(res.starts_at)} (${res.minutes} min).`);
      refresh();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const cancel = async (b) => {
    setError(null);
    try {
      await api(`/me/room-bookings/${b.id}/cancel`, { method: 'POST' });
      setNotice('Reservación cancelada; el tiempo regresó a tu saldo.');
      refresh();
    } catch (err) {
      setError(err);
    }
  };

  const durations = Array.from({ length: Math.max(1, (max?.value ?? 1) * 2) }, (_, i) => (i + 1) * 30);
  const maxDate = isoDate(new Date(Date.now() + (advance?.value ?? 0) * 86400_000));

  return (
    <>
      <PageHeader title="Cubículos de estudio" subtitle="Reserva por horas. Usa primero las horas incluidas en tu plan y después las extra." />
      <Alert tone="success">{notice}</Alert>
      <ErrorAlert error={error && !Object.keys(error.errors ?? {}).length ? error : null} />

      {!max?.available ? (
        <LockedModule title="Tu plan no incluye cubículos" message="Con Bronze, Silver o Gold tienes horas incluidas cada ciclo para reservar cubículos y salas de cómputo." upgradeTo={[{ name: 'Bronze' }, { name: 'Silver' }, { name: 'Gold' }]} />
      ) : (
        <>
          <div className="mb-6"><TimeBalance status={time.data?.modules.study_rooms} onChange={refresh} /></div>

          <div className="mb-6 grid gap-6 lg:grid-cols-3">
            <Card title="Nueva reservación" icon={CalendarPlus} className="lg:col-span-2">
              <form onSubmit={book} className="grid gap-3 sm:grid-cols-2">
                <Field label="Cubículo" className="sm:col-span-2">
                  <Select value={roomId ?? ''} onChange={set('study_room_id')} options={(rooms.data?.data ?? []).map((r) => ({ value: r.id, label: `${r.name} · ${r.location} · ${r.capacity} personas` }))} />
                </Field>
                <Field label="Fecha" hint={`Tu plan permite hasta ${advance?.value ?? 0} días de anticipación`} error={fieldError(error, 'starts_at')}>
                  <Input type="date" min={isoDate(new Date())} max={maxDate} value={form.date} onChange={set('date')} required />
                </Field>
                <Field label="Hora de inicio">
                  <Select value={form.start} onChange={set('start')} options={slots.map((s) => ({ value: s, label: s }))} />
                </Field>
                <Field label="Duración" hint={`Máximo ${max.value} h por reservación en tu plan`} error={fieldError(error, 'minutes')}>
                  <Select value={form.minutes} onChange={set('minutes')} options={durations.map((m) => ({ value: m, label: m % 60 ? `${Math.floor(m / 60) ? `${Math.floor(m / 60)} h ` : ''}30 min` : `${m / 60} h` }))} />
                </Field>
                <div className="flex items-end justify-end">
                  <Button type="submit" icon={CalendarPlus} loading={busy}>Reservar</Button>
                </div>
              </form>
            </Card>

            <Card title={`Ocupado el ${form.date}`} icon={DoorOpen}>
              {busySlots.loading ? <Loading /> : !busySlots.data?.data?.length ? (
                <p className="text-sm text-slate-500">Libre todo el día ({pad(cfg.opens)}:00 – {pad(cfg.closes)}:00).</p>
              ) : (
                <ul className="space-y-1 text-sm">
                  {busySlots.data.data.map((b) => <li key={b.id} className="rounded-lg bg-rose-50 px-3 py-1.5 text-rose-700">{hhmm(b.starts_at)} – {hhmm(b.ends_at)}</li>)}
                </ul>
              )}
            </Card>
          </div>
        </>
      )}

      <Card title="Mis reservaciones" icon={Users} padding={false}>
        {!bookings.data?.data?.length ? <Empty icon={DoorOpen} title="Sin reservaciones" /> : (
          <Table head={['Cubículo', 'Inicio', 'Duración', 'Tiempo usado', 'Estado', '']}>
            {bookings.data.data.map((b) => (
              <tr key={b.id}>
                <Td>{b.room?.name}<p className="text-xs text-slate-400">{b.room?.location}</p></Td>
                <Td className="whitespace-nowrap">{dateTime(b.starts_at)}</Td>
                <Td>{b.minutes} min</Td>
                <Td className="text-xs text-slate-500">{b.included_seconds / 60} min del plan · {b.extra_seconds / 60} min extra</Td>
                <Td>
                  {b.status === 'cancelled' ? <Badge tone="slate">Cancelada</Badge> : b.is_current ? <Badge tone="emerald">En curso</Badge> : b.is_past ? <Badge tone="slate">Terminada</Badge> : <Badge tone="sky">Reservada</Badge>}
                </Td>
                <Td className="text-right">
                  {b.status === 'booked' && !b.is_past && !b.is_current && <Button size="sm" variant="ghost-danger" icon={X} onClick={() => cancel(b)}>Cancelar</Button>}
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  );
}
