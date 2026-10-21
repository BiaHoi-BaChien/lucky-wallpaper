# 九星気学の記録仕様

本命星は六白金星。対象日とLUCKY_TIMEZONE（既定Asia/Ho_Chi_Minh）を外部AIへ渡し、盤・流派・判定方式の選択と吉方位の判断をAIに任せる。アプリ内で暦や吉方位を計算せず、過去実績や分析結果をプロンプトへ渡さない。

## JSON

ルートの必須項目はtitle、art_style、conclusion、overview、composition、color_wu_xing、symbolism、kigaku。titleとart_styleは255文字以内。conclusionは「構図名 × 画風」。新規JSONでは旧feng_shui・composition_zoneを受け付けない。旧形式の未登録JSONは新しいプロンプトで作成し直す。

kigakuは次を保持する。

| 項目 | 内容 |
| --- | --- |
| target_date | 登録対象と一致するYYYY-MM-DD |
| personal_star | 六白金星 |
| timezone | プロンプトのタイムゾーンと一致 |
| status | auspicious / none / undetermined |
| method | 採用した盤・流派・方式・暦の基準 |
| candidate_directions | 重複のない候補方位の配列 |
| primary_direction | 候補から選んだ一つの方位、またはnull |
| reason | 判定と主方位選択の根拠 |
| fallback_reason | 補完理由、またはnull |
| motifs | motif・meaningによるモチーフと象意の対応 |
| references | title・urlによる確認できた参照資料。未確認なら空配列。urlはHTTP(S)またはnull |

方位の値はnorth（北）、northeast（北東）、east（東）、southeast（南東）、south（南）、southwest（南西）、west（西）、northwest（北西）。

auspiciousでは候補と主方位を必須とし、主方位は候補に含める。fallback_reasonはnull。noneは判定の結果吉方位なし、undeterminedは暦や根拠を確認できず判定不能を意味する。後二者は候補=[]、主方位=null、補完理由を必須とし、六白金星の象意による構図と画像を作る。

方位を画面上の上下左右と読み替えない。旧来の丸み・安定した矩形・静穏・内向きなどの独自制約は新規生成に適用しない。9:16、推奨1440×2560、文字・ロゴなし、時計やアイコン用の余白を画像条件とする。

## 保存と集計

既存composition_proposals.calendar_contextにpolicy_version=kigaku-v1とkigakuを保存する。DB構造と過去データは保持し、analysis_hashは新規記録ではnull。JSONと画像を同時に検証・保存し、失敗時はDBと新規ファイルを残さない。プロンプトの変更や同日再提案の追加により古くなった入力は再取得を求める。

壁紙のchosen_proposal_idが指す、同じ壁紙に所属するapprovedの構図のみを集計する。候補や未採用案へ実績を重複計上しない。判定情報がない旧データと本文のみのNotion復元レコードはlegacy（方位情報なし）として扱う。主方位を推測で埋めない。

賞金未登録と0を区別する。1日平均は賞金合計÷実績登録件数、1口あたり賞金は口数既知の賞金合計÷その購入口数合計。口数不明の賞金を後者の分子へ含めない。

アプリは保存形式と整合性を検証する。外部AIの判定根拠の正しさ、実画像生成、プレビュー、ダウンロードの成否は手動の受入確認対象とする。
