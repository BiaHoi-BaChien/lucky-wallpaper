import InputError from '@/components/input-error';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { wallpaperStateLabel } from '@/lib/wallpaper-state';
import { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, LoaderCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Wallpaper {
    id: number;
    target_date: string;
    title: string | null;
    art_style: string | null;
    prize_vnd: number | null;
    state: string;
}

function DeleteWallpaperDialog({ wallpaper, notionConfigured }: { wallpaper: Wallpaper; notionConfigured: boolean }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [deleteError, setDeleteError] = useState<string>();

    const destroy = () => {
        router.delete(route('wallpapers.destroy', { wallpaper: wallpaper.id }), {
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setDeleteError(undefined);
            },
            onSuccess: () => setOpen(false),
            onError: (errors) => setDeleteError(errors.delete),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (!processing) {
                    setOpen(nextOpen);
                    if (nextOpen) {
                        setDeleteError(undefined);
                    }
                }
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm" variant="destructive">
                    <Trash2 aria-hidden="true" />
                    削除
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>この壁紙履歴を削除しますか？</DialogTitle>
                    <DialogDescription>
                        {wallpaper.target_date}
                        {notionConfigured
                            ? 'の履歴を削除します。サーバー上のデータベースレコードと画像ファイルは完全に削除され、Notionバックアップはゴミ箱へ移動します。この画面からは元に戻せません。'
                            : 'の履歴をサーバーから削除します。サーバー上のデータベースレコードと画像ファイルは完全に削除されます。Notionバックアップは変更されません。この画面からは元に戻せません。'}
                    </DialogDescription>
                </DialogHeader>
                <InputError message={deleteError} />
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline" disabled={processing}>
                            キャンセル
                        </Button>
                    </DialogClose>
                    <Button type="button" variant="destructive" disabled={processing} onClick={destroy}>
                        {processing && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                        {processing ? '削除中' : '削除する'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function WallpaperIndex({
    wallpapers,
}: {
    wallpapers: { data: Wallpaper[]; links: { url: string | null; label: string; active: boolean }[] };
}) {
    const { flash, integrations } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={[{ title: '壁紙履歴・ダウンロード', href: route('wallpapers.index') }]}>
            <Head title="壁紙履歴" />
            <div className="p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>壁紙履歴</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {flash.status && <Alert className="mb-4">{flash.status}</Alert>}
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-left text-sm">
                                <thead>
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
                                <tbody>
                                    {wallpapers.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                                壁紙はまだ登録されていません。
                                            </td>
                                        </tr>
                                    )}
                                    {wallpapers.data.map((wallpaper) => (
                                        <tr key={wallpaper.id} className="border-b last:border-b-0">
                                            <td className="p-3 whitespace-nowrap">{wallpaper.target_date}</td>
                                            <td className="p-3">{wallpaper.title ?? '-'}</td>
                                            <td className="p-3">{wallpaper.art_style ?? '-'}</td>
                                            <td className="p-3 whitespace-nowrap">
                                                {wallpaper.prize_vnd === null ? '-' : `${wallpaper.prize_vnd.toLocaleString()} VND`}
                                            </td>
                                            <td className="p-3">
                                                <span className="bg-secondary text-secondary-foreground inline-flex rounded-full px-2 py-1 text-xs font-medium whitespace-nowrap">
                                                    {wallpaperStateLabel(wallpaper.state)}
                                                </span>
                                            </td>
                                            <td className="p-3">
                                                <div className="flex justify-end gap-2">
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={route('wallpapers.show', { wallpaper: wallpaper.id })}>詳細</Link>
                                                    </Button>
                                                    <DeleteWallpaperDialog wallpaper={wallpaper} notionConfigured={integrations.notion.configured} />
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
