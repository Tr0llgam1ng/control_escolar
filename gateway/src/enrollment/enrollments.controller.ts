import { Body, Controller, Delete, Get, HttpCode, Param, Post, Put } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';
import { RosterService } from './roster.service';

/** Service 3 (business logic): enrollment ("reservation") and grades. */
@Controller()
export class EnrollmentsController {
  constructor(
    private readonly services: ServiceClient,
    private readonly roster: RosterService,
  ) {}

  /** Class list + grades, with student data from the catalog. */
  @Roles('admin', 'teacher')
  @Get('courses/:id/roster')
  async getRoster(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    const roster = await this.services.get('enrollment', `/courses/${id}/roster`, { user });
    return { ...roster, data: await this.roster.withStudents(roster.data, user) };
  }

  /** Enroll one or many students: { student_ids: number[] } */
  @Roles('admin')
  @Post('courses/:id/enrollments')
  async enroll(@Param('id') id: string, @Body() body: { student_ids: number[] }, @CurrentUser() user: AuthUser) {
    const result = await this.services.post('enrollment', '/enrollments/bulk', {
      body: { course_id: Number(id), student_ids: body.student_ids ?? [] },
      user,
    });
    const names = await this.services
      .get('catalog', '/students', { query: { ids: (body.student_ids ?? []).join(',') }, user })
      .then((r) => new Map<number, string>(r.data.map((s: any) => [s.id, s.name])))
      .catch(() => new Map<number, string>());

    return {
      ...result,
      results: result.results.map((r: any) => ({ ...r, name: names.get(r.student_id) ?? `#${r.student_id}` })),
    };
  }

  @Roles('admin')
  @Delete('enrollments/:id')
  @HttpCode(204)
  unenroll(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.delete('enrollment', `/enrollments/${id}`, { user });
  }

  /** { grades: [{ enrollment_id, unit, score | null }] } */
  @Roles('admin', 'teacher')
  @Put('courses/:id/grades')
  async saveGrades(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    const result = await this.services.put('enrollment', `/courses/${id}/grades`, { body, user });
    return { ...result, data: await this.roster.withStudents(result.data, user) };
  }
}
