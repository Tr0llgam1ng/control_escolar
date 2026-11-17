import { useState } from 'react';
import { Award, BarChart3, Coins, Lightbulb, LineChart, TriangleAlert } from 'lucide-react';
import { api } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Badge, Button, Card, Empty, ErrorAlert, Grade, Loading, PageHeader, Stat, Table, Td } from '../../components/ui.jsx';
import { LockedModule, credits } from '../../components/membership.jsx';
import { dateTime } from '../../components/payments.jsx';

const RISK = { alto: 'rose', medio: 'amber', bajo: 'emerald' };

/** Credits module 2 — academic analysis of my grades: averages, risk per subject and the grade I need. */
export default function Analytics() {
  const quote = useApi('/me/analytics/quote');
  const list = useApi('/me/analytics-reports');
  const [selected, setSelected] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  if ((quote.loading && !quote.data) || (list.loading && !list.data)) return <Loading />;
  if (quote.error) return <ErrorAlert error={quote.error} />;
  const q = quote.data;

  const generate = async () => {
    setBusy(true);
    setError(null);
    try {
      const report = await api('/me/analytics-reports', { method: 'POST' });
      setSelected(report);
      quote.reload();
      list.reload();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const open = async (id) => {
    setError(null);
    try {
      setSelected(await api(`/me/analytics-reports/${id}`));
    } catch (e) {
      setError(e);
    }
  };

  const r = selected?.result;

  return (
    <>
      <PageHeader title="Análisis académico" subtitle="Módulo con créditos: analiza tus calificaciones y te dice qué necesitas para aprobar.">
        {q.reports.available && <Button icon={LineChart} loading={busy} onClick={generate}>Generar análisis ({q.cost ? credits(q.cost) : 'gratis'})</Button>}
      </PageHeader>
      <ErrorAlert error={error} />

      {!q.reports.available ? (
        <LockedModule title="Tu plan no incluye el análisis académico" message="Disponible desde Bronze. En Gold es gratis e ilimitado." upgradeTo={q.upgrade_to} />
      ) : (
        <div className="mb-6 grid gap-4 sm:grid-cols-2">
          <Stat label="Costo por análisis" value={q.cost ? credits(q.cost) : 'Gratis'} icon={Coins} tone="amber" />
          <Stat label="Análisis disponibles en el ciclo" value={q.reports.unlimited ? 'Ilimitados' : q.reports.remaining} icon={BarChart3} />
        </div>
      )}

      {r && (
        <>
          <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Promedio general (cerradas)" value={r.general_average ?? '—'} icon={Award} tone="indigo" />
            <Stat label="Promedio en curso" value={r.current_average ?? '—'} icon={LineChart} tone="sky" />
            <Stat label="Materias en riesgo" value={r.counts.at_risk} icon={TriangleAlert} tone={r.counts.at_risk ? 'rose' : 'emerald'} />
            <Stat label="Mejor materia" value={r.best ?? '—'} icon={Award} tone="emerald" hint={r.weakest ? `A reforzar: ${r.weakest}` : null} />
          </div>

          <Card title="Recomendaciones" icon={Lightbulb} className="mb-6">
            <ul className="list-disc space-y-1 pl-5 text-sm text-slate-700">{r.recommendations.map((t, i) => <li key={i}>{t}</li>)}</ul>
          </Card>

          <Card title={`Detalle por materia · ${dateTime(selected.created_at)}`} icon={BarChart3} padding={false} className="mb-6">
            <Table head={['Materia', 'Unidades', { label: 'Promedio', className: 'text-center' }, { label: 'Final', className: 'text-center' }, { label: `Necesitas (≥ ${r.passing_grade})`, className: 'text-center' }, 'Riesgo']}>
              {r.subjects.map((s, i) => (
                <tr key={i}>
                  <Td><p className="font-medium">{s.subject}</p><p className="text-xs text-slate-400">{s.period}</p></Td>
                  <Td className="tabular-nums">{s.captured_units} de {s.units}</Td>
                  <Td className="text-center"><Grade value={s.average} passing={r.passing_grade} /></Td>
                  <Td className="text-center"><Grade value={s.final_grade} passing={r.passing_grade} /></Td>
                  <Td className="text-center tabular-nums">{s.needed_average === null ? '—' : s.reachable ? `${s.needed_average} prom.` : <span className="text-rose-600">No alcanzable</span>}</Td>
                  <Td>{s.risk ? <Badge tone={RISK[s.risk]}>{s.risk}</Badge> : <span className="text-xs text-slate-400">{s.status === 'approved' ? 'Aprobada' : s.status === 'failed' ? 'No aprobada' : '—'}</span>}</Td>
                </tr>
              ))}
            </Table>
          </Card>
        </>
      )}

      <Card title="Mis análisis" icon={BarChart3} padding={false}>
        {!list.data?.data?.length ? <Empty icon={BarChart3} title="Aún no generas análisis" /> : (
          <Table head={['#', 'Fecha', 'Plan', { label: 'Créditos', className: 'text-right' }, '']}>
            {list.data.data.map((a) => (
              <tr key={a.id}>
                <Td>{a.id}</Td>
                <Td>{dateTime(a.created_at)}</Td>
                <Td className="capitalize">{a.plan_code}</Td>
                <Td className="text-right tabular-nums">{a.credits_cost}</Td>
                <Td className="text-right"><Button size="sm" variant="secondary" onClick={() => open(a.id)}>Ver</Button></Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  );
}
