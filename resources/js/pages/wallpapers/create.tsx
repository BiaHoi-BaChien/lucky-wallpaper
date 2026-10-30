import { ManualWallpaperForm } from '@/components/manual-wallpaper-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Operation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { wallpaperStateLabel } from '@/lib/wallpaper-state';
import { Head, Link, useForm } from '@inertiajs/react';
import { CalendarCheck } from 'lucide-react';
import { FormEvent } from 'react';

export default function CreateWallpaper({
    defaultDate,
    selectedDate,
    existing,
    apiConfigured,
    apiGeneration,
}: {
    defaultDate: string;
    selectedDate: string;
    existing: { id: number; title: string | null; state: string } | null;
    apiConfigured: boolean;
    apiGeneration: Operation | null;
}) {
    const targetDate = selectedDate || defaultDate;
    const dateForm = useForm({ date: targetDate });
    const dateChanged = dateForm.data.date !== targetDate;
    const applyDate = (event: FormEvent) => {
        event.preventDefault();
        dateForm.get(route('wallpapers.create'), { preserveState: false });
    };

    return (
        <AppLayout breadcrumbs={[{ title: '壁紙作成', href: route('wallpapers.create') }]}>
            <Head title="壁紙作成" />
            <div className="max-w-4xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">九星気学の壁紙作成</h1>
                <form onSubmit={applyDate} className="flex flex-wrap items-end gap-3">
                    <div className="max-w-xs space-y-2">
                        <Label htmlFor="target_date">対象日</Label>
                        <Input
                            id="target_date"
                            type="date"
                            value={dateForm.data.date}
                            onChange={(event) => dateForm.setData('date', event.target.value)}
                            disabled={dateForm.processing}
                            required
                        />
                    </div>
                    <Button type="submit" disabled={dateForm.processing || !dateChanged}>
                        <CalendarCheck aria-hidden="true" />
                        日付を適用
                    </Button>
                </form>
                {dateChanged ? (
                    <p className="text-muted-foreground text-sm">日付を適用して対象日を確定してください。</p>
                ) : existing ? (
                    <Alert variant="warning">
                        <AlertTitle>この日付の壁紙は登録済みです。</AlertTitle>
                        <AlertDescription>
                            <p>
                                {existing.title ?? '構図名未設定'}（{wallpaperStateLabel(existing.state)}）
                            </p>
                            <Button asChild className="mt-3">
                                <Link href={route('wallpapers.show', { wallpaper: existing.id })}>詳細を表示</Link>
                            </Button>
                        </AlertDescription>
                    </Alert>
                ) : (
                    <ManualWallpaperForm key={targetDate} targetDate={targetDate} apiConfigured={apiConfigured} apiGeneration={apiGeneration} />
                )}
            </div>
        </AppLayout>
    );
}
