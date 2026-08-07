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
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { wallpaperStateLabel } from '@/lib/wallpaper-state';
import { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, LoaderCircle } from 'lucide-react';
import { FormEvent, ReactNode, useState } from 'react';

interface Existing {
    id: number;
    target_date: string;
    title: string | null;
    state: string;
}

interface Analysis {
    id: number;
    markdown: string;
    is_latest: boolean;
    created_at: string | null;
    statistics: {
        records?: number;
        max_prize_vnd?: number | null;
        high_prize_threshold_vnd?: number;
    } | null;
}

export default function CreateWallpaper({
    defaultDate,
    selectedDate,
    existing,
    analysis,
    latestAnalysisRun,
}: {
    defaultDate: string;
    selectedDate: string;
    existing: Existing | null;
    analysis: Analysis | null;
    latestAnalysisRun: Operation | null;
}) {
    const targetDate = selectedDate || defaultDate;
    const apiProposalForm = useForm({ target_date: targetDate, api_confirmed: true });
    const apiAnalysisForm = useForm({ api_confirmed: true });
    const manualAnalysisForm = useForm({ analysis_markdown: '', prompt_hash: '', prompt_date: '' });
    const manualProposalForm = useForm({ target_date: targetDate, proposal_json: '', prompt_hash: '' });
    const { flash } = usePage<SharedData>().props;
    const { operation: analysisOperation } = useOperation(latestAnalysisRun);
    const analysisActive = ['queued', 'running'].includes(analysisOperation?.status ?? '');
    const analysisIsLatest = analysis?.is_latest ?? false;
    const [analysisExpanded, setAnalysisExpanded] = useState(true);
    const [analysisMode, setAnalysisMode] = useState<ExecutionMode>('manual');
    const [proposalMode, setProposalMode] = useState<ExecutionMode>('manual');
    const [analysisPrompt, setAnalysisPrompt] = useState<ManualPrompt>();
    const [proposalPrompt, setProposalPrompt] = useState<ManualPrompt>();
    const [analysisPromptLoading, setAnalysisPromptLoading] = useState(false);
    const [proposalPromptLoading, setProposalPromptLoading] = useState(false);
    const [analysisPromptError, setAnalysisPromptError] = useState<string>();
    const [proposalPromptError, setProposalPromptError] = useState<string>();

    const loadAnalysisPrompt = async () => {
        setAnalysisPromptLoading(true);
        setAnalysisPromptError(undefined);
        try {
            const prompt = await fetchManualPrompt(route('wallpaper-analyses.manual-prompt'));
            setAnalysisPrompt(prompt);
            manualAnalysisForm.setData({
                analysis_markdown: prompt.default_result ?? '',
                prompt_hash: prompt.prompt_hash,
                prompt_date: prompt.prompt_date ?? '',
            });
        } catch (error) {
            setAnalysisPromptError(error instanceof Error ? error.message : 'プロンプトを取得できませんでした。');
        } finally {
            setAnalysisPromptLoading(false);
        }
    };

    const saveAnalysis = (event: FormEvent) => {
        event.preventDefault();
        manualAnalysisForm.post(route('wallpaper-analyses.manual-result'), { preserveScroll: true });
    };

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
                    <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
                        <div className="min-w-0 space-y-1.5">
                            <CardTitle>高額当選壁紙の傾向分析</CardTitle>
                            {analysisExpanded && (
                                <CardDescription>当選金額が登録された壁紙履歴を比較し、上位25%の構図傾向をMarkdownで保存します。</CardDescription>
                            )}
                        </div>
                        <TooltipProvider delayDuration={300}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="-m-2 shrink-0"
                                        aria-expanded={analysisExpanded}
                                        aria-controls="wallpaper-analysis-content"
                                        aria-label={analysisExpanded ? '傾向分析を閉じる' : '傾向分析を開く'}
                                        onClick={() => setAnalysisExpanded((expanded) => !expanded)}
                                    >
                                        <ChevronDown className={`transition-transform duration-200 ${analysisExpanded ? 'rotate-180' : ''}`} />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>{analysisExpanded ? '傾向分析を閉じる' : '傾向分析を開く'}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </CardHeader>
                    {analysisExpanded && (
                        <CardContent id="wallpaper-analysis-content" className="space-y-4">
                            <ExecutionModeSelector value={analysisMode} onChange={setAnalysisMode} disabled={analysisActive} />
                            <div className="flex flex-wrap items-center gap-3">
                                {analysisMode === 'manual' ? (
                                    <Button type="button" disabled={analysisActive || analysisPromptLoading} onClick={loadAnalysisPrompt}>
                                        {analysisPromptLoading && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                        {analysisPromptLoading ? '作成中' : analysis ? '再分析プロンプトを作成' : '分析プロンプトを作成'}
                                    </Button>
                                ) : (
                                    <ApiConfirmationButton
                                        label={analysis ? 'APIで再分析' : 'APIで傾向分析'}
                                        processingLabel="傾向分析中"
                                        processing={analysisActive || apiAnalysisForm.processing}
                                        onConfirm={() =>
                                            apiAnalysisForm.post(route('wallpaper-analyses.store'), {
                                                preserveScroll: true,
                                            })
                                        }
                                    />
                                )}
                                {analysis && (
                                    <span className="text-muted-foreground text-sm">
                                        対象 {analysis.statistics?.records ?? 0}件
                                        {analysis.created_at && `・更新 ${new Date(analysis.created_at).toLocaleString('ja-JP')}`}
                                    </span>
                                )}
                            </div>
                            <InputError message={analysisPromptError ?? apiAnalysisForm.errors.api_confirmed} />

                            {analysisPrompt && analysisMode === 'manual' && (
                                <>
                                    <ManualPromptPanel
                                        prompt={analysisPrompt}
                                        title="傾向分析プロンプト"
                                        dataDownloadUrl={route('wallpaper-analyses.manual-data', {
                                            prompt_date: analysisPrompt.prompt_date,
                                        })}
                                    />
                                    <form onSubmit={saveAnalysis} className="space-y-3 border-t pt-4">
                                        <ManualResultField
                                            id="analysis_markdown"
                                            label="ChatGPTの分析結果"
                                            value={manualAnalysisForm.data.analysis_markdown}
                                            onChange={(value) => manualAnalysisForm.setData('analysis_markdown', value)}
                                            placeholder="ChatGPTから返されたMarkdownを貼り付けます"
                                            fileAccept=".md,.txt,text/markdown,text/plain"
                                            fileDescription="Markdown・テキスト（UTF-8）、最大1,000,000文字"
                                            maxLength={1_000_000}
                                            error={manualAnalysisForm.errors.analysis_markdown}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={
                                                manualAnalysisForm.processing ||
                                                (!analysisPrompt.default_result && manualAnalysisForm.data.analysis_markdown.trim() === '')
                                            }
                                        >
                                            {manualAnalysisForm.processing && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                            {manualAnalysisForm.processing ? '保存中' : '分析結果を保存'}
                                        </Button>
                                    </form>
                                </>
                            )}

                            {analysisOperation?.status === 'failed' && (
                                <Alert variant="destructive">
                                    <AlertTitle>
                                        {analysisIsLatest ? '再分析に失敗しました。既存の分析結果は保持されています。' : '傾向分析に失敗しました。'}
                                    </AlertTitle>
                                    <AlertDescription>エラーコード: {analysisOperation.error_code}</AlertDescription>
                                </Alert>
                            )}

                            {analysis && !analysisIsLatest && !analysisActive && (
                                <Alert variant="warning">
                                    <AlertTitle>壁紙履歴が更新されています。</AlertTitle>
                                    <AlertDescription>傾向分析を実行して、最新の履歴を反映してください。</AlertDescription>
                                </Alert>
                            )}

                            {analysis ? (
                                <div className="bg-muted/50 rounded-lg border p-4">
                                    <MarkdownAnalysis markdown={analysis.markdown} />
                                </div>
                            ) : (
                                !analysisActive && <p className="text-muted-foreground text-sm">分析結果はまだありません。</p>
                            )}
                        </CardContent>
                    )}
                </Card>

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

function MarkdownAnalysis({ markdown }: { markdown: string }) {
    return (
        <div className="space-y-2 text-sm leading-6">
            {markdown.split('\n').map((line, index) => {
                let content: ReactNode = line;
                if (line.startsWith('# ')) {
                    content = <h2 className="text-lg font-semibold">{line.slice(2)}</h2>;
                } else if (line.startsWith('## ')) {
                    content = <h3 className="pt-2 font-semibold">{line.slice(3)}</h3>;
                } else if (line.startsWith('### ')) {
                    content = <h4 className="pt-1 font-medium">{line.slice(4)}</h4>;
                } else if (/^[-*] /.test(line)) {
                    content = <p className="pl-4 before:mr-2 before:content-['•']">{line.slice(2)}</p>;
                } else if (line === '') {
                    content = <span className="block h-1" />;
                } else {
                    content = <p>{line}</p>;
                }

                return <div key={`${index}-${line}`}>{content}</div>;
            })}
        </div>
    );
}
