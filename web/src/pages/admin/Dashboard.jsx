import { Link } from 'react-router-dom';
import { BookOpen, Calendar, Layers, Presentation, TriangleAlert, Users } from 'lucide-react';
import { useApi } from '../../hooks.js';
import { Card, ErrorAlert, Loading, PageHeader, Stat } from '../../components/ui.jsx';
import ServicesStatus from '../../components/ServicesStatus.jsx';

export default function AdminDashboard() {
  const { data, error, loading } = useApi('/dashboard');

  return (
    <>
      <PageHeader title="Panel de Control Escolar" subtitle={data?.current_period ? `Periodo actual: ${data.current_period.name}` : 'Sin periodo actual'} />
      <ErrorAlert error={error} />
      {loading && !data ? (
        <Loading />
      ) : data ? (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Alumnos activos" value={data.counts.students} icon={Users} />
            <Stat label="Profesores" value={data.counts.teachers} icon={Presentation} tone="sky" />
            <Stat label="Clases del periodo" value={data.counts.courses} icon={Layers} tone="emerald" hint={`${data.counts.enrolled} inscripciones`} />
            <Stat label="Materias" value={data.counts.subjects} icon={BookOpen} tone="amber" />
          </div>
          <div className="grid gap-6 lg:grid-cols-3">
            <Card title="Primeros pasos" icon={Calendar} className="lg:col-span-2">
              <ol className="space-y-2 text-sm text-slate-600">
                {[
                  ['/admin/periods', 'Registrar el periodo escolar y la ventana de captura de calificaciones'],
                  ['/admin/subjects', 'Dar de alta materias'],
                  ['/admin/teachers', 'Dar de alta profesores (crea cuenta en Auth + expediente en Catálogo)'],
                  ['/admin/students', 'Dar de alta alumnos'],
                  ['/admin/courses', 'Crear clases e inscribir alumnos (Servicio de Inscripciones)'],
                ].map(([to, text], i) => (
                  <li key={to} className="flex items-center gap-3 rounded-lg border border-slate-100 px-3 py-2">
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">{i + 1}</span>
                    <Link to={to} className="hover:text-indigo-600">{text}</Link>
                  </li>
                ))}
              </ol>
              {data.courses_without_teacher > 0 && (
                <p className="mt-4 flex items-center gap-2 text-sm text-amber-700">
                  <TriangleAlert className="h-4 w-4" /> {data.courses_without_teacher} clase(s) sin profesor asignado.
                </p>
              )}
            </Card>
            <ServicesStatus />
          </div>
        </div>
      ) : null}
    </>
  );
}
