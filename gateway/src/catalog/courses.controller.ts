import { Body, Controller, Delete, Get, HttpCode, Param, Patch, Post, Put, Query } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';

/**
 * Courses come from the Catalog Service; occupancy (enrolled / capacity) comes from
 * the Enrollment Service. The gateway composes both — like showing rooms with availability.
 */
@Controller('courses')
export class CoursesController {
  constructor(private readonly services: ServiceClient) {}

  @Get()
  async list(@Query() query: Record<string, string>, @CurrentUser() user: AuthUser) {
    const { data: courses } = await this.services.get('catalog', '/courses', { query, user });
    return { data: await this.withOccupancy(courses, user) };
  }

  @Get(':id')
  async show(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    const course = await this.services.get('catalog', `/courses/${id}`, { user });
    const [withSeats] = await this.withOccupancy([course], user);
    return withSeats;
  }

  @Roles('admin')
  @Post()
  create(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('catalog', '/courses', { body, user });
  }

  @Roles('admin')
  @Put(':id')
  update(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.put('catalog', `/courses/${id}`, { body, user });
  }

  /** Assigned teacher: change class status (Activa / No activa) and number of units. */
  @Roles('admin', 'teacher')
  @Patch(':id/settings')
  settings(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.patch('catalog', `/courses/${id}/settings`, { body, user });
  }

  @Roles('admin')
  @Delete(':id')
  @HttpCode(204)
  remove(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.delete('catalog', `/courses/${id}`, { user });
  }

  private async withOccupancy(courses: any[], user: AuthUser) {
    if (!courses.length) return courses;
    let counts: Record<string, number> = {};
    try {
      const res = await this.services.get('enrollment', '/enrollments/counts', {
        query: { course_ids: courses.map((c) => c.id).join(',') },
        user,
      });
      counts = res.data ?? {};
    } catch {
      // RNF-12: if the Enrollment Service is down the catalog is still shown, without occupancy.
      return courses.map((c) => ({ ...c, enrolled: null }));
    }
    return courses.map((c) => ({ ...c, enrolled: Number(counts[c.id] ?? 0) }));
  }
}
