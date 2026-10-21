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
    public function composition(string $targetDate, ?Wallpaper $wallpaper = null): array
    {
        $input = [
            'target_date' => $targetDate,
            'personal_star' => KigakuPolicy::PERSONAL_STAR,
            'timezone' => config('lucky.timezone'),
            'direction_labels' => KigakuPolicy::DIRECTIONS,
        ];
        if ($wallpaper !== null) {
            $input['previous_same_day_proposals'] = $wallpaper->proposals()->orderBy('sequence')
                ->get(['title', 'art_style', 'composition'])->toArray();
        }
        $prettyInput = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $schema = json_encode(KigakuPolicy::schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $version = KigakuPolicy::VERSION;
        $prompt = <<<PROMPT
あなたは九星気学を基にスマートフォン壁紙を制作するアートディレクターです。
対象日は{$targetDate}です。本命星は六白金星です。入力のタイムゾーンにおける対象日として判断してください。
九星気学による吉方位の判定、象意に基づく構図1案、その構図に一致する壁紙画像1枚を同じ回答で作成してください。
途中の構図確認は不要です。画像生成機能を実際に使用し、生成処理の完了まで待ってください。

判定と構図:
- 吉方位は「日盤＞月盤＞年盤」の優先順位で決定してください。3盤すべてに共通する方位だけに絞る方式は使わないでください。
- まず日盤の吉方位を探し、日盤に吉方位がないと確認できた場合だけ月盤へ、月盤にもない場合だけ年盤へ進んでください。最初に吉方位が見つかった盤を採用盤とし、その盤の吉方位をcandidate_directionsに記録してください。下位盤だけの吉方位を候補に混ぜないでください。
- 候補が複数ある場合は、確認できた下位盤を月盤、年盤の順に比較してprimary_directionを選んでください。下位盤で吉にならないことだけを理由に採用盤の吉方位を除外しないでください。比較しても決まらない場合は、象意と構図の対応から1つ選び、理由を説明してください。
- methodには「日盤＞月盤＞年盤の優先方式」、採用盤、使用した流派・暦の基準・日付の切り替わりを明記してください。reasonには確認した各盤の判定と採用理由、未確認の盤があればその旨を書いてください。月盤や年盤を採用した場合は、上位盤に吉方位がないことを説明してください。
- 対象日の根拠を確認して本命星との関係から判定し、reasonに具体的な根拠を書いてください。参照資料の一覧や出典名・出典URLは回答・JSONに含めないでください。referencesフィールドも出力しないでください。
- 吉方位ありはstatus=auspiciousとし、candidate_directionsに候補を列挙し、その中から構図に使うprimary_directionを1つだけ選んで採用理由を説明してください。fallback_reasonはnullです。
- 3盤すべてで吉方位がないと確認できた場合だけstatus=noneとしてください。採用盤を決めるために必要な上位盤を判定できない場合はstatus=undeterminedとし、未確認を「吉方位なし」と扱って下位盤へ進まないでください。採用盤が決まっている場合は、比較用の下位盤を確認できなくても判定不能にせず、確認できた根拠で主方位を選んでください。
- noneとundeterminedではcandidate_directions=[]、primary_direction=nullとし、fallback_reasonに理由を書いてください。どちらも六白金星の象意から補完構図を作り、吉方位に基づく構図と偽らないでください。
- 主方位の象意に因んだ動植物・色・形・素材などを選んで構図を決め、motifsに各モチーフと象意の対応を記録してください。補完時は六白金星との対応を書いてください。
- 画面上の上下左右を実際の方位と読み替えないでください。過去の当選実績を参照した推奨や当選確率の予測は行わないでください。
- previous_same_day_proposalsがある場合は、それらと実質的に異なる構図を考えてください。これは同日の作り直し用であり、過去実績ではありません。
- title、art_style、overview、composition、color_wu_xingには描画に必要な内容を書き、解釈と採用理由はsymbolismとkigakuにまとめてください。必要なモチーフを説明欄だけに書かないでください。
- titleとart_styleは各255文字以内、conclusionは「構図名 × 画風」の1行にしてください。

画像と納品:
- 縦長9:16、推奨1440×2560px。文字、数字、ロゴ、署名、透かしを入れないでください。時計やアイコンが重なる上部と下部は情報量を抑え、主要モチーフは安全領域に配置してください。
- JSON本文、方位名、鑑定理由、注記は画像に描き込まないでください。構図説明と一致する画像を作成してください。
- 次のJSON Schemaに一致するJSONオブジェクト本文と、同内容のUTF-8ファイル（wallpaper-composition-{$targetDate}.json）を提供してください。JSON内部に説明文やダウンロードリンクを混ぜないでください。
- 画像をチャット内にプレビュー表示し、表示したものと同じ実画像をPNG（wallpaper-{$targetDate}.png）またはJPEG（wallpaper-{$targetDate}.jpg）として添付・ダウンロード可能にしてください。JSONと画像は別ファイルです。
- 実ファイルを参照できる場合は、![壁紙プレビュー](実画像のURL) と [壁紙画像をダウンロード](同じ実画像のURL) をコードフェンスの外に表示してください。
- 直接参照できない場合は、画像生成機能の標準の画像表示と保存・ダウンロード操作を利用できる形で提示してください。
- 完了前に、JSON・画像の両方の生成成功と添付・参照先をツール結果で確認してください。生成済み画像の提示が欠けている場合は再添付・再表示してください。
- 画像生成やファイル提供ができない場合は、できなかった内容と理由を明記してください。未作成のファイルへのリンクや架空のURLを出さないでください。

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

    public function image(Wallpaper $wallpaper, ?CompositionProposal $proposal = null): array
    {
        $details = $proposal ?? $wallpaper;
        $symbolism = $this->imageSymbolism($details->symbolism);
        $prompt = implode("\n\n", [
            'スマートフォン用の縦長壁紙を1枚制作してください。縦横比9:16、推奨1440×2560px。画像内には文字、数字、ロゴ、署名、透かしを一切入れないでください。',
            '構図名: '.$details->title,
            '画風: '.$details->art_style,
            '概要: '.$details->overview,
            '配置: '.$details->composition,
            '色彩: '.$details->color_wu_xing,
            ...($symbolism === '' ? [] : ['補足描写: '.$symbolism]),
            '視認性: ロック画面の時計やアイコンが重なる上部と下部は情報量を抑え、主要モチーフは安全領域に配置する。',
            '保存済み構図に一致する画像を作成してください。画像生成を実行し、生成した実画像をプレビュー表示して、同じPNGまたはJPEGをダウンロードできるよう提供してください。',
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
