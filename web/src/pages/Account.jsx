import { useState } from 'react';
import { KeyRound, Save } from 'lucide-react';
import { api, fieldError } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Alert, Button, Card, Field, Input, PageHeader } from '../components/ui.jsx';

export default function Account() {
  const { user, profile, logout } = useAuth();
  const [password, setPassword] = useState('');
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api('/auth/password', { method: 'PUT', body: { password } });
      await logout(); // tokens are revoked by the Auth Service after a password change
    } catch (err) {
      setError(err);
      setSaving(false);
    }
  }

  return (
    <>
      <PageHeader title="Mi cuenta" subtitle={user.email} />
      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Datos" icon={KeyRound}>
          <dl className="space-y-2 text-sm">
            <Row label="Nombre" value={user.name} />
            <Row label="Rol" value={{ admin: 'Control Escolar', teacher: 'Profesor', student: 'Alumno' }[user.role]} />
            {profile?.enrollment_number && <Row label="Matrícula" value={profile.enrollment_number} />}
            {profile?.employee_number && <Row label="No. empleado" value={profile.employee_number} />}
            {profile?.group_name && <Row label="Grupo" value={profile.group_name} />}
          </dl>
        </Card>
        <Card title="Cambiar contraseña" icon={KeyRound}>
          <form onSubmit={submit} className="space-y-4">
            <Alert>{error && !fieldError(error, 'password') ? error.message : null}</Alert>
            <Field label="Nueva contraseña" error={fieldError(error, 'password')} hint="Mínimo 8 caracteres. Se cerrará tu sesión.">
              <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="new-password" />
            </Field>
            <Button type="submit" icon={Save} loading={saving}>Actualizar</Button>
          </form>
        </Card>
      </div>
    </>
  );
}

const Row = ({ label, value }) => (
  <div className="flex justify-between gap-4">
    <dt className="text-slate-500">{label}</dt>
    <dd className="text-right font-medium text-slate-800">{value}</dd>
  </div>
);
