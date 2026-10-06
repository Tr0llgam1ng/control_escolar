import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Power, Save, Users } from 'lucide-react';
import { api } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Button, Card, Empty, ErrorAlert, Grade, Input, Loading, PageHeader, StatusBadge, Table, Td } from '../../components/ui.jsx';

/** Grade capture (Servicio 3). The service validates ownership, capture window and 0–10 range. */
export default function CourseGrades() {
  const { id } = useParams();
  const course = useApi(`/courses/${id}`);
  const roster = useApi(`/courses/${id}/roster`);
  const [values, setValues] = useState({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  useEffect(() => {
    if (!roster.data) return;
    const next = {};
    roster.data.data.forEach((e) => e.grades.forEach((g) => (next[`${e.id}:${g.unit}`] = String(g.score))));
    setValues(next);
  }, [roster.data]);

  async function save() {
    setSaving(true);
    setError(null);
    setNotice(null);
    const grades = [];
    roster.data.data.forEach((e) => {
      for (let unit = 1; unit <= roster.data.units_count; unit++) {
        const original = e.grades.find((g) => g.unit === unit);
        const value = values[`${e.id}:${unit}`] ?? '';
        if (String(original?.score ?? '') !== value) grades.push({ enrollment_id: e.id, unit, score: value === '' ? null : Number(value) });
      }
    });
    if (!grades.length) {
      setNotice('No hay cambios que guardar.');
      setSaving(false);
      return;
    }
    try {
      const res = await api(`/courses/${id}/grades`, { method: 'PUT', body: { grades } });
      setNotice(`Se guardaron ${res.changes} cambio(s).`);
      roster.reload();
    } catch (err) {
      setError(err);
    } finally {
      setSaving(false);
    }
  }

  async function toggleStatus() {
    const c = course.data;
    if (!window.confirm(c.status === 'active' ? '¿Marcar la clase como No activa?' : '¿Reactivar la clase?')) return;
    try {
      await api(`/courses/${id}/settings`, { method: 'PATCH', body: { status: c.status === 'active' ? 'inactive' : 'active' } });
      course.reload();
    } catch (err) {
      setError(err);
    }
  }

  if ((course.loading && !course.data) || (roster.loading && !roster.data)) return <Loading />;
  if (course.error || roster.error) return <ErrorAlert error={course.error ?? roster.error} />;

  const c = course.data;
  const r = roster.data;
  const open = r.grade_capture_open;

  return (
    <>
      <Link to="/teacher" className="mb-2 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-indigo-600">
        <ArrowLeft className="h-4 w-4" /> Mis materias
      </Link>
      <PageHeader title={c.subject.name} subtitle={`Grupo ${c.group_name} · ${c.period.name}${c.classroom ? ' · Aula ' + c.classroom : ''}`}>
        <StatusBadge status={c.status} />
        <Button variant="secondary" size="sm" icon={Power} onClick={toggleStatus}>
          {c.status === 'active' ? 'Marcar no activa' : 'Activar'}
        </Button>
      </PageHeader>

      {!open && <Alert tone="warning">El periodo de captura de calificaciones está cerrado. Solo puedes consultar.</Alert>}
      {notice && <Alert tone="success">{notice}</Alert>}
      <ErrorAlert error={error} />

      <Card
        title={`Lista de alumnos (${r.data.length})`}
        icon={Users}
        padding={false}
        actions={
          r.data.length > 0 &&
          open && (
            <Button icon={Save} loading={saving} onClick={save}>
              Guardar calificaciones
            </Button>
          )
        }
      >
        {r.data.length === 0 ? (
          <Empty icon={Users} title="Sin alumnos inscritos" message="Control Escolar inscribe a los alumnos." />
        ) : (
          <Table head={['#', 'Alumno', ...Array.from({ length: r.units_count }, (_, i) => ({ label: `Unidad ${i + 1}`, className: 'text-center' })), { label: 'Final', className: 'text-center' }, 'Estado']}>
            {r.data.map((e, idx) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <Td className="text-slate-400">{idx + 1}</Td>
                <Td>
                  <p className="font-medium">{e.student?.name}</p>
                  <p className="text-xs text-slate-400">{e.student?.enrollment_number}</p>
                </Td>
                {Array.from({ length: r.units_count }, (_, i) => {
                  const key = `${e.id}:${i + 1}`;
                  return (
                    <Td key={i} className="text-center">
                      <Input
                        type="number"
                        step="0.1"
                        min="0"
                        max="10"
                        disabled={!open}
                        className="mx-auto w-20 text-center tabular-nums disabled:bg-slate-50"
                        value={values[key] ?? ''}
                        onChange={(ev) => setValues({ ...values, [key]: ev.target.value })}
                      />
                    </Td>
                  );
                })}
                <Td className="text-center"><Grade value={e.final_grade} passing={r.passing_grade} /></Td>
                <Td><StatusBadge status={e.status} /></Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
      <p className="mt-3 text-xs text-slate-400">
        Escala 0–10 · aprobatoria {r.passing_grade}. La calificación final se calcula cuando todas las unidades están capturadas. Deja un campo vacío para borrar la calificación.
      </p>
    </>
  );
}
