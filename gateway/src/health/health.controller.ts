import { Controller, Get } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser, ServiceName } from '../common/types';
import { CurrentUser, Public, Roles } from '../auth/decorators';

@Controller()
export class HealthController {
  constructor(private readonly services: ServiceClient) {}

  /** Service registry / monitoring: is each Laravel service up? (public) */
  @Public()
  @Get('health')
  async health() {
    const names: ServiceName[] = ['auth', 'catalog', 'enrollment', 'payments'];
    const checks = await Promise.all(
      names.map(async (name) => {
        const started = Date.now();
        try {
          const res = await fetch(`${this.services.baseUrl(name)}/up`, { signal: AbortSignal.timeout(3000) });
          return { name, url: this.services.baseUrl(name), up: res.ok, ms: Date.now() - started };
        } catch {
          return { name, url: this.services.baseUrl(name), up: false, ms: Date.now() - started };
        }
      }),
    );
    return { gateway: 'up', services: checks };
  }

  /** Admin dashboard: counts gathered in parallel from the catalog and enrollment services. */
  @Roles('admin')
  @Get('dashboard')
  async dashboard(@CurrentUser() user: AuthUser) {
    const [students, teachers, subjects, current] = await Promise.all([
      this.services.get('catalog', '/students', { user }),
      this.services.get('catalog', '/teachers', { user }),
      this.services.get('catalog', '/subjects', { user }),
      this.services.find('catalog', '/periods/current', { user }),
    ]);

    const courses = current ? (await this.services.get('catalog', '/courses', { query: { period_id: current.id }, user })).data : [];
    let enrolled = 0;
    if (courses.length) {
      const counts = await this.services
        .get('enrollment', '/enrollments/counts', { query: { course_ids: courses.map((c: any) => c.id).join(',') }, user })
        .then((r) => r.data)
        .catch(() => ({}));
      enrolled = Object.values(counts as Record<string, number>).reduce((a, b) => a + Number(b), 0);
    }

    return {
      current_period: current,
      counts: {
        students: students.data.filter((s: any) => s.status === 'active').length,
        teachers: teachers.data.length,
        subjects: subjects.data.length,
        courses: courses.length,
        enrolled,
      },
      courses_without_teacher: courses.filter((c: any) => !c.teacher_id).length,
    };
  }
}
