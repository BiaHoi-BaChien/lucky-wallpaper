import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

export default function Setup() {
    const form = useForm({
        setup_key: '',
        username: '',
        name: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post('/setup', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title="初回管理者作成" description="管理者が0件のときだけ利用できる初期設定です">
            <Head title="初回設定" />
            <form onSubmit={submit} className="space-y-5">
                <div className="space-y-2">
                    <Label htmlFor="setup_key">セットアップキー</Label>
                    <Input
                        id="setup_key"
                        type="password"
                        value={form.data.setup_key}
                        onChange={(e) => form.setData('setup_key', e.target.value)}
                        required
                    />
                    <InputError message={form.errors.setup_key} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="username">ユーザー名</Label>
                    <Input
                        id="username"
                        autoComplete="username"
                        value={form.data.username}
                        onChange={(e) => form.setData('username', e.target.value)}
                        required
                    />
                    <InputError message={form.errors.username} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="name">表示名</Label>
                    <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                    <InputError message={form.errors.name} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="password">パスワード（12文字以上）</Label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        required
                    />
                    <InputError message={form.errors.password} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="password_confirmation">パスワード確認</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                        required
                    />
                </div>
                <Button className="w-full" disabled={form.processing}>
                    {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                    管理者を作成
                </Button>
            </form>
        </AuthLayout>
    );
}
