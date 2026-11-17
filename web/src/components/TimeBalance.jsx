import { useState } from 'react';
import { Clock, Plus } from 'lucide-react';
import { api } from '../api.js';
import { Alert, Button, ErrorAlert, Field, Input, Modal } from './ui.jsx';
import { credits } from './membership.jsx';

/** Balance of one time module (included in the plan + extra bought) with the "buy extra time" dialog. */
export function TimeBalance({ status, onChange }) {
  const [open, setOpen] = useState(false);
  if (!status) return null;
  const unit = status.unit === 'hours' ? 'h' : 'min';

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-4">
          <div className="rounded-lg bg-sky-50 p-2.5 text-sky-600"><Clock className="h-6 w-6" /></div>
          <div>
            <p className="text-sm text-slate-500">Tiempo disponible · {status.label}</p>
            <p className="text-3xl font-semibold tabular-nums text-slate-800">{status.available.human}</p>
            <p className="mt-1 text-xs text-slate-500">
              Incluido en tu plan {status.plan}: {status.included_remaining.human} de {status.included.human} (se reinicia el {new Date(status.cycle_end).toLocaleDateString('es-MX')})
              {' · '}Extra comprado: {status.extra.human}
            </p>
          </div>
        </div>
        <Button variant="secondary" icon={Plus} onClick={() => setOpen(true)}>Comprar {unit === 'h' ? 'horas' : 'minutos'} extra</Button>
      </div>
      {open && <BuyTimeModal status={status} onClose={() => setOpen(false)} onDone={onChange} />}
    </section>
  );
}

function BuyTimeModal({ status, onClose, onDone }) {
  const [quantity, setQuantity] = useState(1);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [done, setDone] = useState(null);
  const price = status.extra_price;

  const buy = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api('/me/time/purchase', { method: 'POST', body: { module: status.module, quantity: Number(quantity) } });
      setDone(res.message);
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open title={`Tiempo extra · ${status.label}`} onClose={onClose}
      footer={done ? <Button onClick={onClose}>Listo</Button> : <><Button variant="secondary" onClick={onClose}>Cancelar</Button><Button loading={busy} onClick={buy}>Comprar por {credits(quantity * price.credits)}</Button></>}>
      {done ? <Alert tone="success">{done}</Alert> : (
        <div className="space-y-3">
          <ErrorAlert error={error} />
          <p className="text-sm text-slate-600">Cada bloque de <b>{price.label}</b> cuesta <b>{credits(price.credits)}</b>. El tiempo extra no caduca con el ciclo y se usa después del incluido en tu plan.</p>
          <Field label="Bloques">
            <Input type="number" min={1} max={20} value={quantity} onChange={(e) => setQuantity(e.target.value)} />
          </Field>
        </div>
      )}
    </Modal>
  );
}
