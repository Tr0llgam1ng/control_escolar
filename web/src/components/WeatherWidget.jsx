import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ChevronDown, Cloud, CloudDrizzle, CloudFog, CloudLightning, CloudMoon, CloudOff, CloudRain, CloudSnow, CloudSun,
  Cloudy, Droplets, LoaderCircle, LocateFixed, MapPin, Moon, RefreshCw, Search, Sun, Sunrise, Sunset, Umbrella, Wind, X,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { Lock } from 'lucide-react';
import { api } from '../api.js';

/*
 * Weather widget in the top bar (every role). Data comes from the gateway (/api/weather), which calls
 * OpenWeatherMap with the API key: the browser never sees the key.
 * Click → current conditions, next 24 h and the forecast of the next 5 days.
 */

const REFRESH_MS = 10 * 60_000;
const STORAGE_KEY = 'ce_weather_location';

const ICONS = {
  clear: [Sun, Moon],
  partly: [CloudSun, CloudMoon],
  clouds: [Cloudy, Cloudy],
  drizzle: [CloudDrizzle, CloudDrizzle],
  rain: [CloudRain, CloudRain],
  storm: [CloudLightning, CloudLightning],
  snow: [CloudSnow, CloudSnow],
  fog: [CloudFog, CloudFog],
};

/** Icon color on a light background. */
const TINT = {
  clear: ['text-amber-500', 'text-indigo-400'],
  partly: ['text-amber-500', 'text-indigo-400'],
  clouds: ['text-slate-500', 'text-slate-500'],
  drizzle: ['text-sky-500', 'text-sky-500'],
  rain: ['text-blue-500', 'text-blue-500'],
  storm: ['text-violet-500', 'text-violet-500'],
  snow: ['text-sky-400', 'text-sky-400'],
  fog: ['text-slate-400', 'text-slate-400'],
};

/** Background of the panel header: changes with the weather and with day / night. */
const SKY = {
  clear: ['from-sky-400 via-sky-500 to-blue-600', 'from-indigo-950 via-indigo-900 to-slate-900'],
  partly: ['from-sky-400 via-blue-500 to-indigo-500', 'from-slate-900 via-indigo-900 to-slate-800'],
  clouds: ['from-slate-400 via-slate-500 to-slate-600', 'from-slate-800 via-slate-700 to-slate-900'],
  drizzle: ['from-slate-500 via-sky-700 to-slate-700', 'from-slate-900 via-sky-950 to-slate-800'],
  rain: ['from-slate-600 via-blue-800 to-slate-800', 'from-slate-900 via-blue-950 to-slate-900'],
  storm: ['from-slate-700 via-violet-900 to-slate-900', 'from-slate-950 via-violet-950 to-slate-900'],
  snow: ['from-sky-300 via-slate-400 to-slate-500', 'from-slate-700 via-slate-800 to-slate-900'],
  fog: ['from-slate-300 via-slate-400 to-slate-500', 'from-slate-700 via-slate-800 to-slate-900'],
};

const pick = (map, condition, isDay = true) => (map[condition] ?? map.clouds)[isDay ? 0 : 1];

export function WeatherIcon({ condition, isDay = true, className = 'h-5 w-5', tinted = true }) {
  const Icon = pick(ICONS, condition, isDay) ?? Cloud;
  return <Icon className={`${className} ${tinted ? pick(TINT, condition, isDay) : ''}`} aria-hidden="true" />;
}

const dayName = (iso, index) => {
  if (index === 0) return 'Mañana';
  const d = new Date(`${iso}T12:00:00Z`);
  const name = d.toLocaleDateString('es-MX', { weekday: 'long', timeZone: 'UTC' });
  return name.charAt(0).toUpperCase() + name.slice(1);
};
const dayDate = (iso) => new Date(`${iso}T12:00:00Z`).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', timeZone: 'UTC' });

const minutesAgo = (iso) => {
  const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000));
  return m < 1 ? 'hace un momento' : m === 1 ? 'hace 1 min' : `hace ${m} min`;
};

function loadLocation() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null');
  } catch {
    return null;
  }
}

function saveLocation(location) {
  try {
    if (location) localStorage.setItem(STORAGE_KEY, JSON.stringify(location));
    else localStorage.removeItem(STORAGE_KEY);
  } catch {
    /* storage unavailable: the choice lasts until the page reloads */
  }
}

const queryOf = (location) =>
  location?.lat !== undefined ? { lat: location.lat, lon: location.lon } : location?.city ? { city: location.city } : {};

/** Loads the weather for the chosen location and refreshes it every 10 minutes. */
function useWeather(location) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const key = JSON.stringify(queryOf(location));

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true }));
    try {
      const data = await api('/weather', { query: JSON.parse(key) });
      setState({ data, error: null, loading: false });
      return true;
    } catch (error) {
      setState((s) => ({ data: s.data, error, loading: false }));
      return false;
    }
  }, [key]);

  useEffect(() => {
    load();
    const timer = setInterval(load, REFRESH_MS);
    return () => clearInterval(timer);
  }, [load]);

  return { ...state, reload: load };
}

export default function WeatherWidget() {
  const [location, setLocation] = useState(loadLocation);
  const { data, error, loading, reload } = useWeather(location);
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
    const onKey = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const choose = (next) => {
    setLocation(next);
    saveLocation(next);
  };

  const current = data?.current;

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="dialog"
        aria-expanded={open}
        title="Ver pronóstico del clima"
        className="group flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1.5 pl-2 pr-3 text-sm shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50/60"
      >
        {current ? (
          <>
            <WeatherIcon condition={current.condition} isDay={current.is_day} className="h-5 w-5" />
            <span className="font-semibold tabular-nums text-slate-800">{current.temp}°</span>
            <span className="hidden max-w-28 truncate text-slate-500 md:inline">{data.location.name}</span>
          </>
        ) : loading ? (
          <>
            <LoaderCircle className="h-5 w-5 animate-spin text-slate-300" />
            <span className="h-3 w-12 animate-pulse rounded bg-slate-200" />
          </>
        ) : (
          <>
            <CloudOff className="h-5 w-5 text-slate-400" />
            <span className="text-slate-500">Clima</span>
          </>
        )}
        <ChevronDown className={`h-3.5 w-3.5 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div
          role="dialog"
          aria-label="Pronóstico del clima"
          className="fixed inset-x-2 top-16 z-50 max-h-[calc(100vh-5rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[25rem]"
        >
          {data ? (
            <Panel data={data} error={error} loading={loading} onReload={reload} onChoose={choose} usingCustom={!!location} />
          ) : (
            <ErrorPanel error={error} loading={loading} onReload={reload} onReset={location ? () => choose(null) : null} />
          )}
        </div>
      )}
    </div>
  );
}

function Panel({ data, error, loading, onReload, onChoose, usingCustom }) {
  const { current, location } = data;
  const [searching, setSearching] = useState(false);
  const [city, setCity] = useState('');
  const [locating, setLocating] = useState(false);
  const [notice, setNotice] = useState(null);
  const [expanded, setExpanded] = useState(null);

  const locate = () => {
    if (!navigator.geolocation) {
      setNotice('Tu navegador no permite obtener la ubicación.');
      return;
    }
    setLocating(true);
    setNotice(null);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLocating(false);
        onChoose({ lat: Number(pos.coords.latitude.toFixed(2)), lon: Number(pos.coords.longitude.toFixed(2)) });
      },
      () => {
        setLocating(false);
        setNotice('No se pudo obtener tu ubicación (permiso denegado o no disponible).');
      },
      { timeout: 10_000, maximumAge: 15 * 60_000 },
    );
  };

  const search = (e) => {
    e.preventDefault();
    if (!city.trim()) return;
    onChoose({ city: city.trim() });
    setSearching(false);
    setCity('');
  };

  const all = data.daily.flatMap((d) => [d.min, d.max]);
  const low = Math.min(...all);
  const high = Math.max(...all);
  const span = Math.max(1, high - low);

  return (
    <>
      <section className={`bg-gradient-to-br ${pick(SKY, current.condition, current.is_day)} px-5 pb-5 pt-4 text-white`}>
        {searching ? (
          <form onSubmit={search} className="flex items-center gap-2">
            <div className="relative flex-1">
              <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-white/70" />
              <input
                autoFocus
                value={city}
                onChange={(e) => setCity(e.target.value)}
                placeholder="Ciudad, ej. Monterrey"
                className="w-full rounded-lg border border-white/30 bg-white/15 py-2 pl-9 pr-3 text-sm text-white placeholder-white/70 outline-none focus:border-white/60 focus:bg-white/20"
              />
            </div>
            <HeaderButton label="Cancelar búsqueda" onClick={() => setSearching(false)} icon={X} />
          </form>
        ) : (
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="flex items-center gap-1 truncate text-sm font-semibold">
                <MapPin className="h-4 w-4 shrink-0" /> {location.name}{location.country ? `, ${location.country}` : ''}
              </p>
              <p className="mt-0.5 text-xs text-white/75">Hora local {current.local_time}</p>
            </div>
            <div className="flex shrink-0 gap-1">
              <HeaderButton label="Buscar ciudad" onClick={() => setSearching(true)} icon={Search} />
              <HeaderButton label="Usar mi ubicación" onClick={locate} icon={locating ? LoaderCircle : LocateFixed} spin={locating} />
              <HeaderButton label="Actualizar" onClick={onReload} icon={RefreshCw} spin={loading} />
            </div>
          </div>
        )}

        <div className="mt-4 flex items-center gap-4">
          <WeatherIcon condition={current.condition} isDay={current.is_day} className="h-16 w-16 drop-shadow" tinted={false} />
          <div>
            <p className="text-5xl font-semibold leading-none tabular-nums">{current.temp}°</p>
            <p className="mt-1 text-sm font-medium">{current.description}</p>
          </div>
        </div>
        <p className="mt-3 text-xs text-white/85">
          Sensación térmica {current.feels_like}° · Máx {current.max}° · Mín {current.min}°
        </p>
      </section>

      {(notice || error) && (
        <p className="border-b border-amber-100 bg-amber-50 px-5 py-2 text-xs text-amber-800">{notice ?? error.message}</p>
      )}

      <section className="grid grid-cols-4 divide-x divide-slate-100 border-b border-slate-100 text-center">
        <Detail icon={Droplets} label="Humedad" value={`${current.humidity}%`} tone="text-sky-500" />
        <Detail icon={Wind} label="Viento" value={`${current.wind} km/h`} tone="text-teal-500" />
        <Detail icon={Sunrise} label="Amanecer" value={current.sunrise ?? '—'} tone="text-amber-500" />
        <Detail icon={Sunset} label="Atardecer" value={current.sunset ?? '—'} tone="text-orange-500" />
      </section>

      {data.hourly.length > 0 && (
        <section className="border-b border-slate-100 px-5 pb-2 pt-4">
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Próximas 24 horas</h3>
          <div className="-mx-1 flex gap-1 overflow-x-auto pb-1">
            {data.hourly.map((h) => (
              <div key={h.time} className="flex min-w-[3.25rem] flex-col items-center gap-1 rounded-xl px-1 py-2 text-xs hover:bg-slate-50">
                <span className="text-slate-500">{h.time}</span>
                <WeatherIcon condition={h.condition} isDay={h.is_day} className="h-5 w-5" />
                <span className="font-semibold tabular-nums text-slate-800">{h.temp}°</span>
                <span className={`text-[10px] tabular-nums ${h.pop > 0 ? 'text-sky-600' : 'text-transparent'}`}>{h.pop}%</span>
              </div>
            ))}
          </div>
        </section>
      )}

      {data.forecast_locked && (
        <section className="flex items-start gap-3 border-b border-slate-100 px-5 py-4 text-sm">
          <div className="rounded-lg bg-indigo-50 p-2 text-indigo-600"><Lock className="h-4 w-4" /></div>
          <div>
            <p className="font-medium text-slate-800">Pronóstico por horas y a 5 días</p>
            <p className="mt-0.5 text-xs text-slate-500">
              Incluido en {(data.upgrade_to ?? []).map((p) => p.name).join(' y ') || 'los planes superiores'}.{' '}
              <Link to="/student/membership" className="font-medium text-indigo-600 hover:underline">Ver planes</Link>
            </p>
          </div>
        </section>
      )}

      {data.daily.length > 0 && <section className="px-3 py-3">
        <h3 className="mb-1 px-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Pronóstico de 5 días</h3>
        <ul>
          {data.daily.map((d, i) => {
            const isOpen = expanded === d.date;
            return (
              <li key={d.date}>
                <button
                  type="button"
                  onClick={() => setExpanded(isOpen ? null : d.date)}
                  aria-expanded={isOpen}
                  className={`grid w-full grid-cols-[6.5rem_1.75rem_2.75rem_1fr] items-center gap-2 rounded-xl px-2 py-2.5 text-left text-sm transition hover:bg-slate-50 ${isOpen ? 'bg-slate-50' : ''}`}
                >
                  <span className="min-w-0 leading-tight">
                    <span className="block truncate font-medium text-slate-800">{dayName(d.date, i)}</span>
                    <span className="block text-xs text-slate-400">
                      {dayDate(d.date)}
                      {d.samples < 5 && <span className="ml-1 text-amber-600" title="El plan gratuito de OpenWeatherMap solo cubre algunas horas de este día">· parcial</span>}
                    </span>
                  </span>
                  <WeatherIcon condition={d.condition} className="h-6 w-6" />
                  <span className={`flex items-center gap-0.5 text-xs tabular-nums ${d.pop >= 20 ? 'text-sky-600' : 'text-slate-300'}`} title="Probabilidad de lluvia">
                    <Umbrella className="h-3.5 w-3.5" />{d.pop}%
                  </span>
                  <span className="flex items-center gap-2 tabular-nums">
                    <span className="w-8 text-right text-slate-400">{d.min}°</span>
                    <span className="relative h-1.5 flex-1 rounded-full bg-slate-100">
                      <span
                        className="absolute inset-y-0 rounded-full bg-gradient-to-r from-sky-400 via-amber-300 to-orange-400"
                        style={{ left: `${((d.min - low) / span) * 100}%`, right: `${((high - d.max) / span) * 100}%` }}
                      />
                    </span>
                    <span className="w-8 font-semibold text-slate-800">{d.max}°</span>
                  </span>
                </button>
                {isOpen && (
                  <div className="mx-2 mb-2 rounded-xl bg-slate-50 px-3 py-3">
                    <p className="text-xs text-slate-600">
                      {d.description} · humedad {d.humidity}% · viento hasta {d.wind} km/h
                    </p>
                    <div className="mt-2 flex gap-1 overflow-x-auto">
                      {d.hours.map((h) => (
                        <div key={h.time} className="flex min-w-[3rem] flex-col items-center gap-1 rounded-lg bg-white px-1 py-1.5 text-[11px] shadow-sm">
                          <span className="text-slate-500">{h.time}</span>
                          <WeatherIcon condition={h.condition} isDay={h.is_day} className="h-4 w-4" />
                          <span className="font-semibold tabular-nums text-slate-800">{h.temp}°</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </li>
            );
          })}
        </ul>
        <p className="mt-1 px-2 text-[11px] text-slate-400">Toca un día para ver su detalle por horas.</p>
      </section>}

      <footer className="flex items-center justify-between gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-2.5 text-[11px] text-slate-400">
        <span>Actualizado {minutesAgo(data.updated_at)} · {data.source}</span>
        {usingCustom && (
          <button type="button" onClick={() => onChoose(null)} className="font-medium text-indigo-600 hover:underline">
            Volver a la ciudad predeterminada
          </button>
        )}
      </footer>
    </>
  );
}

function HeaderButton({ label, onClick, icon: Icon, spin }) {
  return (
    <button type="button" onClick={onClick} title={label} aria-label={label}
      className="rounded-lg bg-white/15 p-1.5 text-white transition hover:bg-white/30">
      <Icon className={`h-4 w-4 ${spin ? 'animate-spin' : ''}`} />
    </button>
  );
}

function Detail({ icon: Icon, label, value, tone }) {
  return (
    <div className="px-1 py-3">
      <Icon className={`mx-auto h-4 w-4 ${tone}`} />
      <p className="mt-1 text-sm font-semibold tabular-nums text-slate-800">{value}</p>
      <p className="text-[11px] text-slate-400">{label}</p>
    </div>
  );
}

function ErrorPanel({ error, loading, onReload, onReset }) {
  return (
    <div className="flex flex-col items-center px-6 py-8 text-center">
      {loading ? (
        <LoaderCircle className="h-8 w-8 animate-spin text-slate-300" />
      ) : (
        <>
          <div className="rounded-full bg-slate-100 p-3 text-slate-400"><CloudOff className="h-7 w-7" /></div>
          <p className="mt-3 font-medium text-slate-700">No se pudo cargar el clima</p>
          <p className="mt-1 text-sm text-slate-500">{error?.message ?? 'Intenta de nuevo en unos minutos.'}</p>
          <div className="mt-4 flex gap-2">
            {onReset && (
              <button type="button" onClick={onReset} className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50">
                Ciudad predeterminada
              </button>
            )}
            <button type="button" onClick={onReload} className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
              <RefreshCw className="h-4 w-4" /> Reintentar
            </button>
          </div>
        </>
      )}
    </div>
  );
}
