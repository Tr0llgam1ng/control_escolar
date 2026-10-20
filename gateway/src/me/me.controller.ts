import { Controller, Get, NotFoundException } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';
import { StudentReportService } from './student-report.service';

/** Endpoints that resolve "the logged user" and compose data from several services. */
@Controller('me')
export class MeController {
  constructor(
    private readonly services: ServiceClient,
    private readonly reports: StudentReportService,
  ) {}

  /** Teacher: the courses assigned to me, with occupancy. */
  @Roles('teacher')
  @Get('courses')
  async myCourses(@CurrentUser() user: AuthUser) {
    const teacher = await this.services.find('catalog', `/teachers/by-user/${user.id}`, { user });
    if (!teacher) throw new NotFoundException('Tu cuenta no tiene expediente de profesor.');

    const { data: courses } = await this.services.get('catalog', '/courses', { query: { teacher_id: teacher.id }, user });
    const counts = courses.length
      ? (await this.services.get('enrollment', '/enrollments/counts', { query: { course_ids: courses.map((c: any) => c.id).join(',') }, user })).data
      : {};

    return { teacher, data: courses.map((c: any) => ({ ...c, enrolled: Number(counts[c.id] ?? 0) })) };
  }

  /** Student: "Consulta académica del alumno" (see StudentReportService). */
  @Roles('student')
  @Get('report')
  myReport(@CurrentUser() user: AuthUser) {
    return this.reports.report(user);
  }
}
