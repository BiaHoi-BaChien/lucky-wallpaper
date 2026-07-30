# Lucky Wallpaper

サーバーに保存した過去実績を参照し、金運をテーマにしたスマートフォン壁紙の構図提案・画像生成・実績登録を行う単一管理者向けシステムです。Notion連携は実績情報と画像のバックアップ、およびバックアップからの復元に使用します。

提案は「過去実績との相関に基づく創作上の傾向」であり、宝くじの当選や当選確率の向上を保証しません。

## 技術構成

- Laravel 13 / PHP 8.3以上
- Inertia 2 / React 19 / TypeScript / Tailwind CSS 4
- MySQL / DBキュー
- Laravel Passkeys（WebAuthn）
- Notion API `2026-03-11`
- OpenAI Responses API / Image API

ReactはViteで事前ビルドし、SSRや本番Nodeプロセスを使用しません。

## ローカルセットアップ

実DBへマイグレーションを適用する前に、接続先がローカル開発DBであることを確認してください。

```bash
cp .env.example .env
composer install
npm ci --cache /tmp/lucky-wallpaper-npm-cache
php artisan key:generate
```

`.env`にMySQL接続、HTTPSの`APP_URL`、各サービスの秘密情報を設定します。

```dotenv
APP_URL=https://wallpaper.example.com
APP_SETUP_KEY=十分に長いランダム値
PASSKEYS_USER_HANDLE_SECRET=APP_KEYとは別の十分に長いランダム値
NOTION_TOKEN=
OPENAI_API_KEY=
WALLPAPER_DELETE_AFTER_NOTION_BACKUP=true
```

`NOTION_TOKEN`は任意です。未設定でも実績登録、壁紙作成、履歴管理などのサーバー機能は利用できますが、Notionへのバックアップとバックアップからの復元は利用できません。`WALLPAPER_DELETE_AFTER_NOTION_BACKUP=true`の場合は、バックアップ成功後にサーバー上の画像を削除するため、バックアップ済み画像のダウンロードにはNotion接続が必要です。`false`にすると、バックアップ後もサーバー上の画像を保持します。

復元は前回の成功時刻以降に更新されたNotion実績を対象とし、サーバーに存在する実績は上書きしません。
サーバー上の履歴データを削除しても、Notionバックアップは削除・変更されません。

秘密情報は`.env`以外へ保存しないでください。初回起動後、`/setup`で管理者を1件だけ作成します。パスワードはArgon2idで保存されます。

開発DBへ適用する場合:

```bash
php artisan migrate
npm run build
php artisan serve
```

## 主要操作

- `GET /settings/notion-backup`: Notionバックアップと復元の設定画面
- `POST /notion-syncs`: Notionバックアップから実績情報を非同期復元
- `POST /wallpaper-analyses`: 高額当選壁紙の傾向分析をOpenAIキューへ登録
- `POST /wallpapers/proposals`: 対象日の構図提案
- `POST /wallpapers/{id}/repropose`: 同日の過去案を除外して再提案
- `POST /wallpapers/{id}/image`: 承認案から画像生成
- `POST /wallpapers/{id}/restore-image`: Notionバックアップの画像をサーバーへ復元
- `GET /wallpapers/{id}/preview`: 認証済み画像プレビュー
- `PUT /wallpapers/{id}/result`: VND賞金を保存し、設定済みの場合はNotionへバックアップ
- `GET /operations/{id}`: 非同期処理の進捗
- `GET /wallpapers/{id}/download`: 認証済み画像ダウンロード

## 品質確認

```bash
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
```

## Hostinger

`main`へのpush後、テスト成功時にGitHub ActionsからHostingerへ自動デプロイできます。本番前提、GitHub Secrets、公開ディレクトリ、cron、キューの詳細は [docs/hostinger.md](docs/hostinger.md) を参照してください。
