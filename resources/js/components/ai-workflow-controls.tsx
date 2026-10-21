import { ClipboardCopyButton } from '@/components/clipboard-copy-button';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Download } from 'lucide-react';
import { ChangeEvent, useState } from 'react';

export interface ManualPrompt {
    prompt: string;
    prompt_hash: string;
    context_hash?: string;
    filename: string;
}

export function ManualPromptPanel({ prompt, title }: { prompt: ManualPrompt; title: string }) {
    const download = () => {
        const url = URL.createObjectURL(new Blob([prompt.prompt], { type: 'text/plain;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = prompt.filename;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 0);
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

async function fetchManualResponse(url: string, data?: Record<string, unknown>, signal?: AbortSignal): Promise<Response> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (data !== undefined) {
        headers['Content-Type'] = 'application/json';
        const xsrfToken = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        if (xsrfToken) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfToken);
    }

    const response = await fetch(url, {
        method: data === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers,
        body: data === undefined ? undefined : JSON.stringify(data),
        signal,
    });
    if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
        const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : undefined;
        throw new Error(firstError ?? payload.message ?? 'データを取得できませんでした。');
    }

    return response;
}

export async function fetchManualPrompt(url: string, data?: Record<string, unknown>, signal?: AbortSignal): Promise<ManualPrompt> {
    const response = await fetchManualResponse(url, data, signal);

    return response.json() as Promise<ManualPrompt>;
}
