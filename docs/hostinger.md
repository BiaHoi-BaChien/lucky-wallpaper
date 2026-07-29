# Hostinger共有環境への配置

## 事前確認

- PHP 8.2以上
- MySQL
- HTTPS
- PHP拡張: `curl`, `gd`, `mbstring`, `openssl`, `pdo_mysql`
- SSHとhPanel cron
- WebルートをLaravelの`public/`へ向けられること

パスキーのRP IDは`APP_URL`のホスト名から生成されます。本番HTTPS URLと完全一致させ、`PASSKEYS_USER_HANDLE_SECRET`は運用開始後に変更しないでください。

## ビルドと配置

CIまたはローカルで次を実行し、`public/build`を含む成果物を配置します。本番サーバーにNode常駐プロセス、SSR、WebSocketサーバーは不要です。

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

本番のマイグレーションはバックアップとメンテナンス手順を確認したうえで、別途承認後に実施します。

```bash
php artisan migrate --force
```

`storage/`と`bootstrap/cache/`はPHP実行ユーザーが書き込める必要があります。生成画像は`storage/app/private/wallpapers`に保存され、認証済みコントローラー経由でのみ配信されます。

## cron

hPanelには毎分1本だけ登録します。`/home/USER/domains/DOMAIN/app`は実際の配置先へ置換してください。

```cron
* * * * * cd /home/USER/domains/DOMAIN/app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Laravel Schedulerが毎分、次相当のDBキューワーカーを起動します。

```bash
php artisan queue:work database --queue=default,integrations,openai --stop-when-empty --max-jobs=10 --max-time=240 --tries=3
```

cron間隔により、UI操作から処理開始まで最大約1分待つ場合があります。

## 本番環境変数

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://...`
- `APP_TIMEZONE=Asia/Ho_Chi_Minh`
- `QUEUE_CONNECTION=database`
- `SESSION_SECURE_COOKIE=true`
- `HASH_DRIVER=argon2id`
- MySQL接続情報
- `APP_SETUP_KEY`
- `PASSKEYS_USER_HANDLE_SECRET`
- `NOTION_TOKEN`
- `NOTION_DATA_SOURCE_ID`
- `OPENAI_API_KEY`
- モデル名とタイムアウト

秘密値をログ、DB、リポジトリへ保存しないでください。
