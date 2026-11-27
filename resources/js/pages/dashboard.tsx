import { DirectionWaveChart } from '@/components/direction-wave-chart';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type DirectionStats } from '@/lib/direction-wave';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, ClipboardPen, ImagePlus } from 'lucide-react';
import { type ReactNode } from 'react';

export default function Dashboard({
    latestWallpaperDate,
    stats,
    directionStats,
}: {
    latestWallpaperDate: string | null;
    stats: { wallpapers: number; result_count: number; total_prize_vnd: number };
    directionStats: DirectionStats[];
}) {
    const highestPrizeTotal = highestPrize(directionStats.map((row) => (row.result_count > 0 ? row.prize_total : null)));
    const highestAveragePrize = highestPrize(directionStats.map((row) => row.average_prize));
    const highestPrizePerTicket = highestPrize(directionStats.map((row) => row.prize_per_ticket));

    return (
        <AppLayout breadcrumbs={[{ title: 'ダッシュボード', href: route('dashboard') }]}>
            <Head title="吉方位別の実績" />
            <div className="min-w-0 space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">吉方位別の実績</h1>
                    <div className="flex flex-wrap gap-3">
                        {latestWallpaperDate && (
                            <Button asChild variant="outline">
                                <Link
                                    href={route('results.index', { date: latestWallpaperDate })}
                                    aria-label={`最新の壁紙の実績を登録（${latestWallpaperDate}）`}
                                >
                                    <ClipboardPen aria-hidden="true" />
                                    最新の壁紙の実績を登録
                                </Link>
                            </Button>
                        )}
                        <Button asChild>
                            <Link href={route('wallpapers.create')}>
                                <ImagePlus aria-hidden="true" />
                                壁紙作成
                            </Link>
                        </Button>
                    </div>
                </div>
                <dl className="grid gap-4 border-y py-4 sm:grid-cols-3">
                    <Stat title="壁紙件数" value={stats.wallpapers.toLocaleString() + '件'} />
                    <Stat title="実績登録件数" value={stats.result_count.toLocaleString() + '件'} />
                    <Stat title="賞金合計" value={formatVnd(stats.total_prize_vnd)} />
                </dl>
                <DirectionWaveChart stats={directionStats} />
                <section className="space-y-3" aria-label="主方位ごとの実績">
                    <p className="text-muted-foreground text-sm">全期間の壁紙を、採用済み構図の主方位に1件ずつ集計します。</p>
                    <table className="block w-full text-left text-sm lg:table">
                        <thead className="hidden border-b lg:table-header-group">
                            <tr>
                                {['主方位', '壁紙', '実績登録', '賞金合計', '1日平均', '1口あたり賞金', '履歴'].map((label) => (
                                    <th key={label} className="p-3">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="block lg:table-row-group">
                            {directionStats.map((row) => (
                                <tr key={row.key} className="block space-y-2 border-b py-4 lg:table-row lg:space-y-0">
                                    <th scope="row" className="block lg:table-cell lg:p-3">
                                        {row.label}
                                    </th>
                                    <Cell label="壁紙" value={row.wallpapers.toLocaleString() + '件'} />
                                    <Cell label="実績登録" value={row.result_count.toLocaleString() + '件'} />
                                    <Cell
                                        label="賞金合計"
                                        value={<PrizeValue value={row.prize_total} highest={row.result_count > 0 ? highestPrizeTotal : null} />}
                                    />
                                    <Cell label="1日平均" value={<PrizeValue value={row.average_prize} highest={highestAveragePrize} />} />
                                    <Cell
                                        label="1口あたり賞金"
                                        value={<PrizeValue value={row.prize_per_ticket} highest={highestPrizePerTicket} />}
                                        detail={`口数登録済み ${row.ticket_result_count}件`}
                                    />
                                    <td className="block lg:table-cell lg:p-3">
                                        <Link
                                            className="text-primary inline-flex items-center gap-1 underline underline-offset-4"
                                            aria-label={row.label + 'の壁紙履歴'}
                                            href={route('wallpapers.index', { direction: row.key })}
                                        >
                                            履歴を確認
                                            <ArrowRight className="size-4" aria-hidden="true" />
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="text-muted-foreground text-sm">
                        賞金未登録は平均に含めません。0
                        VNDは実績1件として含めます。1口あたり賞金は、購入口数が分かる実績の賞金合計を同じ実績の購入口数合計で割ります。
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}
function Stat({ title, value }: { title: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground text-sm">{title}</dt>
            <dd className="mt-1 text-lg font-semibold">{value}</dd>
        </div>
    );
}
function Cell({ label, value, detail }: { label: string; value: ReactNode; detail?: string }) {
    return (
        <td className="grid grid-cols-[8rem_minmax(0,1fr)] gap-2 lg:table-cell lg:p-3">
            <span className="text-muted-foreground lg:hidden">{label}</span>
            <span>
                {value}
                {detail && <span className="text-muted-foreground block text-xs">{detail}</span>}
            </span>
        </td>
    );
}
function PrizeValue({ value, highest }: { value: number | null; highest: number | null }) {
    if (value === null || value !== highest) {
        return formatVnd(value);
    }

    return (
        <strong className="bg-accent text-accent-foreground inline-flex max-w-full flex-wrap items-baseline gap-x-1.5 rounded-md px-2 py-0.5 font-semibold">
            <span>{formatVnd(value)}</span>
            <span className="bg-primary text-primary-foreground rounded-sm px-1.5 text-xs">1位</span>
        </strong>
    );
}
function highestPrize(values: (number | null)[]) {
    return values.reduce<number | null>((highest, value) => {
        if (value === null) {
            return highest;
        }

        return highest === null ? value : Math.max(highest, value);
    }, null);
}
function formatVnd(value: number | null) {
    return value === null ? '—' : value.toLocaleString() + ' VND';
}
