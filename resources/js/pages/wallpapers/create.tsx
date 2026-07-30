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
    const form = useForm({ target_date: selectedDate || defaultDate });
    const analysisForm = useForm({});
    const { operation: analysisOperation } = useOperation(latestAnalysisRun);
    const page = usePage<{ errors: { analysis?: string; proposal?: string } }>();
    const analysisActive = ['queued', 'running'].includes(analysisOperation?.status ?? '');
    const analysisIsLatest = analysis?.is_latest ?? false;
    const [analysisExpanded, setAnalysisExpanded] = useState(true);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('wallpapers.proposals.store'));
    };

    const analyze = () => {
        analysisForm.post(route('wallpaper-analyses.store'), {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: '壁紙作成', href: route('wallpapers.create') }]}>
            <Head title="壁紙作成" />
            <div className="max-w-4xl space-y-6 p-4">
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
                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="button"
                                    variant={analysisIsLatest ? 'outline' : 'default'}
                                    disabled={analysisActive || analysisIsLatest || analysisForm.processing}
                                    onClick={analyze}
                                >
                                    {analysisActive && <LoaderCircle className="size-4 animate-spin" />}
                                    {analysisIsLatest ? '既に最新です' : analysisActive ? '傾向分析中' : '傾向分析'}
                                </Button>
                                {analysis && (
                                    <span className="text-muted-foreground text-sm">
                                        対象 {analysis.statistics?.records ?? 0}件
                                        {analysis.created_at && `・更新 ${new Date(analysis.created_at).toLocaleString('ja-JP')}`}
                                    </span>
                                )}
                            </div>

                            <InputError message={page.props.errors.analysis} />

                            {analysisOperation?.status === 'failed' && (
                                <Alert variant="destructive">
                                    <AlertTitle>傾向分析に失敗しました。</AlertTitle>
                                    <AlertDescription>エラーコード: {analysisOperation.error_code}</AlertDescription>
                                </Alert>
                            )}

                            {analysis && !analysisIsLatest && !analysisActive && (
                                <Alert variant="warning">
                                    <AlertTitle>壁紙履歴が更新されています。</AlertTitle>
                                    <AlertDescription>「傾向分析」を実行して、最新の履歴を反映してください。</AlertDescription>
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
                            <div className="flex gap-3">
                                <Input
                                    id="target_date"
                                    type="date"
                                    value={form.data.target_date}
                                    onChange={(e) => {
                                        form.setData('target_date', e.target.value);
                                        router.get(route('wallpapers.create'), { date: e.target.value }, { preserveState: true, replace: true });
                                    }}
                                />
                            </div>
                            <InputError message={form.errors.target_date} />
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
                            <form onSubmit={submit} className="space-y-2">
                                <Button disabled={form.processing || !analysisIsLatest}>構図を提案してもらう</Button>
                                {!analysisIsLatest && (
                                    <p className="text-muted-foreground text-sm">構図提案の前に、最新の傾向分析を完了してください。</p>
                                )}
                                <InputError message={page.props.errors.proposal} />
                            </form>
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
