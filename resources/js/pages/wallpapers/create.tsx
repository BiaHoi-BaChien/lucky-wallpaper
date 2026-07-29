import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface Existing {
    id: number;
    target_date: string;
    title: string | null;
    state: string;
}

export default function CreateWallpaper({
    defaultDate,
    selectedDate,
    existing,
}: {
    defaultDate: string;
    selectedDate: string;
    existing: Existing | null;
}) {
    const form = useForm({ target_date: selectedDate || defaultDate });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('wallpapers.proposals.store'));
    };

    return (
        <AppLayout breadcrumbs={[{ title: '壁紙作成', href: route('wallpapers.create') }]}>
            <Head title="壁紙作成" />
            <div className="max-w-3xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>対象日を選択</CardTitle>
                        <CardDescription>ホーチミン時間の明日を初期表示します。既存日は新規生成せず内容を表示します。</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="target_date">対象日</Label>
                            <div className="flex gap-3">
                                <Input
                                    id="target_date"
                                    type="date"
                                    value={form.data.target_date}
                                    onChange={(e) => {
                                        form.setData('target_date', e.target.value);
                                        router.get(route('wallpapers.create'), { date: e.target.value }, { preserveState: true, replace: true });
                                    }}
                                />
                            </div>
                            <InputError message={form.errors.target_date} />
                        </div>
                        {existing ? (
                            <div className="rounded-lg border border-amber-300 bg-amber-50 p-4">
                                <p className="font-medium">この日付の壁紙は登録済みです。</p>
                                <p className="text-sm">
                                    {existing.title ?? '構図生成待ち'}（{existing.state}）
                                </p>
                                <Button asChild className="mt-3">
                                    <Link href={route('wallpapers.show', { wallpaper: existing.id })}>詳細を表示</Link>
                                </Button>
                            </div>
                        ) : (
                            <form onSubmit={submit}>
                                <Button disabled={form.processing}>構図を提案してもらう</Button>
                            </form>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
