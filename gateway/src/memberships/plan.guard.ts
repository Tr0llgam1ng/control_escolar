import { CanActivate, ExecutionContext, Injectable } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { REQUIRES_FEATURE } from './plan.decorators';
import { MembershipGate } from './membership-gate.service';

/** Third guard (after AuthGuard and RolesGuard): the student's plan must include the feature. */
@Injectable()
export class PlanGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly gate: MembershipGate,
  ) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const feature = this.reflector.getAllAndOverride<string>(REQUIRES_FEATURE, [context.getHandler(), context.getClass()]);
    if (!feature) return true;

    const user = context.switchToHttp().getRequest().user;
    await this.gate.require(user, feature);
    return true;
  }
}
