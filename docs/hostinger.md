# Hostinger共有環境への配置

Document Root兼Laravel配置先:

```text
/home/u685478147/public_html/public_html/lucky_wallpaper
```

Hostingerの仕様に合わせ、Laravel本体と`.env`をこのディレクトリ配下へ配置し、リポジトリの`public/`の内容だけを同じディレクトリの直下へ展開します。`public/`ディレクトリ自体をWeb公開時のサブディレクトリとして残しません。

配置先直下の`index.php`がLaravelを起動し、`.htaccess`がアプリケーションディレクトリ、設定、依存関係、ログへの直接アクセスを拒否します。

## 事前確認

- PHP 8.2以上
- MySQL
- HTTPS
- PHP拡張: `curl`, `gd`, `mbstring`, `openssl`, `pdo_mysql`
- SSHとhPanel cron
- ApacheまたはLiteSpeedで`.htaccess`と`mod_rewrite`が利用できること

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

ローカルでHostinger用の配置構造を確認する場合は、ビルド後にリポジトリ外の一時ディレクトリを指定します。

```bash
bash deploy/prepare-hostinger-artifact.sh /tmp/lucky-wallpaper-deploy
```

生成物では、`public/build`は`build`として配置先直下に展開され、Hostinger用の`index.php`とルート`.htaccess`が使用されます。

本番のマイグレーションはバックアップとメンテナンス手順を確認したうえで、別途承認後に実施します。

```bash
php artisan migrate --force
```

`storage/`と`bootstrap/cache/`はPHP実行ユーザーが書き込める必要があります。生成画像は`storage/app/private/wallpapers`に保存され、認証済みコントローラー経由でのみ配信されます。

## GitHub Actionsによる自動デプロイ

`.github/workflows/deploy.yml`は、`main`へのpushを対象とした`tests`ワークフローが成功した場合だけHostingerへデプロイします。

- GitHub Actions上で本番用Composer依存関係とViteアセットをビルド
- `public/`の内容を配置先直下へ展開したHostinger用成果物を生成
- SSHのホスト鍵を検証
- rsyncでDocument Root兼Laravel配置先へ差分転送
- 直下の`index.php`だけをフロントコントローラーとして許可
- ルート`.htaccess`で`.env`、ソース、依存関係、DB、ログ、旧`public/`への直接アクセスを拒否
- 本番の`.env`、`storage/`、生成済みキャッシュを転送・削除対象から除外
- Laravelの設定、ルート、ビューキャッシュを再生成
- 失敗時も可能な範囲でメンテナンスモードを解除
- より新しい`main`が存在する場合は古いコミットのデプロイをスキップ

DBマイグレーションは自動実行しません。スキーマ変更がある場合は、バックアップ取得後に別途承認して実行します。

### GitHub Secrets

GitHubリポジトリの `Settings → Secrets and variables → Actions` に次のRepository secretsを登録します。

| Secret                      | 内容                                                   |
| --------------------------- | ------------------------------------------------------ |
| `HOSTINGER_SSH_HOST`        | hPanelのSSH Accessに表示されるホスト名またはIPアドレス |
| `HOSTINGER_SSH_PORT`        | hPanelのSSHポート。共有環境の既定値は`65002`           |
| `HOSTINGER_SSH_USER`        | `u685478147`                                           |
| `HOSTINGER_SSH_PRIVATE_KEY` | GitHub Actions専用SSH秘密鍵の全文                      |
| `HOSTINGER_SSH_KNOWN_HOSTS` | 接続先とポートに対応する検証済みknown_hosts行          |

デプロイ専用鍵は、普段使用する個人鍵と分けて作成します。GitHub Actionsでは対話入力できないため、この専用鍵にはパスフレーズを設定しません。

```bash
ssh-keygen -t ed25519 \
  -C "github-actions-lucky-wallpaper" \
  -f ~/.ssh/lucky-wallpaper-hostinger-deploy
```

公開鍵をHostingerへ登録し、秘密鍵の全文を`HOSTINGER_SSH_PRIVATE_KEY`へ登録します。known_hostsは次のように取得できますが、登録前にhPanel等の信頼できる経路でフィンガープリントを照合してください。

```bash
ssh-keyscan -p 65002 HOSTINGER_SSH_HOST
```

初回デプロイ前に、Document Root兼Laravel配置先へ本番用`.env`を作成しておく必要があります。ワークフローは`.env`が存在しない場合、安全のためデプロイを停止します。rsyncは既存の`.env`を転送・削除しません。

### 公開アクセス確認

初回反映後は、通常ページが表示でき、非公開ファイルと旧`public/`パスが`403`になることを確認します。いずれかが`200`になった場合は公開を停止し、`.htaccess`の有効性を確認してください。

```bash
base_url="https://clb-biahoi.net/lucky_wallpaper"

curl -sS -o /dev/null -w "root: %{http_code}\n" "$base_url/"

for protected_path in .env composer.json public/index.php vendor/autoload.php storage/logs/laravel.log; do
  curl -sS -o /dev/null -w "$protected_path: %{http_code}\n" \
    "$base_url/$protected_path"
done
```

## cron

hPanelには毎分1本だけ登録します。

```cron
* * * * * cd /home/u685478147/public_html/public_html/lucky_wallpaper && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
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
