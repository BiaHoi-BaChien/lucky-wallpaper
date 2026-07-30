import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DeleteWallpaperDialog } from '@/components/wallpaper-delete-dialogs';
import AppLayout from '@/layouts/app-layout';
import { wallpaperStateLabel } from '@/lib/wallpaper-state';
import { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

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
    const { flash } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={[{ title: '壁紙履歴・ダウンロード', href: route('wallpapers.index') }]}>
            <Head title="壁紙履歴" />
            <div className="min-w-0 p-4">
                <Card>
                    <CardHeader className="p-4 sm:p-6">
                        <CardTitle>壁紙履歴</CardTitle>
                    </CardHeader>
                    <CardContent className="px-4 pb-4 sm:px-6 sm:pb-6">
                        {flash.status && <Alert className="mb-4">{flash.status}</Alert>}
                        <div className="min-w-0 rounded-md border">
                            <table className="block w-full text-left text-sm md:table">
                                <thead className="hidden md:table-header-group">
                                    <tr className="bg-muted/50 border-b">
                                        <th className="p-3">対象日</th>
                                        <th className="p-3">構図</th>
                                        <th className="p-3">画風</th>
                                        <th className="p-3">賞金</th>
                                        <th className="p-3">状態</th>
                                        <th>
                                            <span className="sr-only">操作</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="block w-full md:table-row-group">
                                    {wallpapers.data.length === 0 && (
                                        <tr className="block md:table-row">
                                            <td colSpan={6} className="text-muted-foreground block p-6 text-center md:table-cell">
                                                壁紙はまだ登録されていません。
                                            </td>
                                        </tr>
                                    )}
                                    {wallpapers.data.map((wallpaper) => (
                                        <tr
                                            key={wallpaper.id}
                                            className="block space-y-2 border-b p-4 last:border-b-0 md:table-row md:space-y-0 md:p-0"
                                        >
                                            <td className="grid grid-cols-[5rem_minmax(0,1fr)] gap-3 md:table-cell md:p-3 md:whitespace-nowrap">
                                                <span className="text-muted-foreground font-medium md:hidden">対象日</span>
                                                <span>{wallpaper.target_date}</span>
                                            </td>
                                            <td className="grid grid-cols-[5rem_minmax(0,1fr)] gap-3 md:table-cell md:p-3">
                                                <span className="text-muted-foreground font-medium md:hidden">構図</span>
                                                <span className="min-w-0 break-words">{wallpaper.title ?? '-'}</span>
                                            </td>
                                            <td className="grid grid-cols-[5rem_minmax(0,1fr)] gap-3 md:table-cell md:p-3">
                                                <span className="text-muted-foreground font-medium md:hidden">画風</span>
                                                <span className="min-w-0 break-words">{wallpaper.art_style ?? '-'}</span>
                                            </td>
                                            <td className="grid grid-cols-[5rem_minmax(0,1fr)] gap-3 md:table-cell md:p-3 md:whitespace-nowrap">
                                                <span className="text-muted-foreground font-medium md:hidden">賞金</span>
                                                <span>{wallpaper.prize_vnd === null ? '-' : `${wallpaper.prize_vnd.toLocaleString()} VND`}</span>
                                            </td>
                                            <td className="grid grid-cols-[5rem_minmax(0,1fr)] items-start gap-3 md:table-cell md:p-3">
                                                <span className="text-muted-foreground font-medium md:hidden">状態</span>
                                                <span>
                                                    <span className="bg-secondary text-secondary-foreground inline-flex rounded-full px-2 py-1 text-xs font-medium whitespace-nowrap">
                                                        {wallpaperStateLabel(wallpaper.state)}
                                                    </span>
                                                </span>
                                            </td>
                                            <td className="border-t pt-3 md:table-cell md:border-t-0 md:p-3">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={route('wallpapers.show', { wallpaper: wallpaper.id })}>詳細</Link>
                                                    </Button>
                                                    <DeleteWallpaperDialog wallpaper={wallpaper} />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {wallpapers.links.length > 3 && (
                            <nav className="mt-4 flex flex-wrap gap-2" aria-label="壁紙履歴のページ送り">
                                {wallpapers.links.map((link, index) => {
                                    const isPrevious = index === 0;
                                    const isNext = index === wallpapers.links.length - 1;
                                    const label = isPrevious ? (
                                        <>
                                            <ChevronLeft aria-hidden="true" />
                                            <span className="sr-only">前ページ</span>
                                        </>
                                    ) : isNext ? (
                                        <>
                                            <ChevronRight aria-hidden="true" />
                                            <span className="sr-only">次ページ</span>
                                        </>
                                    ) : (
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    );

                                    return (
                                        <Button
                                            key={`${link.label}-${index}`}
                                            size="sm"
                                            variant={link.active ? 'default' : 'outline'}
                                            disabled={!link.url}
                                            asChild={Boolean(link.url)}
                                            className={isPrevious || isNext ? 'size-9 p-0' : undefined}
                                        >
                                            {link.url ? <Link href={link.url}>{label}</Link> : <span>{label}</span>}
                                        </Button>
                                    );
                                })}
                            </nav>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
