import { Link } from 'react-router-dom';
import { BookOpenText, CalendarClock, DoorOpen, History } from 'lucide-react';
import { useApi } from '../../hooks.js';
import { Card, Empty, ErrorAlert, Loading, PageHeader, Stat, Table, Td } from '../../components/ui.jsx';
import { TimeBalance } from '../../components/TimeBalance.jsx';
import { human } from '../../components/membership.jsx';
import { dateTime } from '../../components/payments.jsx';

const MODULE_LABEL = { study_rooms: 'Cubículos', library: 'Biblioteca digital' };
const KIND_LABEL = { use: 'Uso', purchase: 'Compra', refund: 'Devolución' };

/** Student: time of the membership — days left of the plan, hours of study rooms and minutes of library. */
export default function Time() {
  const { data, error, loading, reload } = useApi('/me/time');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorAlert error={error} />;

  const rooms = data.modules.study_rooms;
  const library = data.modules.library;

  return (
    <>
      <PageHeader title="Mi tiempo" subtitle="Días de tu plan, horas de cubículo y minutos de biblioteca digital." />
      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <Stat label={`Plan ${data.plan.name}`} icon={CalendarClock} tone="indigo"
          value={data.plan_remaining ? `${data.plan_remaining.days} d ${data.plan_remaining.hours} h ${data.plan_remaining.minutes} min` : 'Sin vencimiento'}
          hint={data.plan_remaining ? 'restantes' : 'Plan Básico (gratuito)'} />
        <Link to="/student/study-rooms"><Stat label="Cubículos (horas)" value={rooms.available.human} icon={DoorOpen} tone="sky" hint="Reservar →" /></Link>
        <Link to="/student/library"><Stat label="Biblioteca digital (minutos)" value={library.available.human} icon={BookOpenText} tone="emerald" hint="Abrir sesión →" /></Link>
      </div>

      <div className="mb-6 grid gap-4 xl:grid-cols-2">
        <TimeBalance status={rooms} onChange={reload} />
        <TimeBalance status={library} onChange={reload} />
      </div>

      <Card title="Movimientos de tiempo" icon={History} padding={false}>
        {!data.movements.length ? <Empty icon={History} title="Sin movimientos" message="Aquí verás el tiempo que usas, compras y se te devuelve." /> : (
          <Table head={['Fecha', 'Módulo', 'Movimiento', { label: 'Incluido', className: 'text-right' }, { label: 'Extra', className: 'text-right' }]}>
            {data.movements.map((m) => (
              <tr key={m.id}>
                <Td className="whitespace-nowrap text-xs">{dateTime(m.created_at)}</Td>
                <Td>{MODULE_LABEL[m.module]}</Td>
                <Td><p>{m.concept}</p><p className="text-xs text-slate-400">{KIND_LABEL[m.kind] ?? m.kind}</p></Td>
                <Td className={`text-right tabular-nums ${m.included_seconds < 0 ? 'text-rose-600' : 'text-emerald-600'}`}>{m.included_seconds ? `${m.included_seconds > 0 ? '+' : '−'}${human(Math.abs(m.included_seconds))}` : '—'}</Td>
                <Td className={`text-right tabular-nums ${m.extra_seconds < 0 ? 'text-rose-600' : 'text-emerald-600'}`}>{m.extra_seconds ? `${m.extra_seconds > 0 ? '+' : '−'}${human(Math.abs(m.extra_seconds))}` : '—'}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  );
}
