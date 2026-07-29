# Lucky Wallpaper

過去のNotion実績を参照し、金運をテーマにしたスマートフォン壁紙の構図提案・画像生成・実績登録を行う単一管理者向けシステムです。

提案は「過去実績との相関に基づく創作上の傾向」であり、宝くじの当選や当選確率の向上を保証しません。

## 技術構成

- Laravel 12 / PHP 8.2以上
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
```

秘密情報は`.env`以外へ保存しないでください。初回起動後、`/setup`で管理者を1件だけ作成します。パスワードはArgon2idで保存されます。

開発DBへ適用する場合:

```bash
php artisan migrate
npm run build
php artisan serve
```

## 主要操作

- `POST /notion-syncs`: Notion実績の非同期取り込み
- `POST /wallpapers/proposals`: 対象日の構図提案
- `POST /wallpapers/{id}/repropose`: 同日の過去案を除外して再提案
- `POST /wallpapers/{id}/image`: 承認案から画像生成
- `PUT /wallpapers/{id}/result`: VND賞金を保存しNotion同期
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

本番前提、公開ディレクトリ、cron、キューの詳細は [docs/hostinger.md](docs/hostinger.md) を参照してください。
