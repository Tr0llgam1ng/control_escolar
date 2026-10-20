import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, GraduationCap, Printer } from 'lucide-react';
import { useApi } from '../../hooks.js';
import { Button, ErrorAlert, Loading } from '../../components/ui.jsx';
import { formatReference } from '../../components/payments.jsx';

const longDate = (d = new Date()) => new Date(d).toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric' });
const STATUS = { in_progress: 'En curso', approved: 'Aprobada', failed: 'No aprobada' };

/**
 * A document the student paid for. The gateway only returns it when the reference is approved
 * (payments) and composes the data from catalog (student, period) and enrollment (grades).
 */
export default function DocumentView() {
  const { id } = useParams();
  const { data, error, loading } = useApi(`/me/documents/${id}`);

  if (loading && !data) return <Loading />;
  if (error) {
    return (
      <>
        <ErrorAlert error={error} />
        <Link to="/student/payments"><Button variant="secondary" icon={ArrowLeft}>Volver a mis pagos</Button></Link>
      </>
    );
  }

  const { charge, student, current_period: period, summary } = data;
  const code = charge.document_type?.code;
  const current = data.data.filter((e) => e.course.period?.id === period?.id);
  const rows = code === 'kardex' ? data.data : current;

  return (
    <>
      <div className="mb-4 flex justify-between gap-2 print:hidden">
        <Link to="/student/payments"><Button variant="secondary" icon={ArrowLeft}>Mis pagos</Button></Link>
        <Button icon={Printer} onClick={() => window.print()}>Imprimir / guardar PDF</Button>
      </div>

      <article className="mx-auto max-w-3xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm print:border-0 print:p-0 print:shadow-none sm:p-12">
        <header className="flex items-center gap-4 border-b border-slate-200 pb-5">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-white"><GraduationCap className="h-7 w-7" /></div>
          <div>
            <p className="text-lg font-semibold text-slate-900">Control Escolar</p>
            <p className="text-xs text-slate-500">Documento oficial · Folio {formatReference(charge.reference)}</p>
          </div>
        </header>

        <h1 className="mt-8 text-center text-xl font-semibold uppercase tracking-wide text-slate-900">{charge.concept}</h1>

        <section className="mt-6 space-y-3 text-sm leading-relaxed text-slate-700">
          {code === 'constancia_estudios' && (
            <p>
              Se hace constar que <b>{student.name}</b>, con número de control <b>{student.enrollment_number}</b>, es alumno(a) inscrito(a)
              en el programa <b>{student.program}</b>, cursando el <b>{student.semester}° semestre</b>
              {student.group_name ? <> en el grupo <b>{student.group_name}</b></> : null}
              {period ? <> durante el periodo <b>{period.name}</b></> : null}, con {summary.in_progress} materia(s) en curso.
            </p>
          )}
          {code !== 'constancia_estudios' && (
            <p>
              Alumno(a): <b>{student.name}</b> · No. de control <b>{student.enrollment_number}</b> · {student.program} · {student.semester}° semestre
              {period && code !== 'kardex' ? <> · Periodo {period.name}</> : null}
            </p>
          )}
          {code === 'inscripcion' && <p>Se hace constar que el alumno(a) cubrió la cuota de inscripción / reinscripción{period ? <> al periodo <b>{period.name}</b></> : null}.</p>}
        </section>

        {['boleta', 'kardex', 'comprobante_inscripcion'].includes(code) && (
          <table className="mt-6 w-full border-collapse text-sm">
            <thead>
              <tr className="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
                {code === 'kardex' && <th className="py-2 pr-2">Periodo</th>}
                <th className="py-2 pr-2">Materia</th>
                <th className="py-2 pr-2">Grupo</th>
                {code === 'comprobante_inscripcion' ? <th className="py-2 pr-2">Profesor</th> : <th className="py-2 pr-2 text-center">Final</th>}
                <th className="py-2">Estado</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && <tr><td colSpan={5} className="py-4 text-center text-slate-400">Sin materias registradas.</td></tr>}
              {rows.map((e) => (
                <tr key={e.id} className="border-b border-slate-100">
                  {code === 'kardex' && <td className="py-2 pr-2 text-xs">{e.course.period?.name}</td>}
                  <td className="py-2 pr-2">{e.course.subject.code} · {e.course.subject.name}</td>
                  <td className="py-2 pr-2">{e.course.group_name}</td>
                  {code === 'comprobante_inscripcion'
                    ? <td className="py-2 pr-2">{e.course.teacher?.name ?? 'Por asignar'}</td>
                    : <td className="py-2 pr-2 text-center tabular-nums">{e.final_grade ?? '—'}</td>}
                  <td className="py-2">{STATUS[e.status] ?? e.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {code === 'kardex' && (
          <p className="mt-4 text-sm text-slate-700">
            Materias aprobadas: <b>{summary.approved}</b> · No aprobadas: <b>{summary.failed}</b> · Promedio general: <b>{summary.average ?? '—'}</b>
          </p>
        )}

        <footer className="mt-12 text-sm text-slate-600">
          <p>Se extiende el presente a solicitud del interesado(a) el {longDate()}.</p>
          <div className="mt-14 w-64 border-t border-slate-400 pt-1 text-center text-xs">Departamento de Control Escolar</div>
          <p className="mt-8 text-[11px] text-slate-400">
            Pagado el {longDate(charge.paid_at)} · {charge.method_label} · Referencia {formatReference(charge.reference)}
          </p>
        </footer>
      </article>
    </>
  );
}
