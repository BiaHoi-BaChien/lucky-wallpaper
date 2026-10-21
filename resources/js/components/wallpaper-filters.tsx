import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { Filter, RotateCcw } from 'lucide-react';
import { FormEvent } from 'react';

export interface WallpaperFilterValues {
    date_from?: string | null;
    date_to?: string | null;
    direction?: string | null;
}

export function WallpaperFilters({
    filters,
    action,
    directionLabels,
}: {
    filters: WallpaperFilterValues;
    action: string;
    directionLabels?: Record<string, string>;
}) {
    const form = useForm({ date_from: filters.date_from ?? '', date_to: filters.date_to ?? '', direction: filters.direction ?? '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.get(action, { preserveState: true, preserveScroll: true });
    };
    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
            <div className="space-y-1">
                <Label htmlFor="date_from">開始日</Label>
                <Input id="date_from" type="date" value={form.data.date_from} onChange={(event) => form.setData('date_from', event.target.value)} />
                <InputError message={form.errors.date_from} />
            </div>
            <div className="space-y-1">
                <Label htmlFor="date_to">終了日</Label>
                <Input id="date_to" type="date" value={form.data.date_to} onChange={(event) => form.setData('date_to', event.target.value)} />
                <InputError message={form.errors.date_to} />
            </div>
            {directionLabels && (
                <div className="space-y-1">
                    <Label htmlFor="direction">採用した主方位</Label>
                    <select
                        id="direction"
                        value={form.data.direction}
                        onChange={(event) => form.setData('direction', event.target.value)}
                        className="border-input bg-background h-9 max-w-full rounded-md border px-3 text-sm"
                    >
                        <option value="">すべて</option>
                        {Object.entries(directionLabels).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.direction} />
                </div>
            )}
            <Button type="submit" disabled={form.processing}>
                <Filter aria-hidden="true" />
                絞り込む
            </Button>
            <Button type="button" variant="outline" disabled={form.processing} onClick={() => router.get(action, {}, { preserveState: false })}>
                <RotateCcw aria-hidden="true" />
                全期間
            </Button>
        </form>
    );
}
