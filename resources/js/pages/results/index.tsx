import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
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
    latestImport,
}: {
    selectedDate: string;
    defaultDate: string;
    wallpaper: Wallpaper | null;
    latestRun: Operation | null;
    latestImport: Operation | null;
}) {
    const search = useForm({ date: selectedDate || defaultDate });
    const result = useForm({ prize_vnd: wallpaper?.prize_vnd?.toString() ?? '' });
    const { operation: resultOperation } = useOperation(latestRun);
    const { operation: importOperation } = useOperation(latestImport);
    const page = usePage<{ errors: { sync?: string } }>();
    const importActive = importOperation && ['queued', 'running'].includes(importOperation.status);

    const find = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('results.index'), { date: search.data.date }, { preserveState: false });
    };
    const save = (event: FormEvent) => {
        event.preventDefault();
        if (wallpaper) {
            result.put(route('wallpapers.result.update', { wallpaper: wallpaper.id }));
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: '実績登録', href: route('results.index') }]}>
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
                                <Button disabled={result.processing || ['queued', 'running'].includes(resultOperation?.status ?? '')}>
                                    保存してNotion同期
                                </Button>
                            </form>
                            {resultOperation && resultOperation.type !== undefined && (
                                <div className="bg-muted rounded-lg p-3 text-sm">
                                    同期状態: {resultOperation.status}
                                    {resultOperation.error_code && <span className="ml-2 text-red-600">{resultOperation.error_code}</span>}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Notion実績情報取り込み</CardTitle>
                        <CardDescription>
                            初回導入時に、過去のNotion実績を取り込むための補助機能です。通常の実績は上の実績登録から登録されるため、原則として初回のみ使用します。
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Button disabled={Boolean(importActive)} onClick={() => router.post(route('notion-syncs.store'))}>
                            {importActive && <LoaderCircle className="size-4 animate-spin" />}
                            {importActive ? '取り込み中' : 'Notion実績情報を取り込む'}
                        </Button>
                        <InputError message={page.props.errors.sync} />
                        {importOperation && (
                            <div className="bg-muted rounded-lg p-4 text-sm">
                                <p>
                                    状態: <strong>{importOperation.status}</strong>
                                </p>
                                <p>
                                    進捗: {importOperation.processed ?? 0} / {importOperation.total ?? 0}
                                </p>
                                <p>
                                    登録 {importOperation.imported ?? 0}・既存 {importOperation.skipped_existing ?? 0}・ 必須不足{' '}
                                    {importOperation.skipped_invalid ?? 0}・本文空 {importOperation.skipped_empty_body ?? 0}
                                </p>
                                {importOperation.error_code && <p className="text-red-600">エラー: {importOperation.error_code}</p>}
                                {importOperation.warnings?.map((warning) => (
                                    <p key={warning} className="text-amber-700">
                                        警告: {warning}
                                    </p>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
