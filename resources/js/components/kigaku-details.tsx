const directions: Record<string, string> = {
    north: '北',
    northeast: '北東',
    east: '東',
    southeast: '南東',
    south: '南',
    southwest: '南西',
    west: '西',
    northwest: '北西',
};
const statuses = { auspicious: '吉方位あり', none: '吉方位なし', undetermined: '判定不能' };
export interface Kigaku {
    target_date: string;
    personal_star: string;
    timezone: string;
    status: keyof typeof statuses;
    method: string;
    candidate_directions: string[];
    primary_direction: string | null;
    reason: string;
    fallback_reason: string | null;
    motifs: { motif: string; meaning: string }[];
    references?: { title: string; url: string | null }[];
}
export function KigakuDetails({ value }: { value: Kigaku | null }) {
    return (
        <section className="space-y-3 border-t pt-4">
            <h2 className="text-lg font-semibold">九星気学の判定</h2>
            {!value ? (
                <p className="text-muted-foreground text-sm">判定情報なし。過去の構図や復元した本文から方位を推測していません。</p>
            ) : (
                <>
                    <p>
                        {statuses[value.status]}・主方位: {value.primary_direction ? directions[value.primary_direction] : 'なし'}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {value.target_date}・{value.personal_star}・{value.timezone}
                    </p>
                    <dl className="space-y-3 text-sm">
                        <Entry title="採用方式" body={value.method} />
                        <Entry title="候補方位" body={value.candidate_directions.map((d) => directions[d]).join('、') || 'なし'} />
                        <Entry title="判断根拠" body={value.reason} />
                        {value.fallback_reason && <Entry title="補完理由" body={value.fallback_reason} />}
                        {value.motifs.map((motif, index) => (
                            <Entry key={index} title={motif.motif} body={motif.meaning} />
                        ))}
                    </dl>
                    {value.references && value.references.length > 0 && (
                        <div className="space-y-2 text-sm">
                            <h3 className="font-medium">参照資料</h3>
                            <ul className="space-y-1">
                                {value.references.map((reference, index) => (
                                    <li key={index}>
                                        {reference.url && /^https?:\/\//i.test(reference.url) ? (
                                            <a
                                                href={reference.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-primary break-all underline"
                                            >
                                                {reference.title}
                                            </a>
                                        ) : (
                                            reference.title
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
function Entry({ title, body }: { title: string; body: string }) {
    return (
        <div>
            <dt className="font-medium">{title}</dt>
            <dd className="mt-1 break-words whitespace-pre-wrap">{body}</dd>
        </div>
    );
}
