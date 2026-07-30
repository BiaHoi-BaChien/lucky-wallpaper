import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DeleteWallpaperDialog, DeleteWallpaperImageDialog } from '@/components/wallpaper-delete-dialogs';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { proposalStatusLabel, wallpaperStateLabel } from '@/lib/wallpaper-state';
import { SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArchiveRestore, Download, ImagePlus, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

interface Proposal {
    id: number;
    sequence: number;
    status: string;
    title: string;
    art_style: string;
    conclusion: string;
    overview: string;
    composition: string;
    color_wu_xing: string;
    symbolism: string;
}

interface Wallpaper {
    id: number;
    target_date: string;
    title: string | null;
    art_style: string | null;
    conclusion: string | null;
    overview: string | null;
    composition: string | null;
    color_wu_xing: string | null;
    symbolism: string | null;
    state: string;
    warnings?: string[];
    proposals: Proposal[];
}

export default function ShowWallpaper({
    wallpaper,
    latestApiRun,
    localImageAvailable,
    downloadAvailable,
    downloadUnavailableReason,
}: {
    wallpaper: Wallpaper;
    latestApiRun: Operation | null;
    localImageAvailable: boolean;
    downloadAvailable: boolean;
    downloadUnavailableReason: string | null;
}) {
    const { flash } = usePage<SharedData>().props;
    const [restoring, setRestoring] = useState(false);
    const [restoreError, setRestoreError] = useState<string>();
    const { operation } = useOperation(latestApiRun);
    const active = operation && ['queued', 'running'].includes(operation.status);
    const current = wallpaper.proposals.find((proposal) => proposal.status === 'proposed') ?? wallpaper.proposals[0];
    const details = current ?? (hasCompositionDetails(wallpaper) ? wallpaper : null);
    const canCreateImage = !localImageAvailable && current?.status === 'proposed';
    const canRestoreImage = !localImageAvailable && current?.status !== 'proposed';
    const generateImage = () =>
        router.post(route('wallpapers.image', { wallpaper: wallpaper.id }), current ? { proposal_id: current.id } : {}, { preserveScroll: true });
    const restoreImage = () =>
        router.post(
            route('wallpapers.image.restore', { wallpaper: wallpaper.id }),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setRestoring(true);
                    setRestoreError(undefined);
                },
                onError: (errors) => setRestoreError(errors.restoreImage),
                onFinish: () => setRestoring(false),
            },
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: '壁紙履歴', href: route('wallpapers.index') },
                { title: wallpaper.target_date, href: route('wallpapers.show', { wallpaper: wallpaper.id }) },
            ]}
        >
            <Head title={`${wallpaper.target_date} 壁紙`} />
            <div className="space-y-6 p-4">
                {flash.status && <Alert>{flash.status}</Alert>}
                <div>
                    <h1 className="text-2xl font-bold">{wallpaper.target_date}</h1>
                    <p className="text-muted-foreground">状態: {wallpaperStateLabel(wallpaper.state)}</p>
                </div>
                {active && (
                    <div className="bg-muted flex items-center gap-2 rounded-lg p-4">
                        <LoaderCircle className="size-4 animate-spin" />
                        AI処理中です。画面は3秒ごとに更新されます。
                    </div>
                )}
                {operation?.status === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTitle>処理に失敗しました。</AlertTitle>
                        <AlertDescription>エラーコード: {operation.error_code}</AlertDescription>
                    </Alert>
                )}
                {details && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{details.conclusion || details.title || '構図の詳細'}</CardTitle>
                            {current && (
                                <p className="text-muted-foreground text-sm">
                                    案 #{current.sequence}・{proposalStatusLabel(current.status)}
                                </p>
                            )}
                        </CardHeader>
                        <CardContent>
                            <div
                                className={
                                    localImageAvailable ? 'grid items-start gap-6 lg:grid-cols-[minmax(16rem,28rem)_minmax(0,1fr)]' : undefined
                                }
                            >
                                {localImageAvailable && (
                                    <figure className="mx-auto w-full max-w-md">
                                        <div className="bg-muted aspect-[9/16] overflow-hidden rounded-md border">
                                            <img
                                                src={route('wallpapers.preview', { wallpaper: wallpaper.id })}
                                                alt={`${wallpaper.target_date}の壁紙「${details.title || '構図の詳細'}」のプレビュー`}
                                                className="size-full object-contain"
                                            />
                                        </div>
                                        <figcaption className="text-muted-foreground mt-2 text-center text-sm">生成済み壁紙のプレビュー</figcaption>
                                        <Button variant="outline" className="mt-3 w-full" asChild>
                                            <a href={route('wallpapers.download', { wallpaper: wallpaper.id })}>
                                                <Download aria-hidden="true" />
                                                画像をダウンロード
                                            </a>
                                        </Button>
                                    </figure>
                                )}
                                <div className="space-y-5">
                                    <Section
                                        title={!current && details.overview === details.composition ? '構図の詳細' : '概要'}
                                        body={details.overview}
                                    />
                                    {details.composition !== details.overview && <Section title="配置" body={details.composition} />}
                                    <Section title="色彩・五行" body={details.color_wu_xing} />
                                    <Section title="象徴意図" body={details.symbolism} />
                                    <div className="flex flex-wrap gap-3">
                                        {canCreateImage && (
                                            <Button disabled={Boolean(active)} onClick={generateImage}>
                                                <ImagePlus aria-hidden="true" />
                                                この構図で画像を作成
                                            </Button>
                                        )}
                                        {canRestoreImage && (
                                            <Button disabled={Boolean(active) || restoring} onClick={restoreImage}>
                                                {restoring ? (
                                                    <LoaderCircle className="animate-spin" aria-hidden="true" />
                                                ) : (
                                                    <ArchiveRestore aria-hidden="true" />
                                                )}
                                                {restoring ? '復元中' : 'Notionから画像を復元'}
                                            </Button>
                                        )}
                                        {!localImageAvailable && downloadAvailable && (
                                            <Button variant="outline" asChild>
                                                <a href={route('wallpapers.download', { wallpaper: wallpaper.id })}>
                                                    <Download aria-hidden="true" />
                                                    Notionバックアップからダウンロード
                                                </a>
                                            </Button>
                                        )}
                                        {current?.status === 'proposed' && (
                                            <Button
                                                variant="outline"
                                                disabled={Boolean(active)}
                                                onClick={() => router.post(route('wallpapers.repropose', { wallpaper: wallpaper.id }))}
                                            >
                                                再提案
                                            </Button>
                                        )}
                                    </div>
                                    <InputError message={restoreError} />
                                    {downloadUnavailableReason && (
                                        <Alert variant="warning">
                                            <AlertTitle>画像をダウンロードできません。</AlertTitle>
                                            <AlertDescription>{downloadUnavailableReason}</AlertDescription>
                                        </Alert>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}
                {!details && !active && (
                    <div className="space-y-3">
                        <div className="flex flex-wrap gap-3">
                            <Button onClick={() => router.post(route('wallpapers.repropose', { wallpaper: wallpaper.id }))}>構図提案を再試行</Button>
                            {canRestoreImage && (
                                <Button disabled={restoring} onClick={restoreImage}>
                                    {restoring ? <LoaderCircle className="animate-spin" aria-hidden="true" /> : <ArchiveRestore aria-hidden="true" />}
                                    {restoring ? '復元中' : 'Notionから画像を復元'}
                                </Button>
                            )}
                            {!localImageAvailable && downloadAvailable && (
                                <Button variant="outline" asChild>
                                    <a href={route('wallpapers.download', { wallpaper: wallpaper.id })}>
                                        <Download aria-hidden="true" />
                                        Notionバックアップからダウンロード
                                    </a>
                                </Button>
                            )}
                        </div>
                        <InputError message={restoreError} />
                    </div>
                )}
                {wallpaper.warnings && wallpaper.warnings.length > 0 && (
                    <Alert role="note" variant="warning">
                        <AlertTitle>注意事項</AlertTitle>
                        <AlertDescription className="space-y-1">
                            {wallpaper.warnings.map((warning) => (
                                <p key={warning}>{warning}</p>
                            ))}
                        </AlertDescription>
                    </Alert>
                )}
                <div className="flex flex-wrap justify-end gap-3 border-t pt-6">
                    {localImageAvailable && <DeleteWallpaperImageDialog wallpaper={wallpaper} />}
                    <DeleteWallpaperDialog wallpaper={wallpaper} label="履歴を削除" />
                </div>
            </div>
        </AppLayout>
    );
}

function hasCompositionDetails(wallpaper: Wallpaper): boolean {
    return [wallpaper.title, wallpaper.conclusion, wallpaper.overview, wallpaper.composition, wallpaper.color_wu_xing, wallpaper.symbolism].some(
        (value) => value !== null && value.trim() !== '',
    );
}

function Section({ title, body }: { title: string; body: string | null }) {
    if (body === null || body.trim() === '') {
        return null;
    }

    return (
        <section>
            <h2 className="mb-1 font-semibold">{title}</h2>
            <p className="text-sm leading-6 whitespace-pre-wrap">{body}</p>
        </section>
    );
}
