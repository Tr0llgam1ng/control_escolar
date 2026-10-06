import { ServiceClient } from '../common/service-client';
import { AuthUser, Role } from '../common/types';

/**
 * Orchestration shared by teachers and students. Creating a person spans two services:
 *   1) Auth Service creates the account (credentials + role)
 *   2) Catalog Service creates the academic record with that user_id
 * If step 2 fails, step 1 is compensated (account deleted) so no orphan accounts remain.
 */
export class PeopleOrchestrator {
  constructor(
    private readonly services: ServiceClient,
    private readonly resource: 'teachers' | 'students',
    private readonly role: Role,
  ) {}

  async create(body: any, user: AuthUser) {
    const account = await this.services.post('auth', '/users', {
      body: { name: body.name, email: body.email, password: body.password, role: this.role },
      user,
    });

    try {
      const { password, ...profile } = body;
      return await this.services.post('catalog', `/${this.resource}`, {
        body: { ...profile, user_id: account.id },
        user,
      });
    } catch (error) {
      await this.services.delete('auth', `/users/${account.id}`, { user }).catch(() => undefined); // compensation
      throw error;
    }
  }

  async update(id: string, body: any, user: AuthUser) {
    const current = await this.services.get('catalog', `/${this.resource}/${id}`, { user });
    const { password, ...profile } = body;
    const updated = await this.services.put('catalog', `/${this.resource}/${id}`, { body: { ...current, ...profile }, user });

    const accountChanges: Record<string, unknown> = { name: updated.name, email: updated.email };
    if (password) accountChanges.password = password;
    if (this.resource === 'students' && profile.status) accountChanges.is_active = profile.status === 'active';
    await this.services.put('auth', `/users/${current.user_id}`, { body: accountChanges, user });

    return updated;
  }

  async remove(id: string, user: AuthUser) {
    const current = await this.services.get('catalog', `/${this.resource}/${id}`, { user });
    await this.services.delete('catalog', `/${this.resource}/${id}`, { user });
    if (this.resource === 'students') {
      await this.services.delete('enrollment', `/students/${id}/enrollments`, { user }).catch(() => undefined);
    }
    await this.services.delete('auth', `/users/${current.user_id}`, { user }).catch(() => undefined);
  }
}
