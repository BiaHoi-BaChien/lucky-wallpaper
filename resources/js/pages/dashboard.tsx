import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Database, Image, WalletCards } from 'lucide-react';
import { useState } from 'react';

interface ZoneStats {
    records: number;
    averagePrizeVnd: number | null;
    perTicketRecords: number;
    averagePrizePerTicketVnd: number | null;
    heatLevel: number;
}

interface NinePalace {
    todayNineStar: string | null;
    unclassifiedRecords: number;
    zoneLabels: Record<string, string>;
    stars: Array<{ name: string; zones: Record<string, ZoneStats> }>;
}

interface Props {
    stats: {
        wallpapers: number;
        total_prize_vnd: number;
        generated_images: number;
    };
    ninePalace: NinePalace;
}

const zoneOrder = ['top_left', 'top', 'top_right', 'left', 'center', 'right', 'bottom_left', 'bottom', 'bottom_right'];
const heatClasses = ['bg-muted/30', 'bg-primary/5', 'bg-primary/10', 'bg-primary/15', 'bg-primary/20'];

export default function Dashboard({ stats, ninePalace }: Props) {
    const [selectedName, setSelectedName] = useState(ninePalace.todayNineStar ?? ninePalace.stars[0]?.name ?? '');
    const selectedStar = ninePalace.stars.find((star) => star.name === selectedName) ?? ninePalace.stars[0];

    return (
        <AppLayout breadcrumbs={[{ title: 'ダッシュボード', href: route('dashboard') }]}>
            <Head title="ダッシュボード" />
            <div className="space-y-6 p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Stat title="実績件数" value={`${stats.wallpapers.toLocaleString()}件`} icon={<Database />} />
                    <Stat title="累計賞金" value={`${stats.total_prize_vnd.toLocaleString()} VND`} icon={<WalletCards />} />
                    <Stat title="ローカル画像" value={`${stats.generated_images.toLocaleString()}枚`} icon={<Image />} />
                </div>

                <section className="space-y-4 border-t pt-6" aria-labelledby="nine-palace-title">
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                        <div>
                            <h2 id="nine-palace-title" className="text-lg font-semibold">
                                九星 × 九宮構図 × 当選金
                            </h2>
                            <p className="text-muted-foreground text-sm">過去実績による遊び上の傾向です。当選を保証するものではありません。</p>
                        </div>
                        <div className="w-full sm:w-64">
                            <label htmlFor="nine-star" className="mb-1 block text-sm font-medium">
                                九星
                            </label>
                            <select
                                id="nine-star"
                                value={selectedStar?.name ?? ''}
                                onChange={(event) => setSelectedName(event.target.value)}
                                className="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-9 w-full rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                            >
                                {ninePalace.stars.map((star) => (
                                    <option key={star.name} value={star.name}>
                                        {star.name}
                                        {star.name === ninePalace.todayNineStar ? '（今日）' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {selectedStar && (
                        <div className="grid grid-cols-3 overflow-hidden rounded-md border">
                            {zoneOrder.map((zone, index) => {
                                const item = selectedStar.zones[zone];

                                return (
                                    <div
                                        key={zone}
                                        className={`min-h-32 p-3 ${heatClasses[item.heatLevel] ?? heatClasses[0]} ${index % 3 !== 2 ? 'border-r' : ''} ${index < 6 ? 'border-b' : ''}`}
                                    >
                                        <div className="flex items-start justify-between gap-1">
                                            <h3 className="text-sm font-semibold">{ninePalace.zoneLabels[zone]}</h3>
                                            <span className="text-muted-foreground text-xs">{item.records}件</span>
                                        </div>
                                        {item.records === 0 ? (
                                            <p className="text-muted-foreground mt-5 text-center text-xs">記録なし</p>
                                        ) : (
                                            <dl className="mt-3 space-y-2 text-xs">
                                                <div>
                                                    <dt className="text-muted-foreground">平均当選金</dt>
                                                    <dd className="font-medium">{formatVnd(item.averagePrizeVnd)}</dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">1口平均</dt>
                                                    <dd className="font-medium">{formatVnd(item.averagePrizePerTicketVnd)}</dd>
                                                </div>
                                            </dl>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {ninePalace.unclassifiedRecords > 0 && (
                        <p className="text-muted-foreground text-sm">九宮構図が未分類の実績: {ninePalace.unclassifiedRecords.toLocaleString()}件</p>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

function formatVnd(value: number | null): string {
    return value === null ? '-' : `${value.toLocaleString()} VND`;
}

function Stat({ title, value, icon }: { title: string; value: string; icon: React.ReactNode }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <span className="text-primary">{icon}</span>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
            </CardContent>
        </Card>
    );
}
