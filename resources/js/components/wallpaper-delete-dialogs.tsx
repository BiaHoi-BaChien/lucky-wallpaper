import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { router } from '@inertiajs/react';
import { ImageOff, LoaderCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface WallpaperReference {
    id: number;
    target_date: string;
}

export function DeleteWallpaperDialog({ wallpaper, label = '削除' }: { wallpaper: WallpaperReference; label?: string }) {
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
                    {label}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>この壁紙履歴を削除しますか？</DialogTitle>
                    <DialogDescription>
                        {wallpaper.target_date}
                        の履歴をサーバーから削除します。サーバー上のデータベースレコードと画像ファイルは完全に削除されます。Notionバックアップは変更されません。この画面からは元に戻せません。
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

export function DeleteWallpaperImageDialog({ wallpaper }: { wallpaper: WallpaperReference }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [deleteError, setDeleteError] = useState<string>();

    const destroyImage = () => {
        router.delete(route('wallpapers.image.destroy', { wallpaper: wallpaper.id }), {
            preserveScroll: true,
            onStart: () => {
                setProcessing(true);
                setDeleteError(undefined);
            },
            onSuccess: () => setOpen(false),
            onError: (errors) => setDeleteError(errors.deleteImage),
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
                <Button size="sm" variant="outline">
                    <ImageOff aria-hidden="true" />
                    画像のみ削除
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>サーバー上の画像ファイルを削除しますか？</DialogTitle>
                    <DialogDescription>
                        {wallpaper.target_date}
                        の画像ファイルのみ削除します。壁紙履歴、構図、実績データ、Notionバックアップは削除されません。
                    </DialogDescription>
                </DialogHeader>
                <InputError message={deleteError} />
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline" disabled={processing}>
                            キャンセル
                        </Button>
                    </DialogClose>
                    <Button type="button" variant="destructive" disabled={processing} onClick={destroyImage}>
                        {processing && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                        {processing ? '削除中' : '画像を削除する'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
