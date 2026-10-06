import { BookOpen, Calendar, Presentation, Users } from 'lucide-react';
import CrudPage from '../../components/CrudPage.jsx';
import { Badge } from '../../components/ui.jsx';

const fmt = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString('es-MX') : '…');

export function PeriodsPage() {
  return (
    <CrudPage
      title="Periodos"
      subtitle="Servicio 2 · Catálogo — ciclos escolares y ventana de captura de calificaciones."
      endpoint="/periods"
      icon={Calendar}
      noun="periodo"
      columns={[
        { label: 'Periodo', render: (p) => <span className="font-medium">{p.name} {p.is_current && <Badge tone="indigo">Actual</Badge>}</span> },
        { label: 'Vigencia', render: (p) => `${fmt(p.start_date)} – ${fmt(p.end_date)}` },
        {
          label: 'Captura de calificaciones',
          render: (p) => (
            <span className="flex items-center gap-2">
              {p.grades_open_from || p.grades_open_until ? `${fmt(p.grades_open_from)} – ${fmt(p.grades_open_until)}` : 'Sin restricción'}
              <Badge tone={p.grade_capture_open ? 'emerald' : 'slate'}>{p.grade_capture_open ? 'Abierta' : 'Cerrada'}</Badge>
            </span>
          ),
        },
        { label: 'Clases', className: 'text-center', render: (p) => p.courses_count ?? 0 },
      ]}
      fields={[
        { name: 'name', label: 'Nombre', required: true, span: 2, placeholder: 'Septiembre – Diciembre 2026' },
        { name: 'start_date', label: 'Inicio', type: 'date', required: true },
        { name: 'end_date', label: 'Fin', type: 'date', required: true },
        { name: 'grades_open_from', label: 'Captura abre', type: 'date' },
        { name: 'grades_open_until', label: 'Captura cierra', type: 'date' },
        { name: 'is_current', label: 'Periodo actual', type: 'checkbox' },
      ]}
    />
  );
}

export function SubjectsPage() {
  return (
    <CrudPage
      title="Materias"
      subtitle="Servicio 2 · Catálogo — plan de estudios."
      endpoint="/subjects"
      icon={BookOpen}
      noun="materia"
      searchKeys={['name', 'code']}
      columns={[
        { label: 'Clave', render: (s) => <span className="font-mono text-xs">{s.code}</span> },
        { label: 'Materia', render: (s) => <span className="font-medium">{s.name}</span> },
        { label: 'Semestre', className: 'text-center', render: (s) => s.semester ?? '—' },
        { label: 'Créditos', className: 'text-center', render: (s) => s.credits },
        { label: 'Clases', className: 'text-center', render: (s) => s.courses_count ?? 0 },
      ]}
      defaults={{ credits: 6 }}
      fields={[
        { name: 'code', label: 'Clave', required: true, placeholder: 'ISW-901' },
        { name: 'name', label: 'Nombre', required: true },
        { name: 'credits', label: 'Créditos', type: 'number', required: true, min: 0, max: 30 },
        { name: 'semester', label: 'Semestre', type: 'number', min: 1, max: 15 },
        { name: 'description', label: 'Descripción', span: 2 },
      ]}
    />
  );
}

/** Creating a teacher is an orchestration in the gateway: Auth (account) + Catalog (record). */
export function TeachersPage() {
  return (
    <CrudPage
      title="Profesores"
      subtitle="Alta orquestada: cuenta en Servicio 1 (Auth) + expediente en Servicio 2 (Catálogo)."
      endpoint="/teachers"
      icon={Presentation}
      noun="profesor"
      searchKeys={['name', 'employee_number', 'email']}
      columns={[
        { label: 'No. empleado', render: (t) => <span className="font-mono text-xs">{t.employee_number}</span> },
        { label: 'Profesor', render: (t) => <><p className="font-medium">{t.name}</p><p className="text-xs text-slate-400">{t.email}</p></> },
        { label: 'Departamento', render: (t) => t.department ?? '—' },
        { label: 'Clases', className: 'text-center', render: (t) => t.courses_count ?? 0 },
      ]}
      fields={[
        { name: 'name', label: 'Nombre completo', required: true },
        { name: 'email', label: 'Correo', type: 'email', required: true },
        { name: 'password', label: 'Contraseña', type: 'password', required: true, hint: 'Mínimo 8 caracteres.', editHint: 'Déjala vacía para conservarla.' },
        { name: 'employee_number', label: 'Número de empleado', required: true },
        { name: 'department', label: 'Departamento' },
        { name: 'phone', label: 'Teléfono' },
      ]}
    />
  );
}

export function StudentsPage() {
  return (
    <CrudPage
      title="Alumnos"
      subtitle="Alta orquestada: cuenta en Servicio 1 (Auth) + expediente en Servicio 2 (Catálogo)."
      endpoint="/students"
      icon={Users}
      noun="alumno"
      searchKeys={['name', 'enrollment_number', 'email', 'group_name']}
      defaults={{ program: 'Ingeniería en Software', semester: 1, status: 'active' }}
      columns={[
        { label: 'Matrícula', render: (s) => <span className="font-mono text-xs">{s.enrollment_number}</span> },
        { label: 'Alumno', render: (s) => <><p className="font-medium">{s.name}</p><p className="text-xs text-slate-400">{s.email}</p></> },
        { label: 'Carrera', render: (s) => <>{s.program}<p className="text-xs text-slate-400">{s.semester}° semestre</p></> },
        { label: 'Grupo', render: (s) => s.group_name ?? '—' },
        { label: 'Estatus', render: (s) => <Badge tone={s.status === 'active' ? 'emerald' : 'slate'}>{s.status === 'active' ? 'Activo' : 'Baja'}</Badge> },
      ]}
      fields={[
        { name: 'name', label: 'Nombre completo', required: true },
        { name: 'email', label: 'Correo', type: 'email', required: true },
        { name: 'password', label: 'Contraseña', type: 'password', required: true, hint: 'Mínimo 8 caracteres.', editHint: 'Déjala vacía para conservarla.' },
        { name: 'enrollment_number', label: 'Matrícula', required: true },
        { name: 'program', label: 'Carrera', required: true },
        { name: 'semester', label: 'Semestre', type: 'number', required: true, min: 1, max: 15 },
        { name: 'group_name', label: 'Grupo', placeholder: '9B' },
        { name: 'status', label: 'Estatus', type: 'select', required: true, options: [{ value: 'active', label: 'Activo' }, { value: 'inactive', label: 'Baja' }] },
      ]}
    />
  );
}
