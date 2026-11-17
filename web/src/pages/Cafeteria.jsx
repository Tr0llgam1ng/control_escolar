import { useState } from 'react';
import { CircleCheck, Coffee, CreditCard, KeyRound, PackageCheck, Plus, ReceiptText, ShoppingCart, Trash2, Truck, UserPlus } from 'lucide-react';
import { api } from '../api.js';
import { useApi } from '../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Field, Input, Loading, Modal, PageHeader, Select, Table, Td } from '../components/ui.jsx';
import { dateTime, money } from '../components/payments.jsx';

/**
 * Cafeteria: consumes the external Sales System through the gateway.
 * Menu (catalog) · Cart · Orders · Delivery tracking (shipping).
 */

const ORDER_STATUS = {
  PENDIENTE_PAGO: ['Pendiente de pago', 'amber'],
  PAGADA: ['Pagado', 'sky'],
  PAGADA_EN_PREPARACION: ['Pagado · en preparación', 'sky'],
  EN_CAMINO: ['En camino', 'indigo'],
  ENTREGADA: ['Entregado', 'emerald'],
  PAGO_RECHAZADO: ['Pago rechazado', 'rose'],
};

const DELIVERY_STEPS = [
  { key: 'EN_PREPARACION', label: 'En preparación' },
  { key: 'EN_CAMINO', label: 'En camino' },
  { key: 'ENTREGADO', label: 'Entregado' },
];

const PLACES = [
  { value: 'Recoger en la cafetería', label: 'Recoger en la cafetería' },
  { value: 'Edificio de Docencia', label: 'Edificio de Docencia' },
  { value: 'Biblioteca', label: 'Biblioteca' },
  { value: 'Laboratorio de Cómputo', label: 'Laboratorio de Cómputo' },
  { value: 'Control Escolar', label: 'Control Escolar' },
];

const PAYMENT_METHODS = [
  { value: 'EFECTIVO', label: 'Efectivo en caja' },
  { value: 'TARJETA', label: 'Tarjeta' },
];

export function OrderStatusBadge({ status }) {
  const [label, tone] = ORDER_STATUS[status] ?? [status, 'slate'];
  return <Badge tone={tone}>{label}</Badge>;
}

export default function Cafeteria() {
  const account = useApi('/me/cafeteria/account');
  const menu = useApi('/cafeteria/menu');
  const active = account.data?.state === 'active';

  if ((account.loading && !account.data && !account.error) || (menu.loading && !menu.data && !menu.error)) return <Loading />;

  return (
    <>
      <PageHeader title="Cafetería" subtitle="Menú, carrito, pedidos y entrega — servicio externo del Sistema de Ventas consumido por el API Gateway." />
      <ErrorAlert error={account.error ?? menu.error} />

      {account.data && !active && <AccountSetup account={account.data} onChange={account.reload} />}

      {active ? (
        <ActiveCafeteria menu={menu} />
      ) : (
        <Card title="Menú" icon={Coffee}>
          <Menu products={menu.data?.data ?? []} disabled />
        </Card>
      )}
    </>
  );
}

function ActiveCafeteria({ menu }) {
  const cart = useApi('/me/cafeteria/cart');
  const orders = useApi('/me/cafeteria/orders');
  const [adding, setAdding] = useState(null);
  const [error, setError] = useState(null);
  const [tracking, setTracking] = useState(null);

  const add = async (product) => {
    setAdding(product.id);
    setError(null);
    try {
      await api('/me/cafeteria/cart/items', { method: 'POST', body: { product_id: product.id, quantity: 1 } });
      cart.reload();
    } catch (e) {
      setError(e);
    } finally {
      setAdding(null);
    }
  };

  const afterOrder = (order) => {
    cart.reload();
    orders.reload();
    menu.reload(); // stock changed
    setTracking(order.order_id);
  };

  return (
    <>
      <ErrorAlert error={error ?? cart.error ?? orders.error} />
      <div className="mb-6 grid gap-6 lg:grid-cols-3">
        <Card title="Menú" icon={Coffee} className="lg:col-span-2">
          <Menu products={menu.data?.data ?? []} adding={adding} onAdd={add} />
        </Card>
        <CartCard cart={cart} onChanged={cart.reload} onOrdered={afterOrder} onAdd={add} />
      </div>
      <OrdersCard orders={orders} onTrack={setTracking} />
      {tracking && <TrackingModal orderId={tracking} onlinePayment={orders.data?.online_payment} onClose={() => setTracking(null)} onChanged={orders.reload} />}
    </>
  );
}

/** Step shown until the user has an active account in the Sales System. */
function AccountSetup({ account, onChange }) {
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const run = async (path, body) => {
    setBusy(true);
    setError(null);
    try {
      await api(path, { method: 'POST', body });
      onChange();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Tu cuenta de la cafetería" icon={KeyRound} className="mb-6">
      <ErrorAlert error={error} />
      {account.state === 'not_registered' ? (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-slate-600">
            Para usar el carrito y hacer pedidos se crea una cuenta en el Sistema de Ventas con tu correo <span className="font-medium">{account.email}</span>.
          </p>
          <Button icon={UserPlus} loading={busy} onClick={() => run('/me/cafeteria/account')}>Crear mi cuenta</Button>
        </div>
      ) : (
        <form className="flex flex-col gap-3 sm:flex-row sm:items-end" onSubmit={(e) => { e.preventDefault(); run('/me/cafeteria/account/activate', { code }); }}>
          <Field label="Código de activación" hint="El Sistema de Ventas lo envía como notificación al crear la cuenta (en la demo aparece en la consola de su servidor)." className="flex-1">
            <Input inputMode="numeric" maxLength={6} required value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} placeholder="000000" />
          </Field>
          <Button type="submit" icon={CircleCheck} loading={busy} disabled={code.length !== 6}>Activar</Button>
        </form>
      )}
    </Card>
  );
}

function Menu({ products, adding, onAdd, disabled }) {
  if (!products.length) return <Empty icon={Coffee} title="El menú está vacío" message="La cafetería todavía no registra productos." />;

  const byCategory = products.reduce((acc, p) => {
    (acc[p.categoria || 'Otros'] ??= []).push(p);
    return acc;
  }, {});

  return (
    <div className="space-y-5">
      {Object.entries(byCategory).map(([category, items]) => (
        <div key={category}>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{category}</p>
          <div className="grid gap-3 sm:grid-cols-2">
            {items.map((p) => (
              <div key={p.id} className="flex flex-col rounded-lg border border-slate-200 p-4">
                <div className="flex items-start justify-between gap-2">
                  <p className="font-medium text-slate-800">{p.nombre}</p>
                  <span className="font-semibold tabular-nums text-slate-800">{money(p.precio)}</span>
                </div>
                <p className="mt-1 flex-1 text-xs text-slate-500">{p.descripcion}</p>
                <div className="mt-3 flex items-center justify-between gap-2">
                  <span className={`text-xs ${p.stock > 0 ? 'text-slate-400' : 'text-rose-600'}`}>{p.stock > 0 ? `${p.stock} disponibles` : 'Agotado'}</span>
                  {!disabled && (
                    <Button size="sm" icon={Plus} loading={adding === p.id} disabled={p.stock < 1} onClick={() => onAdd(p)}>Agregar</Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function CartCard({ cart, onChanged, onOrdered, onAdd }) {
  const [form, setForm] = useState({ deliver_to: PLACES[0].value, payment_method: PAYMENT_METHODS[0].value });
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  const items = cart.data?.items ?? [];

  const remove = async (item) => {
    setBusy(`rm-${item.product_id}`);
    setError(null);
    try {
      await api(`/me/cafeteria/cart/items/${item.product_id}`, { method: 'DELETE' });
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  };

  const order = async (e) => {
    e.preventDefault();
    setBusy('order');
    setError(null);
    try {
      onOrdered(await api('/me/cafeteria/orders', { method: 'POST', body: form }));
    } catch (err) {
      setError(err);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card title="Mi carrito" icon={ShoppingCart}>
      <ErrorAlert error={error} />
      {cart.loading && !cart.data ? (
        <Loading />
      ) : items.length === 0 ? (
        <Empty icon={ShoppingCart} title="Carrito vacío" message="Agrega productos del menú." />
      ) : (
        <form onSubmit={order} className="space-y-4">
          <ul className="divide-y divide-slate-100">
            {items.map((i) => (
              <li key={i.product_id} className="flex items-center gap-2 py-2 text-sm">
                <div className="min-w-0 flex-1">
                  <p className="font-medium leading-tight text-slate-800">{i.nombre}</p>
                  <p className="text-xs text-slate-500">{money(i.precio_unitario)} c/u</p>
                </div>
                <span className="w-6 text-center tabular-nums">{i.cantidad}</span>
                <Button size="sm" variant="ghost" icon={Plus} aria-label="Agregar uno" onClick={() => onAdd({ id: i.product_id })} />
                <Button size="sm" variant="ghost-danger" icon={Trash2} aria-label="Quitar del carrito" loading={busy === `rm-${i.product_id}`} onClick={() => remove(i)} />
                <span className="w-20 text-right tabular-nums">{money(i.subtotal_item)}</span>
              </li>
            ))}
          </ul>
          <div className="flex justify-between border-t border-slate-100 pt-3 text-sm font-semibold">
            <span>Subtotal</span>
            <span className="tabular-nums">{money(cart.data?.subtotal_carrito)}</span>
          </div>
          <p className="text-xs text-slate-400">El costo de entrega lo calcula la cafetería al crear el pedido.</p>
          <Field label="Entregar en">
            <Select options={PLACES} value={form.deliver_to} onChange={(e) => setForm({ ...form, deliver_to: e.target.value })} />
          </Field>
          <Field label="Forma de pago">
            <Select options={PAYMENT_METHODS} value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value })} />
          </Field>
          <Button type="submit" icon={ReceiptText} loading={busy === 'order'} className="w-full">Hacer pedido</Button>
        </form>
      )}
    </Card>
  );
}

function OrdersCard({ orders, onTrack }) {
  const list = orders.data?.data ?? [];
  return (
    <Card title="Mis pedidos" icon={ReceiptText} padding={false}>
      {orders.loading && !orders.data ? (
        <Loading />
      ) : list.length === 0 ? (
        <Empty icon={ReceiptText} title="Aún no tienes pedidos" />
      ) : (
        <Table head={['Pedido', 'Fecha', 'Entregar en', { label: 'Total', className: 'text-right' }, 'Estado', 'Guía', '']}>
          {list.map((o) => (
            <tr key={o.order_id} className="hover:bg-slate-50">
              <Td className="font-medium">#{o.order_id}</Td>
              <Td className="whitespace-nowrap text-xs">{dateTime(o.fecha_creacion)}</Td>
              <Td>{o.direccion_envio}</Td>
              <Td className="text-right tabular-nums">{money(o.total)}</Td>
              <Td><OrderStatusBadge status={o.estado} /></Td>
              <Td className="font-mono text-xs">{o.guia_rastreo ?? <span className="text-slate-400">—</span>}</Td>
              <Td className="text-right">
                <Button size="sm" variant="secondary" icon={Truck} onClick={() => onTrack(o.order_id)}>Seguimiento</Button>
              </Td>
            </tr>
          ))}
        </Table>
      )}
    </Card>
  );
}

/** Order detail + delivery steps (Orders + Shipping services), with the simulated online payment. */
function TrackingModal({ orderId, onlinePayment, onClose, onChanged }) {
  const order = useApi(`/me/cafeteria/orders/${orderId}`);
  const [paying, setPaying] = useState(false);
  const [error, setError] = useState(null);
  const [paid, setPaid] = useState(null);
  const o = order.data;

  const pay = async () => {
    setPaying(true);
    setError(null);
    try {
      const result = await api(`/me/cafeteria/orders/${orderId}/pay`, { method: 'POST' });
      setPaid(result.guia_rastreo ? `Pago aplicado. La cafetería asignó la guía ${result.guia_rastreo}.` : 'Pago aplicado.');
      order.reload();
      onChanged();
    } catch (e) {
      setError(e);
    } finally {
      setPaying(false);
    }
  };

  const step = o?.shipping ? DELIVERY_STEPS.findIndex((s) => s.key === o.shipping.estado_logistico) : -1;

  return (
    <Modal open title={`Pedido #${orderId}`} onClose={onClose} wide
      footer={<Button variant="secondary" onClick={onClose}>Cerrar</Button>}>
      <ErrorAlert error={order.error ?? error} />
      {paid && <Alert tone="success">{paid}</Alert>}
      {!o ? (
        !order.error && <Loading />
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <OrderStatusBadge status={o.estado} />
            <span className="text-slate-500">{dateTime(o.fecha_creacion)}</span>
            <span className="text-slate-500">· {o.direccion_envio} · {o.metodo_pago}</span>
          </div>

          <Table head={['Producto', { label: 'Cant.', className: 'text-right' }, { label: 'Precio', className: 'text-right' }, { label: 'Subtotal', className: 'text-right' }]}>
            {(o.productos ?? []).map((p, i) => (
              <tr key={i}>
                <Td>{p.nombre}</Td>
                <Td className="text-right tabular-nums">{p.cantidad}</Td>
                <Td className="text-right tabular-nums">{money(p.precio_unitario)}</Td>
                <Td className="text-right tabular-nums">{money(p.subtotal)}</Td>
              </tr>
            ))}
          </Table>
          <div className="ml-auto max-w-xs space-y-1 text-sm">
            <Row label="Subtotal" value={money(o.subtotal)} />
            <Row label="Entrega" value={money(o.costo_envio)} />
            <Row label="Total" value={money(o.total)} strong />
          </div>

          <div>
            <p className="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700"><Truck className="h-4 w-4 text-indigo-500" /> Entrega</p>
            {o.shipping ? (
              <>
                <ol className="grid grid-cols-3 gap-2">
                  {DELIVERY_STEPS.map((s, i) => (
                    <li key={s.key} className={`rounded-lg border px-3 py-2 text-center text-xs font-medium ${i <= step ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-400'}`}>
                      {i <= step && <PackageCheck className="mx-auto mb-1 h-4 w-4" />}
                      {s.label}
                    </li>
                  ))}
                </ol>
                <p className="mt-2 text-xs text-slate-500">Guía <span className="font-mono">{o.shipping.guia_rastreo}</span> · actualizado {dateTime(o.shipping.fecha_actualizacion)}</p>
              </>
            ) : o.estado === 'PENDIENTE_PAGO' ? (
              <div className="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 sm:flex-row sm:items-center sm:justify-between">
                <span>La entrega se genera cuando la cafetería confirma el pago.{!onlinePayment && ' Paga en la caja de la cafetería.'}</span>
                {onlinePayment && <Button icon={CreditCard} loading={paying} onClick={pay}>Pagar {money(o.total)}</Button>}
              </div>
            ) : (
              <p className="text-sm text-slate-500">Sin información de entrega.</p>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}

const Row = ({ label, value, strong }) => (
  <div className={`flex justify-between ${strong ? 'border-t border-slate-100 pt-1 font-semibold text-slate-800' : 'text-slate-600'}`}>
    <span>{label}</span>
    <span className="tabular-nums">{value}</span>
  </div>
);
