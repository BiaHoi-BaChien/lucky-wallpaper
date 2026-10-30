<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use App\Models\CompositionProposal;
use App\Models\Wallpaper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class WallpaperPromptService
{
    public function composition(string $targetDate, ?Wallpaper $wallpaper = null, bool $jsonOnly = false): array
    {
        $judgement = app(KigakuDetermination::class)->determine($targetDate);
        $input = [
            'target_date' => $targetDate,
            'personal_star' => KigakuPolicy::PERSONAL_STAR,
            'timezone' => config('lucky.timezone'),
            'direction_labels' => KigakuPolicy::DIRECTIONS,
            'fixed_kigaku' => $judgement,
            'star_motif' => KigakuPolicy::MOTIFS[$judgement['direction_star'] ?? 6],
        ];
        if ($wallpaper !== null) {
            $input['previous_same_day_proposals'] = $wallpaper->proposals()->orderBy('sequence')
                ->get(['title', 'art_style', 'composition'])->toArray();
        }
        $prettyInput = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $schema = json_encode(KigakuPolicy::schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $version = KigakuPolicy::VERSION;
        $task = $jsonOnly
            ? 'アプリが確定した吉方位と配置を使い、象意に基づく構図1案をJSONオブジェクトのみで返してください。画像生成・ファイル作成・リンクの出力は不要です。画像はアプリがこのJSONから別途生成します。'
            : <<<'TASK'
アプリが確定した吉方位と配置を使い、象意に基づく構図1案、その構図に一致する壁紙画像1枚を同じ回答で作成してください。
必須の納品物は「構図の説明JSONファイル」と「壁紙画像ファイル」の2つです。両方をこのチャットから個別にダウンロードできる状態で返してください。画像だけを返して回答を終了しないでください。
途中の構図確認は不要です。画像生成機能を実際に使用し、生成処理の完了まで待ってください。
TASK;
        $delivery = $jsonOnly ? '' : <<<DELIVERY
必須の納品手順（順番どおりに実行）:
1. まず、次のJSON Schemaに一致する構図の説明JSONオブジェクトを作成してください。コード実行などのファイル作成機能を使い、UTF-8の実ファイル wallpaper-composition-{$targetDate}.json として保存してください。ファイルの中身はJSONオブジェクトのみとし、コードフェンス、説明文、ダウンロードリンクを混ぜないでください。
2. 保存したJSONファイルを再読込し、有効なJSONであることと、Schema・入力のfixed_kigakuに一致することを確認してから画像生成に進んでください。
3. そのJSONの構図に一致する画像を生成し、PNG（wallpaper-{$targetDate}.png）またはJPEG（wallpaper-{$targetDate}.jpg）の実ファイルとして保存してください。JSONと画像は別ファイルです。
4. 画像生成が終わったら、JSON・画像の両方の生成成功と添付・参照先をツール結果で確認してください。片方が欠けていれば、未作成のファイルを作成し、作成済みのファイルは再添付・再表示してください。
5. 最終回答には、次の2つのダウンロードリンクと画像プレビューを必ず含めてください。参照先には、実際に作成・確認したファイルの参照先を使い、コードフェンスの外に表示してください。
   - [構図の説明JSONをダウンロード](JSON実ファイルの参照先)
   - [壁紙画像をダウンロード](画像実ファイルの参照先)
   - ![壁紙プレビュー](同じ画像実ファイルの参照先)
- 画像の参照先を直接取得できない場合は、画像生成機能の標準の画像表示と保存・ダウンロード操作を利用できる形で提示し、JSONファイルのダウンロードリンクも同じ回答に必ず表示してください。
- JSON本文をチャットに表示するだけ、ファイル名だけを書く、「保存できます」と案内するだけでは納品完了ではありません。JSONファイルと画像ファイルの両方をダウンロードできることを完了条件にしてください。
- 画像生成やファイル提供ができない場合は、できなかった内容と理由を明記してください。未作成のファイルへのリンクや架空のURLを出さないでください。
DELIVERY;
        $prompt = <<<PROMPT
あなたは九星気学を基にスマートフォン壁紙を制作するアートディレクターです。
対象日は{$targetDate}です。本命星は六白金星です。入力のタイムゾーンにおける対象日として判断してください。
{$task}

判定と構図:
- 入力のfixed_kigakuをJSONのkigakuへそのままコピーし、motifsだけを追記してください。判定・候補順・主方位・回座星・構図位置・理由を変更したり、暦を推測・再判定しないでください。
- 本方式は壁紙用の独自方式です。六白金星を基準に月命星は使わず、二黒・八白・七赤・一白の回座方位から五黄殺・暗剣殺・本命殺・本命的殺・各盤の破を除外します。8方位で斜めの破は方位全体を除外し、中宮は方位として扱いません。
- 吉方位は日盤＞月盤＞年盤の順に確認し、最初に候補がある盤だけを採用済みです。3盤共通部分や下位盤の比較は使いません。複数候補は北→北東→東→南東→南→南西→西→北西の固定順で選択済みです。この順は吉の強弱を表しません。
- status=noneは全盤に候補なし、status=undeterminedは必要な盤を計算できず判定不能です。未確認を「なし」として下位盤へ進みません。両者の配置は中央（左50%、上55%）で六白金星の象意による補完です。
- 方位は主役の位置、回座星はモチーフ・質感を担当します。star_motifの固定対応表から主役を1つ選び、motifsのmotifには対応表の名称をそのまま使い、meaningに回座星（補完時は六白金星）との対応を書いてください。自然素材と銀白を尊重し、露骨な財宝・金貨・人工的な金色発光を避けてください。
- 画面上＝北、右＝東の象徴的な配置です。南が上の九星盤とは別の座標です。主役の中心をsubject_positionの左上原点の百分率に置き、compositionに同じ数値を記載してください。北(50,40)、北東(70,40)、東(70,55)、南東(70,70)、南(50,70)、南西(30,70)、西(30,55)、北西(30,40)、方位指定なし(50,55)です。
- 上部30%は時計用余白。主役1つ・自然光・上昇感を維持してください。配置と動きを分け、南でも下降表現にしません。スマホを実際の地理的な吉方位に向ける指示ではありません。
- 特定流派への完全準拠や当選への効果を主張しないでください。過去の当選実績を参照した推奨や当選確率の予測は行わないでください。
- 参照資料の一覧や出典名・出典URLは回答・JSONに含めないでください。referencesフィールドも出力しないでください。
- previous_same_day_proposalsがある場合は、それらと実質的に異なる構図を考えてください。これは同日の作り直し用であり、過去実績ではありません。
- title、art_style、overview、composition、color_wu_xingには描画に必要な内容を書き、解釈と採用理由はsymbolismとkigakuにまとめてください。必要なモチーフを説明欄だけに書かないでください。
- titleとart_styleは各255文字以内、conclusionは「構図名 × 画風」の1行にしてください。

画像:
- 縦長9:16、推奨1440×2560px。文字、数字、ロゴ、署名、透かしを入れないでください。上部30%は時計用余白とし、下部も情報量を抑えてください。
- JSON本文、方位名、鑑定理由、注記は画像に描き込まないでください。構図説明と一致する画像を作成してください。

{$delivery}

仕様バージョン: {$version}
JSON Schema:
{$schema}

入力データ:
{$prettyInput}
PROMPT;
        $inputHash = hash('sha256', $version.'|'.$prettyInput);

        return [
            'prompt' => $prompt,
            'input_hash' => $inputHash,
            'prompt_hash' => hash('sha256', $version.'|'.$prompt),
            'context_hash' => $inputHash,
            'filename' => 'wallpaper-creation-'.$targetDate.'.txt',
        ];
    }

    public function image(Wallpaper $wallpaper, ?CompositionProposal $proposal = null, bool $imageOnly = false): array
    {
        $details = $proposal ?? $wallpaper;
        $kigaku = $proposal === null ? $wallpaper->kigaku() : ($proposal->calendar_context['kigaku'] ?? null);
        $fixedPlacement = '';
        if (($kigaku['policy_version'] ?? null) === KigakuPolicy::VERSION) {
            $position = $kigaku['subject_position'];
            $fixedPlacement = '保存済みの主方位: '.(KigakuPolicy::DIRECTIONS[$kigaku['primary_direction'] ?? ''] ?? '指定なし')
                .'。回座星: '.(KigakuPolicy::STARS[$kigaku['direction_star'] ?? 0] ?? 'なし（六白金星で補完）')
                .'。保存済みの主役中心: 左から'.$position['x_percent'].'%、上から'.$position['y_percent'].'%。画面上＝北、右＝東の象徴的な配置。上部30%は時計用余白。主役1つ、自然光、上昇感。南でも下降させない。露骨な財宝・人工的な金色発光を避ける。';
        }
        $symbolism = $this->imageSymbolism($details->symbolism);
        $prompt = implode("\n\n", [
            'スマートフォン用の縦長壁紙を1枚制作してください。縦横比9:16、推奨1440×2560px。画像内には文字、数字、ロゴ、署名、透かしを一切入れないでください。',
            '構図名: '.$details->title,
            '画風: '.$details->art_style,
            '概要: '.$details->overview,
            '配置: '.$details->composition,
            ...($fixedPlacement === '' ? [] : [$fixedPlacement]),
            '色彩: '.$details->color_wu_xing,
            ...($symbolism === '' ? [] : ['補足描写: '.$symbolism]),
            '視認性: ロック画面の時計やアイコンが重なる上部と下部は情報量を抑え、主要モチーフは安全領域に配置する。',
            $imageOnly ? '構図に一致する壁紙画像を作成してください。'
                : '保存済み構図に一致する画像を作成してください。画像生成を実行し、生成した実画像をプレビュー表示して、同じPNGまたはJPEGをダウンロードできるよう提供してください。',
        ]);

        return [
            'prompt' => $prompt,
            'prompt_hash' => hash('sha256', KigakuPolicy::VERSION.'|'.$prompt),
            'context_hash' => hash('sha256', ($proposal->id ?? '').'|'.$prompt),
            'filename' => 'wallpaper-image-'.$wallpaper->target_date->format('Y-m-d').'.txt',
        ];
    }

    private function imageSymbolism(?string $symbolism): string
    {
        // Legacy symbolism can contain drawing instructions absent from the other fields.
        $analysisPattern = '/
            当選|高額|金額|購入口数|購入(?:額|金額|数|口数)|実績|統計|確率|相関|因果|母数|標本|サンプル
            |(?:過去|直近|累計|対象)\s*[0-9０-９,，]+\s*件
            |\b(?:VND|JPY|USD|EUR|prize|winnings|odds|statistics|correlation)\b
            |[₫$€¥￥]
            |[0-9０-９〇零一二三四五六七八九十百千万億兆,，.．]+\s*(?:円|ドン|ドル|ユーロ)
        /iux';
        $sentences = preg_split('/(?<=[。！？!?])|(?<=\.)\s+|\R+/u', $symbolism ?? '', flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $descriptions = [];

        foreach ($sentences as $sentence) {
            if (preg_match($analysisPattern, $sentence) === 1) {
                // Split mixed sentences at clause boundaries, keeping commas within amounts intact.
                $clauses = preg_split('/(?<=[、；;])\s*|(?<=,)(?!\s*[0-9０-９])\s*/u', $sentence) ?: [];
                $sentence = implode('', array_filter(
                    $clauses,
                    fn (string $clause): bool => preg_match($analysisPattern, $clause) === 0,
                ));
                $sentence = preg_replace('/[、,；;]\s*$/u', '。', $sentence) ?? '';
            }

            if (trim($sentence) !== '') {
                $descriptions[] = trim($sentence);
            }
        }

        return implode("\n", $descriptions);
    }

    public function parseProposal(string $json, string $targetDate): array
    {
        $json = trim($json);
        if (preg_match('/\A\x60{3}(?:json)?\s*(.*?)\s*\x60{3}\z/is', $json, $matches) === 1) {
            $json = trim($matches[1]);
        }
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['proposal_json' => '構図を有効なJSON形式で入力してください。']);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw ValidationException::withMessages(['proposal_json' => '構図はJSONオブジェクトで入力してください。']);
        }
        if (! isset($payload['kigaku']) || array_diff(array_keys($payload), [...KigakuPolicy::COMPOSITION_FIELDS, 'kigaku']) !== []) {
            throw ValidationException::withMessages(['proposal_json' => '九星気学の新しい形式が必要です。最新のプロンプトでJSONを作成し直してください。']);
        }
        $rules = [];
        foreach (KigakuPolicy::COMPOSITION_FIELDS as $field) {
            $rules[$field] = ['required', 'string', 'max:'.(in_array($field, ['title', 'art_style'], true) ? 255 : ($field === 'conclusion' ? 65535 : 500000))];
        }
        $validator = Validator::make($payload, [...$rules, ...KigakuPolicy::validationRules($targetDate)], [
            'required' => ':attributeは必須です。',
            'in' => ':attributeが対象日・本命星・方位の指定と一致しません。',
            'max' => ':attributeは:max以内にしてください。',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['proposal_json' => implode(' ', $validator->errors()->all())]);
        }
        $result = $validator->validated();
        $kigaku = $result['kigaku'];
        $expected = app(KigakuDetermination::class)->determine($targetDate);
        foreach ($expected as $field => $value) {
            $actual = $kigaku[$field];
            if ($field === 'subject_position') {
                $actual = ['x_percent' => $actual['x_percent'], 'y_percent' => $actual['y_percent']];
            }
            if ($actual !== $value) {
                throw ValidationException::withMessages(['proposal_json' => '吉方位の判定・配置がアプリの固定方式と一致しません。最新のプロンプトのfixed_kigakuをそのまま使用してください。']);
            }
        }
        $allowedMotifs = KigakuPolicy::MOTIFS[$expected['direction_star'] ?? 6]['motifs'];
        foreach ($kigaku['motifs'] as $motif) {
            if (! in_array($motif['motif'], $allowedMotifs, true)) {
                throw ValidationException::withMessages(['proposal_json' => 'モチーフは採用した回座星の固定対応表から選んでください。']);
            }
        }
        if ($kigaku['status'] === 'auspicious') {
            if ($kigaku['primary_direction'] === null
                || ! in_array($kigaku['primary_direction'], $kigaku['candidate_directions'], true)
                || $kigaku['fallback_reason'] !== null) {
                throw ValidationException::withMessages(['proposal_json' => '主方位は吉方位の候補から1つ選び、補完理由はnullにしてください。']);
            }
        } elseif ($kigaku['candidate_directions'] !== [] || $kigaku['primary_direction'] !== null
            || trim($kigaku['fallback_reason'] ?? '') === '') {
            throw ValidationException::withMessages(['proposal_json' => '吉方位なし・判定不能では方位を空にし、補完理由を記録してください。']);
        }

        return $result;
    }

    public function saveProposal(Wallpaper $wallpaper, array $result, string $expectedInputHash, bool $reproposal): CompositionProposal
    {
        $result = $this->parseProposal(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $wallpaper->target_date->format('Y-m-d'));

        return DB::transaction(function () use ($wallpaper, $result, $expectedInputHash, $reproposal): CompositionProposal {
            $wallpaper = Wallpaper::query()->lockForUpdate()->findOrFail($wallpaper->id);
            $prepared = $this->composition($wallpaper->target_date->format('Y-m-d'), $reproposal ? $wallpaper : null);
            if (! hash_equals($prepared['input_hash'], $expectedInputHash)) {
                throw new ExternalApiException('composition_prompt_stale', false);
            }
            $kigaku = $result['kigaku'];
            unset($result['kigaku']);
            $wallpaper->proposals()->where('status', 'proposed')->update(['status' => 'rejected']);
            $proposal = $wallpaper->proposals()->create([
                ...$result,
                'sequence' => ((int) $wallpaper->proposals()->max('sequence')) + 1,
                'status' => 'approved',
                'calendar_context' => ['policy_version' => KigakuPolicy::VERSION, 'kigaku' => $kigaku],
                'analysis_hash' => null,
                'input_hash' => $prepared['input_hash'],
            ]);
            $wallpaper->update([
                ...$result,
                'composition_zone' => null,
                'state' => 'generated',
                'warnings' => [],
                'chosen_proposal_id' => $proposal->id,
            ]);

            return $proposal;
        });
    }
}
