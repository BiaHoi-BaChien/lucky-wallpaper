import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Operation, useOperation } from '@/hooks/use-operation';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { SharedData, type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Notionバックアップ',
        href: route('notion-backup.index'),
    },
];

export default function NotionBackup({ latestRestore }: { latestRestore: Operation | null }) {
    const { integrations, errors } = usePage<SharedData & { errors: { restore?: string } }>().props;
    const { operation } = useOperation(latestRestore);
    const configured = integrations.notion.configured;
    const active = operation && ['queued', 'running'].includes(operation.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notionバックアップ" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Notionバックアップ" description="実績情報のバックアップと復元を管理します。" />

                    {configured ? (
                        <Alert>
                            <AlertTitle>Notionバックアップは利用可能です。</AlertTitle>
                            <AlertDescription>実績登録時に実績情報と画像をNotionへバックアップします。</AlertDescription>
                        </Alert>
                    ) : (
                        <Alert variant="warning">
                            <AlertTitle>Notionバックアップは未設定です。</AlertTitle>
                            <AlertDescription>
                                NOTION_TOKENが未設定です。バックアップと復元は利用できませんが、通常のサーバー機能は利用できます。
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="space-y-4">
                        <HeadingSmall
                            title="バックアップから実績情報を復元"
                            description="Notionバックアップの更新分を確認し、サーバーに未登録の実績情報を復元します。既存実績は上書きしません。"
                        />
                        <Button disabled={!configured || Boolean(active)} onClick={() => router.post(route('notion-syncs.store'))}>
                            {active && <LoaderCircle className="size-4 animate-spin" />}
                            {active ? '復元中' : 'バックアップから復元'}
                        </Button>
                        <InputError message={errors.restore} />
                        {operation && (
                            <div className="bg-muted space-y-1 rounded-lg p-4 text-sm">
                                <p>
                                    状態: <strong>{operation.status}</strong>
                                </p>
                                <p>
                                    進捗: {operation.processed ?? 0} / {operation.total ?? 0}
                                </p>
                                <p>
                                    復元 {operation.imported ?? 0}・既存 {operation.skipped_existing ?? 0}・必須不足 {operation.skipped_invalid ?? 0}
                                    ・本文空 {operation.skipped_empty_body ?? 0}
                                </p>
                                {operation.error_code && <p className="text-red-600 dark:text-red-400">エラー: {operation.error_code}</p>}
                                {operation.warnings?.map((warning) => (
                                    <p key={warning} className="text-amber-700 dark:text-amber-300">
                                        警告: {warning}
                                    </p>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
