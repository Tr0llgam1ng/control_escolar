import { Activity, Coffee, Server } from 'lucide-react';
import { useApi } from '../hooks.js';

const LABELS = {
  auth: ['Servicio 1', 'Autenticación'],
  catalog: ['Servicio 2', 'Catálogo académico (CRUD)'],
  enrollment: ['Servicio 3', 'Inscripciones y calificaciones'],
  payments: ['Servicio 4', 'Pagos y referencias'],
};

// External Sales System (classmate's computer): one process, four services.
const CAFETERIA_LABELS = {
  'cafeteria-catalog': ['Catálogo', 'Menú de la cafetería'],
  'cafeteria-cart': ['Carrito', 'Productos por pedir'],
  'cafeteria-orders': ['Órdenes', 'Pedidos'],
  'cafeteria-shipping': ['Envíos', 'Entrega y seguimiento'],
};

/** Shows the SOA topology: App → NestJS Gateway → 4 Laravel services + the external cafeteria, with live status. */
export default function ServicesStatus({ compact = false }) {
  const { data, error, reload } = useApi('/health');

  const services = data?.services ?? [
    ...Object.keys(LABELS).map((name) => ({ name, up: false })),
    ...Object.keys(CAFETERIA_LABELS).map((name) => ({ name, group: 'cafeteria', up: false })),
  ];
  const own = services.filter((s) => s.group !== 'cafeteria');
  const cafeteria = services.filter((s) => s.group === 'cafeteria');
  const cafeteriaUrl = cafeteria.find((s) => s.url)?.url;

  return (
    <div className={compact ? '' : 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm'}>
      <div className="mb-3 flex items-center justify-between">
        <p className="flex items-center gap-2 text-sm font-semibold text-slate-700">
          <Activity className="h-4 w-4 text-indigo-500" /> Estado de la arquitectura
        </p>
        <button onClick={reload} className="text-xs text-indigo-600 hover:underline">
          Actualizar
        </button>
      </div>
      <div className="flex items-center gap-2 text-xs">
        <Dot up={!error} /> <span className="font-medium text-slate-700">API Gateway (NestJS)</span>
        <span className="text-slate-400">{error ? 'sin conexión' : ':3000'}</span>
      </div>
      <ul className="mt-2 space-y-1.5 border-l-2 border-slate-100 pl-4">
        {own.map((s) => (
          <li key={s.name} className="flex items-center gap-2 text-xs">
            <Dot up={s.up} />
            <Server className="h-3.5 w-3.5 text-slate-400" />
            <span className="font-medium text-slate-700">{LABELS[s.name]?.[0]}</span>
            <span className="text-slate-500">{LABELS[s.name]?.[1]}</span>
            {s.url && <span className="ml-auto text-slate-400">{s.url.replace(/^https?:\/\//, '')}</span>}
          </li>
        ))}
        <li className="pt-1.5">
          <div className="flex items-center gap-2 text-xs">
            <Dot up={cafeteria.length > 0 && cafeteria.every((s) => s.up)} />
            <Coffee className="h-3.5 w-3.5 text-amber-500" />
            <span className="font-medium text-slate-700">Cafetería</span>
            <span className="text-slate-500">Sistema de Ventas · externo</span>
            {cafeteriaUrl && <span className="ml-auto text-slate-400">{cafeteriaUrl.replace(/^https?:\/\//, '')}</span>}
          </div>
          <ul className="mt-1.5 space-y-1 border-l-2 border-amber-100 pl-4">
            {cafeteria.map((s) => (
              <li key={s.name} className="flex items-center gap-2 text-xs">
                <Dot up={s.up} />
                <span className="font-medium text-slate-700">{CAFETERIA_LABELS[s.name]?.[0]}</span>
                <span className="text-slate-500">{CAFETERIA_LABELS[s.name]?.[1]}</span>
                {s.up && typeof s.ms === 'number' && <span className="ml-auto text-slate-400">{s.ms} ms</span>}
              </li>
            ))}
          </ul>
        </li>
      </ul>
    </div>
  );
}

function Dot({ up }) {
  return <span className={`inline-block h-2 w-2 shrink-0 rounded-full ${up ? 'bg-emerald-500' : 'bg-rose-500'}`} />;
}
