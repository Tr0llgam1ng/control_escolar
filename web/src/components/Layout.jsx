import { useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { BookOpen, Calendar, ClipboardList, GraduationCap, KeyRound, Layers, LayoutDashboard, LogOut, Menu, Presentation, Users } from 'lucide-react';
import { useAuth } from '../auth.jsx';

const NAV = {
  admin: [
    { to: '/admin', label: 'Inicio', icon: LayoutDashboard, end: true },
    { to: '/admin/periods', label: 'Periodos', icon: Calendar },
    { to: '/admin/subjects', label: 'Materias', icon: BookOpen },
    { to: '/admin/teachers', label: 'Profesores', icon: Presentation },
    { to: '/admin/students', label: 'Alumnos', icon: Users },
    { to: '/admin/courses', label: 'Clases e inscripciones', icon: Layers },
  ],
  teacher: [{ to: '/teacher', label: 'Mis materias', icon: ClipboardList, end: true }],
  student: [{ to: '/student', label: 'Mi boleta', icon: GraduationCap, end: true }],
};

const ROLE_LABEL = { admin: 'Control Escolar', teacher: 'Profesor', student: 'Alumno' };

export default function Layout() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const initials = user.name.split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();

  const link = ({ isActive }) =>
    `flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${isActive ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-300 hover:bg-slate-800 hover:text-white'}`;

  return (
    <div className="min-h-screen">
      {open && <div className="fixed inset-0 z-30 bg-slate-900/60 lg:hidden" onClick={() => setOpen(false)} />}

      <aside className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 transition-transform lg:translate-x-0 ${open ? 'translate-x-0' : '-translate-x-full'}`}>
        <div className="flex h-16 items-center gap-3 border-b border-slate-800 px-5">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600 text-white">
            <GraduationCap className="h-5 w-5" />
          </div>
          <div className="leading-tight">
            <p className="text-sm font-semibold text-white">Control Escolar</p>
            <p className="text-xs text-slate-400">{ROLE_LABEL[user.role]}</p>
          </div>
        </div>
        <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4" onClick={() => setOpen(false)}>
          {NAV[user.role].map(({ to, label, icon: Icon, end }) => (
            <NavLink key={to} to={to} end={end} className={link}>
              <Icon className="h-5 w-5" /> {label}
            </NavLink>
          ))}
        </nav>
        <div className="space-y-1 border-t border-slate-800 p-3">
          <NavLink to="/account" className={link} onClick={() => setOpen(false)}>
            <KeyRound className="h-5 w-5" /> Mi cuenta
          </NavLink>
          <button onClick={logout} className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white">
            <LogOut className="h-5 w-5" /> Cerrar sesión
          </button>
        </div>
      </aside>

      <div className="flex min-h-screen flex-col lg:pl-64">
        <header className="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
          <button onClick={() => setOpen(true)} className="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Abrir menú">
            <Menu className="h-6 w-6" />
          </button>
          <p className="flex-1 truncate text-sm text-slate-500">Sistema Escolar · Arquitectura Orientada a Servicios</p>
          <div className="hidden text-right leading-tight sm:block">
            <p className="text-sm font-medium text-slate-800">{user.name}</p>
            <p className="text-xs text-slate-500">{user.email}</p>
          </div>
          <div className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">{initials}</div>
        </header>
        <main className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}
