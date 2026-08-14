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
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEvent, useState } from 'react';

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

export default function WallpaperAnalyses({ analysis, latestAnalysisRun }: { analysis: Analysis | null; latestAnalysisRun: Operation | null }) {
    const apiAnalysisForm = useForm({ api_confirmed: true });
    const manualAnalysisForm = useForm({ analysis_markdown: '', prompt_hash: '', prompt_date: '' });
    const { flash } = usePage<SharedData>().props;
    const { operation: analysisOperation } = useOperation(latestAnalysisRun);
    const analysisActive = ['queued', 'running'].includes(analysisOperation?.status ?? '');
    const analysisIsLatest = analysis?.is_latest ?? false;
    const [analysisMode, setAnalysisMode] = useState<ExecutionMode>('manual');
    const [analysisPrompt, setAnalysisPrompt] = useState<ManualPrompt>();
    const [analysisPromptLoading, setAnalysisPromptLoading] = useState(false);
    const [analysisPromptError, setAnalysisPromptError] = useState<string>();

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

    return (
        <AppLayout breadcrumbs={[{ title: '傾向分析', href: route('wallpaper-analyses.index') }]}>
            <Head title="傾向分析" />
            <div className="max-w-4xl space-y-6 p-4">
                {flash.status && <Alert>{flash.status}</Alert>}
                <Card>
                    <CardHeader>
                        <CardTitle>高額当選壁紙の傾向分析</CardTitle>
                        <CardDescription>当選金額が登録された壁紙履歴を比較し、上位25%の構図傾向をMarkdownで保存します。</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
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
