import { useState } from 'react';
import { GraduationCap, Lock } from 'lucide-react';
import { useAuth } from '../auth.jsx';
import { Alert, Button, Field, Input } from '../components/ui.jsx';
import ServicesStatus from '../components/ServicesStatus.jsx';

export default function Login() {
  const { login } = useAuth();
  const [form, setForm] = useState({ email: '', password: '' });
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      await login(form.email, form.password);
    } catch (err) {
      setError(err);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      <div className="hidden flex-col justify-between bg-slate-900 p-12 text-white lg:flex">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-600"><GraduationCap className="h-6 w-6" /></div>
          <div>
            <p className="font-semibold">Control Escolar</p>
            <p className="text-sm text-slate-400">Universidad Politécnica de Durango</p>
          </div>
        </div>
        <div className="space-y-4">
          <h2 className="text-3xl font-semibold leading-tight">Un sistema escolar construido con servicios.</h2>
          <p className="text-slate-400">React → API Gateway en NestJS → 4 servicios Laravel independientes, cada uno con su propia base de datos.</p>
        </div>
        <p className="text-xs text-slate-500">Arquitectura Orientada a Servicios · 9B BIS ISW</p>
      </div>

      <div className="flex flex-col items-center justify-center gap-6 bg-slate-50 px-6 py-12">
        <form onSubmit={submit} className="w-full max-w-md space-y-5 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
          <div>
            <h1 className="text-2xl font-semibold text-slate-900">Iniciar sesión</h1>
            <p className="mt-1 text-sm text-slate-500">El token lo emite el Servicio de Autenticación.</p>
          </div>
          <Alert>{error?.message}</Alert>
          <Field label="Correo electrónico">
            <Input type="email" required autoFocus autoComplete="username" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </Field>
          <Field label="Contraseña">
            <Input type="password" required autoComplete="current-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          </Field>
          <Button type="submit" icon={Lock} loading={loading} className="w-full">
            Entrar
          </Button>
          <p className="text-center text-xs text-slate-400">Demo: admin@escolar.test · profesor@escolar.test · alumno@escolar.test — contraseña <code>password</code></p>
        </form>
        <div className="w-full max-w-md">
          <ServicesStatus />
        </div>
      </div>
    </div>
  );
}
