import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, Link, router } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { FormEvent, useState } from 'react';

interface Passkey {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string;
}

export default function Passkeys({ passkeys }: { passkeys: Passkey[] }) {
    const [name, setName] = useState('');
    const registration = usePasskeyRegister({
        routes: {
            options: route('passkey.registration-options'),
            submit: route('passkey.store'),
        },
        onSuccess: () => {
            setName('');
            router.reload();
        },
    });

    const register = (event: FormEvent) => {
        event.preventDefault();
        registration.register(name);
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'パスキー設定', href: route('passkeys.index') }]}>
            <Head title="パスキー設定" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="パスキー" description="HTTPSかつ本番ドメインと一致する環境で登録してください。" />
                    <p className="text-muted-foreground text-sm">
                        登録・削除前に現在のパスワード確認が必要です。未確認の場合は
                        <Link href={route('password.confirm')} className="ml-1 underline">
                            パスワード確認画面
                        </Link>
                        を開いてください。
                    </p>
                    <form onSubmit={register} className="flex gap-3">
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="例: Windows Hello" required />
                        <Button disabled={!registration.isSupported || registration.isLoading || name.trim() === ''}>
                            {registration.isLoading ? '登録中…' : '登録'}
                        </Button>
                    </form>
                    {registration.error && <p className="text-sm text-red-600">{registration.error}</p>}
                    <div className="space-y-3">
                        {passkeys.length === 0 && <p className="text-muted-foreground text-sm">登録済みパスキーはありません。</p>}
                        {passkeys.map((passkey) => (
                            <div key={passkey.id} className="flex items-center justify-between rounded-lg border p-4">
                                <div>
                                    <p className="font-medium">{passkey.name}</p>
                                    <p className="text-muted-foreground text-xs">最終利用: {passkey.last_used_at ?? '未使用'}</p>
                                </div>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => router.delete(route('passkey.destroy', { passkey: passkey.id }))}
                                >
                                    削除
                                </Button>
                            </div>
                        ))}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
