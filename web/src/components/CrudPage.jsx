import { useMemo, useState } from 'react';
import { Pencil, Plus, Save, Search, Trash } from 'lucide-react';
import { api, fieldError } from '../api.js';
import { useApi } from '../hooks.js';
import { Alert, Button, Card, Empty, ErrorAlert, Field, Input, Loading, Modal, PageHeader, Select, Table, Td } from './ui.jsx';

/**
 * Generic CRUD screen over a gateway resource (e.g. /subjects).
 * fields: [{ name, label, type, required, options, span, hint, createOnly }]
 * columns: [{ label, render: row => node, className }]
 */
export default function CrudPage({ title, subtitle, endpoint, listQuery, icon, columns, fields, defaults = {}, toForm, noun = 'registro', searchKeys = ['name'], extraActions, toolbar }) {
  const { data, error, loading, reload } = useApi(endpoint, listQuery ? { query: listQuery } : undefined);
  const [query, setQuery] = useState('');
  const [editing, setEditing] = useState(null); // null | {} (new) | row
  const [form, setForm] = useState({});
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);

  const rows = useMemo(() => {
    const list = data?.data ?? [];
    const q = query.trim().toLowerCase();
    return q ? list.filter((r) => searchKeys.some((k) => String(r[k] ?? '').toLowerCase().includes(q))) : list;
  }, [data, query, searchKeys]);

  function open(row) {
    setEditing(row ?? {});
    setForm(row ? (toForm ? toForm(row) : { ...row }) : { ...defaults });
    setFormError(null);
  }

  async function save(e) {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      const isNew = !editing.id;
      await api(isNew ? endpoint : `${endpoint}/${editing.id}`, { method: isNew ? 'POST' : 'PUT', body: normalize(form, fields) });
      setEditing(null);
      setNotice(`${noun[0].toUpperCase() + noun.slice(1)} ${isNew ? 'registrado' : 'actualizado'}.`);
      reload();
    } catch (err) {
      setFormError(err);
    } finally {
      setSaving(false);
    }
  }

  async function remove(row) {
    if (!window.confirm(`¿Eliminar este ${noun}?`)) return;
    try {
      await api(`${endpoint}/${row.id}`, { method: 'DELETE' });
      setNotice(`${noun[0].toUpperCase() + noun.slice(1)} eliminado.`);
      reload();
    } catch (err) {
      setNotice(null);
      setFormError(err);
    }
  }

  const isNew = editing && !editing.id;

  return (
    <>
      <PageHeader title={title} subtitle={subtitle}>
        {toolbar}
        <div className="relative w-56">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <Input className="pl-9" placeholder="Buscar…" value={query} onChange={(e) => setQuery(e.target.value)} />
        </div>
        <Button icon={Plus} onClick={() => open(null)}>Nuevo</Button>
      </PageHeader>

      {notice && <Alert tone="success">{notice}</Alert>}
      <ErrorAlert error={error} />
      {!editing && <ErrorAlert error={formError} />}

      <Card padding={false}>
        {loading && !data ? (
          <Loading />
        ) : rows.length === 0 ? (
          <Empty icon={icon} title={`Sin ${title.toLowerCase()}`} message="Agrega el primero con el botón Nuevo." />
        ) : (
          <Table head={[...columns.map((c) => ({ label: c.label, className: c.className })), '']}>
            {rows.map((row) => (
              <tr key={row.id} className="hover:bg-slate-50">
                {columns.map((c, i) => (
                  <Td key={i} className={c.className}>{c.render(row)}</Td>
                ))}
                <Td className="whitespace-nowrap text-right">
                  {extraActions?.(row)}
                  <Button variant="ghost" size="sm" icon={Pencil} onClick={() => open(row)} title="Editar" />
                  <Button variant="ghost-danger" size="sm" icon={Trash} onClick={() => remove(row)} title="Eliminar" />
                </Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Modal
        open={!!editing}
        title={`${isNew ? 'Nuevo' : 'Editar'} ${noun}`}
        onClose={() => setEditing(null)}
        wide={fields.length > 5}
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>Cancelar</Button>
            <Button type="submit" form="crud-form" icon={Save} loading={saving}>Guardar</Button>
          </>
        }
      >
        <form id="crud-form" onSubmit={save} className="grid gap-4 sm:grid-cols-2">
          {formError && !Object.keys(formError.errors ?? {}).length && <div className="sm:col-span-2"><ErrorAlert error={formError} /></div>}
          {fields
            .filter((f) => !(f.createOnly && !isNew))
            .map((f) => (
              <Field key={f.name} label={f.label} error={fieldError(formError, f.name)} hint={!isNew && f.editHint ? f.editHint : f.hint} className={f.span === 2 ? 'sm:col-span-2' : ''}>
                {f.type === 'select' ? (
                  <Select value={form[f.name] ?? ''} onChange={(e) => setForm({ ...form, [f.name]: e.target.value })} options={f.options} placeholder={f.placeholder} required={f.required} />
                ) : f.type === 'checkbox' ? (
                  <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-indigo-600" checked={!!form[f.name]} onChange={(e) => setForm({ ...form, [f.name]: e.target.checked })} />
                ) : (
                  <Input
                    type={f.type ?? 'text'}
                    value={form[f.name] ?? ''}
                    onChange={(e) => setForm({ ...form, [f.name]: e.target.value })}
                    required={f.required && !(f.type === 'password' && !isNew)}
                    min={f.min}
                    max={f.max}
                    placeholder={f.placeholder}
                    autoComplete={f.type === 'password' ? 'new-password' : undefined}
                  />
                )}
              </Field>
            ))}
        </form>
      </Modal>
    </>
  );
}

function normalize(form, fields) {
  const out = { ...form };
  for (const f of fields) {
    if (out[f.name] === '' && !f.required) out[f.name] = null;
    if (f.type === 'number' && out[f.name] !== null && out[f.name] !== undefined && out[f.name] !== '') out[f.name] = Number(out[f.name]);
    if (f.type === 'password' && !out[f.name]) delete out[f.name];
  }
  return out;
}
