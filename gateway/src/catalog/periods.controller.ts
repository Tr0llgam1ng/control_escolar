import { Body, Controller, Delete, Get, HttpCode, Param, Post, Put } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';

/** Pure proxy to the Catalog Service (Service 2: CRUD). */
@Controller('periods')
export class PeriodsController {
  constructor(private readonly services: ServiceClient) {}

  @Get()
  list(@CurrentUser() user: AuthUser) {
    return this.services.get('catalog', '/periods', { user });
  }

  @Get('current')
  current(@CurrentUser() user: AuthUser) {
    return this.services.find('catalog', '/periods/current', { user });
  }

  @Roles('admin')
  @Post()
  create(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('catalog', '/periods', { body, user });
  }

  @Roles('admin')
  @Put(':id')
  update(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.put('catalog', `/periods/${id}`, { body, user });
  }

  @Roles('admin')
  @Delete(':id')
  @HttpCode(204)
  remove(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.delete('catalog', `/periods/${id}`, { user });
  }
}
