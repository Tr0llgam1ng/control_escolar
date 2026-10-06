import { Link } from 'react-router-dom';
import { BookOpen, ClipboardList, Layers, Users } from 'lucide-react';
import { useApi } from '../../hooks.js';
import { Button, Card, Empty, ErrorAlert, Loading, PageHeader, StatusBadge } from '../../components/ui.jsx';

export default function MyCourses() {
  const { data, error, loading } = useApi('/me/courses');

  if (loading && !data) return <Loading />;

  return (
    <>
      <PageHeader title="Mis materias" subtitle={data?.teacher ? `${data.teacher.name} · ${data.teacher.employee_number}` : null} />
      <ErrorAlert error={error} />
      {data && data.data.length === 0 && (
        <Card>
          <Empty icon={BookOpen} title="Sin clases asignadas" message="Control Escolar aún no te asigna clases." />
        </Card>
      )}
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {data?.data.map((c) => (
          <div key={c.id} className="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-3">
              <div className="rounded-lg bg-indigo-50 p-2 text-indigo-600"><BookOpen className="h-5 w-5" /></div>
              <StatusBadge status={c.status} />
            </div>
            <h3 className="mt-3 font-semibold text-slate-800">{c.subject.name}</h3>
            <p className="text-xs text-slate-400">{c.subject.code} · Grupo {c.group_name} · {c.period.name}</p>
            <dl className="mt-4 flex-1 space-y-1.5 text-sm text-slate-600">
              <div className="flex items-center gap-2"><Users className="h-4 w-4 text-slate-400" /> {c.enrolled} / {c.capacity} alumnos</div>
              <div className="flex items-center gap-2"><Layers className="h-4 w-4 text-slate-400" /> {c.units_count} unidades</div>
            </dl>
            <Link to={`/teacher/courses/${c.id}`} className="mt-4">
              <Button variant="secondary" icon={ClipboardList} className="w-full">Lista y calificaciones</Button>
            </Link>
          </div>
        ))}
      </div>
    </>
  );
}
