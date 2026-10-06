import { Body, Controller, Delete, Get, HttpCode, Param, Post, Put, Query } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';

/** Pure proxy to the Catalog Service (Service 2: CRUD). */
@Controller('subjects')
export class SubjectsController {
  constructor(private readonly services: ServiceClient) {}

  @Get()
  list(@Query('search') search: string, @CurrentUser() user: AuthUser) {
    return this.services.get('catalog', '/subjects', { query: { search }, user });
  }

  @Roles('admin')
  @Post()
  create(@Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.post('catalog', '/subjects', { body, user });
  }

  @Roles('admin')
  @Put(':id')
  update(@Param('id') id: string, @Body() body: unknown, @CurrentUser() user: AuthUser) {
    return this.services.put('catalog', `/subjects/${id}`, { body, user });
  }

  @Roles('admin')
  @Delete(':id')
  @HttpCode(204)
  remove(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.services.delete('catalog', `/subjects/${id}`, { user });
  }
}
