import { createParamDecorator, ExecutionContext, SetMetadata } from '@nestjs/common';
import { Role } from '../common/types';

export const IS_PUBLIC = 'isPublic';
export const ROLES = 'roles';

/** Route does not require a token (login, health). */
export const Public = () => SetMetadata(IS_PUBLIC, true);

/** Route restricted to the given roles (RF-02). */
export const Roles = (...roles: Role[]) => SetMetadata(ROLES, roles);

/** Injects the authenticated user resolved by AuthGuard. */
export const CurrentUser = createParamDecorator((_: unknown, ctx: ExecutionContext) => ctx.switchToHttp().getRequest().user);
