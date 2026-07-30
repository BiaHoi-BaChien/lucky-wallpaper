const wallpaperStateLabels: Record<string, string> = {
    draft: '構図生成待ち',
    proposed: '構図提案済み',
    approved: '構図承認済み',
    generated: '画像生成済み',
    archived: 'Notionバックアップ済み',
    result_synced: '実績バックアップ済み',
    imported: 'バックアップから復元',
};

const proposalStatusLabels: Record<string, string> = {
    proposed: '提案中',
    approved: '承認済み',
    rejected: '却下',
};

export function wallpaperStateLabel(state: string): string {
    return wallpaperStateLabels[state] ?? state;
}

export function proposalStatusLabel(status: string): string {
    return proposalStatusLabels[status] ?? status;
}
