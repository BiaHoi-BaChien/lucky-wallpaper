import {
    ApiConfirmationButton,
    ExecutionMode,
    ExecutionModeSelector,
    fetchManualPrompt,
    ManualPrompt,
    ManualPromptPanel,
    ManualResultField,
} from '@/components/ai-workflow-controls';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { wallpaperStateLabel } from '@/lib/wallpaper-state';
import { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ImagePlus, LoaderCircle } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

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
    analysisIsLatest,
}: {
    defaultDate: string;
    selectedDate: string;
    existing: Existing | null;
    analysisIsLatest: boolean;
}) {
    const targetDate = selectedDate || defaultDate;
    const apiProposalForm = useForm({ target_date: targetDate, api_confirmed: true });
    const manualProposalForm = useForm<{ target_date: string; proposal_json: string; prompt_hash: string; image: File | null }>({
        target_date: targetDate,
        proposal_json: '',
        prompt_hash: '',
        image: null,
    });
    const { flash } = usePage<SharedData>().props;
    const [proposalMode, setProposalMode] = useState<ExecutionMode>('manual');
    const [proposalPrompt, setProposalPrompt] = useState<ManualPrompt>();
    const [proposalPromptLoading, setProposalPromptLoading] = useState(false);
    const [proposalPromptError, setProposalPromptError] = useState<string>();
    const [imagePreview, setImagePreview] = useState<string>();
    const busy = proposalPromptLoading || manualProposalForm.processing || apiProposalForm.processing;

    useEffect(() => {
        if (!manualProposalForm.data.image) {
            setImagePreview(undefined);
            return;
        }

        const url = URL.createObjectURL(manualProposalForm.data.image);
        setImagePreview(url);
        return () => URL.revokeObjectURL(url);
    }, [manualProposalForm.data.image]);

    const loadProposalPrompt = async () => {
        setProposalPromptLoading(true);
        setProposalPromptError(undefined);
        try {
            const prompt = await fetchManualPrompt(route('wallpapers.proposals.manual-prompt', { target_date: manualProposalForm.data.target_date }));
            setProposalPrompt(prompt);
            manualProposalForm.setData('prompt_hash', prompt.prompt_hash);
        } catch (error) {
            setProposalPromptError(error instanceof Error ? error.message : 'プロンプトを取得できませんでした。');
        } finally {
            setProposalPromptLoading(false);
        }
    };

    const saveProposal = (event: FormEvent) => {
        event.preventDefault();
        manualProposalForm.post(route('wallpapers.proposals.manual-result'), { forceFormData: true, preserveScroll: 'errors' });
    };

    const changeDate = (value: string) => {
        apiProposalForm.setData('target_date', value);
        manualProposalForm.setData('target_date', value);
        manualProposalForm.setData('proposal_json', '');
        manualProposalForm.setData('prompt_hash', '');
        manualProposalForm.setData('image', null);
        manualProposalForm.clearErrors();
        apiProposalForm.clearErrors();
        setProposalPrompt(undefined);
        setProposalPromptError(undefined);
        router.get(route('wallpapers.create'), { date: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: '壁紙作成', href: route('wallpapers.create') }]}>
            <Head title="壁紙作成" />
            <div className="max-w-4xl space-y-6 p-4">
                {flash.status && <Alert>{flash.status}</Alert>}
                <Card>
                    <CardHeader>
                        <CardTitle>対象日を選択</CardTitle>
                        <CardDescription>ホーチミン時間の明日を初期表示します。既存日は新規生成せず内容を表示します。</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="target_date">対象日</Label>
                            <Input
                                id="target_date"
                                type="date"
                                disabled={busy}
                                value={manualProposalForm.data.target_date}
                                onChange={(event) => changeDate(event.target.value)}
                            />
                            <InputError message={manualProposalForm.errors.target_date ?? apiProposalForm.errors.target_date} />
                        </div>
                        {existing ? (
                            <Alert variant="warning">
                                <AlertTitle>この日付の壁紙は登録済みです。</AlertTitle>
                                <AlertDescription>
                                    <p>
                                        {existing.title ?? '構図生成待ち'}（{wallpaperStateLabel(existing.state)}）
                                    </p>
                                    <Button asChild className="mt-3">
                                        <Link href={route('wallpapers.show', { wallpaper: existing.id })}>詳細を表示</Link>
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <div className="space-y-4">
                                <ExecutionModeSelector
                                    value={proposalMode}
                                    disabled={busy}
                                    onChange={(mode) => {
                                        if (mode === proposalMode) return;
                                        setProposalMode(mode);
                                        manualProposalForm.setData('image', null);
                                    }}
                                />
                                {proposalMode === 'manual' ? (
                                    <>
                                        <p className="text-muted-foreground text-sm">
                                            1つのプロンプトで、AIに構図の説明JSONと壁紙画像を作成してもらいます。返されたJSONと画像を下のフォームで登録してください。
                                        </p>
                                        <Button type="button" disabled={busy} onClick={loadProposalPrompt}>
                                            {proposalPromptLoading ? (
                                                <LoaderCircle className="animate-spin" aria-hidden="true" />
                                            ) : (
                                                <ImagePlus aria-hidden="true" />
                                            )}
                                            {proposalPromptLoading ? '作成中' : '壁紙作成プロンプトを作成'}
                                        </Button>
                                        <InputError message={proposalPromptError} />
                                        {proposalPrompt && (
                                            <>
                                                <ManualPromptPanel prompt={proposalPrompt} title="壁紙作成プロンプト" />
                                                <form onSubmit={saveProposal} className="border-t pt-4">
                                                    <fieldset disabled={busy} className="min-w-0 space-y-4">
                                                        <legend className="mb-3 font-medium">構図JSONと壁紙画像を登録</legend>
                                                        <ManualResultField
                                                            key={manualProposalForm.data.target_date}
                                                            id="proposal_json"
                                                            label="構図の説明JSON"
                                                            value={manualProposalForm.data.proposal_json}
                                                            onChange={(value) => {
                                                                manualProposalForm.setData('proposal_json', value);
                                                                manualProposalForm.clearErrors('proposal_json');
                                                            }}
                                                            placeholder="AIから返された構図の説明JSONを貼り付けます"
                                                            fileAccept=".json,.txt,application/json,text/plain"
                                                            fileDescription="JSON・テキスト（UTF-8）、最大2,000,000文字"
                                                            maxLength={2_000_000}
                                                            error={manualProposalForm.errors.proposal_json}
                                                        />
                                                        <div className="space-y-2">
                                                            <Label htmlFor="manual_image">壁紙画像</Label>
                                                            <Input
                                                                key={manualProposalForm.data.target_date}
                                                                id="manual_image"
                                                                type="file"
                                                                accept="image/jpeg,image/png,image/webp"
                                                                onChange={(event) => {
                                                                    manualProposalForm.setData('image', event.target.files?.[0] ?? null);
                                                                    manualProposalForm.clearErrors('image');
                                                                }}
                                                            />
                                                            <p className="text-muted-foreground text-sm">JPEG・PNG・WebP、最大20MB</p>
                                                            <InputError message={manualProposalForm.errors.image} />
                                                            {imagePreview && (
                                                                <img
                                                                    src={imagePreview}
                                                                    alt="登録する壁紙画像のプレビュー"
                                                                    className="max-h-80 max-w-full rounded-md object-contain"
                                                                />
                                                            )}
                                                        </div>
                                                        <InputError message={manualProposalForm.errors.prompt_hash} />
                                                        {manualProposalForm.progress && (
                                                            <p className="text-muted-foreground text-sm" role="status">
                                                                アップロード中: {manualProposalForm.progress.percentage}%
                                                            </p>
                                                        )}
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                busy ||
                                                                manualProposalForm.data.proposal_json.trim() === '' ||
                                                                manualProposalForm.data.image === null
                                                            }
                                                        >
                                                            {manualProposalForm.processing && (
                                                                <LoaderCircle className="animate-spin" aria-hidden="true" />
                                                            )}
                                                            {manualProposalForm.processing ? '登録中' : 'JSONと画像を登録'}
                                                        </Button>
                                                    </fieldset>
                                                </form>
                                            </>
                                        )}
                                    </>
                                ) : (
                                    <ApiConfirmationButton
                                        label="APIで構図を提案"
                                        processingLabel="提案を開始中"
                                        processing={apiProposalForm.processing}
                                        disabled={!analysisIsLatest}
                                        onConfirm={() => apiProposalForm.post(route('wallpapers.proposals.store'))}
                                    />
                                )}
                                {proposalMode === 'api' && !analysisIsLatest && (
                                    <p className="text-muted-foreground text-sm">APIでの構図提案の前に、最新の傾向分析を完了してください。</p>
                                )}
                                <InputError message={apiProposalForm.errors.api_confirmed} />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
