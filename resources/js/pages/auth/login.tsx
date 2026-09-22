import { Head, useForm } from '@inertiajs/react';
import type { VerifyResponse } from '@laravel/passkeys';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { flushSync } from 'react-dom';

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
    const [isRedirecting, setIsRedirecting] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
        remember: false as boolean,
    });
    const passkeyRoutes = {
        options: route('passkey.login-options'),
        submit: route('passkey.login'),
    };
    const handlePasskeySuccess = (response: VerifyResponse) => {
        // Show the loading screen before the browser starts a full-page navigation.
        flushSync(() => setIsRedirecting(true));
        window.location.href = response.redirect || route('dashboard');
    };
    const passkeyAutofill = usePasskeyVerify({
        autofill: true,
        routes: passkeyRoutes,
        onSuccess: handlePasskeySuccess,
    });
    const passkey = usePasskeyVerify({
        routes: passkeyRoutes,
        onSuccess: handlePasskeySuccess,
    });
    const isBusy = processing || passkey.isLoading || isRedirecting;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (isBusy) return;

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    if (isRedirecting) {
        return (
            <AuthLayout title="ログイン中" description="認証が完了しました。しばらくお待ちください。">
                <Head title="ログイン中" />
                <div role="status" className="flex flex-col items-center gap-4 py-6 text-center">
                    <LoaderCircle className="text-primary size-8 animate-spin" aria-hidden="true" />
                    <p className="text-muted-foreground text-sm">画面を読み込んでいます…</p>
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="管理者ログイン" description="ユーザー名とパスワード、またはパスキーでログインします">
            <Head title="ログイン" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <fieldset className="grid gap-6" disabled={isBusy}>
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

                    <Button type="submit" className="mt-4 w-full" tabIndex={4} disabled={isBusy}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        ログイン
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        disabled={!passkey.isSupported || isBusy}
                        onClick={() => passkey.verify()}
                    >
                        {passkey.isLoading && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
                        <span role="status">{passkey.isLoading ? 'パスキーを確認中…' : 'パスキーでログイン'}</span>
                    </Button>
                    {(passkey.error || passkeyAutofill.error) && (
                        <p className="text-sm text-red-600 dark:text-red-400">{passkey.error || passkeyAutofill.error}</p>
                    )}
                </fieldset>
            </form>

            {status && <div className="mb-4 text-center text-sm font-medium text-green-600 dark:text-green-400">{status}</div>}
        </AuthLayout>
    );
}
