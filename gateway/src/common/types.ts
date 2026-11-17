export type Role = 'admin' | 'teacher' | 'student';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: Role;
  is_active: boolean;
}

/** 'cafeteria' is the external Sales System (FastAPI) running on a classmate's computer. */
export type ServiceName = 'auth' | 'catalog' | 'enrollment' | 'payments' | 'memberships' | 'cafeteria';

/** Services that are not ours: they never receive X-Service-Key or X-User-* headers. */
export const EXTERNAL_SERVICES: ServiceName[] = ['cafeteria'];
