import { Injectable } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';

/** Joins enrollments (Enrollment Service) with student records (Catalog Service). */
@Injectable()
export class RosterService {
  constructor(private readonly services: ServiceClient) {}

  async withStudents(enrollments: any[], user: AuthUser) {
    if (!enrollments.length) return [];
    const ids = [...new Set(enrollments.map((e) => e.student_id))].join(',');
    const { data: students } = await this.services.get('catalog', '/students', { query: { ids }, user });
    const byId = new Map<number, any>(students.map((s: any) => [s.id, s]));

    return enrollments
      .map((e) => ({ ...e, student: byId.get(e.student_id) ?? null }))
      .sort((a, b) => (a.student?.name ?? '').localeCompare(b.student?.name ?? ''));
  }
}
