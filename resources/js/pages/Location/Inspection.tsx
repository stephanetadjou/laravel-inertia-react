import { Link, useForm, usePage } from '@inertiajs/react';

import { Page, Panel } from '../../components/page/Page';
import AppLayout from '../../layout/AppLayout';
import { Field, field, money } from './shared';

/* État des lieux sur téléphone : photos (appareil photo), kilométrage, carburant, dommages. */

interface Props {
    phase: 'out' | 'in';
    booking: {
        id: number;
        vehicle: string;
        customer: string;
        starts_at: string;
        ends_at: string;
        late: boolean;
        mileage: number | null;
        km_out: number | null;
        fuel_out: number | null;
        deposit: number | null;
        driver_name: string | null;
        pickup_place: string | null;
        out_photos: number[];
        out_inspection_id: number | null;
        out_damages: string | null;
        out_checklist: string[];
        accessories: string[];
    };
    [key: string]: unknown;
}

function LocationInspection() {
    const { phase, booking } = usePage<Props>().props;
    const { data, setData, post, processing, errors, progress } = useForm({
        mileage: String((phase === 'in' ? booking.km_out : booking.mileage) ?? ''),
        fuel: phase === 'out' ? '100' : '',
        damages: '',
        signed_name: '',
        photos: [] as File[],
        checklist: (phase === 'in' ? booking.out_checklist : booking.accessories) as string[],
    });

    return (
        <Page title={phase === 'out' ? 'État des lieux de départ' : 'État des lieux de retour'} subtitle={`${booking.vehicle} · ${booking.customer}`}>
            <Panel>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(`/location/bookings/${booking.id}/inspection/${phase}`, { forceFormData: true });
                    }}
                    className="mx-auto max-w-md space-y-4 text-sm"
                >
                    <p className="text-gray-600">
                        Du {booking.starts_at} au {booking.ends_at}
                        {booking.pickup_place ? ` · ${booking.pickup_place}` : ''}
                        {booking.driver_name ? ` · chauffeur ${booking.driver_name}` : ''}
                        {booking.deposit ? ` · caution ${money(booking.deposit)}` : ''}
                    </p>
                    {phase === 'in' && booking.late && <p className="font-medium text-error-600">Retour en retard sur la date prévue.</p>}
                    {phase === 'in' && (booking.km_out !== null || booking.out_damages) && (
                        <p className="rounded-lg bg-gray-50 p-2 dark:bg-white/5">
                            Au départ : {booking.km_out ?? '—'} km, carburant {booking.fuel_out ?? '—'} %{booking.out_damages ? `, déjà noté : ${booking.out_damages}` : ''}
                        </p>
                    )}
                    {phase === 'in' && booking.out_inspection_id && booking.out_photos.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {booking.out_photos.map((i) => (
                                <a key={i} href={`/location/inspections/${booking.out_inspection_id}/photos/${i}`} target="_blank" rel="noreferrer">
                                    <img src={`/location/inspections/${booking.out_inspection_id}/photos/${i}`} alt="Photo du départ" className="h-16 w-16 rounded object-cover" />
                                </a>
                            ))}
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Kilométrage" error={errors.mileage}>
                            <input type="number" inputMode="numeric" className={field} value={data.mileage} onChange={(e) => setData('mileage', e.target.value)} />
                        </Field>
                        <Field label={`Carburant : ${data.fuel || '—'} %`} error={errors.fuel}>
                            <input type="range" min={0} max={100} step={12.5} value={data.fuel || 0} onChange={(e) => setData('fuel', String(Math.round(Number(e.target.value))))} className="w-full" />
                        </Field>
                    </div>
                    <Field label="Photos (avant, arrière, côtés, intérieur, compteur)" error={errors.photos}>
                        <input type="file" accept="image/*" capture="environment" multiple onChange={(e) => setData('photos', [...data.photos, ...Array.from(e.target.files ?? [])])} />
                        {data.photos.length > 0 && <span className="text-xs text-gray-500">{data.photos.length} photo(s) prête(s)</span>}
                    </Field>
                    {booking.accessories.length > 0 && (
                        <fieldset className="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <legend className="px-1 font-medium">Accessoires présents</legend>
                            <div className="grid grid-cols-2 gap-1.5">
                                {booking.accessories.map((a) => {
                                    const missing = phase === 'in' && booking.out_checklist.includes(a) && !data.checklist.includes(a);
                                    return (
                                        <label key={a} className={`flex items-center gap-2 ${missing ? 'font-semibold text-error-600' : ''}`}>
                                            <input type="checkbox" checked={data.checklist.includes(a)} onChange={(e) => setData('checklist', e.target.checked ? [...data.checklist, a] : data.checklist.filter((x) => x !== a))} />
                                            {a}
                                            {missing && ' (manquant)'}
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>
                    )}
                    <Field label="Dommages constatés" error={errors.damages}>
                        <textarea className={field} rows={3} value={data.damages} onChange={(e) => setData('damages', e.target.value)} placeholder="ex. rayure pare-choc avant droit" />
                    </Field>
                    <Field label="Nom du client (vaut accord sur l’état des lieux)">
                        <input className={field} value={data.signed_name} onChange={(e) => setData('signed_name', e.target.value)} />
                    </Field>
                    {(errors as Record<string, string>).booking && <p className="text-error-500">{(errors as Record<string, string>).booking}</p>}
                    {progress && <progress value={progress.percentage} max={100} className="w-full" />}
                    <div className="flex justify-between gap-2">
                        <Link href="/location/planning" className="mdash-btn ghost">
                            Retour
                        </Link>
                        <button type="submit" className="mdash-btn brand" disabled={processing}>
                            {phase === 'out' ? 'Valider le départ' : 'Valider le retour'}
                        </button>
                    </div>
                </form>
            </Panel>
        </Page>
    );
}

LocationInspection.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LocationInspection;
