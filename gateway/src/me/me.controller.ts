import { Controller, Get, NotFoundException } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';

/** Endpoints that resolve "the logged user" and compose data from several services. */
@Controller('me')
export class MeController {
  constructor(private readonly services: ServiceClient) {}

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

  /**
   * Student: "Consulta académica del alumno" (business process from the SOA document).
   * 1) Catalog → my student record   2) Enrollment → my enrollments + grades
   * 3) Catalog → the courses of those enrollments   4) the gateway merges everything.
   */
  @Roles('student')
  @Get('report')
  async myReport(@CurrentUser() user: AuthUser) {
    const student = await this.services.find('catalog', `/students/by-user/${user.id}`, { user });
    if (!student) throw new NotFoundException('Tu cuenta no tiene expediente de alumno.');

    const [{ data: enrollments }, current] = await Promise.all([
      this.services.get('enrollment', `/students/${student.id}/enrollments`, { user }),
      this.services.find('catalog', '/periods/current', { user }),
    ]);

    const ids = enrollments.map((e: any) => e.course_id).join(',');
    const courses = ids ? (await this.services.get('catalog', '/courses', { query: { ids }, user })).data : [];
    const byId = new Map<number, any>(courses.map((c: any) => [c.id, c]));

    const items = enrollments
      .map((e: any) => ({ ...e, course: byId.get(e.course_id) ?? null }))
      .filter((e: any) => e.course);

    const closed = items.filter((e: any) => e.status !== 'in_progress');
    const average = closed.length ? closed.reduce((s: number, e: any) => s + Number(e.final_grade), 0) / closed.length : null;

    return {
      student,
      current_period: current,
      summary: {
        in_progress: items.filter((e: any) => e.status === 'in_progress' && e.course.status === 'active').length,
        approved: items.filter((e: any) => e.status === 'approved').length,
        failed: items.filter((e: any) => e.status === 'failed').length,
        average: average === null ? null : Math.round(average * 100) / 100,
      },
      // RN-05: classes marked "No activa" are not shown as current
      data: items.filter((e: any) => e.course.status === 'active' || e.status !== 'in_progress'),
    };
  }
}
