import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router-dom';
import { BookOpen, Calendar, ChevronDown, ClipboardList, GraduationCap, Layers, LayoutDashboard, LogOut, Menu, Presentation, Receipt, UserCog, Users } from 'lucide-react';
import { useAuth } from '../auth.jsx';

const NAV = {
  admin: [
    { to: '/admin', label: 'Inicio', icon: LayoutDashboard, end: true },
    { to: '/admin/periods', label: 'Periodos', icon: Calendar },
    { to: '/admin/subjects', label: 'Materias', icon: BookOpen },
    { to: '/admin/teachers', label: 'Profesores', icon: Presentation },
    { to: '/admin/students', label: 'Alumnos', icon: Users },
    { to: '/admin/courses', label: 'Clases e inscripciones', icon: Layers },
    { to: '/admin/payments', label: 'Pagos y referencias', icon: Receipt },
  ],
  teacher: [{ to: '/teacher', label: 'Mis materias', icon: ClipboardList, end: true }],
  student: [
    { to: '/student', label: 'Mi boleta', icon: GraduationCap, end: true },
    { to: '/student/payments', label: 'Trámites y pagos', icon: Receipt },
  ],
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

      <aside className={`print:hidden fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 transition-transform lg:translate-x-0 ${open ? 'translate-x-0' : '-translate-x-full'}`}>
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
      </aside>

      <div className="flex min-h-screen flex-col lg:pl-64 print:pl-0">
        <header className="print:hidden sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
          <button onClick={() => setOpen(true)} className="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Abrir menú">
            <Menu className="h-6 w-6" />
          </button>
          <p className="flex-1 truncate text-sm text-slate-500">Sistema Escolar · Arquitectura Orientada a Servicios</p>
          <ProfileMenu user={user} initials={initials} onLogout={logout} />
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

/** Avatar in the header: opens the account menu (profile / password and sign out). */
function ProfileMenu({ user, initials, onLogout }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
    const onKey = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div ref={ref} className="relative">
      <button type="button" onClick={() => setOpen((o) => !o)} aria-haspopup="menu" aria-expanded={open}
        className="flex items-center gap-3 rounded-lg py-1 pl-2 pr-1 hover:bg-slate-100">
        <div className="hidden text-right leading-tight sm:block">
          <p className="text-sm font-medium text-slate-800">{user.name}</p>
          <p className="text-xs text-slate-500">{user.email}</p>
        </div>
        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">{initials}</div>
        <ChevronDown className={`h-4 w-4 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div role="menu" className="absolute right-0 mt-2 w-60 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
          <div className="border-b border-slate-100 px-4 py-3 leading-tight">
            <p className="truncate text-sm font-medium text-slate-800">{user.name}</p>
            <p className="truncate text-xs text-slate-500">{user.email}</p>
            <p className="mt-1 text-xs text-indigo-600">{ROLE_LABEL[user.role]}</p>
          </div>
          <div className="p-1">
            <Link to="/account" role="menuitem" onClick={() => setOpen(false)}
              className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
              <UserCog className="h-4 w-4 text-slate-500" /> Mi perfil y contraseña
            </Link>
            <button type="button" role="menuitem" onClick={() => { setOpen(false); onLogout(); }}
              className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-rose-600 hover:bg-rose-50">
              <LogOut className="h-4 w-4" /> Cerrar sesión
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
