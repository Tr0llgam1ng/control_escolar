import { Body, Controller, Get, HttpCode, Post, Put, Req } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { AuthGuard } from './auth.guard';
import { CurrentUser, Public } from './decorators';

@Controller('auth')
export class AuthController {
  constructor(
    private readonly services: ServiceClient,
    private readonly guard: AuthGuard,
  ) {}

  /** POST /api/auth/login → Auth Service; then attaches the academic profile (composition). */
  @Public()
  @Post('login')
  @HttpCode(200)
  async login(@Body() body: { email: string; password: string }) {
    const session = await this.services.post('auth', '/auth/login', { body });
    const profile = await this.profileOf(session.user);
    return { ...session, profile };
  }

  /** GET /api/auth/me → user (Auth Service) + profile (Catalog Service). */
  @Get('me')
  async me(@CurrentUser() user: AuthUser) {
    return { user, profile: await this.profileOf(user) };
  }

  @Post('logout')
  @HttpCode(200)
  async logout(@Req() req: any) {
    this.guard.forget(req.token);
    return this.services.post('auth', '/auth/logout', { body: { token: req.token } });
  }

  /** Any user can change their own password. */
  @Put('password')
  async changePassword(@CurrentUser() user: AuthUser, @Body() body: { password: string }) {
    await this.services.put('auth', `/users/${user.id}`, { body: { password: body.password }, user });
    return { message: 'Contraseña actualizada. Vuelve a iniciar sesión.' };
  }

  private async profileOf(user: AuthUser) {
    if (user.role === 'teacher') return this.services.find('catalog', `/teachers/by-user/${user.id}`, { user });
    if (user.role === 'student') return this.services.find('catalog', `/students/by-user/${user.id}`, { user });
    return null;
  }
}
