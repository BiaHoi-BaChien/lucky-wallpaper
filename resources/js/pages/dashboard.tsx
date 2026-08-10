import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Database, Image, MoonStar, WalletCards } from 'lucide-react';
import { useState } from 'react';

interface MoonPoint {
    id: number;
    target_date: string;
    title: string | null;
    art_style: string | null;
    prize_vnd: number;
    prize_percentile: number;
    moon_age: number;
    moon_phase: string;
    has_image: boolean;
}

interface TodayMoon {
    target_date: string;
    moon_age: number;
    moon_phase: string;
}

interface Props {
    stats: {
        wallpapers: number;
        total_prize_vnd: number;
        generated_images: number;
    };
    moonChart: MoonPoint[];
    moonChartMinimumPrizeVnd: number;
    todayMoon: TodayMoon | null;
}

const chartSize = 420;
const center = chartSize / 2;
const innerRadius = 38;
const outerRadius = 158;
const lunarCycleDays = 29.53059;
const moonPhases = [
    { icon: '🌑', label: '新月' },
    { icon: '🌒', label: '満ちていく三日月' },
    { icon: '🌒', label: '満ちていく三日月' },
    { icon: '🌓', label: '上弦' },
    { icon: '🌔', label: '満ちていく凸月' },
    { icon: '🌔', label: '満ちていく凸月' },
    { icon: '🌕', label: '満月' },
    { icon: '🌖', label: '欠けていく凸月' },
    { icon: '🌖', label: '欠けていく凸月' },
    { icon: '🌗', label: '下弦' },
    { icon: '🌘', label: '欠けていく三日月' },
    { icon: '🌘', label: '欠けていく三日月' },
] as const;
const moonDirections = {
    waxing: { label: '満ちていく月', color: 'hsl(165, 60%, 45%)' },
    waning: { label: '欠けていく月', color: 'hsl(280, 65%, 60%)' },
};
const todayColor = 'var(--destructive)';

export default function Dashboard({ stats, moonChart, moonChartMinimumPrizeVnd, todayMoon }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'ダッシュボード', href: route('dashboard') }]}>
            <Head title="ダッシュボード" />
            <div className="space-y-6 p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Stat title="実績件数" value={`${stats.wallpapers.toLocaleString()}件`} icon={<Database />} />
                    <Stat title="累計賞金" value={`${stats.total_prize_vnd.toLocaleString()} VND`} icon={<WalletCards />} />
                    <Stat title="ローカル画像" value={`${stats.generated_images.toLocaleString()}枚`} icon={<Image />} />
                </div>
                <MoonLuckyStarChart points={moonChart} minimumPrizeVnd={moonChartMinimumPrizeVnd} todayMoon={todayMoon} />
            </div>
        </AppLayout>
    );
}

function MoonLuckyStarChart({ points, minimumPrizeVnd, todayMoon }: { points: MoonPoint[]; minimumPrizeVnd: number; todayMoon: TodayMoon | null }) {
    const [selectedId, setSelectedId] = useState(points.at(-1)?.id ?? null);
    const [failedPreviewId, setFailedPreviewId] = useState<number | null>(null);
    const selected = points.find((point) => point.id === selectedId) ?? points.at(-1) ?? null;
    const maxPrizeLog = Math.max(0, ...points.map((point) => Math.log1p(point.prize_vnd)));
    const selectedPosition = selected === null ? null : position(selected);
    const todayPosition = todayMoon === null ? null : polarPoint(outerRadius, moonAngle(todayMoon.moon_age));

    const moveSelection = (event: React.KeyboardEvent<SVGGElement>, index: number) => {
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const step = event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1;
        const nextIndex = (index + step + points.length) % points.length;
        setSelectedId(points[nextIndex].id);
        (event.currentTarget.parentElement?.children.item(nextIndex) as SVGGElement | null)?.focus();
    };

    return (
        <Card>
            <CardHeader className="p-4 sm:p-6">
                <div className="flex items-start gap-3">
                    <MoonStar className="text-primary mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <div className="min-w-0 space-y-1">
                        <CardTitle className="text-base sm:text-lg">月相ラッキー星図</CardTitle>
                        <CardDescription>
                            表示対象：{minimumPrizeVnd.toLocaleString()} VND超・{points.length.toLocaleString()}件
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="px-4 pb-4 sm:px-6 sm:pb-6">
                {points.length === 0 ? (
                    <p className="text-muted-foreground py-12 text-center text-sm">
                        {minimumPrizeVnd.toLocaleString()} VNDを超える賞金実績がありません。
                    </p>
                ) : (
                    <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-center">
                        <div className="min-w-0">
                            <svg
                                viewBox={`0 0 ${chartSize} ${chartSize}`}
                                className="mx-auto h-auto w-full max-w-[36rem]"
                                role="group"
                                aria-labelledby="moon-chart-title moon-chart-description"
                            >
                                <title id="moon-chart-title">月相ラッキー星図</title>
                                <desc id="moon-chart-description">
                                    月齢を角度、表示対象内の賞金パーセンタイルを中心からの距離、賞金額を星の大きさで示します。色は月が満ちていく期間と欠けていく期間を表します。
                                    外周は新月を12時として12分割し、各位置の月相を示します。
                                    {todayMoon && `朱色の破線は${todayMoon.target_date}の${todayMoon.moon_phase}の位置です。`}
                                </desc>

                                {[0.25, 0.5, 0.75, 1].map((percentile) => {
                                    const radius = radialDistance(percentile);
                                    const label = polarPoint(radius, -Math.PI / 4);

                                    return (
                                        <g key={percentile} aria-hidden="true">
                                            <circle
                                                cx={center}
                                                cy={center}
                                                r={radius}
                                                fill="none"
                                                stroke="currentColor"
                                                className="text-border"
                                                strokeWidth="1"
                                            />
                                            <text x={label.x + 3} y={label.y - 3} fill="currentColor" className="text-muted-foreground text-[10px]">
                                                {percentile * 100}%
                                            </text>
                                        </g>
                                    );
                                })}

                                {moonPhases.map(({ icon, label }, index) => {
                                    const angle = moonAngle((lunarCycleDays * index) / moonPhases.length);
                                    const end = polarPoint(outerRadius, angle);
                                    const iconPosition = polarPoint(190, angle);

                                    return (
                                        <g key={index} aria-hidden="true">
                                            <line
                                                x1={center}
                                                y1={center}
                                                x2={end.x}
                                                y2={end.y}
                                                stroke="currentColor"
                                                className="text-border"
                                                strokeWidth="1"
                                            />
                                            <text x={iconPosition.x} y={iconPosition.y} textAnchor="middle" dominantBaseline="middle" fontSize="18">
                                                <title>{label}</title>
                                                {icon}
                                            </text>
                                        </g>
                                    );
                                })}

                                {todayPosition && (
                                    <g aria-hidden="true">
                                        <line
                                            x1={center}
                                            y1={center}
                                            x2={todayPosition.x}
                                            y2={todayPosition.y}
                                            stroke={todayColor}
                                            strokeWidth="2"
                                            strokeDasharray="5 4"
                                        />
                                        <circle cx={todayPosition.x} cy={todayPosition.y} r="4" fill={todayColor} />
                                    </g>
                                )}

                                <g role="listbox" aria-label="壁紙実績。矢印キーで実績を選択できます。">
                                    {points.map((point, index) => {
                                        const plotted = position(point);
                                        const radius = maxPrizeLog === 0 ? 5 : 5 + (Math.log1p(point.prize_vnd) / maxPrizeLog) * 7;
                                        const isSelected = point.id === selected?.id;
                                        const direction = moonDirection(point.moon_age);

                                        return (
                                            <g
                                                key={point.id}
                                                role="option"
                                                aria-selected={isSelected}
                                                tabIndex={isSelected ? 0 : -1}
                                                aria-label={`${point.target_date}、${point.moon_phase}、${moonDirections[direction].label}、${point.prize_vnd.toLocaleString()} VND`}
                                                className="cursor-pointer outline-none"
                                                onMouseEnter={() => setSelectedId(point.id)}
                                                onFocus={() => setSelectedId(point.id)}
                                                onClick={() => setSelectedId(point.id)}
                                                onKeyDown={(event) => moveSelection(event, index)}
                                            >
                                                <title>
                                                    {point.target_date}・{point.moon_phase}・{point.prize_vnd.toLocaleString()} VND
                                                </title>
                                                <circle
                                                    cx={plotted.x}
                                                    cy={plotted.y}
                                                    r={radius}
                                                    fill={moonDirections[direction].color}
                                                    fillOpacity={isSelected ? 1 : 0.78}
                                                    stroke="var(--background)"
                                                    strokeWidth="2"
                                                    className="transition-[r,fill-opacity] duration-150"
                                                />
                                            </g>
                                        );
                                    })}
                                </g>

                                {selectedPosition && (
                                    <circle
                                        cx={selectedPosition.x}
                                        cy={selectedPosition.y}
                                        r="17"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                        className="text-primary"
                                        pointerEvents="none"
                                        aria-hidden="true"
                                    />
                                )}
                            </svg>

                            <div className="flex flex-wrap justify-center gap-x-4 gap-y-2 text-xs" aria-label="月の満ち欠けと今日の位置">
                                {Object.entries(moonDirections).map(([direction, { label, color }]) => (
                                    <span key={direction} className="inline-flex items-center gap-1.5">
                                        <span className="size-2.5 rounded-full" style={{ backgroundColor: color }} aria-hidden="true" />
                                        {label}
                                    </span>
                                ))}
                                {todayMoon && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <span className="w-4 border-t-2 border-dashed" style={{ borderColor: todayColor }} aria-hidden="true" />
                                        今日（{todayMoon.target_date}・{todayMoon.moon_phase}）
                                    </span>
                                )}
                            </div>
                        </div>

                        {selected && (
                            <div className="min-w-0 space-y-4 border-t pt-6 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-6" aria-live="polite">
                                {selected.has_image && failedPreviewId !== selected.id ? (
                                    <img
                                        src={route('wallpapers.preview', { wallpaper: selected.id })}
                                        alt={`${selected.target_date}の壁紙「${selected.title ?? '構図名未設定'}」`}
                                        className="mx-auto aspect-[9/16] max-h-64 w-auto rounded-md border object-cover"
                                        loading="lazy"
                                        onError={() => setFailedPreviewId(selected.id)}
                                    />
                                ) : (
                                    <div className="bg-muted text-muted-foreground mx-auto flex aspect-[9/16] max-h-64 w-36 items-center justify-center rounded-md border">
                                        <Image className="size-8" aria-hidden="true" />
                                        <span className="sr-only">画像なし</span>
                                    </div>
                                )}

                                <div className="min-w-0 space-y-3">
                                    <div>
                                        <p className="text-muted-foreground text-xs">{selected.target_date}</p>
                                        <p className="font-semibold break-words">{selected.title ?? '構図名未設定'}</p>
                                        <p className="text-muted-foreground text-sm break-words">{selected.art_style ?? '画風未設定'}</p>
                                    </div>
                                    <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                                        <dt className="text-muted-foreground">月相</dt>
                                        <dd className="text-right font-medium">{selected.moon_phase}</dd>
                                        <dt className="text-muted-foreground">月齢</dt>
                                        <dd className="text-right font-medium">{selected.moon_age.toFixed(1)}</dd>
                                        <dt className="text-muted-foreground">表示対象内の賞金順位</dt>
                                        <dd className="text-right font-medium">
                                            上位 {Math.max(1, Math.round((1 - selected.prize_percentile) * 100 + 1))}%
                                        </dd>
                                        <dt className="text-muted-foreground">賞金</dt>
                                        <dd className="text-right font-medium whitespace-nowrap">{selected.prize_vnd.toLocaleString()} VND</dd>
                                    </dl>
                                </div>

                                <Button variant="outline" className="w-full" asChild>
                                    <Link href={route('wallpapers.show', { wallpaper: selected.id })}>
                                        壁紙詳細
                                        <ArrowUpRight aria-hidden="true" />
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function position(point: MoonPoint) {
    return polarPoint(radialDistance(point.prize_percentile), moonAngle(point.moon_age));
}

function radialDistance(percentile: number) {
    return innerRadius + (outerRadius - innerRadius) * percentile;
}

function moonAngle(age: number) {
    return (age / lunarCycleDays) * Math.PI * 2 - Math.PI / 2;
}

function moonDirection(age: number): keyof typeof moonDirections {
    return age < lunarCycleDays / 2 ? 'waxing' : 'waning';
}

function polarPoint(radius: number, angle: number) {
    return {
        x: center + Math.cos(angle) * radius,
        y: center + Math.sin(angle) * radius,
    };
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
