import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { wallpaperStateLabel } from '@/lib/wallpaper-state';
import { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
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
    imageAvailable,
    latestRun,
}: {
    selectedDate: string;
    defaultDate: string;
    wallpaper: Wallpaper | null;
    imageAvailable: boolean;
    latestRun: Operation | null;
}) {
    const search = useForm({ date: selectedDate || defaultDate });
    const result = useForm({ prize_vnd: wallpaper?.prize_vnd?.toString() ?? '' });
    const { operation: resultOperation } = useOperation(latestRun);
    const { integrations, flash } = usePage<SharedData>().props;
    const notionConfigured = integrations.notion.configured;

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
                {flash.status && <Alert>{flash.status}</Alert>}
                <Card>
                    <CardHeader>
                        <CardTitle>対象日の検索</CardTitle>
                        <CardDescription>登録済み壁紙を日付で取得します。</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={find} className="flex flex-col gap-3 sm:flex-row">
                            <Input type="date" value={search.data.date} onChange={(e) => search.setData('date', e.target.value)} required />
                            <Button className="w-full sm:w-auto">検索</Button>
                        </form>
                    </CardContent>
                </Card>

                {selectedDate && !wallpaper && (
                    <Alert>
                        <AlertTitle>壁紙が見つかりません。</AlertTitle>
                        <AlertDescription>選択した日付には壁紙が登録されていません。</AlertDescription>
                    </Alert>
                )}
                {wallpaper && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{wallpaper.title ?? '構図名未設定'}</CardTitle>
                            <CardDescription>
                                {wallpaper.target_date}・{wallpaper.art_style ?? '画風未設定'}・{wallpaperStateLabel(wallpaper.state)}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {imageAvailable && (
                                <figure className="mx-auto w-full max-w-md">
                                    <div className="bg-muted aspect-[9/16] overflow-hidden rounded-md border">
                                        <img
                                            src={route('wallpapers.preview', { wallpaper: wallpaper.id })}
                                            alt={`${wallpaper.target_date}の壁紙「${wallpaper.title ?? '構図名未設定'}」のプレビュー`}
                                            className="size-full object-contain"
                                        />
                                    </div>
                                    <figcaption className="text-muted-foreground mt-2 text-center text-sm">登録済み壁紙のプレビュー</figcaption>
                                </figure>
                            )}
                            <p className="text-sm whitespace-pre-wrap">{wallpaper.composition}</p>
                            <p className="text-muted-foreground text-sm">
                                {notionConfigured
                                    ? '実績をサーバーに保存し、Notionにもバックアップします。'
                                    : '実績はサーバーに保存します。NotionバックアップはNOTION_TOKEN未設定のため実行されません。'}
                            </p>
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
                                    {notionConfigured ? '保存してバックアップ' : 'サーバーに保存'}
                                </Button>
                            </form>
                            {resultOperation && resultOperation.type !== undefined && (
                                <div className="bg-muted rounded-lg p-3 text-sm">
                                    バックアップ状態: {resultOperation.status}
                                    {resultOperation.error_code && (
                                        <span className="ml-2 text-red-600 dark:text-red-400">{resultOperation.error_code}</span>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
