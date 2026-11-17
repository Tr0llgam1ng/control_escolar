import {
  Body, ConflictException, Controller, Delete, Get, HttpCode, HttpException, Param, ParseIntPipe, Post, Query,
  ServiceUnavailableException,
} from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser } from '../auth/decorators';
import { UsesQuota } from '../memberships/plan.decorators';
import { CafeteriaAccountService } from './cafeteria-account.service';

/**
 * Cafeteria = the external Sales System (FastAPI, classmate's computer) consumed through the gateway.
 * Catalog → menu · Cart · Orders · Shipping → delivery tracking.
 * The gateway only routes, translates field names and composes; prices, stock, totals,
 * order states and tracking numbers are computed by the Sales System.
 * Any logged user (student, teacher, admin) can order.
 */
@Controller()
export class CafeteriaController {
  constructor(
    private readonly services: ServiceClient,
    private readonly accounts: CafeteriaAccountService,
  ) {}

  // ------------------------------------------------------------ catalog (menu)

  @Get('cafeteria/menu')
  async menu(@Query('category') category?: string) {
    const data = await this.services.get<any[]>('cafeteria', '/v1/products', { query: { category } });
    return { data };
  }

  // ------------------------------------------------------------ account bridge

  @Get('me/cafeteria/account')
  account(@CurrentUser() user: AuthUser) {
    return this.accounts.status(user);
  }

  @Post('me/cafeteria/account')
  @HttpCode(200)
  register(@CurrentUser() user: AuthUser) {
    return this.accounts.register(user);
  }

  /** { code } — the activation code the Sales System sent. */
  @Post('me/cafeteria/account/activate')
  @HttpCode(200)
  activate(@CurrentUser() user: AuthUser, @Body() body: { code?: string }) {
    return this.accounts.activate(user, body?.code ?? '');
  }

  // ------------------------------------------------------------ cart

  @Get('me/cafeteria/cart')
  cart(@CurrentUser() user: AuthUser) {
    return this.accounts.call(user, 'GET', '/v1/cart');
  }

  /** { product_id, quantity } */
  @Post('me/cafeteria/cart/items')
  @HttpCode(200)
  addItem(@CurrentUser() user: AuthUser, @Body() body: { product_id?: number; quantity?: number }) {
    return this.accounts.call(user, 'POST', '/v1/cart/items', {
      body: { product_id: body?.product_id, quantity: body?.quantity ?? 1 },
    });
  }

  @Delete('me/cafeteria/cart/items/:productId')
  removeItem(@CurrentUser() user: AuthUser, @Param('productId', ParseIntPipe) productId: number) {
    return this.accounts.call(user, 'DELETE', `/v1/cart/items/${productId}`);
  }

  // ------------------------------------------------------------ orders

  @Get('me/cafeteria/orders')
  async orders(@CurrentUser() user: AuthUser) {
    const data = await this.accounts.call<any[]>(user, 'GET', '/v1/orders');
    return { data, online_payment: this.accounts.onlinePaymentEnabled };
  }

  /**
   * { deliver_to, payment_method } → the cart becomes an order (the Sales System empties the cart).
   * Students spend one "cafeteria.orders" of their plan; given back if the Sales System fails.
   */
  @UsesQuota('cafeteria.orders')
  @Post('me/cafeteria/orders')
  createOrder(@CurrentUser() user: AuthUser, @Body() body: { deliver_to?: string; payment_method?: string }) {
    return this.accounts.call(user, 'POST', '/v1/orders', {
      body: { direccion_envio: body?.deliver_to, metodo_pago: body?.payment_method },
    });
  }

  /** Order + delivery tracking (composition of Orders and Shipping). */
  @Get('me/cafeteria/orders/:id')
  async order(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    // Asking for the order with the user's token proves it is theirs (404 otherwise).
    const order = await this.accounts.call(user, 'GET', `/v1/orders/${id}`);
    const shipping = await this.services.find('cafeteria', `/v1/shipping/${id}`);
    return { ...order, shipping };
  }

  @Get('me/cafeteria/orders/:id/shipping')
  async shipping(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    await this.accounts.call(user, 'GET', `/v1/orders/${id}`);
    return this.services.get('cafeteria', `/v1/shipping/${id}`);
  }

  /**
   * Online payment (simulated): the gateway notifies the Sales System through its signed
   * payment webhook. The Sales System then marks the order as paid, creates the delivery
   * and its tracking number, and sends the notification.
   */
  @Post('me/cafeteria/orders/:id/pay')
  @HttpCode(200)
  async pay(@CurrentUser() user: AuthUser, @Param('id', ParseIntPipe) id: number) {
    if (!this.accounts.onlinePaymentEnabled) {
      throw new ServiceUnavailableException({
        message: 'El pago en línea de la cafetería no está configurado. Paga en la caja de la cafetería.',
        service: 'cafeteria',
      });
    }
    const order = await this.accounts.call(user, 'GET', `/v1/orders/${id}`);
    if (order.estado !== 'PENDIENTE_PAGO') {
      throw new ConflictException({ message: 'Este pedido ya no está pendiente de pago.', service: 'cafeteria' });
    }

    try {
      const result = await this.services.post('cafeteria', '/v1/payments/webhook', {
        headers: this.accounts.webhookHeaders(),
        body: {
          id_evento: `ce-${user.id}-${id}-${Date.now()}`,
          tipo: 'charge.succeeded',
          data: { order_id: id, monto: order.total, status: 'paid' },
        },
      });
      return { ...result, order: await this.order(user, id) };
    } catch (e) {
      if (e instanceof HttpException && e.getStatus() === 401) {
        throw new ServiceUnavailableException({
          message: 'La cafetería rechazó la firma del pago. Revisa CAFETERIA_WEBHOOK_SECRET en gateway/.env.',
          service: 'cafeteria',
        });
      }
      throw e;
    }
  }
}
