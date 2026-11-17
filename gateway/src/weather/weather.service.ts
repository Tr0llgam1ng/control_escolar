import { BadGatewayException, BadRequestException, Injectable, NotFoundException, ServiceUnavailableException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

/** Weather condition groups the web app maps to icons and colors. */
export type Condition = 'clear' | 'partly' | 'clouds' | 'drizzle' | 'rain' | 'storm' | 'snow' | 'fog';

export interface WeatherQuery {
  lat?: number;
  lon?: number;
  city?: string;
}

/**
 * External service: OpenWeatherMap (free plan: "current weather" + "5 day / 3 hour forecast").
 * The API key never leaves the gateway. Responses are normalized (Spanish descriptions, °C, km/h,
 * local times of the city) and cached for a few minutes so every page load does not cost an API call.
 */
@Injectable()
export class WeatherService {
  private static readonly BASE = 'https://api.openweathermap.org/data/2.5';
  private static readonly TTL_MS = 10 * 60_000;
  private readonly cache = new Map<string, { until: number; data: any }>();

  constructor(private readonly config: ConfigService) {}

  async get(query: WeatherQuery) {
    const key = this.config.get<string>('OPENWEATHER_API_KEY') ?? '';
    if (!key) {
      throw new ServiceUnavailableException({ message: 'El clima no está configurado: falta OPENWEATHER_API_KEY en gateway/.env.', service: 'weather' });
    }

    const location = this.location(query);
    const cacheKey = JSON.stringify(location);
    const hit = this.cache.get(cacheKey);
    if (hit && hit.until > Date.now()) return { ...hit.data, cached: true };

    const params = { ...location, units: 'metric', lang: 'es', appid: key };
    const [current, forecast] = await Promise.all([this.call('/weather', params), this.call('/forecast', params)]);
    const data = this.normalize(current, forecast);

    this.cache.set(cacheKey, { until: Date.now() + WeatherService.TTL_MS, data });
    if (this.cache.size > 200) this.cache.delete(this.cache.keys().next().value!);

    return { ...data, cached: false };
  }

  /** Coordinates (rounded to ~1 km so nearby users share the cache), a city name, or the default city. */
  private location(query: WeatherQuery): Record<string, string> {
    const { lat, lon } = query;
    if (lat !== undefined || lon !== undefined) {
      if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat!) > 90 || Math.abs(lon!) > 180) {
        throw new BadRequestException('Coordenadas inválidas.');
      }
      return { lat: lat!.toFixed(2), lon: lon!.toFixed(2) };
    }
    const city = (query.city ?? '').trim();
    if (city) {
      if (city.length > 80) throw new BadRequestException('Nombre de ciudad demasiado largo.');
      return { q: city };
    }
    return { q: this.config.get<string>('WEATHER_DEFAULT_CITY') || 'Durango,MX' };
  }

  private async call(path: string, params: Record<string, string>) {
    const url = new URL(WeatherService.BASE + path);
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

    let response: Response;
    try {
      response = await fetch(url, { signal: AbortSignal.timeout(8000) });
    } catch {
      throw new ServiceUnavailableException({ message: 'No se pudo conectar con el servicio del clima (OpenWeatherMap).', service: 'weather' });
    }

    const body: any = await response.json().catch(() => null);
    if (response.ok) return body;

    if (response.status === 401) {
      throw new BadGatewayException({
        message: 'OpenWeatherMap rechazó la API key. Si la acabas de crear, puede tardar hasta 2 horas en activarse.',
        service: 'weather',
      });
    }
    if (response.status === 404) throw new NotFoundException({ message: 'No se encontró esa ciudad.', service: 'weather' });
    if (response.status === 429) {
      throw new ServiceUnavailableException({ message: 'Se alcanzó el límite de consultas del clima. Intenta en unos minutos.', service: 'weather' });
    }
    throw new BadGatewayException({ message: `El servicio del clima respondió ${response.status}: ${body?.message ?? 'sin detalle'}.`, service: 'weather' });
  }

  private normalize(current: any, forecast: any) {
    const offset: number = current.timezone ?? forecast.city?.timezone ?? 0; // seconds from UTC
    const w = current.weather?.[0] ?? {};

    const items = (forecast.list ?? []).map((i: any) => ({
      dt: i.dt as number,
      date: localDate(i.dt, offset),
      time: localTime(i.dt, offset),
      hour: new Date((i.dt + offset) * 1000).getUTCHours(),
      temp: round(i.main.temp),
      min: i.main.temp_min as number,
      max: i.main.temp_max as number,
      humidity: i.main.humidity as number,
      wind: kmh(i.wind?.speed),
      pop: Math.round((i.pop ?? 0) * 100),
      description: capitalize(i.weather?.[0]?.description),
      condition: condition(i.weather?.[0]?.id),
      is_day: (i.weather?.[0]?.icon ?? 'd').endsWith('d'),
    }));

    // Group the 3-hour steps by local day; skip today (it is the "current" block) and keep 5 days.
    const today = localDate(current.dt, offset);
    const byDay = new Map<string, any[]>();
    for (const i of items) {
      if (i.date === today) continue;
      byDay.set(i.date, [...(byDay.get(i.date) ?? []), i]);
    }

    const daily = [...byDay.entries()].slice(0, 5).map(([date, list]) => {
      // The midday step represents the day best; otherwise the most frequent condition.
      const midday = [...list].sort((a, b) => Math.abs(a.hour - 13) - Math.abs(b.hour - 13))[0];
      return {
        date,
        min: round(Math.min(...list.map((i) => i.min))),
        max: round(Math.max(...list.map((i) => i.max))),
        pop: Math.max(...list.map((i) => i.pop)),
        humidity: Math.round(list.reduce((s, i) => s + i.humidity, 0) / list.length),
        wind: Math.max(...list.map((i) => i.wind)),
        description: midday.description,
        condition: midday.condition,
        samples: list.length,
        hours: list.map(({ time, temp, pop, condition, is_day }) => ({ time, temp, pop, condition, is_day })),
      };
    });

    return {
      location: { name: current.name || forecast.city?.name, country: current.sys?.country ?? forecast.city?.country, lat: current.coord?.lat, lon: current.coord?.lon },
      current: {
        temp: round(current.main.temp),
        feels_like: round(current.main.feels_like),
        min: round(current.main.temp_min),
        max: round(current.main.temp_max),
        humidity: current.main.humidity,
        pressure: current.main.pressure,
        wind: kmh(current.wind?.speed),
        wind_deg: current.wind?.deg ?? null,
        clouds: current.clouds?.all ?? null,
        visibility_km: current.visibility != null ? Math.round(current.visibility / 100) / 10 : null,
        description: capitalize(w.description),
        condition: condition(w.id),
        is_day: (w.icon ?? 'd').endsWith('d'),
        sunrise: current.sys?.sunrise ? localTime(current.sys.sunrise, offset) : null,
        sunset: current.sys?.sunset ? localTime(current.sys.sunset, offset) : null,
        local_time: localTime(current.dt, offset),
      },
      // Next 24 h in 3-hour steps.
      hourly: items.filter((i: any) => i.dt > current.dt).slice(0, 8).map(({ time, temp, pop, condition, is_day }: any) => ({ time, temp, pop, condition, is_day })),
      daily,
      updated_at: new Date().toISOString(),
      source: 'OpenWeatherMap',
    };
  }
}

const round = (n: number) => Math.round(n);
const kmh = (ms?: number) => Math.round((ms ?? 0) * 3.6);
const capitalize = (s?: string) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');
const shifted = (dt: number, offset: number) => new Date((dt + offset) * 1000);
const localDate = (dt: number, offset: number) => shifted(dt, offset).toISOString().slice(0, 10);
const localTime = (dt: number, offset: number) => shifted(dt, offset).toISOString().slice(11, 16);

/** OpenWeatherMap condition codes → groups (https://openweathermap.org/weather-conditions). */
function condition(id?: number): Condition {
  if (!id) return 'clouds';
  if (id >= 200 && id < 300) return 'storm';
  if (id >= 300 && id < 400) return 'drizzle';
  if (id === 511 || (id >= 600 && id < 700)) return 'snow';
  if (id >= 500 && id < 600) return 'rain';
  if (id >= 700 && id < 800) return 'fog';
  if (id === 800) return 'clear';
  if (id === 801 || id === 802) return 'partly';
  return 'clouds';
}
