import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { ChartNoAxesCombined, Download, LayoutGrid, Settings, Sparkles, TrendingUp } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'ダッシュボード',
        url: route('dashboard'),
        icon: LayoutGrid,
    },
    {
        title: '傾向分析',
        url: route('wallpaper-analyses.index'),
        icon: TrendingUp,
    },
    {
        title: '壁紙作成',
        url: route('wallpapers.create'),
        icon: Sparkles,
    },
    {
        title: '壁紙履歴・ダウンロード',
        url: route('wallpapers.index'),
        icon: Download,
    },
    {
        title: '実績登録',
        url: route('results.index'),
        icon: ChartNoAxesCombined,
    },
    {
        title: '設定',
        url: route('passkeys.index'),
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
                            <Link href={route('dashboard')} prefetch>
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
