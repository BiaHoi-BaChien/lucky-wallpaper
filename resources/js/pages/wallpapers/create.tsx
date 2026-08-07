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
import { LoaderCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

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
    const manualProposalForm = useForm({ target_date: targetDate, proposal_json: '', prompt_hash: '' });
    const { flash } = usePage<SharedData>().props;
    const [proposalMode, setProposalMode] = useState<ExecutionMode>('manual');
    const [proposalPrompt, setProposalPrompt] = useState<ManualPrompt>();
    const [proposalPromptLoading, setProposalPromptLoading] = useState(false);
    const [proposalPromptError, setProposalPromptError] = useState<string>();

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
        manualProposalForm.post(route('wallpapers.proposals.manual-result'));
    };

    const changeDate = (value: string) => {
        apiProposalForm.setData('target_date', value);
        manualProposalForm.setData('target_date', value);
        manualProposalForm.setData('proposal_json', '');
        manualProposalForm.setData('prompt_hash', '');
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
                                <ExecutionModeSelector value={proposalMode} onChange={setProposalMode} />
                                {proposalMode === 'manual' ? (
                                    <>
                                        <Button type="button" disabled={!analysisIsLatest || proposalPromptLoading} onClick={loadProposalPrompt}>
                                            {proposalPromptLoading && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                            {proposalPromptLoading ? '作成中' : '構図提案プロンプトを作成'}
                                        </Button>
                                        <InputError message={proposalPromptError} />
                                        {proposalPrompt && (
                                            <>
                                                <ManualPromptPanel prompt={proposalPrompt} title="構図提案プロンプト" />
                                                <form onSubmit={saveProposal} className="space-y-3 border-t pt-4">
                                                    <ManualResultField
                                                        id="proposal_json"
                                                        label="ChatGPTの構図提案JSON"
                                                        value={manualProposalForm.data.proposal_json}
                                                        onChange={(value) => manualProposalForm.setData('proposal_json', value)}
                                                        placeholder="ChatGPTから返されたJSONを貼り付けます"
                                                        fileAccept=".json,.txt,application/json,text/plain"
                                                        fileDescription="JSON・テキスト（UTF-8）、最大2,000,000文字"
                                                        maxLength={2_000_000}
                                                        error={manualProposalForm.errors.proposal_json}
                                                    />
                                                    <Button
                                                        type="submit"
                                                        disabled={
                                                            manualProposalForm.processing || manualProposalForm.data.proposal_json.trim() === ''
                                                        }
                                                    >
                                                        {manualProposalForm.processing && (
                                                            <LoaderCircle className="animate-spin" aria-hidden="true" />
                                                        )}
                                                        {manualProposalForm.processing ? '保存中' : '構図提案を保存'}
                                                    </Button>
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
                                {!analysisIsLatest && (
                                    <p className="text-muted-foreground text-sm">構図提案の前に、最新の傾向分析を完了してください。</p>
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
