# Lucky Wallpaper

九星気学を基にスマートフォン壁紙を作成し、壁紙と宝くじの実績を記録する単一管理者向けシステムです。本命星は六白金星に固定しています。

対象日用の指示プロンプトをユーザーが外部AIへ手動で渡し、吉方位の判定、象意に基づく構図、壁紙画像をまとめて作成してもらいます。返された構図JSONと画像を一括登録し、後から賞金と任意の購入口数を登録します。AI APIへの送信経路や傾向分析機能はありません。

吉方位は日盤＞月盤＞年盤の順で決定します。日盤に吉方位がなければ月盤、月盤にもなければ年盤へ進み、最初に吉方位が見つかった盤を採用します。3盤共通を条件にせず、複数候補がある場合は下位盤を順に比較して主方位を選びます。AIは採用盤・方式・根拠を記録し、参照資料の一覧・出典名・出典URLを回答や構図JSONに含めません。3盤すべてに吉方位がない場合と、必要な判定ができない場合を区別し、どちらも六白金星の象意から補完構図を作成します。アプリは入力の形式・整合性を検証し、鑑定の正しさや当選確率の向上を保証するものではありません。保存形式は[九星気学の記録仕様](docs/kigaku-policy.md)を参照してください。

## 技術構成

- Laravel 13 / PHP 8.5.12以上
- Inertia 2 / React 19 / TypeScript / Tailwind CSS 4
- MySQL / DBキュー（Notion連携用）
- Laravel Passkeys（WebAuthn）
- Notion API `2026-03-11`（任意）

ReactはViteで事前ビルドし、SSRや本番Nodeプロセスを使用しません。

## ローカルセットアップ

DBの接続先がローカル開発用であることを確認してから実行します。

~~~powershell
Copy-Item .env.example .env
composer install
npm ci
php artisan key:generate
~~~

.envにMySQL接続、HTTPSのAPP_URL、初回設定用APP_SETUP_KEY、APP_KEYとは別のPASSKEYS_USER_HANDLE_SECRETを設定します。秘密情報は.env以外へ保存しないでください。初回起動後、/setupで管理者を1件だけ作成します。パスワードはArgon2idで保存されます。

~~~powershell
php artisan migrate
npm run build
php artisan serve
~~~

この仕様変更で新しいDBマイグレーションは追加していません。過去の壁紙・実績・旧分析履歴は保持します。

## 壁紙作成と実績確認

1. 「壁紙作成」で対象日を選択します。初期値は設定タイムゾーンにおける翌日です。
2. 「壁紙作成プロンプトを作成」で指示をコピーまたはダウンロードし、画像生成できる外部AIへ手動で渡します。
3. AIが返したJSONを貼り付けるかUTF-8ファイルから読み込み、画像と一緒に登録します。
4. 壁紙詳細で画像・構図・主方位・採用方式・根拠を確認します。
5. 「実績登録」で賞金（VND、0以上の整数）と任意の購入口数を登録します。
6. ダッシュボードで全期間の主方位別の件数・賞金合計・1日平均・1口あたり賞金を確認します。各行から主方位で絞り込んだ壁紙履歴へ移動でき、上部から最新の対象日の壁紙の実績登録画面を開けます。

作成済みのJSONと画像がある場合は、対象日を選んで「日付を適用」すれば、プロンプトを表示せずに登録できます。ブラウザを閉じた後も同じ手順で再開できます。JSON内の対象日は選択した日付と一致している必要があります。

1日につき壁紙は1件です。画像がない既存レコードでは構図と画像の再作成、または保存済み構図から画像だけの手動登録ができます。既存画像の暗黙の上書きは行いません。

画像はJPEG・PNG・WebP、最大20MBです。受信時は一辺8192px・合計1600万pxを上限とし、1440×2560のJPEGへ正規化します。PHPのupload_max_filesizeは20MBより大きく、post_max_sizeは32MB以上を推奨します。

## ダッシュボードの集計

採用済み構図に保存した主方位に1件ずつ計上します。複数の候補方位へ重複計上しません。「吉方位なし」「判定不能」「方位情報なし」は別行です。旧データやNotionから復元した本文の方位を推測して集計しません。

- 1日平均 = 賞金合計 ÷ 実績登録件数
- 1口あたり賞金 = 購入口数が分かる実績の賞金合計 ÷ 同じ実績の購入口数合計

賞金未登録は平均から除外し、0 VNDは実績1件に含めます。購入口数不明は1口あたり賞金の分子・分母から除外し、対象件数も表示します。計算対象のない平均は「—」です。ダッシュボードは常に全期間を集計します。期間による絞り込みは壁紙履歴で行えます。

## Notionバックアップと復元

NOTION_TOKENは任意です。未設定でも壁紙・実績の記録と閲覧ができます。設定時は実績登録後に賞金・画像をバックアップします。新規Notionページの本文には構図と九星気学の判定情報を含めます。購入口数はサーバーにのみ保存します。既存ページの本文の一括書換えは行いません。

WALLPAPER_DELETE_AFTER_NOTION_BACKUP=trueでは、バックアップ成功後にローカル画像を削除し、閲覧・ダウンロードにNotion接続を使います。falseでは画像をローカルにも保持します。

画面表示時のNotion画像確認は1回・通信全体3秒以内に制限し、失敗時も構図や実績フォームを表示します。バックアップ・復元処理の再試行は従来どおりです。

復元は前回成功時刻以降に更新されたNotion実績を対象とし、サーバーに存在する実績を上書きしません。復元した判定情報は本文として閲覧できますが、構造化データとして復元しないため方位別集計では「方位情報なし」に含めます。本文取得には深度20・10,000ブロック・300 APIページ・1MBの上限があります。サーバーから履歴を削除してもNotionバックアップは変更しません。

## 主要操作

- `GET /dashboard`: 全期間の主方位別実績集計
- `GET /wallpapers`: 履歴。date_from・date_to・directionで絞り込み
- `GET /wallpapers/proposals/manual-prompt`: 対象日の作成プロンプト
- `POST /wallpapers/proposals/manual-result`: 構図JSONと画像の一括登録
- `GET|POST /wallpapers/{id}/proposals/manual-*`: 手動再作成プロンプト・一括登録
- `GET|POST /wallpapers/{id}/image/manual-*`: 保存済み構図の手動画像作成・登録
- `GET /wallpapers/{id}/preview`・`download`: 認証済み画像表示・保存
- `PUT /wallpapers/{id}/result`: 賞金と購入口数の登録
- `GET /settings/notion-backup`・`POST /notion-syncs`: バックアップ設定・復元
- `POST /wallpapers/{id}/restore-image`: Notion画像をローカルへ復元
- `GET /operations/{id}`: Notion非同期処理の進捗

## 品質確認

~~~powershell
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run lint:check
npm run types:check
npm run format:check
npm run build
composer audit
npm audit
php artisan schedule:list
~~~

WindowsでGDが既定で有効でない場合は、例えば`php -d extension=D:\php\ext\php_gd.dll vendor/bin/phpunit`で画像テストを実行します。

## Hostinger

mainへのpush後、テスト成功時にGitHub ActionsからHostingerへ自動デプロイできます。切替時の旧AIワーカー停止、残キューの扱い、cronなどは[配置・運用手順](docs/hostinger.md)を参照してください。
