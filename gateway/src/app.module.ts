import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { APP_GUARD, APP_INTERCEPTOR } from '@nestjs/core';
import { ServiceClient } from './common/service-client';
import { AuthGuard } from './auth/auth.guard';
import { RolesGuard } from './auth/roles.guard';
import { AuthController } from './auth/auth.controller';
import { PeriodsController } from './catalog/periods.controller';
import { SubjectsController } from './catalog/subjects.controller';
import { CoursesController } from './catalog/courses.controller';
import { TeachersController } from './catalog/teachers.controller';
import { StudentsController } from './catalog/students.controller';
import { EnrollmentsController } from './enrollment/enrollments.controller';
import { RosterService } from './enrollment/roster.service';
import { MeController } from './me/me.controller';
import { StudentReportService } from './me/student-report.service';
import { HealthController } from './health/health.controller';
import { PaymentsController } from './payments/payments.controller';
import { InvoicingController } from './invoicing/invoicing.controller';
import { WeatherController } from './weather/weather.controller';
import { WeatherService } from './weather/weather.service';
import { CafeteriaController } from './cafeteria/cafeteria.controller';
import { CafeteriaAccountService } from './cafeteria/cafeteria-account.service';
import { StudentVerificationController } from './partners/student-verification.controller';
import { MembershipsController } from './memberships/memberships.controller';
import { MembershipModulesController } from './memberships/membership-modules.controller';
import { MembershipGate } from './memberships/membership-gate.service';
import { PlanGuard } from './memberships/plan.guard';
import { QuotaInterceptor } from './memberships/quota.interceptor';

@Module({
  imports: [ConfigModule.forRoot({ isGlobal: true })],
  controllers: [
    AuthController,
    PeriodsController,
    SubjectsController,
    CoursesController,
    TeachersController,
    StudentsController,
    EnrollmentsController,
    MeController,
    HealthController,
    PaymentsController,
    InvoicingController,
    WeatherController,
    CafeteriaController,
    StudentVerificationController,
    MembershipsController,
    MembershipModulesController,
  ],
  providers: [
    ServiceClient,
    RosterService,
    StudentReportService,
    CafeteriaAccountService,
    WeatherService,
    MembershipGate,
    AuthGuard,
    // Order matters: authenticate first, then check roles, then check the plan (memberships).
    { provide: APP_GUARD, useExisting: AuthGuard },
    { provide: APP_GUARD, useClass: RolesGuard },
    { provide: APP_GUARD, useClass: PlanGuard },
    // Quotas of the plan: taken before routing, given back if the routed call fails.
    { provide: APP_INTERCEPTOR, useClass: QuotaInterceptor },
  ],
})
export class AppModule {}
