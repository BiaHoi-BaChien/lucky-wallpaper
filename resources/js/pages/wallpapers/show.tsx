import { fetchManualPrompt, ManualPrompt, ManualPromptPanel } from '@/components/ai-workflow-controls';
import { ClipboardCopyButton } from '@/components/clipboard-copy-button';
import InputError from '@/components/input-error';
import { Kigaku, KigakuDetails } from '@/components/kigaku-details';
import { ManualWallpaperForm } from '@/components/manual-wallpaper-form';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { DeleteWallpaperDialog, DeleteWallpaperImageDialog } from '@/components/wallpaper-delete-dialogs';
import AppLayout from '@/layouts/app-layout';
import { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArchiveRestore, ChevronLeft, ChevronRight, Download, LoaderCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Composition {
    title: string | null;
    art_style: string | null;
    conclusion: string | null;
    overview: string | null;
    composition: string | null;
    color_wu_xing: string | null;
    symbolism: string | null;
}
interface Proposal extends Composition {
    id: number;
    status: string;
}
interface Wallpaper extends Composition {
    id: number;
    target_date: string;
    chosen_proposal_id: number | null;
    proposals: Proposal[];
    prize_vnd: number | null;
    purchase_count: number | null;
    warnings?: string[];
}
export default function ShowWallpaper({
    wallpaper,
    kigaku,
    directionLabel,
    localImageAvailable,
    downloadAvailable,
    downloadUnavailableReason,
    previousWallpaperId,
    nextWallpaperId,
}: {
    wallpaper: Wallpaper;
    kigaku: Kigaku | null;
    directionLabel: string;
    localImageAvailable: boolean;
    downloadAvailable: boolean;
    downloadUnavailableReason: string | null;
    previousWallpaperId: number | null;
    nextWallpaperId: number | null;
}) {
    const { flash, errors } = usePage<SharedData>().props;
    const selected = wallpaper.proposals.find((p) => p.id === wallpaper.chosen_proposal_id && p.status === 'approved');
    const current = selected ?? (!localImageAvailable ? wallpaper.proposals.find((p) => p.status === 'proposed') : undefined);
    const details = current ?? wallpaper;
    const hasComposition = [details.title, details.overview, details.composition, details.symbolism].some((value) => value?.trim());
    const [imagePrompt, setImagePrompt] = useState<ManualPrompt>();
    const [promptError, setPromptError] = useState<string>();
    const [loading, setLoading] = useState(false);
    const [restoring, setRestoring] = useState(false);
    const imageForm = useForm<{ proposal_id: number | null; prompt_hash: string; image: File | null }>({
        proposal_id: current?.id ?? null,
        prompt_hash: '',
        image: null,
    });
    const compositionText = [details.conclusion, details.overview, details.composition, details.color_wu_xing, details.symbolism]
        .filter(Boolean)
        .join('\n\n');
    const loadImagePrompt = async () => {
        setLoading(true);
        setPromptError(undefined);
        try {
            const prompt = await fetchManualPrompt(route('wallpapers.image.manual-prompt', { wallpaper: wallpaper.id, proposal_id: current?.id }));
            setImagePrompt(prompt);
            imageForm.setData('prompt_hash', prompt.prompt_hash);
        } catch (error) {
            setPromptError(error instanceof Error ? error.message : 'プロンプトを取得できませんでした。');
        } finally {
            setLoading(false);
        }
    };
    const saveImage = (event: FormEvent) => {
        event.preventDefault();
        imageForm.post(route('wallpapers.image.manual-result', { wallpaper: wallpaper.id }), { forceFormData: true, preserveScroll: 'errors' });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: '壁紙履歴', href: route('wallpapers.index') },
                { title: wallpaper.target_date, href: route('wallpapers.show', { wallpaper: wallpaper.id }) },
            ]}
        >
            <Head title={details.title ?? '壁紙の詳細'} />
            <div className="min-w-0 space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{details.title ?? '構図名未設定'}</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {wallpaper.target_date}・{directionLabel}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {previousWallpaperId !== null && (
                            <Button asChild variant="outline">
                                <Link href={route('wallpapers.show', { wallpaper: previousWallpaperId })}>
                                    <ChevronLeft aria-hidden="true" />
                                    前の壁紙
                                </Link>
                            </Button>
                        )}
                        {nextWallpaperId !== null && (
                            <Button asChild variant="outline">
                                <Link href={route('wallpapers.show', { wallpaper: nextWallpaperId })}>
                                    次の壁紙
                                    <ChevronRight aria-hidden="true" />
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
                {flash.status && <Alert>{flash.status}</Alert>}
                <div className="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                    <div className="space-y-4">
                        {downloadAvailable && (
                            <>
                                <img
                                    src={route('wallpapers.preview', { wallpaper: wallpaper.id })}
                                    alt={wallpaper.target_date + 'の壁紙'}
                                    className="mx-auto aspect-[9/16] w-full max-w-sm rounded-md border object-contain"
                                />
                                <Button asChild>
                                    <a href={route('wallpapers.download', { wallpaper: wallpaper.id })}>
                                        <Download aria-hidden="true" />
                                        壁紙をダウンロード
                                    </a>
                                </Button>
                            </>
                        )}
                        {downloadUnavailableReason && <Alert variant="warning">{downloadUnavailableReason}</Alert>}
                        {kigaku?.policy_version === 'lucky-wallpaper-v1' && (
                            <Button asChild variant="outline">
                                <a href={route('wallpapers.composition.download', { wallpaper: wallpaper.id })}>
                                    <Download aria-hidden="true" />
                                    説明JSONをダウンロード
                                </a>
                            </Button>
                        )}
                        <section className="space-y-2 border-t pt-4">
                            <h2 className="font-semibold">宝くじ実績</h2>
                            <p>賞金: {wallpaper.prize_vnd === null ? '未登録' : wallpaper.prize_vnd.toLocaleString() + ' VND'}</p>
                            <p>購入口数: {wallpaper.purchase_count === null ? '未登録' : wallpaper.purchase_count.toLocaleString() + '口'}</p>
                            <Button asChild variant="outline">
                                <Link href={route('results.index', { date: wallpaper.target_date })}>実績を登録・編集</Link>
                            </Button>
                        </section>
                    </div>
                    <div className="min-w-0 space-y-5">
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold">構図の説明</h2>
                            <ClipboardCopyButton value={compositionText} label="構図の説明" />
                        </div>
                        <Section title="画風" body={details.art_style} />
                        <Section title="結論" body={details.conclusion} />
                        <Section title="概要" body={details.overview} />
                        <Section title="配置" body={details.composition} />
                        <Section title="色彩" body={details.color_wu_xing} />
                        <Section title="象徴意図" body={details.symbolism} />
                        <KigakuDetails value={kigaku} />
                    </div>
                </div>
                {!localImageAvailable && (
                    <div className="max-w-4xl space-y-5 border-t pt-5">
                        {downloadAvailable && (
                            <>
                                <Button
                                    variant="outline"
                                    disabled={restoring}
                                    onClick={() =>
                                        router.post(
                                            route('wallpapers.image.restore', { wallpaper: wallpaper.id }),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onStart: () => setRestoring(true),
                                                onFinish: () => setRestoring(false),
                                            },
                                        )
                                    }
                                >
                                    {restoring ? <LoaderCircle className="animate-spin" aria-hidden="true" /> : <ArchiveRestore aria-hidden="true" />}
                                    Notionから画像を復元
                                </Button>
                                <InputError message={errors.restoreImage} />
                            </>
                        )}
                        {hasComposition && (
                            <details className="space-y-3">
                                <summary className="cursor-pointer font-medium">保存済み構図で画像を作成・登録</summary>
                                <Button type="button" disabled={loading} onClick={() => void loadImagePrompt()}>
                                    {loading ? '作成中' : '画像作成プロンプトを取得'}
                                </Button>
                                <InputError message={promptError} />
                                {imagePrompt && <ManualPromptPanel prompt={imagePrompt} title="画像作成プロンプト" />}
                                <form onSubmit={saveImage} className="space-y-3">
                                    <Label htmlFor="replacement_image">登録する画像</Label>
                                    <Input
                                        id="replacement_image"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={(event) => imageForm.setData('image', event.target.files?.[0] ?? null)}
                                    />
                                    <p className="text-muted-foreground text-sm">JPEG・PNG・WebP、最大20MB</p>
                                    <InputError message={imageForm.errors.image ?? imageForm.errors.prompt_hash} />
                                    <Button type="submit" disabled={imageForm.processing || imageForm.data.image === null}>
                                        画像を登録
                                    </Button>
                                </form>
                            </details>
                        )}
                        <details className="space-y-4">
                            <summary className="cursor-pointer font-medium">新しい構図と壁紙を作成・登録</summary>
                            <ManualWallpaperForm
                                key={wallpaper.id + '-' + (wallpaper.chosen_proposal_id ?? '')}
                                targetDate={wallpaper.target_date}
                                wallpaperId={wallpaper.id}
                            />
                        </details>
                    </div>
                )}
                {wallpaper.warnings?.map((warning) => (
                    <Alert key={warning} variant="warning">
                        {warning}
                    </Alert>
                ))}
                <div className="flex flex-wrap justify-end gap-3 border-t pt-5">
                    {localImageAvailable && <DeleteWallpaperImageDialog wallpaper={wallpaper} />}
                    <DeleteWallpaperDialog wallpaper={wallpaper} label="履歴を削除" />
                </div>
            </div>
        </AppLayout>
    );
}
function Section({ title, body }: { title: string; body: string | null }) {
    return body ? (
        <section className="text-sm">
            <h3 className="font-medium">{title}</h3>
            <p className="mt-1 break-words whitespace-pre-wrap">{body}</p>
        </section>
    ) : null;
}
