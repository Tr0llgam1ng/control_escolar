export type Role = 'admin' | 'teacher' | 'student';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: Role;
  is_active: boolean;
}

export type ServiceName = 'auth' | 'catalog' | 'enrollment';
