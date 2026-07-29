import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

interface Wallpaper {
    id: number;
    target_date: string;
    title: string | null;
    art_style: string | null;
    composition: string | null;
    prize_vnd: number | null;
    state: string;
    image_path: string | null;
    notion_page_id: string | null;
}

export default function Results({
    selectedDate,
    defaultDate,
    wallpaper,
    latestRun,
}: {
    selectedDate: string;
    defaultDate: string;
    wallpaper: Wallpaper | null;
    latestRun: Operation | null;
}) {
    const search = useForm({ date: selectedDate || defaultDate });
    const result = useForm({ prize_vnd: wallpaper?.prize_vnd?.toString() ?? '' });
    const { operation } = useOperation(latestRun);

    const find = (event: FormEvent) => {
        event.preventDefault();
        router.get('/results', { date: search.data.date }, { preserveState: false });
    };
    const save = (event: FormEvent) => {
        event.preventDefault();
        if (wallpaper) {
            result.put(`/wallpapers/${wallpaper.id}/result`);
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: '実績登録', href: '/results' }]}>
            <Head title="実績登録" />
            <div className="max-w-4xl space-y-6 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>対象日の検索</CardTitle>
                        <CardDescription>登録済み壁紙を日付で取得します。</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={find} className="flex gap-3">
                            <Input type="date" value={search.data.date} onChange={(e) => search.setData('date', e.target.value)} required />
                            <Button>検索</Button>
                        </form>
                    </CardContent>
                </Card>

                {selectedDate && !wallpaper && <div className="rounded-lg border p-4">この日付の壁紙はありません。</div>}
                {wallpaper && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{wallpaper.title ?? '構図名未設定'}</CardTitle>
                            <CardDescription>
                                {wallpaper.target_date}・{wallpaper.art_style ?? '画風未設定'}・{wallpaper.state}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <p className="text-sm whitespace-pre-wrap">{wallpaper.composition}</p>
                            <form onSubmit={save} className="space-y-3">
                                <Label htmlFor="prize_vnd">当選金額（VND、0以上の整数）</Label>
                                <Input
                                    id="prize_vnd"
                                    type="number"
                                    min="0"
                                    max="999999999999999"
                                    step="1"
                                    value={result.data.prize_vnd}
                                    onChange={(e) => result.setData('prize_vnd', e.target.value)}
                                    required
                                />
                                <InputError message={result.errors.prize_vnd} />
                                <Button disabled={result.processing || ['queued', 'running'].includes(operation?.status ?? '')}>
                                    保存してNotion同期
                                </Button>
                            </form>
                            {operation && operation.type !== undefined && (
                                <div className="bg-muted rounded-lg p-3 text-sm">
                                    同期状態: {operation.status}
                                    {operation.error_code && <span className="ml-2 text-red-600">{operation.error_code}</span>}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
