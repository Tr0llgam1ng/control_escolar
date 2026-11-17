import { CallHandler, ExecutionContext, Injectable, NestInterceptor } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { Observable, catchError, from, switchMap, throwError } from 'rxjs';
import { USES_QUOTA } from './plan.decorators';
import { MembershipGate } from './membership-gate.service';

/**
 * @UsesQuota routes: take the use from the plan before routing; if the routed service fails,
 * give it back (compensation), then let the original error through.
 */
@Injectable()
export class QuotaInterceptor implements NestInterceptor {
  constructor(
    private readonly reflector: Reflector,
    private readonly gate: MembershipGate,
  ) {}

  intercept(context: ExecutionContext, next: CallHandler): Observable<unknown> {
    const quota = this.reflector.getAllAndOverride<{ feature: string; amount: number }>(USES_QUOTA, [context.getHandler(), context.getClass()]);
    if (!quota) return next.handle();

    const user = context.switchToHttp().getRequest().user;
    return from(this.gate.consume(user, quota.feature, quota.amount)).pipe(
      switchMap((ticket) =>
        next.handle().pipe(catchError((error) => from(this.gate.release(user, ticket)).pipe(switchMap(() => throwError(() => error))))),
      ),
    );
  }
}
