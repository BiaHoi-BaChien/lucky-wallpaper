import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

interface Wallpaper {
    id: number;
    target_date: string;
    title: string | null;
    art_style: string | null;
    prize_vnd: number | null;
    state: string;
}

export default function WallpaperIndex({
    wallpapers,
}: {
    wallpapers: { data: Wallpaper[]; links: { url: string | null; label: string; active: boolean }[] };
}) {
    return (
        <AppLayout breadcrumbs={[{ title: '壁紙履歴・ダウンロード', href: route('wallpapers.index') }]}>
            <Head title="壁紙履歴" />
            <div className="p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>壁紙履歴</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="p-3">対象日</th>
                                        <th className="p-3">構図</th>
                                        <th className="p-3">画風</th>
                                        <th className="p-3">賞金</th>
                                        <th className="p-3">状態</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {wallpapers.data.map((wallpaper) => (
                                        <tr key={wallpaper.id} className="border-b">
                                            <td className="p-3">{wallpaper.target_date}</td>
                                            <td className="p-3">{wallpaper.title ?? '-'}</td>
                                            <td className="p-3">{wallpaper.art_style ?? '-'}</td>
                                            <td className="p-3">
                                                {wallpaper.prize_vnd === null ? '-' : `${wallpaper.prize_vnd.toLocaleString()} VND`}
                                            </td>
                                            <td className="p-3">{wallpaper.state}</td>
                                            <td className="p-3">
                                                <Button size="sm" variant="outline" asChild>
                                                    <Link href={route('wallpapers.show', { wallpaper: wallpaper.id })}>詳細</Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {wallpapers.links.map((link) => (
                                <Button
                                    key={link.label}
                                    size="sm"
                                    variant={link.active ? 'default' : 'outline'}
                                    disabled={!link.url}
                                    asChild={Boolean(link.url)}
                                >
                                    {link.url ? (
                                        <Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} />
                                    ) : (
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    )}
                                </Button>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
