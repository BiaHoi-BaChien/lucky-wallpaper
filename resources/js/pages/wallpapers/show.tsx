import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { proposalStatusLabel, wallpaperStateLabel } from '@/lib/wallpaper-state';
import { Head, router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

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

export default function ShowWallpaper({ wallpaper, latestApiRun }: { wallpaper: Wallpaper; latestApiRun: Operation | null }) {
    const { operation } = useOperation(latestApiRun);
    const active = operation && ['queued', 'running'].includes(operation.status);
    const current = wallpaper.proposals.find((proposal) => proposal.status === 'proposed') ?? wallpaper.proposals[0];

    return (
        <AppLayout
            breadcrumbs={[
                { title: '壁紙履歴', href: route('wallpapers.index') },
                { title: wallpaper.target_date, href: route('wallpapers.show', { wallpaper: wallpaper.id }) },
            ]}
        >
            <Head title={`${wallpaper.target_date} 壁紙`} />
            <div className="space-y-6 p-4">
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
                {current && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{current.conclusion}</CardTitle>
                            <p className="text-muted-foreground text-sm">
                                案 #{current.sequence}・{proposalStatusLabel(current.status)}
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <Section title="概要" body={current.overview} />
                            <Section title="配置" body={current.composition} />
                            <Section title="色彩・五行" body={current.color_wu_xing} />
                            <Section title="象徴意図" body={current.symbolism} />
                            <div className="flex flex-wrap gap-3">
                                {current.status === 'proposed' && (
                                    <>
                                        <Button
                                            disabled={Boolean(active)}
                                            onClick={() =>
                                                router.post(route('wallpapers.image', { wallpaper: wallpaper.id }), { proposal_id: current.id })
                                            }
                                        >
                                            この構図で画像を作成
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={Boolean(active)}
                                            onClick={() => router.post(route('wallpapers.repropose', { wallpaper: wallpaper.id }))}
                                        >
                                            再提案
                                        </Button>
                                    </>
                                )}
                                {current.status === 'approved' && wallpaper.state !== 'generated' && (
                                    <Button
                                        disabled={Boolean(active)}
                                        onClick={() =>
                                            router.post(route('wallpapers.image', { wallpaper: wallpaper.id }), { proposal_id: current.id })
                                        }
                                    >
                                        画像生成を再試行
                                    </Button>
                                )}
                                {['generated', 'archived', 'result_synced'].includes(wallpaper.state) && (
                                    <Button variant="outline" asChild>
                                        <a href={route('wallpapers.download', { wallpaper: wallpaper.id })}>画像をダウンロード</a>
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}
                {!current && !active && (
                    <Button onClick={() => router.post(route('wallpapers.repropose', { wallpaper: wallpaper.id }))}>構図提案を再試行</Button>
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
            </div>
        </AppLayout>
    );
}

function Section({ title, body }: { title: string; body: string }) {
    return (
        <section>
            <h2 className="mb-1 font-semibold">{title}</h2>
            <p className="text-sm leading-6 whitespace-pre-wrap">{body}</p>
        </section>
    );
}
