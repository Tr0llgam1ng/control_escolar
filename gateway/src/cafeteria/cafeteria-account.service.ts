import { BadGatewayException, ConflictException, HttpException, Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { createHmac } from 'crypto';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';

export type CafeteriaAccountState = 'active' | 'pending_activation' | 'not_registered';

interface CallOptions {
  body?: unknown;
  query?: Record<string, string | number | undefined | null>;
}

/**
 * Identity bridge with the external Sales System (cafeteria).
 * Its cart and orders belong to ITS users, so every user of the school system gets an
 * account there with the same e-mail. The password is derived (HMAC of the e-mail) so the
 * gateway never stores it. The Sales System activates accounts with a random code that it
 * sends as a notification, so the user types that code once (activate()).
 */
@Injectable()
export class CafeteriaAccountService {
  private readonly tokens = new Map<string, { token: string; until: number }>();
  private static readonly TOKEN_TTL_MS = 55 * 60_000; // their JWT lasts 60 minutes
  private readonly secret: string;
  private readonly webhookSecret: string;

  constructor(
    private readonly services: ServiceClient,
    config: ConfigService,
  ) {
    this.secret = config.get('CAFETERIA_ACCOUNT_SECRET') || config.get('SERVICE_KEY') || '';
    this.webhookSecret = config.get('CAFETERIA_WEBHOOK_SECRET') ?? '';
  }

  get onlinePaymentEnabled(): boolean {
    return this.webhookSecret.length > 0;
  }

  /** Header the Sales System expects on its payment webhook. */
  webhookHeaders(): Record<string, string> {
    return { 'Webhook-Signature': this.webhookSecret };
  }

  async status(user: AuthUser): Promise<{ state: CafeteriaAccountState; email: string; online_payment: boolean }> {
    let state: CafeteriaAccountState = 'active';
    try {
      await this.token(user, true);
    } catch (e) {
      const code = this.codeOf(e);
      if (code === 'cafeteria_activation_required') state = 'pending_activation';
      else if (code === 'cafeteria_account_required') state = 'not_registered';
      else throw e;
    }
    return { state, email: user.email, online_payment: this.onlinePaymentEnabled };
  }

  /** Step 1: create the account in the Sales System. It answers by sending an activation code. */
  async register(user: AuthUser) {
    try {
      await this.services.post('cafeteria', '/v1/auth/register', {
        body: { nombre: user.name, correo: user.email, password: this.password(user) },
      });
    } catch (e) {
      if (e instanceof HttpException && e.getStatus() === 400) {
        throw new ConflictException({
          message: 'Ese correo ya tiene una cuenta en la cafetería creada fuera de Control Escolar. Pide al administrador de la cafetería que la elimine.',
          service: 'cafeteria',
        });
      }
      throw e;
    }
    return this.status(user);
  }

  /** Step 2: send the activation code the Sales System issued. */
  async activate(user: AuthUser, code: string) {
    await this.services.post('cafeteria', '/v1/auth/validate', {
      body: { correo: user.email, codigo_validacion: String(code ?? '').trim() },
    });
    this.tokens.delete(user.email);
    return this.status(user);
  }

  /** Calls the Sales System as this user; on an expired token logs in again once. */
  async call<T = any>(user: AuthUser, method: string, path: string, opts: CallOptions = {}): Promise<T> {
    const send = async (token: string) =>
      this.services.call<T>('cafeteria', method, path, { ...opts, headers: { Authorization: `Bearer ${token}` } });
    try {
      return await send(await this.token(user));
    } catch (e) {
      if (e instanceof HttpException && e.getStatus() === 401) {
        this.tokens.delete(user.email);
        try {
          return await send(await this.token(user, true));
        } catch (retry) {
          // Never hand the browser a 401 from an external service: the web app would log the user out.
          if (retry instanceof HttpException && retry.getStatus() === 401) {
            throw new BadGatewayException({ message: 'La cafetería rechazó la sesión. Intenta de nuevo.', service: 'cafeteria' });
          }
          throw retry;
        }
      }
      throw e;
    }
  }

  private async token(user: AuthUser, fresh = false): Promise<string> {
    const cached = this.tokens.get(user.email);
    if (!fresh && cached && cached.until > Date.now()) return cached.token;

    try {
      const res = await this.services.post<{ token: string }>('cafeteria', '/v1/auth/login', {
        body: { correo: user.email, password: this.password(user) },
      });
      this.tokens.set(user.email, { token: res.token, until: Date.now() + CafeteriaAccountService.TOKEN_TTL_MS });
      return res.token;
    } catch (e) {
      if (e instanceof HttpException && e.getStatus() === 403) {
        throw new ConflictException({
          message: 'Activa tu cuenta de la cafetería con el código que te envió el servicio.',
          code: 'cafeteria_activation_required',
          service: 'cafeteria',
        });
      }
      if (e instanceof HttpException && e.getStatus() === 401) {
        throw new ConflictException({
          message: 'Aún no tienes cuenta en la cafetería.',
          code: 'cafeteria_account_required',
          service: 'cafeteria',
        });
      }
      throw e;
    }
  }

  private password(user: AuthUser): string {
    return createHmac('sha256', this.secret).update(`cafeteria:${user.email.toLowerCase()}`).digest('base64url').slice(0, 32);
  }

  private codeOf(e: unknown): string | undefined {
    if (!(e instanceof HttpException)) return undefined;
    const body = e.getResponse() as any;
    return typeof body === 'object' ? body?.code : undefined;
  }
}
