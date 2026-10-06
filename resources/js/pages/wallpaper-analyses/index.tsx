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
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

interface Analysis {
    id: number;
    markdown: string;
    html: string;
    is_latest: boolean;
    created_at: string | null;
    statistics: {
        records?: number;
        max_prize_vnd?: number | null;
        high_prize_threshold_vnd?: number;
    } | null;
}

interface AnalysisPlan {
    mode: 'full' | 'incremental' | 'unchanged' | 'requires_full';
    record_count: number;
    delta_count: number;
    reason: string | null;
    proposal: string | null;
}

export default function WallpaperAnalyses({
    analysis,
    latestAnalysisRun,
    analysisPlan,
}: {
    analysis: Analysis | null;
    latestAnalysisRun: Operation | null;
    analysisPlan: AnalysisPlan;
}) {
    const apiAnalysisForm = useForm({ api_confirmed: true, full_confirmed: false, perspective: '' });
    const manualAnalysisForm = useForm<{
        analysis_markdown: string;
        prompt_hash: string;
        prompt_date: string;
        full_confirmed: boolean;
        perspective: string;
    }>({ analysis_markdown: '', prompt_hash: '', prompt_date: '', full_confirmed: false, perspective: '' });
    const { flash } = usePage<SharedData>().props;
    const { operation: analysisOperation } = useOperation(latestAnalysisRun);
    const analysisActive = ['queued', 'running'].includes(analysisOperation?.status ?? '');
    const analysisIsLatest = analysis?.is_latest ?? false;
    const [analysisMode, setAnalysisMode] = useState<ExecutionMode>('manual');
    const [analysisPrompt, setAnalysisPrompt] = useState<ManualPrompt>();
    const [analysisPromptLoading, setAnalysisPromptLoading] = useState(false);
    const [analysisPromptError, setAnalysisPromptError] = useState<string>();
    const promptRequest = useRef<AbortController | null>(null);
    const [fullConfirmationOpen, setFullConfirmationOpen] = useState(false);
    const [perspective, setPerspective] = useState('');
    const requiresFull = analysisPlan.mode === 'requires_full';
    const unchanged = analysisPlan.mode === 'unchanged';
    const busy = analysisActive || analysisPromptLoading || apiAnalysisForm.processing || manualAnalysisForm.processing;

    useEffect(() => () => promptRequest.current?.abort(), []);

    const loadAnalysisPrompt = async (fullConfirmed = false, approvedPerspective = '') => {
        if (busy || promptRequest.current) return;

        const controller = new AbortController();
        promptRequest.current = controller;
        setAnalysisPromptLoading(true);
        setAnalysisPromptError(undefined);
        setAnalysisPrompt(undefined);
        try {
            const prompt = await fetchManualPrompt(
                route('wallpaper-analyses.manual-prompt'),
                { full_confirmed: fullConfirmed, perspective: approvedPerspective },
                controller.signal,
            );
            if (controller.signal.aborted) return;

            setAnalysisPrompt(prompt);
            manualAnalysisForm.setData({
                analysis_markdown: prompt.default_result ?? '',
                prompt_hash: prompt.prompt_hash,
                prompt_date: prompt.prompt_date ?? '',
                full_confirmed: fullConfirmed,
                perspective: approvedPerspective,
            });
        } catch (error) {
            if (!controller.signal.aborted) {
                setAnalysisPromptError(error instanceof Error ? error.message : 'プロンプトを取得できませんでした。');
            }
        } finally {
            promptRequest.current = null;
            if (!controller.signal.aborted) setAnalysisPromptLoading(false);
        }
    };

    const saveAnalysis = (event: FormEvent) => {
        event.preventDefault();
        manualAnalysisForm.post(route('wallpaper-analyses.manual-result'), {
            preserveScroll: true,
            onSuccess: () => {
                setAnalysisPrompt(undefined);
                manualAnalysisForm.reset();
            },
        });
    };

    const startApiAnalysis = (fullConfirmed = false, approvedPerspective = '') => {
        apiAnalysisForm.transform(() => ({ api_confirmed: true, full_confirmed: fullConfirmed, perspective: approvedPerspective }));
        apiAnalysisForm.post(route('wallpaper-analyses.store'), { preserveScroll: true });
    };

    const reuseAnalysis = () => {
        apiAnalysisForm.transform(() => ({ reuse_only: true }));
        apiAnalysisForm.post(route('wallpaper-analyses.store'), { preserveScroll: true });
    };

    const confirmFullAnalysis = () => {
        setFullConfirmationOpen(false);
        if (analysisMode === 'manual') {
            void loadAnalysisPrompt(true, perspective);
        } else {
            startApiAnalysis(true, perspective);
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: '傾向分析', href: route('wallpaper-analyses.index') }]}>
            <Head title="傾向分析" />
            <div className="max-w-4xl space-y-6 p-4">
                {flash.status && <Alert>{flash.status}</Alert>}
                <Card>
                    <CardHeader>
                        <CardTitle>高額当選壁紙の傾向分析</CardTitle>
                        <CardDescription>
                            前回の分析結果に追加データの傾向を反映します。新しい切り口での全件再分析は、確認後に実行します。
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <ExecutionModeSelector value={analysisMode} onChange={setAnalysisMode} disabled={busy} />
                        <p className="text-muted-foreground text-sm">
                            {unchanged
                                ? '追加・変更はありません。前回の分析結果を利用できます。'
                                : requiresFull
                                  ? analysisPlan.reason
                                  : analysisPlan.mode === 'incremental'
                                    ? `前回の結果と差分 ${analysisPlan.delta_count}件を分析します（高額当選の分類が変わった履歴を含む）。全体 ${analysisPlan.record_count}件。`
                                    : `初回は全 ${analysisPlan.record_count}件を分析して保存します。`}
                        </p>
                        {analysisPlan.proposal && (
                            <Alert>
                                <AlertTitle>新しい切り口での全件再分析が提案されています</AlertTitle>
                                <AlertDescription className="break-words whitespace-pre-wrap">{analysisPlan.proposal}</AlertDescription>
                            </Alert>
                        )}
                        <div className="flex flex-wrap items-center gap-3">
                            {unchanged && !analysisIsLatest && (
                                <Button type="button" disabled={busy} onClick={reuseAnalysis}>
                                    前回の結果を再利用
                                </Button>
                            )}
                            {!requiresFull &&
                                (!unchanged || analysisIsLatest) &&
                                (analysisMode === 'manual' ? (
                                    <Button type="button" disabled={busy || unchanged} onClick={() => void loadAnalysisPrompt()}>
                                        {analysisPromptLoading && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                                        {analysisPromptLoading ? '作成中' : analysis ? '差分分析プロンプトを作成' : '分析プロンプトを作成'}
                                    </Button>
                                ) : (
                                    <ApiConfirmationButton
                                        label={analysis ? 'APIで差分分析' : 'APIで傾向分析'}
                                        processingLabel="傾向分析中"
                                        processing={analysisActive || apiAnalysisForm.processing}
                                        disabled={busy || unchanged}
                                        onConfirm={() => startApiAnalysis()}
                                    />
                                ))}
                            {analysis && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => {
                                        setPerspective(analysisPlan.proposal ?? '');
                                        setFullConfirmationOpen(true);
                                    }}
                                >
                                    全件再分析を確認
                                </Button>
                            )}
                            {analysis && (
                                <span className="text-muted-foreground text-sm">
                                    対象 {analysis.statistics?.records ?? 0}件
                                    {analysis.created_at && `・更新 ${new Date(analysis.created_at).toLocaleString('ja-JP')}`}
                                </span>
                            )}
                        </div>
                        <InputError message={analysisPromptError ?? apiAnalysisForm.errors.api_confirmed} />
                        <InputError message={apiAnalysisForm.errors.full_confirmed ?? manualAnalysisForm.errors.full_confirmed} />
                        <InputError message={apiAnalysisForm.errors.perspective ?? manualAnalysisForm.errors.perspective} />
                        <InputError message={(apiAnalysisForm.errors as Record<string, string>).analysis} />

                        <Dialog open={fullConfirmationOpen} onOpenChange={setFullConfirmationOpen}>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>全 {analysisPlan.record_count}件を再分析しますか？</DialogTitle>
                                    <DialogDescription>
                                        {analysisPlan.reason ?? '保存済みの分析結果を更新するため、過去の全データを分析し直します。'}
                                        {analysisMode === 'api'
                                            ? ' OpenAI APIへ送信し、利用料金が発生する可能性があります。'
                                            : ' ChatGPTに渡す全件データとプロンプトを作成します。'}
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-2">
                                    <Label htmlFor="analysis-perspective">追加する分析の切り口（任意）</Label>
                                    <Textarea
                                        id="analysis-perspective"
                                        value={perspective}
                                        onChange={(event) => setPerspective(event.target.value)}
                                        maxLength={2000}
                                        rows={5}
                                    />
                                </div>
                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="outline">
                                            今回は見送る
                                        </Button>
                                    </DialogClose>
                                    <Button type="button" disabled={busy} onClick={confirmFullAnalysis}>
                                        全件再分析を許可する
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>

                        {analysisPrompt && analysisMode === 'manual' && (
                            <>
                                <ManualPromptPanel
                                    key={analysisPrompt.prompt_hash}
                                    prompt={analysisPrompt}
                                    title="傾向分析プロンプト"
                                    dataDownload={{
                                        url: route('wallpaper-analyses.manual-data'),
                                        data: {
                                            prompt_date: analysisPrompt.prompt_date,
                                            prompt_hash: analysisPrompt.prompt_hash,
                                            full_confirmed: manualAnalysisForm.data.full_confirmed,
                                            perspective: manualAnalysisForm.data.perspective,
                                        },
                                    }}
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

                        {analysis && !analysisIsLatest && !analysisActive && !unchanged && (
                            <Alert variant="warning">
                                <AlertTitle>壁紙履歴が更新されています。</AlertTitle>
                                <AlertDescription>傾向分析を実行して、最新の履歴を反映してください。</AlertDescription>
                            </Alert>
                        )}

                        {analysis ? (
                            <div className="border-t pt-6">
                                <MarkdownAnalysis html={analysis.html} />
                            </div>
                        ) : (
                            !analysisActive && <p className="text-muted-foreground text-sm">分析結果はまだありません。</p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function MarkdownAnalysis({ html }: { html: string }) {
    return <div className="markdown-analysis" dangerouslySetInnerHTML={{ __html: html }} />;
}
