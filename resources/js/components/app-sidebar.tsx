import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { ChartNoAxesCombined, Download, LayoutGrid, Settings, Sparkles } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'ダッシュボード・同期',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: '壁紙作成',
        url: '/wallpapers/create',
        icon: Sparkles,
    },
    {
        title: '壁紙履歴・ダウンロード',
        url: '/wallpapers',
        icon: Download,
    },
    {
        title: '実績登録',
        url: '/results',
        icon: ChartNoAxesCombined,
    },
    {
        title: 'アカウント・パスキー',
        url: '/settings/passkeys',
        icon: Settings,
    },
];

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
