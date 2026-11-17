import { BadRequestException, Controller, Get, NotFoundException, Query } from '@nestjs/common';
import { ServiceClient } from '../common/service-client';
import { Public } from '../auth/decorators';

/**
 * Service offered to an external system (the classmate's Sales System, FastAPI).
 * GET /api/alumnos/verificar?email=... → is this e-mail an active student?
 * Public on purpose (server-to-server, no account in our system) and it only answers
 * for ONE given e-mail with the minimum data, never a list.
 */
@Controller('alumnos')
export class StudentVerificationController {
  constructor(private readonly services: ServiceClient) {}

  @Public()
  @Get('verificar')
  async verify(@Query('email') email?: string) {
    const wanted = String(email ?? '').trim().toLowerCase();
    if (!wanted) throw new BadRequestException({ inscrito: false, mensaje: 'Falta el parámetro email.' });

    const { data } = await this.services.get<{ data: any[] }>('catalog', '/students', { query: { status: 'active' } });
    const student = data.find((s) => String(s.email ?? '').toLowerCase() === wanted);

    if (!student) {
      throw new NotFoundException({
        inscrito: false,
        mensaje: 'El correo proporcionado no pertenece a un alumno activo en Control Escolar',
      });
    }

    return {
      inscrito: true,
      institucion: 'Sistema de Control Escolar',
      beneficio_autorizado: 'CONVENIO_ESTUDIANTIL_15',
      alumno: {
        id: student.id,
        name: student.name,
        email: student.email,
        matricula: student.enrollment_number,
        carrera: student.program,
        semestre: student.semester,
        grupo: student.group_name,
      },
    };
  }
}
