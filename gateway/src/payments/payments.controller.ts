import { Body, ConflictException, Controller, Get, HttpCode, Param, Patch, Post, Query } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';
import { StudentReportService } from '../me/student-report.service';

/**
 * Payments Service routes. The gateway validates the token, checks the role and routes;
 * every payment rule (prices, references, card validation, status changes) lives in
 * payments-service. The gateway only composes: student names (catalog) and paid documents
 * (payments + catalog + enrollment).
 */
@Controller()
export class PaymentsController {
  constructor(
    private readonly services: ServiceClient,
    private readonly reports: StudentReportService,
  ) {}

  /** Paperwork a student can request, with its price. */
  @Roles('student', 'admin')
  @Get('payments/document-types')
  documentTypes(@CurrentUser() user: AuthUser) {
    return this.services.get('payments', '/document-types', { user });
  }

  // ---------------------------------------------------------------- student

  /** Student: my references and payments. */
  @Roles('student')
  @Get('me/charges')
  async myCharges(@CurrentUser() user: AuthUser) {
    const student = await this.reports.studentOf(user);
    return this.services.get('payments', `/students/${student.id}/charges`, { user });
  }

  /** Student: generate a payment reference for a document { document_type_id }. */
  @Roles('student')
  @Post('me/charges')
  createMine(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('payments', '/charges', { body, user });
  }

  @Roles('student')
  @Get('me/charges/:id')
  showMine(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.get('payments', `/charges/${encodeURIComponent(id)}`, { user });
  }

  /** Student: pay { method: card|cash|transfer, card? } → { result: approved|rejected|pending, message } */
  @Roles('student')
  @Post('me/charges/:id/pay')
  @HttpCode(200)
  pay(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('payments', `/charges/${encodeURIComponent(id)}/pay`, { body, user });
  }

  /**
   * Student: data of a paid document. Composition of 3 services:
   * payments (the reference must be approved) + catalog (student, period) + enrollment (grades).
   */
  @Roles('student')
  @Get('me/documents/:id')
  async document(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    const charge = await this.services.get('payments', `/charges/${encodeURIComponent(id)}`, { user });
    if (charge.status !== 'approved') {
      throw new ConflictException('El documento estará disponible cuando tu pago sea aprobado.');
    }
    const report = await this.reports.report(user);
    return { charge, ...report };
  }

  // ---------------------------------------------------------------- admin

  /** Admin: references (filters: status, method, new, expired, student_id, q) + student names. */
  @Roles('admin')
  @Get('payments/charges')
  async list(@Query() query: Record<string, string>, @CurrentUser() user: AuthUser) {
    const result = await this.services.get('payments', '/charges', { query, user });
    return { ...result, data: await this.withStudents(result.data, user) };
  }

  @Roles('admin')
  @Get('payments/charges/summary')
  summary(@CurrentUser() user: AuthUser) {
    return this.services.get('payments', '/charges/summary', { user });
  }

  /** Admin: generate a reference for a student { student_id, document_type_id }. */
  @Roles('admin')
  @Post('payments/charges')
  create(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('payments', '/charges', { body, user });
  }

  @Roles('admin')
  @Get('payments/charges/:id')
  async show(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    const charge = await this.services.get('payments', `/charges/${encodeURIComponent(id)}`, { user });
    return (await this.withStudents([charge], user))[0];
  }

  /** Admin: { status: approved|rejected|pending, reason? } — the rules are enforced by payments-service. */
  @Roles('admin')
  @Patch('payments/charges/:id/status')
  async changeStatus(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    const charge = await this.services.patch('payments', `/charges/${encodeURIComponent(id)}/status`, { body, user });
    return (await this.withStudents([charge], user))[0];
  }

  /** Admin: mark the reference as checked. */
  @Roles('admin')
  @Post('payments/charges/:id/review')
  @HttpCode(200)
  async review(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    const charge = await this.services.post('payments', `/charges/${encodeURIComponent(id)}/review`, { user });
    return (await this.withStudents([charge], user))[0];
  }

  /** Admin: cash received at the window { amount_received }. */
  @Roles('admin')
  @Post('payments/charges/:id/cash')
  @HttpCode(200)
  async cash(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    const result = await this.services.post('payments', `/charges/${encodeURIComponent(id)}/cash`, { body, user });
    return { ...result, charge: (await this.withStudents([result.charge], user))[0] };
  }

  /** Adds name and enrollment number from the catalog; if catalog is down the list still works. */
  private async withStudents(charges: any[], user: AuthUser) {
    const ids = [...new Set(charges.map((c) => c.student_id))];
    if (!ids.length) return charges;
    const students = await this.services
      .get('catalog', '/students', { query: { ids: ids.join(',') }, user })
      .then((r) => new Map<number, any>(r.data.map((s: any) => [s.id, s])))
      .catch(() => new Map<number, any>());
    return charges.map((c) => {
      const s = students.get(c.student_id);
      return { ...c, student_name: s?.name ?? null, student_enrollment_number: s?.enrollment_number ?? null };
    });
  }
}
