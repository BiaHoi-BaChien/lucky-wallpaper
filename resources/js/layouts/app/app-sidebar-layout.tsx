import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Alert } from '@/components/ui/alert';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <Alert role="note" variant="warning" className="mx-4 mt-4">
                    本システムの提案は、過去実績との相関に基づく創作上の傾向です。宝くじの当選や当選確率の向上を保証するものではありません。
                </Alert>
                {children}
            </AppContent>
        </AppShell>
    );
}
