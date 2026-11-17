import { SetMetadata } from '@nestjs/common';

export const REQUIRES_FEATURE = 'membership:requiresFeature';
export const USES_QUOTA = 'membership:usesQuota';

/**
 * Authorization by plan: the route is only for students whose plan includes the feature
 * (checked by PlanGuard against memberships-service). Other roles are never limited by a plan.
 */
export const RequiresFeature = (feature: string) => SetMetadata(REQUIRES_FEATURE, feature);

/**
 * The route spends one use of a quota feature of the student's plan (QuotaInterceptor):
 * the use is taken before routing and given back if the routed call fails (compensation).
 */
export const UsesQuota = (feature: string, amount = 1) => SetMetadata(USES_QUOTA, { feature, amount });
