import { BadRequestException, Body, Controller, Get, HttpCode, Param, Post, Put, Query } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';
import { StudentReportService } from '../me/student-report.service';

/**
 * Memberships Service routes: plans (Basic, Bronze, Silver, Gold), subscriptions and credits.
 * Every rule (prices, limits, cycles, ledger) lives in memberships-service. The gateway routes,
 * orchestrates the purchase of credits (payments-service + memberships-service) and composes names.
 */
@Controller()
export class MembershipsController {
  constructor(
    private readonly services: ServiceClient,
    private readonly reports: StudentReportService,
  ) {}

  // ---------------------------------------------------------------- plans

  /** Comparison table of plans (every role can see it). */
  @Get('memberships/plans')
  plans(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/plans', { user });
  }

  /** Student: my plan, cycle, usage of each feature and credit balance. */
  @Roles('student')
  @Get('me/membership')
  mine(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/me/membership', { user });
  }

  /** Student: buy / change plan with credits { plan_code }. */
  @Roles('student')
  @Post('me/subscription')
  subscribe(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', '/me/subscription', { body, user });
  }

  @Roles('student')
  @Post('me/subscription/cancel')
  @HttpCode(200)
  cancel(@CurrentUser() user: AuthUser) {
    return this.services.post('memberships', '/me/subscription/cancel', { user });
  }

  @Roles('student')
  @Post('me/subscription/resume')
  @HttpCode(200)
  resume(@CurrentUser() user: AuthUser) {
    return this.services.post('memberships', '/me/subscription/resume', { user });
  }

  // ---------------------------------------------------------------- credits (money → credits)

  /**
   * Credit packs = composition: credits and plan bonus (memberships-service) + the payment
   * concept and its price (payments-service, category "credits").
   */
  @Roles('student', 'admin')
  @Get('memberships/credit-packs')
  async packs(@CurrentUser() user: AuthUser) {
    const [packs, types] = await Promise.all([
      this.services.get('memberships', '/credit-packs', { user }),
      this.services.get('payments', '/document-types', { query: { category: 'credits' }, user }),
    ]);
    const byCode = new Map<string, any>(types.data.map((t: any) => [t.code, t]));
    return {
      ...packs,
      data: packs.data
        .filter((p: any) => byCode.has(p.code))
        .map((p: any) => ({ ...p, document_type_id: byCode.get(p.code).id, price: byCode.get(p.code).price, description: byCode.get(p.code).description })),
    };
  }

  /** Student: balance, credits ledger and purchases (pending purchases are checked against payments first). */
  @Roles('student')
  @Get('me/wallet')
  wallet(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/me/wallet', { user });
  }

  /**
   * Student buys a pack { pack_code }. Orchestration of 2 services:
   *  1) payments-service generates the payment reference of the pack (card or cash, like any paperwork)
   *  2) memberships-service registers it; when the reference is approved it exchanges it for credits.
   * Repeating the call is safe: payments reuses the live reference and memberships the purchase.
   */
  @Roles('student')
  @Post('me/credit-purchases')
  async buy(@Body() body: { pack_code?: string }, @CurrentUser() user: AuthUser) {
    if (!body?.pack_code) throw new BadRequestException('Elige un paquete de créditos.');
    const [student, types] = await Promise.all([
      this.reports.studentOf(user),
      this.services.get('payments', '/document-types', { query: { category: 'credits' }, user }),
    ]);
    const type = types.data.find((t: any) => t.code === body.pack_code);
    if (!type) throw new BadRequestException('El paquete de créditos no está disponible.');

    const charge = await this.services.post('payments', '/charges', { body: { document_type_id: type.id, category: 'credits' }, user });
    const purchase = await this.services.post('memberships', '/credit-purchases', {
      body: { charge_id: charge.id, student_id: student.id, pack_code: body.pack_code },
      user,
    });
    return { charge, purchase };
  }

  /** Student: check again whether the reference of a purchase was paid. */
  @Roles('student', 'admin')
  @Post('me/credit-purchases/:id/sync')
  @HttpCode(200)
  sync(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', `/credit-purchases/${encodeURIComponent(id)}/sync`, { user });
  }

  // ---------------------------------------------------------------- admin

  @Roles('admin')
  @Get('memberships/summary')
  summary(@CurrentUser() user: AuthUser) {
    return this.services.get('memberships', '/summary', { user });
  }

  /** Admin: edit a plan (price, days, limits of each feature). */
  @Roles('admin')
  @Put('memberships/plans/:id')
  updatePlan(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.put('memberships', `/plans/${encodeURIComponent(id)}`, { body, user });
  }

  @Roles('admin')
  @Get('memberships/subscriptions')
  async subscriptions(@Query() query: Record<string, string>, @CurrentUser() user: AuthUser) {
    const result = await this.services.get('memberships', '/subscriptions', { query, user });
    return { ...result, data: await this.withUsers(result.data, user) };
  }

  @Roles('admin')
  @Get('memberships/wallets')
  async wallets(@CurrentUser() user: AuthUser) {
    const result = await this.services.get('memberships', '/wallets', { user });
    return { ...result, data: await this.withUsers(result.data, user) };
  }

  /** Admin: { amount (±), reason } — written to the ledger with the admin as actor. */
  @Roles('admin')
  @Post('memberships/wallets/:userId/adjust')
  adjust(@Param('userId') userId: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', `/wallets/${encodeURIComponent(userId)}/adjust`, { body, user });
  }

  @Roles('admin')
  @Get('memberships/transactions')
  async transactions(@Query() query: Record<string, string>, @CurrentUser() user: AuthUser) {
    const result = await this.services.get('memberships', '/credit-transactions', { query, user });
    return { ...result, data: await this.withUsers(result.data, user) };
  }

  @Roles('admin')
  @Get('memberships/purchases')
  async purchases(@Query() query: Record<string, string>, @CurrentUser() user: AuthUser) {
    const result = await this.services.get('memberships', '/credit-purchases', { query, user });
    return { ...result, data: await this.withUsers(result.data, user) };
  }

  /** Admin: purchase in review { action: credit|reject, reason }. */
  @Roles('admin')
  @Post('memberships/purchases/:id/resolve')
  @HttpCode(200)
  resolve(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('memberships', `/credit-purchases/${encodeURIComponent(id)}/resolve`, { body, user });
  }

  @Roles('admin')
  @Get('memberships/tutoring-sessions')
  async tutoring(@CurrentUser() user: AuthUser) {
    const result = await this.services.get('memberships', '/tutoring-sessions', { user });
    return { ...result, data: await this.withUsers(result.data, user) };
  }

  @Roles('admin')
  @Get('memberships/room-bookings')
  async bookings(@Query('date') date: string, @CurrentUser() user: AuthUser) {
    const result = await this.services.get('memberships', '/room-bookings', { query: { date }, user });
    return { ...result, data: await this.withUsers(result.data, user) };
  }

  /** Adds the account name / e-mail (auth-service); if auth is down the list still works. */
  private async withUsers(rows: any[], user: AuthUser) {
    if (!rows?.length) return rows ?? [];
    const users = await this.services
      .get('auth', '/users', { query: { role: 'student' }, user })
      .then((r) => new Map<number, any>((r.data ?? r).map((u: any) => [u.id, u])))
      .catch(() => new Map<number, any>());
    return rows.map((row) => {
      const u = users.get(row.user_id);
      return { ...row, user_name: u?.name ?? null, user_email: u?.email ?? null };
    });
  }
}
