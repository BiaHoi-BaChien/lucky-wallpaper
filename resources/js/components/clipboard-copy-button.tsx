import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

export function ClipboardCopyButton({
    value,
    label,
    variant = 'ghost',
    className,
}: {
    value: string;
    label: string;
    variant?: 'ghost' | 'outline';
    className?: string;
}) {
    const [status, setStatus] = useState<'idle' | 'copied' | 'error'>('idle');
    const actionLabel =
        status === 'copied' ? `${label}をコピーしました` : status === 'error' ? `${label}をコピーできませんでした` : `${label}をコピー`;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setStatus('copied');
        } catch {
            setStatus('error');
        }
    };

    return (
        <>
            <TooltipProvider delayDuration={300}>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            type="button"
                            variant={variant}
                            size="icon"
                            className={cn('shrink-0', className)}
                            aria-label={`${label}をコピー`}
                            onClick={copy}
                        >
                            {status === 'copied' ? <Check aria-hidden="true" /> : <Copy aria-hidden="true" />}
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{actionLabel}</TooltipContent>
                </Tooltip>
            </TooltipProvider>
            <span className="sr-only" role="status" aria-live="polite">
                {status === 'idle' ? '' : actionLabel}
            </span>
        </>
    );
}
