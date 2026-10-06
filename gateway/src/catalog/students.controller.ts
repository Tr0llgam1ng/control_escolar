import { Body, Controller, Delete, Get, HttpCode, Param, Post, Put, Query } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { AuthUser } from '../common/types';
import { CurrentUser, Roles } from '../auth/decorators';
import { PeopleOrchestrator } from './people';

@Controller('students')
export class StudentsController {
  private readonly people: PeopleOrchestrator;

  constructor(private readonly services: ServiceClient) {
    this.people = new PeopleOrchestrator(services, 'students', 'student');
  }

  @Roles('admin', 'teacher')
  @Get()
  list(@Query() query: Record<string, string>, @CurrentUser() user: AuthUser) {
    return this.services.get('catalog', '/students', { query, user });
  }

  @Roles('admin')
  @Post()
  create(@Body() body: any, @CurrentUser() user: AuthUser) {
    return this.people.create(body, user);
  }

  @Roles('admin')
  @Put(':id')
  update(@Param('id') id: string, @Body() body: any, @CurrentUser() user: AuthUser) {
    return this.people.update(id, body, user);
  }

  @Roles('admin')
  @Delete(':id')
  @HttpCode(204)
  remove(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    return this.people.remove(id, user);
  }
}
