import { Award, BookOpen, CircleCheck, CircleX, GraduationCap } from 'lucide-react';
import { useApi } from '../../hooks.js';
import { Card, Empty, ErrorAlert, Grade, Loading, PageHeader, Stat, StatusBadge, Table, Td } from '../../components/ui.jsx';

/** "Consulta académica del alumno": one screen fed by the gateway composing 2 services. */
export default function StudentReport() {
  const { data, error, loading } = useApi('/me/report');

  if (loading && !data) return <Loading />;
  if (error) return <ErrorAlert error={error} />;

  const { student, summary, current_period: period } = data;
  const maxUnits = Math.max(0, ...data.data.map((e) => e.course.units_count));
  const byPeriod = data.data.reduce((acc, e) => {
    const key = e.course.period?.name ?? 'Sin periodo';
    (acc[key] ??= []).push(e);
    return acc;
  }, {});

  return (
    <>
      <PageHeader
        title={`Hola, ${student.name.split(' ')[0]}`}
        subtitle={`${student.enrollment_number} · ${student.program} · ${student.semester}° semestre${student.group_name ? ' · Grupo ' + student.group_name : ''}`}
      />
      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Materias en curso" value={summary.in_progress} icon={BookOpen} hint={period?.name} />
        <Stat label="Aprobadas" value={summary.approved} icon={CircleCheck} tone="emerald" />
        <Stat label="No aprobadas" value={summary.failed} icon={CircleX} tone="rose" />
        <Stat label="Promedio general" value={summary.average ?? '—'} icon={Award} tone="amber" />
      </div>

      {data.data.length === 0 ? (
        <Card>
          <Empty icon={GraduationCap} title="Aún no tienes materias" message="Cuando Control Escolar te inscriba en una clase aparecerá aquí." />
        </Card>
      ) : (
        Object.entries(byPeriod).map(([name, items]) => (
          <Card key={name} title={name} icon={GraduationCap} padding={false} className="mb-6">
            <Table head={['Materia', 'Profesor', ...Array.from({ length: maxUnits }, (_, i) => ({ label: `U${i + 1}`, className: 'text-center' })), { label: 'Final', className: 'text-center' }, 'Estado']}>
              {items.map((e) => {
                const byUnit = Object.fromEntries(e.grades.map((g) => [g.unit, g.score]));
                return (
                  <tr key={e.id} className="hover:bg-slate-50">
                    <Td>
                      <p className="font-medium">{e.course.subject.name}</p>
                      <p className="text-xs text-slate-400">{e.course.subject.code} · Grupo {e.course.group_name}</p>
                    </Td>
                    <Td>{e.course.teacher?.name ?? 'Por asignar'}</Td>
                    {Array.from({ length: maxUnits }, (_, i) => (
                      <Td key={i} className="text-center">{i < e.course.units_count ? <Grade value={byUnit[i + 1]} /> : <span className="text-slate-200">·</span>}</Td>
                    ))}
                    <Td className="text-center"><Grade value={e.final_grade} /></Td>
                    <Td><StatusBadge status={e.status} /></Td>
                  </tr>
                );
              })}
            </Table>
          </Card>
        ))
      )}
    </>
  );
}
