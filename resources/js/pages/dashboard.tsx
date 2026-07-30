import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Database, Image, WalletCards } from 'lucide-react';

interface Props {
    stats: {
        wallpapers: number;
        total_prize_vnd: number;
        generated_images: number;
    };
}

export default function Dashboard({ stats }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'ダッシュボード', href: route('dashboard') }]}>
            <Head title="ダッシュボード" />
            <div className="space-y-6 p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Stat title="実績件数" value={`${stats.wallpapers.toLocaleString()}件`} icon={<Database />} />
                    <Stat title="累計賞金" value={`${stats.total_prize_vnd.toLocaleString()} VND`} icon={<WalletCards />} />
                    <Stat title="ローカル画像" value={`${stats.generated_images.toLocaleString()}枚`} icon={<Image />} />
                </div>
            </div>
        </AppLayout>
    );
}

function Stat({ title, value, icon }: { title: string; value: string; icon: React.ReactNode }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                <span className="text-amber-600">{icon}</span>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
            </CardContent>
        </Card>
    );
}
