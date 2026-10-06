import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, Layers } from 'lucide-react';
import { useApi } from '../../hooks.js';
import CrudPage from '../../components/CrudPage.jsx';
import { Badge, Button, Loading, Select, StatusBadge } from '../../components/ui.jsx';

export default function CoursesPage() {
  const periods = useApi('/periods');
  const subjects = useApi('/subjects');
  const teachers = useApi('/teachers');
  const [periodId, setPeriodId] = useState('');

  useEffect(() => {
    const list = periods.data?.data ?? [];
    if (!periodId && list.length) setPeriodId(String((list.find((p) => p.is_current) ?? list[0]).id));
  }, [periods.data, periodId]);

  if (periods.loading || subjects.loading || teachers.loading) return <Loading />;

  const opt = (list, label) => (list?.data ?? []).map((x) => ({ value: String(x.id), label: label(x) }));
  const periodOptions = opt(periods.data, (p) => p.name);

  return (
    <CrudPage
      key={periodId}
      title="Clases"
      subtitle="Servicio 2 (CRUD de clases) + Servicio 3 (cupo e inscripciones), compuestos por el gateway."
      endpoint="/courses"
      listQuery={periodId ? { period_id: periodId } : undefined}
      icon={Layers}
      noun="clase"
      searchKeys={['group_name', 'classroom']}
      toolbar={periodOptions.length > 0 && <div className="w-56"><Select value={periodId} onChange={(e) => setPeriodId(e.target.value)} options={periodOptions} /></div>}
      defaults={{ period_id: periodId, capacity: 30, units_count: 3, status: 'active' }}
      toForm={(c) => ({ ...c, subject_id: String(c.subject_id), period_id: String(c.period_id), teacher_id: c.teacher_id ? String(c.teacher_id) : '' })}
      columns={[
        { label: 'Materia', render: (c) => <Link to={`/admin/courses/${c.id}`} className="font-medium hover:text-indigo-600">{c.subject?.name}<p className="text-xs font-normal text-slate-400">{c.subject?.code}</p></Link> },
        { label: 'Grupo', render: (c) => c.group_name },
        { label: 'Profesor', render: (c) => c.teacher?.name ?? <span className="text-amber-600">Por asignar</span> },
        {
          label: 'Cupo',
          className: 'text-center',
          render: (c) =>
            c.enrolled === null ? <span className="text-xs text-slate-400">N/D</span> : <Badge tone={c.enrolled >= c.capacity ? 'rose' : 'sky'}>{c.enrolled} / {c.capacity}</Badge>,
        },
        { label: 'Unidades', className: 'text-center', render: (c) => c.units_count },
        { label: 'Status', render: (c) => <StatusBadge status={c.status} /> },
      ]}
      extraActions={(c) => (
        <Link to={`/admin/courses/${c.id}`}>
          <Button variant="ghost" size="sm" icon={Eye} title="Inscripciones" />
        </Link>
      )}
      fields={[
        { name: 'period_id', label: 'Periodo', type: 'select', required: true, options: periodOptions, placeholder: 'Selecciona…' },
        { name: 'subject_id', label: 'Materia', type: 'select', required: true, options: opt(subjects.data, (s) => `${s.code} · ${s.name}`), placeholder: 'Selecciona…' },
        { name: 'group_name', label: 'Grupo', required: true, placeholder: '9B' },
        { name: 'teacher_id', label: 'Profesor', type: 'select', options: opt(teachers.data, (t) => t.name), placeholder: 'Por asignar' },
        { name: 'classroom', label: 'Aula', placeholder: 'B-12' },
        { name: 'capacity', label: 'Cupo', type: 'number', required: true, min: 1, max: 200 },
        { name: 'units_count', label: 'Unidades a evaluar', type: 'number', required: true, min: 1, max: 10 },
        { name: 'status', label: 'Status', type: 'select', required: true, options: [{ value: 'active', label: 'Activa' }, { value: 'inactive', label: 'No activa' }] },
      ]}
    />
  );
}
