import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { usePasskeyVerify } from '@laravel/passkeys/react';

interface LoginProps {
    status?: string;
}

export default function Login({ status }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
        remember: false as boolean,
    });
    const passkey = usePasskeyVerify({
        autofill: true,
        routes: {
            options: route('passkey.login-options'),
            submit: route('passkey.login'),
        },
        onSuccess: (response) => {
            window.location.href = response.redirect || route('dashboard');
        },
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="管理者ログイン" description="ユーザー名とパスワード、またはパスキーでログインします">
            <Head title="ログイン" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="username">ユーザー名</Label>
                        <Input
                            id="username"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="username webauthn"
                            value={data.username}
                            onChange={(e) => setData('username', e.target.value)}
                            placeholder="admin"
                        />
                        <InputError message={errors.username} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center">
                            <Label htmlFor="password">パスワード</Label>
                        </div>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="パスワード"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="flex items-center space-x-3">
                        <Checkbox
                            id="remember"
                            name="remember"
                            tabIndex={3}
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', checked === true)}
                        />
                        <Label htmlFor="remember">ログイン状態を保持</Label>
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={4} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        ログイン
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        disabled={!passkey.isSupported || passkey.isLoading}
                        onClick={() => passkey.verify()}
                    >
                        {passkey.isLoading ? '確認中…' : 'パスキーでログイン'}
                    </Button>
                    {passkey.error && <p className="text-sm text-red-600 dark:text-red-400">{passkey.error}</p>}
                </div>
            </form>

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600 dark:text-green-400">{status}</div>}
        </AuthLayout>
    );
}
