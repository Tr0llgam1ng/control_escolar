import { Body, Controller, Get, HttpCode, Param, Post, Query, Res } from '@nestjs/common';
import type { Response } from 'express';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';
import { UsesQuota } from '../memberships/plan.decorators';

/** Stamping goes through the PAC (TimbraCFDI): give it more time than a local service call. */
const PAC_TIMEOUT = 45000;

/**
 * Invoicing (CFDI 4.0) routes. Everything fiscal (catalog rules, VAT, building and stamping the CFDI)
 * lives in payments-service, which talks to the PAC. The gateway checks roles, routes, and adds the
 * student name for the admin list.
 */
@Controller()
export class InvoicingController {
  constructor(private readonly services: ServiceClient) {}

  /** SAT catalogs for the fiscal data form (regimes, CFDI uses, cancellation reasons). */
  @Roles('student', 'admin')
  @Get('payments/invoicing/catalogs')
  catalogs(@CurrentUser() user: AuthUser) {
    return this.services.get('payments', '/invoicing/catalogs', { user });
  }

  // ---------------------------------------------------------------- student

  /** Student: invoices of one of my paid references + my last fiscal data. */
  @Roles('student')
  @Get('me/charges/:id/invoice')
  myChargeInvoice(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.get('payments', `/charges/${encodeURIComponent(id)}/invoice`, { user });
  }

  /** Student: invoice a paid reference { rfc, name, zip, regime, cfdi_use }. Spends one invoice of the plan (Silver: 3, Gold: unlimited). */
  @Roles('student')
  @UsesQuota('invoicing.invoices')
  @Post('me/charges/:id/invoice')
  invoiceMine(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('payments', `/charges/${encodeURIComponent(id)}/invoice`, { body, user, timeoutMs: PAC_TIMEOUT });
  }

  @Roles('student')
  @Get('me/invoices/:id')
  myInvoice(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.get('payments', `/invoices/${encodeURIComponent(id)}`, { user });
  }

  @Roles('student')
  @Get('me/invoices/:id/xml')
  myInvoiceXml(@Param('id') id: string, @CurrentUser() user: AuthUser, @Res({ passthrough: true }) res: Response) {
    return this.xml(id, user, res);
  }

  // ---------------------------------------------------------------- admin

  /** Admin: invoices (filters: status, student_id, q) + student names from catalog. */
  @Roles('admin')
  @Get('payments/invoices')
  async list(@Query() query: Record<string, string>, @CurrentUser() user: AuthUser) {
    const result = await this.services.get('payments', '/invoices', { query, user });
    const ids = [...new Set<number>(result.data.map((i: any) => i.student_id))];
    const students = ids.length
      ? await this.services
          .get('catalog', '/students', { query: { ids: ids.join(',') }, user })
          .then((r) => new Map<number, any>(r.data.map((s: any) => [s.id, s])))
          .catch(() => new Map<number, any>())
      : new Map<number, any>();
    return {
      ...result,
      data: result.data.map((i: any) => ({
        ...i,
        student_name: students.get(i.student_id)?.name ?? null,
        student_enrollment_number: students.get(i.student_id)?.enrollment_number ?? null,
      })),
    };
  }

  @Roles('admin')
  @Get('payments/charges/:id/invoice')
  chargeInvoice(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.get('payments', `/charges/${encodeURIComponent(id)}/invoice`, { user });
  }

  /** Admin: invoice a paid reference on behalf of the student. */
  @Roles('admin')
  @Post('payments/charges/:id/invoice')
  invoiceCharge(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('payments', `/charges/${encodeURIComponent(id)}/invoice`, { body, user, timeoutMs: PAC_TIMEOUT });
  }

  @Roles('admin')
  @Get('payments/invoices/:id')
  show(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.get('payments', `/invoices/${encodeURIComponent(id)}`, { user });
  }

  @Roles('admin')
  @Get('payments/invoices/:id/xml')
  adminXml(@Param('id') id: string, @CurrentUser() user: AuthUser, @Res({ passthrough: true }) res: Response) {
    return this.xml(id, user, res);
  }

  /** Admin: { reason: 01|02|03|04, replacement_uuid? } → cancellation request to the SAT. */
  @Roles('admin')
  @Post('payments/invoices/:id/cancel')
  @HttpCode(200)
  cancel(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('payments', `/invoices/${encodeURIComponent(id)}/cancel`, { body, user, timeoutMs: PAC_TIMEOUT });
  }

  @Roles('admin')
  @Get('payments/invoices/:id/sat-status')
  satStatus(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.get('payments', `/invoices/${encodeURIComponent(id)}/sat-status`, { user, timeoutMs: PAC_TIMEOUT });
  }

  private async xml(id: string, user: AuthUser, res: Response) {
    const xml = await this.services.get<string>('payments', `/invoices/${encodeURIComponent(id)}/xml`, { user, raw: true });
    res.setHeader('Content-Type', 'application/xml; charset=utf-8');
    res.setHeader('Content-Disposition', `attachment; filename="factura-${encodeURIComponent(id)}.xml"`);
    return xml;
  }
}
