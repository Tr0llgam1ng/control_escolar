import { HttpException, Injectable, ServiceUnavailableException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { AuthUser, ServiceName } from './types';

interface CallOptions {
  body?: unknown;
  query?: Record<string, string | number | undefined | null>;
  user?: AuthUser | null;
}

/**
 * The only place where the gateway talks to the Laravel services.
 * Adds the shared service key and forwards the authenticated user as X-User-Id / X-User-Role.
 * Service errors (401, 403, 404, 409, 422…) are passed through to the client unchanged.
 */
@Injectable()
export class ServiceClient {
  private readonly urls: Record<ServiceName, string>;
  private readonly key: string;

  constructor(config: ConfigService) {
    this.urls = {
      auth: config.get('AUTH_SERVICE_URL') ?? 'http://127.0.0.1:8001',
      catalog: config.get('CATALOG_SERVICE_URL') ?? 'http://127.0.0.1:8002',
      enrollment: config.get('ENROLLMENT_SERVICE_URL') ?? 'http://127.0.0.1:8003',
    };
    this.key = config.get('SERVICE_KEY') ?? '';
  }

  baseUrl(service: ServiceName): string {
    return this.urls[service].replace(/\/$/, '');
  }

  get<T = any>(service: ServiceName, path: string, opts: CallOptions = {}) {
    return this.call<T>(service, 'GET', path, opts);
  }

  post<T = any>(service: ServiceName, path: string, opts: CallOptions = {}) {
    return this.call<T>(service, 'POST', path, opts);
  }

  put<T = any>(service: ServiceName, path: string, opts: CallOptions = {}) {
    return this.call<T>(service, 'PUT', path, opts);
  }

  patch<T = any>(service: ServiceName, path: string, opts: CallOptions = {}) {
    return this.call<T>(service, 'PATCH', path, opts);
  }

  delete<T = any>(service: ServiceName, path: string, opts: CallOptions = {}) {
    return this.call<T>(service, 'DELETE', path, opts);
  }

  /** Like get() but returns null on 404 instead of throwing. */
  async find<T = any>(service: ServiceName, path: string, opts: CallOptions = {}): Promise<T | null> {
    try {
      return await this.get<T>(service, path, opts);
    } catch (e) {
      if (e instanceof HttpException && e.getStatus() === 404) return null;
      throw e;
    }
  }

  async call<T = any>(service: ServiceName, method: string, path: string, opts: CallOptions = {}): Promise<T> {
    const url = new URL(this.baseUrl(service) + '/api' + path);
    for (const [k, v] of Object.entries(opts.query ?? {})) {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    }

    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Service-Key': this.key,
    };
    if (opts.user) {
      headers['X-User-Id'] = String(opts.user.id);
      headers['X-User-Role'] = opts.user.role;
    }

    let response: Response;
    try {
      response = await fetch(url, {
        method,
        headers,
        body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
        signal: AbortSignal.timeout(8000),
      });
    } catch {
      // RNF-12: one service down must not crash the gateway — report which one.
      throw new ServiceUnavailableException({ message: `El servicio "${service}" no está disponible.`, service });
    }

    if (response.status === 204) return null as T;

    const text = await response.text();
    let payload: any = null;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      payload = { message: text };
    }

    if (!response.ok) {
      throw new HttpException(
        { ...(payload ?? {}), message: payload?.message ?? 'Error en el servicio', service },
        response.status,
      );
    }

    return payload as T;
  }
}
