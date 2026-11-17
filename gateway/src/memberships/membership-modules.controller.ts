import { BadRequestException, Body, Controller, Get, HttpCode, NotFoundException, Param, Patch, Post, Query } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';
import { StudentReportService } from '../me/student-report.service';
import { RequiresFeature } from './plan.decorators';

/**
 * The 4 membership modules:
 *  credits → tutoring with a teacher · academic analysis
 *  time    → study rooms (hours) · digital library (minutes)
 * Prices, plan limits and balances live in memberships-service; the gateway adds data from
 * catalog (teachers, student name) and enrollment (grades for the analysis).
 */
@Controller()
export class MembershipModulesController {
  constructor(
    private readonly services: ServiceClient,
    private readonly reports: StudentReportService,
  ) {}

  // ---------------------------------------------------------------- time balances

  @Roles('student')
  @Get('me/time')
  time(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/me/time', { user });
  }

  /** { module: study_rooms|library, quantity } — extra time paid with credits. */
  @Roles('student')
  @Post('me/time/purchase')
  buyTime(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', '/me/time/purchase', { body, user });
  }

  // ---------------------------------------------------------------- time module 1: study rooms (hours)

  @Roles('student', 'admin')
  @Get('memberships/study-rooms')
  rooms(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/study-rooms', { user });
  }

  @Roles('student', 'admin')
  @Get('memberships/study-rooms/:id/bookings')
  busy(@Param('id') id: string, @Query('date') date: string, @CurrentUser() user: AuthUser) {
    return this.services.get('memberships', `/study-rooms/${encodeURIComponent(id)}/bookings`, { query: { date }, user });
  }

  @Roles('student')
  @Get('me/room-bookings')
  myBookings(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/room-bookings', { user });
  }

  /** { study_room_id, starts_at, minutes } — only plans that include study rooms (PlanGuard). */
  @Roles('student')
  @RequiresFeature('study_rooms.max_hours')
  @Post('me/room-bookings')
  book(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', '/room-bookings', { body, user });
  }

  @Roles('student', 'admin')
  @Post('me/room-bookings/:id/cancel')
  @HttpCode(200)
  cancelBooking(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', `/room-bookings/${encodeURIComponent(id)}/cancel`, { user });
  }

  // ---------------------------------------------------------------- time module 2: digital library (minutes)

  @Roles('student', 'admin')
  @Get('memberships/library/resources')
  resources(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/library/resources', { user });
  }

  @Roles('student')
  @Get('me/library-sessions')
  sessions(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/library-sessions', { user });
  }

  /** { resource } — opens a session; the minutes are charged when it closes. */
  @Roles('student')
  @Post('me/library-sessions')
  openSession(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', '/library-sessions', { body, user });
  }

  @Roles('student')
  @Post('me/library-sessions/:id/close')
  @HttpCode(200)
  closeSession(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', `/library-sessions/${encodeURIComponent(id)}/close`, { user });
  }

  // ---------------------------------------------------------------- credits module 1: tutoring

  @Roles('student')
  @Get('me/tutoring/quote')
  quote(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/tutoring/quote', { user });
  }

  /** Teachers to choose from: the ones of my current classes first (catalog + enrollment), then all. */
  @Roles('student')
  @Get('me/tutoring/options')
  async options(@CurrentUser() user: AuthUser) {
    const [report, teachers] = await Promise.all([this.reports.report(user), this.services.get('catalog', '/teachers', { user })]);
    const fromCourses = report.data
      .filter((e: any) => e.status === 'in_progress' && e.course.teacher)
      .map((e: any) => ({ teacher_id: e.course.teacher.id, teacher_name: e.course.teacher.name, subject: e.course.subject?.name }));
    return { from_courses: fromCourses, teachers: teachers.data.map((t: any) => ({ id: t.id, name: t.name, department: t.department ?? null })) };
  }

  @Roles('student')
  @Get('me/tutoring-sessions')
  myTutoring(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/tutoring-sessions', { user });
  }

  /** { teacher_id, subject, topic, scheduled_at } — the gateway adds the names from the catalog. */
  @Roles('student')
  @RequiresFeature('tutoring.sessions')
  @Post('me/tutoring-sessions')
  async requestTutoring(@Body() body: any, @CurrentUser() user: AuthUser) {
    if (!body?.teacher_id) throw new BadRequestException({ message: 'Elige al profesor.', errors: { teacher_id: ['Elige al profesor.'] } });
    const [student, teacher] = await Promise.all([
      this.reports.studentOf(user),
      this.services.find('catalog', `/teachers/${encodeURIComponent(body.teacher_id)}`, { user }),
    ]);
    if (!teacher) throw new NotFoundException('El profesor no existe.');
    return this.services.post('memberships', '/tutoring-sessions', {
      body: { ...body, teacher_id: teacher.id, teacher_name: teacher.name, student_name: student.name },
      user,
    });
  }

  @Roles('student')
  @Post('me/tutoring-sessions/:id/cancel')
  @HttpCode(200)
  cancelTutoring(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', `/tutoring-sessions/${encodeURIComponent(id)}/cancel`, { user });
  }

  /** Teacher: tutoring requested to me (memberships-service resolves my teacher record in catalog). */
  @Roles('teacher')
  @Get('me/tutoring-requests')
  requests(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/tutoring-sessions', { user });
  }

  /** Teacher: { status: confirmed|rejected|completed, notes? } */
  @Roles('teacher')
  @Patch('me/tutoring-requests/:id')
  decide(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.patch('memberships', `/tutoring-sessions/${encodeURIComponent(id)}`, { body, user });
  }

  // ---------------------------------------------------------------- credits module 2: academic analysis

  @Roles('student')
  @Get('me/analytics/quote')
  analyticsQuote(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/analytics/quote', { user });
  }

  @Roles('student')
  @Get('me/analytics-reports')
  analyticsList(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/analytics-reports', { user });
  }

  /** Composition: my report (catalog + enrollment) is sent to memberships-service, which analyses and charges it. */
  @Roles('student')
  @RequiresFeature('analytics.reports')
  @Post('me/analytics-reports')
  async analyse(@CurrentUser() user: AuthUser) {
    const report = await this.reports.report(user);
    const items = report.data.map((e: any) => ({
      subject: e.course.subject?.name ?? `Clase ${e.course_id}`,
      code: e.course.subject?.code ?? null,
      period: e.course.period?.name ?? null,
      units_count: Number(e.course.units_count ?? 0),
      status: e.status,
      final_grade: e.final_grade === null || e.final_grade === undefined ? null : Number(e.final_grade),
      grades: (e.grades ?? []).filter((g: any) => g.score !== null && g.score !== undefined).map((g: any) => ({ unit: Number(g.unit), score: Number(g.score) })),
    }));
    return this.services.post('memberships', '/analytics-reports', { body: { items }, user });
  }

  @Roles('student')
  @Get('me/analytics-reports/:id')
  analytics(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.get('memberships', `/analytics-reports/${encodeURIComponent(id)}`, { user });
  }
}
