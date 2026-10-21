import { fetchManualPrompt, ManualPrompt, ManualPromptPanel, ManualResultField } from '@/components/ai-workflow-controls';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { ImagePlus, LoaderCircle } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';

export function ManualWallpaperForm({ targetDate, wallpaperId }: { targetDate: string; wallpaperId?: number }) {
    const form = useForm<{ target_date: string; proposal_json: string; prompt_hash: string; image: File | null }>({
        target_date: targetDate,
        proposal_json: '',
        prompt_hash: '',
        image: null,
    });
    const [prompt, setPrompt] = useState<ManualPrompt>();
    const [loading, setLoading] = useState(false);
    const [promptError, setPromptError] = useState<string>();
    const [preview, setPreview] = useState<string>();
    const controller = useRef<AbortController | null>(null);
    const busy = loading || form.processing;
    useEffect(() => () => controller.current?.abort(), []);
    useEffect(() => {
        if (!form.data.image) {
            setPreview(undefined);
            return;
        }
        const url = URL.createObjectURL(form.data.image);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [form.data.image]);

    const load = async () => {
        controller.current?.abort();
        const current = new AbortController();
        controller.current = current;
        setLoading(true);
        setPromptError(undefined);
        try {
            const url =
                wallpaperId === undefined
                    ? route('wallpapers.proposals.manual-prompt', { target_date: targetDate })
                    : route('wallpapers.reproposal.manual-prompt', { wallpaper: wallpaperId });
            const result = await fetchManualPrompt(url, undefined, current.signal);
            if (current.signal.aborted) return;
            setPrompt(result);
            form.setData('prompt_hash', result.prompt_hash);
        } catch (error) {
            if (!current.signal.aborted) setPromptError(error instanceof Error ? error.message : 'プロンプトを取得できませんでした。');
        } finally {
            if (!current.signal.aborted) setLoading(false);
        }
    };
    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            wallpaperId === undefined
                ? route('wallpapers.proposals.manual-result')
                : route('wallpapers.reproposal.manual-result', { wallpaper: wallpaperId }),
            {
                forceFormData: true,
                preserveScroll: 'errors',
            },
        );
    };

    return (
        <section className="space-y-4">
            <p className="text-muted-foreground text-sm">
                {targetDate}・本命星は六白金星。プロンプトを外部AIへ渡し、吉方位の判定・構図JSON・壁紙画像をまとめて作成してもらいます。
            </p>
            <Button type="button" disabled={busy} onClick={() => void load()}>
                {loading ? <LoaderCircle className="animate-spin" aria-hidden="true" /> : <ImagePlus aria-hidden="true" />}
                {loading ? '作成中' : '壁紙作成プロンプトを作成'}
            </Button>
            <InputError message={promptError ?? form.errors.target_date} />
            {prompt && <ManualPromptPanel prompt={prompt} title="壁紙作成プロンプト" />}
            {(wallpaperId === undefined || prompt) && (
                <form onSubmit={save} className="border-t pt-4">
                    <fieldset disabled={busy} className="min-w-0 space-y-4">
                        <legend className="mb-3 font-medium">構図JSONと壁紙画像を登録</legend>
                        {wallpaperId === undefined && (
                            <p className="text-muted-foreground text-sm">
                                プロンプトを表示していなくても、対象日に対応するJSONと画像を登録できます。
                            </p>
                        )}
                        <ManualResultField
                            id="proposal_json"
                            label="構図の説明JSON"
                            value={form.data.proposal_json}
                            onChange={(value) => {
                                form.setData('proposal_json', value);
                                form.clearErrors('proposal_json');
                            }}
                            placeholder="AIから返されたJSONを貼り付けます"
                            fileAccept=".json,.txt,application/json,text/plain"
                            fileDescription="JSON・テキスト（UTF-8）、最大2,000,000文字"
                            maxLength={2_000_000}
                            error={form.errors.proposal_json}
                        />
                        <div className="space-y-2">
                            <Label htmlFor="creation_image">壁紙画像</Label>
                            <Input
                                id="creation_image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(event) => {
                                    form.setData('image', event.target.files?.[0] ?? null);
                                    form.clearErrors('image');
                                }}
                            />
                            <p className="text-muted-foreground text-sm">JPEG・PNG・WebP、最大20MB</p>
                            <InputError message={form.errors.image} />
                            {preview && (
                                <img src={preview} alt="登録する壁紙画像のプレビュー" className="max-h-80 max-w-full rounded-md object-contain" />
                            )}
                        </div>
                        <InputError message={form.errors.prompt_hash} />
                        {form.progress && (
                            <p className="text-muted-foreground text-sm" role="status">
                                アップロード中: {form.progress.percentage}%
                            </p>
                        )}
                        <Button type="submit" disabled={busy || !form.data.image || !form.data.proposal_json.trim()}>
                            {form.processing && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                            {form.processing ? '登録中' : '構図と壁紙を登録'}
                        </Button>
                    </fieldset>
                </form>
            )}
        </section>
    );
}
