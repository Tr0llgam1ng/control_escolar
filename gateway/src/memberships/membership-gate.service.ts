import { ForbiddenException, Injectable } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';

export interface QuotaTicket {
  feature: string;
  amount: number;
  cycle_start?: string;
  applies: boolean;
}

/**
 * The gateway side of "authorization by plan". It never decides limits itself: it asks
 * memberships-service (which owns plans, cycles and usage) and translates the answer.
 * Only students have memberships.
 */
@Injectable()
export class MembershipGate {
  constructor(private readonly services: ServiceClient) {}

  applies(user?: AuthUser | null): boolean {
    return user?.role === 'student';
  }

  /** { allowed, feature, upgrade_to } — no usage is spent. */
  async check(user: AuthUser, feature: string): Promise<{ allowed: boolean; applies: boolean; feature?: any; plan?: any; upgrade_to?: any[] }> {
    if (!this.applies(user)) return { allowed: true, applies: false };
    return this.services.get('memberships', '/access/check', { query: { feature }, user });
  }

  /** Like check() but answers 403 PLAN_REQUIRED when the plan does not include it. */
  async require(user: AuthUser, feature: string): Promise<void> {
    const result = await this.check(user, feature);
    if (!result.allowed) {
      const f = result.feature ?? {};
      const limited = f.available && f.type === 'quota';
      throw new ForbiddenException({
        message: limited
          ? `Alcanzaste el límite de tu plan ${result.plan?.name ?? ''}: ${f.label}.`
          : `Tu plan ${result.plan?.name ?? ''} no incluye: ${f.label ?? feature}.`,
        code: limited ? 'PLAN_LIMIT' : 'PLAN_REQUIRED',
        feature,
        upgrade_to: result.upgrade_to ?? [],
        service: 'memberships',
      });
    }
  }

  /** Spends uses of a quota (memberships-service answers 403 PLAN_REQUIRED / PLAN_LIMIT itself). */
  async consume(user: AuthUser, feature: string, amount = 1): Promise<QuotaTicket> {
    if (!this.applies(user)) return { feature, amount, applies: false };
    const result = await this.services.post('memberships', '/access/consume', { body: { feature, amount }, user });
    return { feature, amount, cycle_start: result.cycle_start, applies: result.applies !== false };
  }

  /** Compensation: gives the uses back. Never throws (the original error is what matters). */
  async release(user: AuthUser, ticket: QuotaTicket): Promise<void> {
    if (!ticket.applies) return;
    await this.services
      .post('memberships', '/access/release', { body: { feature: ticket.feature, amount: ticket.amount, cycle_start: ticket.cycle_start }, user })
      .catch(() => undefined);
  }

  /** consume → run → release if run fails (saga with compensation). */
  async withQuota<T>(user: AuthUser, feature: string, run: () => Promise<T>, amount = 1): Promise<T> {
    const ticket = await this.consume(user, feature, amount);
    try {
      return await run();
    } catch (error) {
      await this.release(user, ticket);
      throw error;
    }
  }
}
