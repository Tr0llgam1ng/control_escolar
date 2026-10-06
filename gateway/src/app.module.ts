import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { APP_GUARD } from '@nestjs/core';
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
import { HealthController } from './health/health.controller';

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
  ],
  providers: [
    ServiceClient,
    RosterService,
    AuthGuard,
    // Order matters: authenticate first, then check roles.
    { provide: APP_GUARD, useExisting: AuthGuard },
    { provide: APP_GUARD, useClass: RolesGuard },
  ],
})
export class AppModule {}
