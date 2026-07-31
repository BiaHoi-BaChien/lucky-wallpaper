import { ClipboardCopyButton } from '@/components/clipboard-copy-button';
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
import { Textarea } from '@/components/ui/textarea';
import { Download, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

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
