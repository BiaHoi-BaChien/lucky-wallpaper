import { ClipboardCopyButton } from '@/components/clipboard-copy-button';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Download, LoaderCircle } from 'lucide-react';
import { ChangeEvent, useState } from 'react';

export type ExecutionMode = 'manual' | 'api';

export interface ManualPrompt {
    prompt: string;
    prompt_hash: string;
    context_hash: string;
    filename: string;
    default_result?: string | null;
}

export function ExecutionModeSelector({
    value,
    onChange,
    disabled = false,
}: {
    value: ExecutionMode;
    onChange: (mode: ExecutionMode) => void;
    disabled?: boolean;
}) {
    return (
        <div className="border-input inline-flex max-w-full rounded-md border p-1" role="radiogroup" aria-label="AI処理方法">
            <Button
                type="button"
                size="sm"
                variant={value === 'manual' ? 'secondary' : 'ghost'}
                role="radio"
                aria-checked={value === 'manual'}
                disabled={disabled}
                onClick={() => onChange('manual')}
            >
                手動（ChatGPT）
            </Button>
            <Button
                type="button"
                size="sm"
                variant={value === 'api' ? 'secondary' : 'ghost'}
                role="radio"
                aria-checked={value === 'api'}
                disabled={disabled}
                onClick={() => onChange('api')}
            >
                OpenAI API
            </Button>
        </div>
    );
}

export function ManualPromptPanel({ prompt, title }: { prompt: ManualPrompt; title: string }) {
    const download = () => {
        const url = URL.createObjectURL(new Blob([prompt.prompt], { type: 'text/plain;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = prompt.filename;
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <section className="space-y-3 border-t pt-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">{title}</h3>
                <div className="flex gap-2">
                    <ClipboardCopyButton value={prompt.prompt} label={title} variant="outline" className="size-9" />
                    <Button type="button" size="sm" variant="outline" onClick={download}>
                        <Download aria-hidden="true" />
                        ダウンロード
                    </Button>
                </div>
            </div>
            <Textarea readOnly value={prompt.prompt} rows={12} className="font-mono text-xs leading-5" aria-label={title} />
        </section>
    );
}

export function ManualResultField({
    id,
    label,
    value,
    onChange,
    placeholder,
    fileAccept,
    fileDescription,
    maxLength,
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    fileAccept: string;
    fileDescription: string;
    maxLength: number;
    error?: string;
}) {
    const [fileError, setFileError] = useState<string>();
    const fileInputId = `${id}_file`;

    const loadFile = async (event: ChangeEvent<HTMLInputElement>) => {
        const input = event.currentTarget;
        const file = input.files?.[0];
        if (!file) {
            return;
        }

        setFileError(undefined);
        try {
            if (file.size > maxLength * 4) {
                throw new Error(`ファイルが大きすぎます。${maxLength.toLocaleString('ja-JP')}文字以内のファイルを選択してください。`);
            }

            const bytes = await file.arrayBuffer();
            let text: string;
            try {
                text = new TextDecoder('utf-8', { fatal: true }).decode(bytes).replace(/^\uFEFF/, '');
            } catch {
                throw new Error('ファイルをUTF-8として読み取れませんでした。');
            }

            if (text.length > maxLength) {
                throw new Error(`ファイルが大きすぎます。${maxLength.toLocaleString('ja-JP')}文字以内のファイルを選択してください。`);
            }

            onChange(text);
        } catch (loadError) {
            setFileError(loadError instanceof Error ? loadError.message : 'ファイルを読み取れませんでした。');
        } finally {
            input.value = '';
        }
    };

    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Textarea
                id={id}
                rows={12}
                maxLength={maxLength}
                value={value}
                onChange={(event) => {
                    setFileError(undefined);
                    onChange(event.target.value);
                }}
                placeholder={placeholder}
            />
            <div className="space-y-2 pt-1">
                <Label htmlFor={fileInputId}>ファイルから入力</Label>
                <Input id={fileInputId} type="file" accept={fileAccept} onChange={loadFile} />
                <p className="text-muted-foreground text-sm">{fileDescription}</p>
            </div>
            <InputError message={fileError ?? error} />
        </div>
    );
}

export function ApiConfirmationButton({
    label,
    processingLabel,
    processing = false,
    disabled = false,
    onConfirm,
}: {
    label: string;
    processingLabel: string;
    processing?: boolean;
    disabled?: boolean;
    onConfirm: () => void;
}) {
    const [open, setOpen] = useState(false);

    const confirm = () => {
        setOpen(false);
        onConfirm();
    };

    return (
        <Dialog open={open} onOpenChange={(nextOpen) => !processing && setOpen(nextOpen)}>
            <DialogTrigger asChild>
                <Button type="button" disabled={disabled || processing}>
                    {processing && <LoaderCircle className="animate-spin" aria-hidden="true" />}
                    {processing ? processingLabel : label}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>OpenAI APIを実行しますか？</DialogTitle>
                    <DialogDescription>
                        OpenAI APIへリクエストを送信するため、API利用料金が発生する可能性があります。今回の処理だけAPIを使用します。
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            キャンセル
                        </Button>
                    </DialogClose>
                    <Button type="button" onClick={confirm}>
                        APIを実行する
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export async function fetchManualPrompt(url: string): Promise<ManualPrompt> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const payload = (await response.json()) as ManualPrompt & {
        message?: string;
        errors?: Record<string, string[]>;
    };
    if (!response.ok) {
        const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : undefined;
        throw new Error(firstError ?? payload.message ?? 'プロンプトを取得できませんでした。');
    }

    return payload;
}
