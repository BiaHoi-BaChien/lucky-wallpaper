import { buildDirectionWaves, DIRECTIONS, type DirectionStats } from '@/lib/direction-wave';
import { cn } from '@/lib/utils';
import { useId, useState } from 'react';

const WIDTH = 720;
const HEIGHT = 480;
const CENTER_X = WIDTH / 2;
const CENTER_Y = 240;
const WAVE_HEIGHT = 52;

function project(angle: number, radius: number, height = 0) {
    return { x: CENTER_X + Math.sin(angle) * radius, y: CENTER_Y - Math.cos(angle) * radius * 0.5 - height };
}

function coordinates(point: { x: number; y: number }) {
    return `${point.x.toFixed(2)},${point.y.toFixed(2)}`;
}

function waveSegment(index: number, radius: number, from: number, to: number) {
    const points = Array.from({ length: 17 }, (_, step) => {
        const progress = step / 16;
        // Cosine interpolation joins the eight measured values without introducing extra peaks.
        const value = from + (to - from) * (1 - Math.cos(progress * Math.PI)) * 0.5;
        const angle = ((index + progress) * Math.PI) / 4;
        return { top: project(angle, radius, value * WAVE_HEIGHT), base: project(angle, radius) };
    });
    const curve = `M${points.map(({ top }) => coordinates(top)).join(' L')}`;
    return {
        curve,
        face: `${curve} L${points
            .map(({ base }) => coordinates(base))
            .reverse()
            .join(' L')} Z`,
        depth: project(((index + 0.5) * Math.PI) / 4, radius).y,
    };
}

function formatValue(value: number | null, unit: string) {
    return value === null ? '未登録' : `${value.toLocaleString('ja-JP')} ${unit}`;
}

export function DirectionWaveChart({ stats }: { stats: DirectionStats[] }) {
    const id = useId();
    const waves = buildDirectionWaves(stats);
    const [selected, setSelected] = useState(() =>
        Math.max(
            0,
            DIRECTIONS.findIndex((direction) => stats.some((row) => row.key === direction.key && row.wallpapers > 0)),
        ),
    );
    const hasData = waves[0].values.some((value) => value !== null && value > 0);
    const selectedAngle = (selected * Math.PI) / 4;
    const selectedEnd = project(selectedAngle, 280);
    const segments = waves
        .flatMap((wave, ring) =>
            DIRECTIONS.flatMap((_, index) => {
                const from = wave.normalized[index];
                const to = wave.normalized[(index + 1) % DIRECTIONS.length];
                return from === null || to === null ? [] : [{ ...waveSegment(index, 80 + ring * 44, from, to), wave, index }];
            }),
        )
        .sort((a, b) => a.depth - b.depth);

    return (
        <section aria-labelledby={`${id}-heading`} className="min-w-0 space-y-4 border-y py-6">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id={`${id}-heading`} className="font-semibold">
                    八方位のウェーブ
                </h2>
                <span className="text-muted-foreground text-xs">全期間 · 8方位 × 5指標</span>
            </div>
            <div className="grid min-w-0 items-center gap-4 xl:grid-cols-[minmax(0,1fr)_17rem] xl:gap-8">
                <div className="relative min-w-0">
                    <svg
                        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                        className="block h-auto w-full"
                        role="img"
                        aria-labelledby={`${id}-title ${id}-description`}
                    >
                        <title id={`${id}-title`}>8方位・5指標の円形ウェーブグラフ</title>
                        <desc id={`${id}-description`}>
                            北を奥、南を手前にした俯瞰図。内側から壁紙件数、実績登録件数、賞金合計、1日平均賞金、1口あたり賞金。高さは各指標の8方位内の最大値に対する割合です。方位ボタンで実数を確認できます。未登録の金額は線をつなぎません。
                        </desc>
                        <defs>
                            <filter id={`${id}-glow`} x="-30%" y="-50%" width="160%" height="200%">
                                <feGaussianBlur stdDeviation="2.5" />
                            </filter>
                        </defs>
                        <ellipse cx={CENTER_X} cy={CENTER_Y} rx="280" ry="140" fill="var(--muted)" fillOpacity="0.35" />
                        <g fill="none" stroke="var(--muted-foreground)" strokeOpacity="0.2" strokeWidth="1">
                            {waves.map((wave, index) => (
                                <ellipse key={wave.key} cx={CENTER_X} cy={CENTER_Y} rx={80 + index * 44} ry={(80 + index * 44) * 0.5} />
                            ))}
                            {DIRECTIONS.map((direction, index) => {
                                const start = project((index * Math.PI) / 4, 54);
                                const end = project((index * Math.PI) / 4, 280);
                                return <line key={direction.key} x1={start.x} y1={start.y} x2={end.x} y2={end.y} />;
                            })}
                        </g>
                        <line
                            x1={CENTER_X}
                            y1={CENTER_Y}
                            x2={selectedEnd.x}
                            y2={selectedEnd.y}
                            stroke="var(--primary)"
                            strokeWidth="1.5"
                            strokeOpacity="0.45"
                        />
                        <g filter={`url(#${id}-glow)`} fill="none" strokeWidth="5" strokeOpacity="0.22" aria-hidden="true">
                            {segments.map(({ curve, wave, index }) => (
                                <path key={`${wave.key}-${index}`} d={curve} stroke={wave.color} />
                            ))}
                        </g>
                        {segments.map(({ curve, face, wave, index, depth }) => (
                            <g key={`${wave.key}-${index}`}>
                                <path d={face} fill={wave.color} fillOpacity="0.09" />
                                <path
                                    d={curve}
                                    fill="none"
                                    stroke={wave.color}
                                    strokeWidth="2.5"
                                    strokeOpacity={depth < CENTER_Y ? 0.7 : 1}
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                />
                            </g>
                        ))}
                        {waves.flatMap((wave, ring) =>
                            wave.normalized.map((value, index) => {
                                if (value === null) return [];
                                const point = project((index * Math.PI) / 4, 80 + ring * 44, value * WAVE_HEIGHT);
                                return (
                                    <circle
                                        key={`${wave.key}-${index}`}
                                        cx={point.x}
                                        cy={point.y}
                                        r={selected === index ? 5 : 3}
                                        fill={wave.color}
                                        stroke="var(--background)"
                                        strokeWidth="1.5"
                                        onMouseEnter={() => setSelected(index)}
                                        onClick={() => setSelected(index)}
                                    >
                                        <title>
                                            {DIRECTIONS[index].label} · {wave.label}: {formatValue(wave.values[index], wave.unit)}
                                        </title>
                                    </circle>
                                );
                            }),
                        )}
                    </svg>
                    {DIRECTIONS.map((direction, index) => {
                        const angle = (index * Math.PI) / 4;
                        return (
                            <button
                                key={direction.key}
                                type="button"
                                aria-label={`${direction.label}の実績を表示`}
                                aria-pressed={selected === index}
                                onMouseEnter={() => setSelected(index)}
                                onFocus={() => setSelected(index)}
                                onClick={() => setSelected(index)}
                                style={{
                                    left: `${((CENTER_X + Math.sin(angle) * 310) / WIDTH) * 100}%`,
                                    top: `${((CENTER_Y - Math.cos(angle) * 208) / HEIGHT) * 100}%`,
                                }}
                                className={cn(
                                    'hover:bg-accent focus-visible:outline-ring absolute flex min-h-11 min-w-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-md px-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 motion-reduce:transition-none',
                                    selected === index ? 'bg-accent text-accent-foreground' : 'text-muted-foreground',
                                )}
                            >
                                {direction.label}
                            </button>
                        );
                    })}
                </div>
                <div className="min-w-0 space-y-4">
                    <div className="flex items-baseline justify-between gap-2 border-b pb-3">
                        <h3 className="text-lg font-semibold">{DIRECTIONS[selected].label}</h3>
                        <span className="text-muted-foreground text-xs">内側 → 外側</span>
                    </div>
                    <dl className="space-y-4" aria-live="polite" aria-atomic="true">
                        {waves.map((wave, index) => (
                            <div key={wave.key} className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <dt className="flex items-center gap-2 text-sm">
                                    <span className="inline-block h-0.5 w-4 shrink-0" style={{ backgroundColor: wave.color }} aria-hidden="true" />
                                    <span className="text-muted-foreground text-xs">{index + 1}</span>
                                    {wave.label}
                                </dt>
                                <dd className="ml-auto text-sm font-medium tabular-nums">{formatValue(wave.values[selected], wave.unit)}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </div>
            {!hasData && <p className="text-muted-foreground text-sm">方位が登録された壁紙がまだありません。登録後に波形が表示されます。</p>}
            <p className="text-muted-foreground text-xs leading-relaxed">
                方位を選ぶと実数を表示します。波の高さは指標ごとに最大値を100%とした相対値で、指標間の金額・件数の比較には使いません。未登録の金額は点と線を表示しません。吉方位なし・判定不能・方位情報なしは下の表で確認できます。
            </p>
        </section>
    );
}
