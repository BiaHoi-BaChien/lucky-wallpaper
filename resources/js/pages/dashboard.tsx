import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Database, Image, LoaderCircle, WalletCards } from 'lucide-react';

interface Props {
    stats: {
        wallpapers: number;
        total_prize_vnd: number;
        generated_images: number;
    };
    latestSync: Operation | null;
}

export default function Dashboard({ stats, latestSync }: Props) {
    const { operation } = useOperation(latestSync);
    const page = usePage<{ errors: { sync?: string } }>();
    const active = operation && ['queued', 'running'].includes(operation.status);

    return (
        <AppLayout breadcrumbs={[{ title: 'ダッシュボード・同期', href: '/dashboard' }]}>
            <Head title="ダッシュボード" />
            <div className="space-y-6 p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Stat title="実績件数" value={`${stats.wallpapers.toLocaleString()}件`} icon={<Database />} />
                    <Stat title="累計賞金" value={`${stats.total_prize_vnd.toLocaleString()} VND`} icon={<WalletCards />} />
                    <Stat title="ローカル画像" value={`${stats.generated_images.toLocaleString()}枚`} icon={<Image />} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Notion実績情報取り込み</CardTitle>
                        <CardDescription>初回は全件、2回目以降は前回成功時刻から1分重複させた差分をDBキューへ登録します。</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Button disabled={Boolean(active)} onClick={() => router.post('/notion-syncs')}>
                            {active && <LoaderCircle className="size-4 animate-spin" />}
                            {active ? '取り込み中' : '実績情報取り込み'}
                        </Button>
                        <InputError message={page.props.errors.sync} />
                        {operation && (
                            <div className="bg-muted rounded-lg p-4 text-sm">
                                <p>
                                    状態: <strong>{operation.status}</strong>
                                </p>
                                <p>
                                    進捗: {operation.processed ?? 0} / {operation.total ?? 0}
                                </p>
                                <p>
                                    登録 {operation.imported ?? 0}・既存 {operation.skipped_existing ?? 0}・ 必須不足 {operation.skipped_invalid ?? 0}
                                    ・本文空 {operation.skipped_empty_body ?? 0}
                                </p>
                                {operation.error_code && <p className="text-red-600">エラー: {operation.error_code}</p>}
                                {operation.warnings?.map((warning) => (
                                    <p key={warning} className="text-amber-700">
                                        警告: {warning}
                                    </p>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function Stat({ title, value, icon }: { title: string; value: string; icon: React.ReactNode }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <span className="text-amber-600">{icon}</span>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
            </CardContent>
        </Card>
    );
}
