import { CanActivate, ExecutionContext, Injectable, UnauthorizedException } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { IS_PUBLIC } from './decorators';

/**
 * Centralized authentication (document: "un solo servicio emite y valida los tokens").
 * Every request's Bearer token is validated against the Auth Service; results are
 * cached for a few seconds to avoid one extra call per request.
 */
@Injectable()
export class AuthGuard implements CanActivate {
  private readonly cache = new Map<string, { user: AuthUser; until: number }>();
  private static readonly TTL_MS = 30_000;

  constructor(
    private readonly reflector: Reflector,
    private readonly services: ServiceClient,
  ) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const isPublic = this.reflector.getAllAndOverride<boolean>(IS_PUBLIC, [context.getHandler(), context.getClass()]);
    if (isPublic) return true;

    const request = context.switchToHttp().getRequest();
    const header: string = request.headers['authorization'] ?? '';
    const token = header.startsWith('Bearer ') ? header.slice(7).trim() : '';
    if (!token) throw new UnauthorizedException('Falta el token de acceso.');

    const cached = this.cache.get(token);
    if (cached && cached.until > Date.now()) {
      request.user = cached.user;
      request.token = token;
      return true;
    }

    try {
      const result = await this.services.post<{ valid: boolean; user: AuthUser }>('auth', '/auth/validate', { body: { token } });
      this.cache.set(token, { user: result.user, until: Date.now() + AuthGuard.TTL_MS });
      request.user = result.user;
      request.token = token;
      return true;
    } catch (e: any) {
      if (e?.getStatus?.() === 401) throw new UnauthorizedException('Sesión inválida o expirada.');
      throw e;
    }
  }

  forget(token: string) {
    this.cache.delete(token);
  }
}
