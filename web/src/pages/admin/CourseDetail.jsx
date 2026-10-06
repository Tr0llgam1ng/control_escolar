import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Search, Trash, UserPlus, Users } from 'lucide-react';
import { api } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Grade, Input, Loading, PageHeader, StatusBadge, Table, Td } from '../../components/ui.jsx';

/** Enrollment screen: the "reservation" of seats in a course (Servicio 3). */
export default function CourseDetail() {
  const { id } = useParams();
  const course = useApi(`/courses/${id}`);
  const roster = useApi(`/courses/${id}/roster`);
  const students = useApi('/students', { query: { status: 'active' } });
  const [selected, setSelected] = useState([]);
  const [filter, setFilter] = useState('');
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const enrolledIds = useMemo(() => new Set((roster.data?.data ?? []).map((e) => e.student_id)), [roster.data]);
  const available = useMemo(() => {
    const q = filter.trim().toLowerCase();
    return (students.data?.data ?? [])
      .filter((s) => !enrolledIds.has(s.id))
      .filter((s) => !q || `${s.name} ${s.enrollment_number} ${s.group_name ?? ''}`.toLowerCase().includes(q));
  }, [students.data, enrolledIds, filter]);

  async function enroll() {
    setBusy(true);
    setError(null);
    try {
      const res = await api(`/courses/${id}/enrollments`, { method: 'POST', body: { student_ids: selected } });
      setResult(res);
      setSelected([]);
      roster.reload();
      course.reload();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  async function unenroll(enrollment) {
    if (!window.confirm(`¿Dar de baja a ${enrollment.student?.name} de esta clase? Se borrarán sus calificaciones.`)) return;
    try {
      await api(`/enrollments/${enrollment.id}`, { method: 'DELETE' });
      roster.reload();
      course.reload();
    } catch (err) {
      setError(err);
    }
  }

  if (course.loading && !course.data) return <Loading />;
  if (course.error) return <ErrorAlert error={course.error} />;
  const c = course.data;
  const units = roster.data?.units_count ?? c.units_count;

  return (
    <>
      <Link to="/admin/courses" className="mb-2 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-600">
        <ArrowLeft className="h-4 w-4" /> Clases
      </Link>
      <PageHeader title={c.subject.name} subtitle={`${c.subject.code} · Grupo ${c.group_name} · ${c.period.name} · ${c.teacher?.name ?? 'Sin profesor'}`}>
        <StatusBadge status={c.status} />
        <Badge tone={c.enrolled >= c.capacity ? 'rose' : 'sky'}>Cupo {c.enrolled ?? '?'} / {c.capacity}</Badge>
      </PageHeader>

      <ErrorAlert error={error} />
      {result && (
        <Alert tone={result.enrolled ? 'success' : 'warning'}>
          Se inscribieron {result.enrolled} alumno(s).
          {result.results.filter((r) => !r.enrolled).map((r) => (
            <div key={r.student_id} className="text-xs">✗ {r.name}: {r.message}</div>
          ))}
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title={`Inscritos (${roster.data?.data?.length ?? 0})`} icon={Users} padding={false} className="lg:col-span-2">
          <ErrorAlert error={roster.error} />
          {roster.loading && !roster.data ? (
            <Loading />
          ) : !roster.data?.data?.length ? (
            <Empty icon={Users} title="Sin alumnos inscritos" message="Selecciona alumnos a la derecha para inscribirlos." />
          ) : (
            <Table head={['Alumno', ...Array.from({ length: units }, (_, i) => ({ label: `U${i + 1}`, className: 'text-center' })), { label: 'Final', className: 'text-center' }, 'Estado', '']}>
              {roster.data.data.map((e) => {
                const byUnit = Object.fromEntries(e.grades.map((g) => [g.unit, g.score]));
                return (
                  <tr key={e.id}>
                    <Td>
                      <p className="font-medium">{e.student?.name ?? `#${e.student_id}`}</p>
                      <p className="text-xs text-slate-400">{e.student?.enrollment_number}</p>
                    </Td>
                    {Array.from({ length: units }, (_, i) => (
                      <Td key={i} className="text-center"><Grade value={byUnit[i + 1]} passing={roster.data.passing_grade} /></Td>
                    ))}
                    <Td className="text-center"><Grade value={e.final_grade} passing={roster.data.passing_grade} /></Td>
                    <Td><StatusBadge status={e.status} /></Td>
                    <Td className="text-right"><Button variant="ghost-danger" size="sm" icon={Trash} onClick={() => unenroll(e)} title="Dar de baja" /></Td>
                  </tr>
                );
              })}
            </Table>
          )}
        </Card>

        <Card title="Inscribir alumnos" icon={UserPlus}>
          <div className="relative mb-3">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input className="pl-9" placeholder="Nombre, matrícula o grupo" value={filter} onChange={(e) => setFilter(e.target.value)} />
          </div>
          {available.length === 0 ? (
            <p className="text-sm text-slate-400">No hay alumnos activos disponibles.</p>
          ) : (
            <>
              <button className="mb-2 text-xs text-indigo-600 hover:underline" onClick={() => setSelected(available.map((s) => s.id))}>
                Seleccionar los {available.length} mostrados
              </button>
              <div className="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                {available.map((s) => (
                  <label key={s.id} className="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50">
                    <input
                      type="checkbox"
                      className="rounded border-slate-300 text-indigo-600"
                      checked={selected.includes(s.id)}
                      onChange={(e) => setSelected(e.target.checked ? [...selected, s.id] : selected.filter((x) => x !== s.id))}
                    />
                    <span className="flex-1">{s.name}</span>
                    <span className="text-xs text-slate-400">{s.group_name}</span>
                  </label>
                ))}
              </div>
            </>
          )}
          <Button className="mt-4 w-full" icon={UserPlus} loading={busy} disabled={!selected.length} onClick={enroll}>
            Inscribir {selected.length ? `(${selected.length})` : ''}
          </Button>
          <p className="mt-2 text-xs text-slate-400">El Servicio 3 valida cupo, duplicados, status de la clase y del alumno.</p>
        </Card>
      </div>
    </>
  );
}
