import {
    ApiConfirmationButton,
    ExecutionMode,
    ExecutionModeSelector,
    fetchManualPrompt,
    ManualPrompt,
    ManualPromptPanel,
} from '@/components/ai-workflow-controls';
import { ClipboardCopyButton } from '@/components/clipboard-copy-button';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { DeleteWallpaperDialog, DeleteWallpaperImageDialog } from '@/components/wallpaper-delete-dialogs';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { proposalStatusLabel, wallpaperStateLabel } from '@/lib/wallpaper-state';
import { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArchiveRestore, Download, LoaderCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

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
    const { operation } = useOperation(latestApiRun);
    const active = operation && ['queued', 'running'].includes(operation.status);
    const current = wallpaper.proposals.find((proposal) => proposal.status === 'proposed') ?? wallpaper.proposals[0];
    const details = current ?? (hasCompositionDetails(wallpaper) ? wallpaper : null);
    const displayedTitle = details?.conclusion || details?.title || '構図の詳細';
    const compositionDetails = details ? formatCompositionDetails(details) : '';
    const canCreateImage = !localImageAvailable && current?.status === 'proposed';
    const canRestoreImage = !localImageAvailable && current?.status !== 'proposed';
    const [restoring, setRestoring] = useState(false);
    const [restoreError, setRestoreError] = useState<string>();
    const [proposalMode, setProposalMode] = useState<ExecutionMode>('manual');
    const [imageMode, setImageMode] = useState<ExecutionMode>('manual');
    const [proposalPrompt, setProposalPrompt] = useState<ManualPrompt>();
    const [imagePrompt, setImagePrompt] = useState<ManualPrompt>();
    const [proposalPromptLoading, setProposalPromptLoading] = useState(false);
    const [imagePromptLoading, setImagePromptLoading] = useState(false);
    const [proposalPromptError, setProposalPromptError] = useState<string>();
    const [imagePromptError, setImagePromptError] = useState<string>();
    const proposalForm = useForm({ proposal_json: '', prompt_hash: '' });
    const imageForm = useForm<{ proposal_id: number | null; prompt_hash: string | null; image: File | null }>({
        proposal_id: current?.id ?? null,
        prompt_hash: null,
        image: null,
    });

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

    const loadProposalPrompt = async () => {
        setProposalPromptLoading(true);
        setProposalPromptError(undefined);
        try {
            const prompt = await fetchManualPrompt(route('wallpapers.reproposal.manual-prompt', { wallpaper: wallpaper.id }));
            setProposalPrompt(prompt);
            proposalForm.setData('prompt_hash', prompt.prompt_hash);
        } catch (error) {
            setProposalPromptError(error instanceof Error ? error.message : 'プロンプトを取得できませんでした。');
        } finally {
            setProposalPromptLoading(false);
        }
    };

    const saveProposal = (event: FormEvent) => {
        event.preventDefault();
        proposalForm.post(route('wallpapers.reproposal.manual-result', { wallpaper: wallpaper.id }), { preserveScroll: true });
    };

    const loadImagePrompt = async () => {
        setImagePromptLoading(true);
        setImagePromptError(undefined);
        try {
            const prompt = await fetchManualPrompt(
                route('wallpapers.image.manual-prompt', {
                    wallpaper: wallpaper.id,
                    proposal_id: current?.id,
                }),
            );
            setImagePrompt(prompt);
            imageForm.setData('prompt_hash', prompt.prompt_hash);
        } catch (error) {
            setImagePromptError(error instanceof Error ? error.message : 'プロンプトを取得できませんでした。');
        } finally {
            setImagePromptLoading(false);
        }
    };

    const saveImage = (event: FormEvent) => {
        event.preventDefault();
        imageForm.post(route('wallpapers.image.manual-result', { wallpaper: wallpaper.id }), {
            preserveScroll: true,
            forceFormData: true,
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: '壁紙履歴', href: route('wallpapers.index') },
                { title: wallpaper.target_date, href: route('wallpapers.show', { wallpaper: wallpaper.id }) },
            ]}
        >
            <Head title={`${wallpaper.target_date} 壁紙`} />
            <div className="max-w-6xl space-y-6 p-4">
                {flash.status && <Alert>{flash.status}</Alert>}
                <div>
                    <h1 className="text-2xl font-bold">{wallpaper.target_date}</h1>
                    <p className="text-muted-foreground">状態: {wallpaperStateLabel(wallpaper.state)}</p>
                </div>
                {active && (
                    <div className="bg-muted flex items-center gap-2 rounded-lg p-4">
                        <LoaderCircle className="size-4 animate-spin" />
                        API処理中です。画面は3秒ごとに更新されます。
                    </div>
                )}
                {operation?.status === 'failed' && (
                    <Alert variant="destructive">
                        <AlertTitle>API処理に失敗しました。</AlertTitle>
                        <AlertDescription>エラーコード: {operation.error_code}</AlertDescription>
                    </Alert>
                )}
                {details && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-start justify-between gap-3">
                                <CardTitle className="min-w-0 break-words">{displayedTitle}</CardTitle>
                                <ClipboardCopyButton value={displayedTitle} label="壁紙のタイトル" className="-m-2" />
                            </div>
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
                                        <figcaption className="text-muted-foreground mt-2 text-center text-sm">保存済み壁紙のプレビュー</figcaption>
                                        <Button variant="outline" className="mt-3 w-full" asChild>
                                            <a href={route('wallpapers.download', { wallpaper: wallpaper.id })}>
                                                <Download aria-hidden="true" />
                                                画像をダウンロード
                                            </a>
                                        </Button>
                                    </figure>
                                )}
                                <div className="space-y-5">
                                    <div className="flex items-center justify-between gap-3">
                                        <h2 className="font-semibold">構図の詳細</h2>
                                        {compositionDetails !== '' && (
                                            <ClipboardCopyButton value={compositionDetails} label="構図の詳細" className="-m-2" />
                                        )}
                                    </div>
                                    <Section
                                        title={!current && details.overview === details.composition ? undefined : '概要'}
                                        body={details.overview}
                                    />
                                    {details.composition !== details.overview && <Section title="配置" body={details.composition} />}
                                    <Section title="色彩・五行" body={details.color_wu_xing} />
                                    <Section title="象徴意図" body={details.symbolism} />

                                    {canCreateImage && (
                                        <section className="space-y-4 border-t pt-4">
                                            <div className="space-y-2">
                                                <h3 className="font-semibold">画像作成</h3>
                                                <ExecutionModeSelector value={imageMode} onChange={setImageMode} disabled={Boolean(active)} />
                                            </div>
                                            {imageMode === 'manual' ? (
                                                <>
                                                    <Button type="button" disabled={Boolean(active) || imagePromptLoading} onClick={loadImagePrompt}>
                                                        {imagePromptLoading && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                                        {imagePromptLoading ? '作成中' : '画像作成プロンプトを作成'}
                                                    </Button>
                                                    <InputError message={imagePromptError} />
                                                    {imagePrompt && <ManualPromptPanel prompt={imagePrompt} title="画像作成プロンプト" />}
                                                    <form onSubmit={saveImage} className="space-y-3 border-t pt-4">
                                                        <div className="space-y-2">
                                                            <Label htmlFor="manual_image">登録する画像</Label>
                                                            <Input
                                                                id="manual_image"
                                                                type="file"
                                                                accept="image/jpeg,image/png,image/webp"
                                                                onChange={(event) => imageForm.setData('image', event.target.files?.[0] ?? null)}
                                                            />
                                                            <p className="text-muted-foreground text-sm">JPEG・PNG・WebP、最大20MB</p>
                                                            <InputError message={imageForm.errors.image} />
                                                        </div>
                                                        <Button type="submit" disabled={imageForm.processing || imageForm.data.image === null}>
                                                            {imageForm.processing && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                                            {imageForm.processing ? '登録中' : '画像を登録'}
                                                        </Button>
                                                    </form>
                                                </>
                                            ) : (
                                                <ApiConfirmationButton
                                                    label="APIで画像を作成"
                                                    processingLabel="画像作成中"
                                                    processing={Boolean(active)}
                                                    onConfirm={() =>
                                                        router.post(
                                                            route('wallpapers.image', { wallpaper: wallpaper.id }),
                                                            { proposal_id: current.id, api_confirmed: true },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                />
                                            )}
                                        </section>
                                    )}

                                    <div className="flex flex-wrap gap-3">
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

                {!localImageAvailable && (
                    <section className="space-y-4 border-t pt-6">
                        <div className="space-y-2">
                            <h2 className="text-lg font-semibold">{details ? '構図を再提案' : '構図提案を再試行'}</h2>
                            <ExecutionModeSelector value={proposalMode} onChange={setProposalMode} disabled={Boolean(active)} />
                        </div>
                        {proposalMode === 'manual' ? (
                            <>
                                <Button type="button" disabled={Boolean(active) || proposalPromptLoading} onClick={loadProposalPrompt}>
                                    {proposalPromptLoading && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                    {proposalPromptLoading ? '作成中' : '構図提案プロンプトを作成'}
                                </Button>
                                <InputError message={proposalPromptError} />
                                {proposalPrompt && (
                                    <>
                                        <ManualPromptPanel prompt={proposalPrompt} title="構図提案プロンプト" />
                                        <form onSubmit={saveProposal} className="space-y-3 border-t pt-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="proposal_json">ChatGPTの構図提案JSON</Label>
                                                <Textarea
                                                    id="proposal_json"
                                                    rows={12}
                                                    value={proposalForm.data.proposal_json}
                                                    onChange={(event) => proposalForm.setData('proposal_json', event.target.value)}
                                                    placeholder="ChatGPTから返されたJSONを貼り付けます"
                                                />
                                                <InputError message={proposalForm.errors.proposal_json} />
                                            </div>
                                            <Button type="submit" disabled={proposalForm.processing || proposalForm.data.proposal_json.trim() === ''}>
                                                {proposalForm.processing && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                                {proposalForm.processing ? '保存中' : '構図提案を保存'}
                                            </Button>
                                        </form>
                                    </>
                                )}
                            </>
                        ) : (
                            <ApiConfirmationButton
                                label={details ? 'APIで再提案' : 'APIで構図提案を再試行'}
                                processingLabel="提案中"
                                processing={Boolean(active)}
                                onConfirm={() =>
                                    router.post(
                                        route('wallpapers.repropose', { wallpaper: wallpaper.id }),
                                        { api_confirmed: true },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        )}
                    </section>
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
                {!active && (
                    <div className="flex flex-wrap justify-end gap-3 border-t pt-6">
                        {localImageAvailable && <DeleteWallpaperImageDialog wallpaper={wallpaper} />}
                        <DeleteWallpaperDialog wallpaper={wallpaper} label="履歴を削除" />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function hasCompositionDetails(wallpaper: Wallpaper): boolean {
    return [wallpaper.title, wallpaper.conclusion, wallpaper.overview, wallpaper.composition, wallpaper.color_wu_xing, wallpaper.symbolism].some(
        (value) => value !== null && value.trim() !== '',
    );
}

function formatCompositionDetails(details: Proposal | Wallpaper): string {
    const sections = [
        details.overview?.trim() ? `${details.overview === details.composition ? '構図の詳細' : '概要'}\n${details.overview.trim()}` : '',
        details.composition?.trim() && details.composition !== details.overview ? `配置\n${details.composition.trim()}` : '',
        details.color_wu_xing?.trim() ? `色彩・五行\n${details.color_wu_xing.trim()}` : '',
        details.symbolism?.trim() ? `象徴意図\n${details.symbolism.trim()}` : '',
    ];

    return sections.filter((section) => section !== '').join('\n\n');
}

function Section({ title, body }: { title?: string; body: string | null }) {
    if (body === null || body.trim() === '') {
        return null;
    }

    return (
        <section>
            {title && <h3 className="mb-1 font-semibold">{title}</h3>}
            <p className="text-sm leading-6 whitespace-pre-wrap">{body}</p>
        </section>
    );
}
