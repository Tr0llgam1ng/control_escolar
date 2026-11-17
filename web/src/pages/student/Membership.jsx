import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ArrowRightLeft, CalendarClock, Check, Coins, CreditCard, History, Minus, Package, RefreshCw, RotateCcw, Sparkles, Wallet } from 'lucide-react';
import { api } from '../../api.js';
import { useApi } from '../../hooks.js';
import { Alert, Badge, Button, Card, Empty, ErrorAlert, Loading, Modal, PageHeader, Stat, Table, Td } from '../../components/ui.jsx';
import { PlanBadge, UsageBar, credits, planStyle } from '../../components/membership.jsx';
import { dateTime, formatReference, money } from '../../components/payments.jsx';
import { PayModal } from './Payments.jsx';

const TABS = [
  { key: 'plan', label: 'Mi plan', icon: Sparkles },
  { key: 'credits', label: 'Créditos', icon: Coins },
];

/** Student: membership (plan Basic / Bronze / Silver / Gold) and the credits wallet. */
export default function Membership() {
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'credits' ? 'credits' : 'plan';
  const membership = useApi('/me/membership');

  if (membership.loading && !membership.data) return <Loading />;

  const m = membership.data;
  return (
    <>
      <PageHeader title="Mi membresía" subtitle="Tu plan decide qué puedes usar y cuánto. Los planes y los módulos especiales se pagan con créditos.">
        {m && <PlanBadge plan={m.plan} />}
        {m && <Badge tone="emerald"><Wallet className="mr-1 h-3 w-3" /> {credits(m.balance)}</Badge>}
      </PageHeader>
      <ErrorAlert error={membership.error} />

      <div className="mb-6 flex gap-1 rounded-xl bg-slate-100 p-1 sm:w-fit">
        {TABS.map(({ key, label, icon: Icon }) => (
          <button key={key} type="button" onClick={() => setParams(key === 'plan' ? {} : { tab: key })}
            className={`flex flex-1 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition sm:flex-none ${tab === key ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-600 hover:text-slate-800'}`}>
            <Icon className="h-4 w-4" /> {label}
          </button>
        ))}
      </div>

      {m && tab === 'plan' && <PlanTab membership={m} reload={membership.reload} goCredits={() => setParams({ tab: 'credits' })} />}
      {m && tab === 'credits' && <CreditsTab onChange={membership.reload} />}
    </>
  );
}

// ------------------------------------------------------------------ plan

const USAGE = ['paperwork.requests', 'invoicing.invoices', 'cafeteria.orders', 'tutoring.sessions', 'analytics.reports', 'study_rooms.hours', 'library.minutes'];

function PlanTab({ membership: m, reload, goCredits }) {
  const plans = useApi('/memberships/plans');
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  const sub = m.subscription;
  const style = planStyle(m.plan.code);
  const PlanIcon = style.icon;

  const run = async (path, body) => {
    setBusy(true);
    setError(null);
    try {
      const res = await api(path, { method: 'POST', body });
      setNotice(res.message);
      setConfirm(null);
      reload();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const refundPreview = () => {
    if (!sub) return 0;
    const total = (new Date(sub.ends_at) - new Date(sub.starts_at)) / 1000;
    return Math.floor((sub.price_credits * sub.remaining_seconds) / Math.max(1, total));
  };

  return (
    <>
      <Alert tone="success">{notice}</Alert>
      <ErrorAlert error={error && !confirm ? error : null} />

      <div className="mb-6 grid gap-4 lg:grid-cols-3">
        <section className={`rounded-xl border border-slate-200 p-5 shadow-sm ring-1 ring-inset ${style.ring} ${style.bg}`}>
          <div className="flex items-center gap-3">
            <div className={`rounded-xl p-2.5 text-white ${style.solid}`}><PlanIcon className="h-6 w-6" /></div>
            <div>
              <p className="text-sm text-slate-500">Plan actual</p>
              <p className={`text-2xl font-semibold ${style.text}`}>{m.plan.name}</p>
            </div>
          </div>
          {sub ? (
            <div className="mt-4 space-y-1 text-sm text-slate-600">
              <p className="flex items-center gap-2"><CalendarClock className="h-4 w-4 text-slate-400" /> Vigente hasta el {dateTime(sub.ends_at)}</p>
              <p className="text-2xl font-semibold tabular-nums text-slate-800">{m.subscription_remaining.days} d {m.subscription_remaining.hours} h {m.subscription_remaining.minutes} min</p>
              <p className="text-xs text-slate-500">restantes del plan ({m.plan.duration_days} días por {credits(m.plan.price_credits)})</p>
              <div className="pt-2">
                {sub.auto_renew ? (
                  <Button size="sm" variant="secondary" icon={Minus} loading={busy} onClick={() => run('/me/subscription/cancel')}>Desactivar renovación</Button>
                ) : (
                  <>
                    <p className="mb-2 text-xs text-amber-700">La renovación está desactivada: al terminar pasarás al plan Básico.</p>
                    <Button size="sm" variant="secondary" icon={RotateCcw} loading={busy} onClick={() => run('/me/subscription/resume')}>Reactivar renovación</Button>
                  </>
                )}
              </div>
            </div>
          ) : (
            <p className="mt-4 text-sm text-slate-600">Plan gratuito. Los límites se reinician cada mes (siguiente ciclo: {dateTime(m.cycle.end)}).</p>
          )}
        </section>

        <Card title="Uso en este ciclo" icon={History} className="lg:col-span-2">
          <p className="-mt-1 mb-4 text-xs text-slate-500">Del {dateTime(m.cycle.start)} al {dateTime(m.cycle.end)}</p>
          <div className="grid gap-4 sm:grid-cols-2">
            {USAGE.map((key) => <UsageBar key={key} feature={m.features[key]} label={`${m.features[key]?.group?.replace(/ \(.*\)/, '')} · ${m.features[key]?.label}`} />)}
          </div>
        </Card>
      </div>

      <h2 className="mb-3 text-lg font-semibold text-slate-800">Planes</h2>
      {plans.loading && !plans.data ? <Loading /> : (
        <ErrorAlert error={plans.error} />
      )}
      {plans.data && <PlanGrid plans={plans.data.data} current={m.plan} onChoose={setConfirm} />}

      <Modal open={!!confirm} title={`Cambiar al plan ${confirm?.name ?? ''}`} onClose={() => { setConfirm(null); setError(null); }}
        footer={<>
          <Button variant="secondary" onClick={() => { setConfirm(null); setError(null); }}>Cancelar</Button>
          <Button icon={Check} loading={busy} onClick={() => run('/me/subscription', { plan_code: confirm.code })}>Confirmar</Button>
        </>}>
        {confirm && (
          <div className="space-y-3 text-sm text-slate-600">
            <ErrorAlert error={error} />
            {error?.code === 'INSUFFICIENT_CREDITS' && <Button size="sm" icon={Coins} onClick={() => { setConfirm(null); setError(null); goCredits(); }}>Comprar créditos</Button>}
            <div className="rounded-lg bg-slate-50 px-4 py-3">
              <div className="flex justify-between"><span>Precio</span><b>{credits(confirm.price_credits)}</b></div>
              <div className="flex justify-between"><span>Duración</span><b>{confirm.duration_days} días</b></div>
              {sub && <div className="flex justify-between text-emerald-700"><span>Reembolso de tu plan {m.plan.name} (aprox.)</span><b>+{credits(refundPreview())}</b></div>}
              <div className="mt-2 flex justify-between border-t border-slate-200 pt-2"><span>Tu saldo</span><b>{credits(m.balance)}</b></div>
            </div>
            <p className="text-xs text-slate-500">El nuevo plan empieza ahora y sus límites se cuentan desde hoy. Se renueva solo si tienes créditos suficientes.</p>
          </div>
        )}
      </Modal>
    </>
  );
}

function PlanGrid({ plans, current, onChoose }) {
  const groups = [...new Set(plans[0]?.features.map((f) => f.group))];

  return (
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      {plans.map((p) => {
        const style = planStyle(p.code);
        const Icon = style.icon;
        const isCurrent = p.code === current.code;
        return (
          <section key={p.code} className={`flex flex-col rounded-xl border bg-white shadow-sm ${isCurrent ? 'border-indigo-400 ring-2 ring-indigo-200' : 'border-slate-200'}`}>
            <header className={`rounded-t-xl px-5 py-4 ${style.bg}`}>
              <div className="flex items-center justify-between">
                <p className={`flex items-center gap-2 text-lg font-semibold ${style.text}`}><Icon className="h-5 w-5" /> {p.name}</p>
                {isCurrent && <Badge tone="indigo">Tu plan</Badge>}
              </div>
              <p className="mt-1 text-xs text-slate-500">{p.description}</p>
              <p className="mt-3 text-2xl font-semibold tabular-nums text-slate-800">
                {p.price_credits ? credits(p.price_credits) : 'Gratis'}
                {p.price_credits > 0 && <span className="text-sm font-normal text-slate-500"> / {p.duration_days} días</span>}
              </p>
            </header>
            <div className="flex-1 space-y-3 px-5 py-4">
              {groups.map((g) => (
                <div key={g}>
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{g}</p>
                  <ul className="space-y-1">
                    {p.features.filter((f) => f.group === g).map((f) => (
                      <li key={f.key} className="flex items-start justify-between gap-2 text-xs">
                        <span className={f.available ? 'text-slate-600' : 'text-slate-400 line-through'}>{f.label}</span>
                        <span className={`shrink-0 font-medium ${f.available ? 'text-slate-800' : 'text-slate-400'}`}>{f.display}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
            <footer className="px-5 pb-5">
              {p.code === 'basic' ? (
                <p className="text-center text-xs text-slate-400">{isCurrent ? 'Plan gratuito de todo alumno' : 'Desactiva la renovación para volver a Básico'}</p>
              ) : (
                <Button className="w-full" variant={isCurrent ? 'secondary' : 'primary'} disabled={isCurrent} icon={isCurrent ? Check : ArrowRightLeft} onClick={() => onChoose(p)}>
                  {isCurrent ? 'Plan actual' : current.code === 'basic' ? 'Contratar' : 'Cambiar a este plan'}
                </Button>
              )}
            </footer>
          </section>
        );
      })}
    </div>
  );
}

// ------------------------------------------------------------------ credits

const PURCHASE_TONE = { pending: 'amber', credited: 'emerald', review: 'sky', rejected: 'rose' };

function CreditsTab({ onChange }) {
  const wallet = useApi('/me/wallet');
  const packs = useApi('/memberships/credit-packs');
  const [buying, setBuying] = useState(null);
  const [error, setError] = useState(null);
  const [paying, setPaying] = useState(null);
  const [notice, setNotice] = useState(null);

  const refresh = () => {
    wallet.reload();
    onChange();
  };

  const buy = async (pack) => {
    setBuying(pack.code);
    setError(null);
    try {
      const { charge } = await api('/me/credit-purchases', { method: 'POST', body: { pack_code: pack.code } });
      setPaying(charge);
    } catch (e) {
      setError(e);
    } finally {
      setBuying(null);
    }
  };

  const openCharge = async (chargeId) => {
    setError(null);
    try {
      const charge = await api(`/me/charges/${chargeId}`);
      if (charge.status !== 'pending' || charge.is_expired) {
        setNotice('La referencia ya no está pendiente; revisando el estado…');
        refresh();
        return;
      }
      setPaying(charge);
    } catch (e) {
      setError(e);
    }
  };

  const sync = async (p) => {
    try {
      const res = await api(`/me/credit-purchases/${p.id}/sync`, { method: 'POST' });
      setNotice(res.status === 'credited' ? `Se acreditaron ${credits(res.total_credits)}.` : `Estado: ${res.status_label}.`);
      refresh();
    } catch (e) {
      setError(e);
    }
  };

  if ((wallet.loading && !wallet.data) || (packs.loading && !packs.data)) return <Loading />;
  const w = wallet.data;

  return (
    <>
      <Alert tone="success">{notice ?? (w?.synced ? `Se acreditaron ${w.synced} compra(s) recién pagadas.` : null)}</Alert>
      <ErrorAlert error={error ?? wallet.error ?? packs.error} />

      <div className="mb-6 grid gap-4 sm:grid-cols-3">
        <Stat label="Saldo" value={credits(w?.balance)} icon={Wallet} tone="emerald" />
        <Stat label="Tipo de cambio" value="$1 = 1 crédito" icon={ArrowRightLeft} hint="Los paquetes grandes regalan créditos" />
        <Stat label="Regalo de tu plan" value={`${packs.data?.bonus_pct ?? 0}%`} icon={Sparkles} tone="amber" hint="Extra sobre cada paquete" />
      </div>

      <Card title="Cambiar dinero por créditos" icon={Package} className="mb-6">
        <p className="-mt-1 mb-4 text-sm text-slate-500">
          Al comprar se genera una referencia de pago (como cualquier trámite). Págala con tarjeta o en la caja de Control Escolar; cuando se apruebe, los créditos llegan a tu monedero.
        </p>
        <div className="grid gap-3 sm:grid-cols-3">
          {(packs.data?.data ?? []).map((p) => (
            <div key={p.code} className="flex flex-col rounded-xl border border-slate-200 p-4">
              <p className="text-2xl font-semibold tabular-nums text-slate-800">{p.total_credits.toLocaleString('es-MX')} <span className="text-sm font-normal text-slate-500">créditos</span></p>
              <p className="mt-1 flex-1 text-xs text-slate-500">
                {p.description}
                {p.bonus_credits > 0 && <span className="block text-emerald-600">+{p.bonus_credits} de regalo por tu plan</span>}
              </p>
              <div className="mt-3 flex items-center justify-between gap-2">
                <span className="text-lg font-semibold tabular-nums">{money(p.price)}</span>
                <Button size="sm" icon={CreditCard} loading={buying === p.code} onClick={() => buy(p)}>Comprar</Button>
              </div>
            </div>
          ))}
        </div>
      </Card>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card title="Mis compras" icon={Package} padding={false} actions={<Button size="sm" variant="ghost" icon={RefreshCw} onClick={refresh}>Actualizar</Button>}>
          {!w?.purchases?.length ? <Empty icon={Package} title="Sin compras" message="Compra un paquete para obtener créditos." /> : (
            <Table head={['Referencia', 'Paquete', { label: 'Créditos', className: 'text-right' }, 'Estado', '']}>
              {w.purchases.map((p) => (
                <tr key={p.id}>
                  <Td className="font-mono text-xs">{formatReference(p.reference)}</Td>
                  <Td>{p.pack?.name}<p className="text-xs text-slate-400">{money(p.amount)}</p></Td>
                  <Td className="text-right tabular-nums">{p.total_credits}{p.bonus_credits > 0 && <p className="text-xs text-emerald-600">+{p.bonus_credits} regalo</p>}</Td>
                  <Td><Badge tone={PURCHASE_TONE[p.status]}>{p.status_label}</Badge>{p.status === 'review' && <p className="mt-1 max-w-48 text-xs text-slate-500">{p.status_reason}</p>}</Td>
                  <Td className="text-right">
                    {p.status === 'pending' && (
                      <div className="flex justify-end gap-1">
                        <Button size="sm" icon={CreditCard} onClick={() => openCharge(p.charge_id)}>Pagar</Button>
                        <Button size="sm" variant="ghost" icon={RefreshCw} onClick={() => sync(p)} aria-label="Revisar pago" />
                      </div>
                    )}
                  </Td>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <Card title="Movimientos de créditos" icon={History} padding={false}>
          {!w?.transactions?.length ? <Empty icon={History} title="Sin movimientos" /> : (
            <Table head={['Fecha', 'Concepto', { label: 'Créditos', className: 'text-right' }, { label: 'Saldo', className: 'text-right' }]}>
              {w.transactions.map((t) => (
                <tr key={t.id}>
                  <Td className="whitespace-nowrap text-xs">{dateTime(t.created_at)}</Td>
                  <Td><p className="text-sm">{t.concept}</p><p className="text-xs text-slate-400">{t.type_label}</p></Td>
                  <Td className={`text-right font-semibold tabular-nums ${t.amount > 0 ? 'text-emerald-600' : 'text-rose-600'}`}>{t.amount > 0 ? '+' : ''}{t.amount}</Td>
                  <Td className="text-right tabular-nums text-slate-500">{t.balance_after}</Td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      {paying && (
        <PayModal charge={paying} onClose={() => { setPaying(null); refresh(); }} onDone={refresh} />
      )}
    </>
  );
}

