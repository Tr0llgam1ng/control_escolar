import { Controller, Get, Query } from '@nestjs/common';
import { CurrentUser, Roles } from '../auth/decorators';
import { AuthUser } from '../common/types';
import { MembershipGate } from '../memberships/membership-gate.service';
import { WeatherService } from './weather.service';

/**
 * Weather widget (every role). External service consumed by the gateway: OpenWeatherMap.
 *  GET /api/weather                 → default city (WEATHER_DEFAULT_CITY)
 *  GET /api/weather?lat=..&lon=..   → the user's location (browser geolocation)
 *  GET /api/weather?city=Monterrey  → a city searched by the user
 * Students see the hourly and 5-day forecast only if their plan includes "weather.forecast" (Silver, Gold).
 */
@Controller('weather')
export class WeatherController {
  constructor(
    private readonly weather: WeatherService,
    private readonly gate: MembershipGate,
  ) {}

  @Roles('admin', 'teacher', 'student')
  @Get()
  async get(@CurrentUser() user: AuthUser, @Query('lat') lat?: string, @Query('lon') lon?: string, @Query('city') city?: string) {
    const [data, access] = await Promise.all([
      this.weather.get({
        lat: lat !== undefined && lat !== '' ? Number(lat) : undefined,
        lon: lon !== undefined && lon !== '' ? Number(lon) : undefined,
        city,
      }),
      // If memberships-service is down the forecast is hidden, never the current weather.
      this.gate.check(user, 'weather.forecast').catch(() => ({ allowed: false, applies: true, upgrade_to: [] as any[] })),
    ]);
    if (access.allowed) return data;
    return { ...data, hourly: [], daily: [], forecast_locked: true, upgrade_to: access.upgrade_to ?? [] };
  }
}
