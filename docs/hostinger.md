# Hostinger共有環境への配置

Document Root兼Laravel配置先:

```text
/home/u685478147/public_html/public_html/lucky_wallpaper
```

Hostingerの仕様に合わせ、Laravel本体と`.env`をこのディレクトリ配下へ配置し、リポジトリの`public/`の内容だけを同じディレクトリの直下へ展開します。`public/`ディレクトリ自体をWeb公開時のサブディレクトリとして残しません。

配置先直下の`index.php`がLaravelを起動し、`.htaccess`がアプリケーションディレクトリ、設定、依存関係、ログへの直接アクセスを拒否します。

## 事前確認

- PHP 8.5.12以上
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
php artisan view:cache
```

ローカルでHostinger用の配置構造を確認する場合は、ビルド後にリポジトリ外の一時ディレクトリを指定します。

```bash
bash deploy/prepare-hostinger-artifact.sh /tmp/lucky-wallpaper-deploy
```

生成物では、`public/build`は`build`として配置先直下に展開され、Hostinger用の`index.php`とルート`.htaccess`が使用されます。

本番のマイグレーションは、自動デプロイのメンテナンスモード中に実施します。スキーマ変更を含むリリース前にバックアップを取得してください。

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
- `php artisan migrate --force`で未適用のDBマイグレーションを実行
- Laravelの設定、ビューキャッシュを再生成
- 失敗時も可能な範囲でメンテナンスモードを解除
- より新しい`main`が存在する場合は古いコミットのデプロイをスキップ

DBマイグレーションに失敗した場合はデプロイを停止し、後続のキャッシュ再生成やメンテナンスモード解除を通常処理では行いません。最後の復旧ステップでメンテナンスモードの解除を試みます。

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

### 画面復帰時のキャッシュ確認

Inertiaは同じ画面URLから、通常アクセスにはHTML、`X-Inertia: true`付きの画面更新通信にはJSONを返します。スマホで別アプリから戻った際にJSONがそのまま表示されることを防ぐため、HTMLとInertiaのJSON応答には`Cache-Control: private, no-store`を設定しています。画像プレビューのキャッシュ設定は別に管理します。

反映後は、ブラウザの開発者ツールで壁紙詳細画面への通常アクセスと画面更新通信を確認してください。

- 両方の応答の`Cache-Control`に`private`と`no-store`が含まれること
- 両方の応答の`Vary`に`X-Inertia`が含まれること
- 通常アクセスは`Content-Type: text/html`、画面更新通信は`Content-Type: application/json`と`X-Inertia: true`を返すこと

Laravel側で設定していても公開URLの応答にこれらのヘッダーがない場合は、Hostinger/CDN側のヘッダー変更・キャッシュ設定を確認します。アプリ側のテストだけではCDN通過後の応答は検証できません。

Android・Chromeでは反映後に一度ページを再読み込みし、「画像作成プロンプトを表示 → コピー → ChatGPTアプリへ切り替え → Chromeへ戻る → 画像を登録」の操作を確認します。画像作成の間、Chromeをバックグラウンドに置いた場合も確認してください。

## cron

hPanelには毎分1本だけ登録します。

```cron
* * * * * cd /home/u685478147/public_html/public_html/lucky_wallpaper && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Laravel Schedulerが毎分、次相当のDBキューワーカーを起動します。

```bash
php artisan queue:work database --queue=default,integrations --stop-when-empty --max-jobs=10 --max-time=240 --tries=3
```

cron間隔により、UI操作から処理開始まで最大約1分待つ場合があります。

API自動作成も同じDBキューを利用します。利用する場合は`.env`に`OPENAI_API_KEY`を設定し、`DB_QUEUE_RETRY_AFTER=900`以上にしてください。自動作成ジョブのタイムアウトは420秒で、自動再試行は行いません。HTTP接続の途中切断でもAPI利用料が発生する場合があるため、失敗時は状態を確認してから画面で再実行します。設定を変更したら設定キャッシュとワーカーを更新します。

多重起動を防ぐロックは、通常終了時に解除し、異常終了して残った場合も15分で失効します。`--max-time=240`はジョブの合間に判定されるため、最後に開始したバックアップジョブの最大600秒も含めて期限を設定しています。

### バックアップがqueuedから進まない場合

`No scheduled commands are ready to run.`は、キューが空という意味ではありません。残留ロックでスケジュールをスキップした場合にも表示されます。

SSHで配置先へ移動し、スケジュールと実行中プロセスを確認します。

```bash
cd /home/u685478147/public_html/public_html/lucky_wallpaper
php artisan schedule:list
ps -u "$(id -u)" -o pid,etime,args | grep -E '[a]rtisan (queue:work|queue:listen|schedule:run|schedule:work)'
```

`Has Mutex`があり、このアプリのワーカーとスケジューラーが動いていない場合は、残留ロックを解除します。次のcronで待機中のNotionバックアップが実行されるため、本番操作として承認を得てから実行してください。

```bash
php artisan schedule:clear-cache
```

旧設定で作成された24時間のロックは、コード更新だけでは期限が短くなりません。実行中のプロセスがある場合はロックを解除せず、終了を待ってください。ロックがない場合は`storage/logs/laravel.log`と`php artisan queue:failed`でジョブの失敗を確認します。

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
- `NOTION_TOKEN`（Notionバックアップを利用する場合のみ）
- `NOTION_DATA_SOURCE_ID`（Notionバックアップを利用する場合のみ）

秘密値をログ、DB、リポジトリへ保存しないでください。


## 九星気学・手動専用版への切替

この版はDB構造を変更せず、旧壁紙・実績・分析履歴・残キューデータを保持します。AI APIルートと生成ジョブは廃止しました。

1. 切替前に、常駐ワーカーや別cronでopenaiキューを処理しているものを停止します。進行中の旧AI処理がないことを確認してから新しいコードへ切り替えます。
2. 設定キャッシュを再生成し、queue:restartで旧ワーカーを再起動します。常駐ワーカーを独自に管理している場合は、その管理プロセスから停止・起動します。
3. schedule:listでdefault,integrationsのみが処理対象であることを確認します。残っているopenaiキューは処理・再試行・削除しません。
4. 旧OPENAI_*環境変数は新コードでは参照しません。アプリは外部AIへAPI送信しません。Notion連携には引き続きintegrationsキューを使用します。
5. ログイン、作成プロンプト取得、JSONと画像の一括登録、実績登録、主方位別集計、期間・方位別履歴、既存画像の表示とNotion復元を確認します。

外部AIでの判定・構図・画像の作成、および同じ実画像の表示と保存は、アプリの入力テストとは別に手動で確認します。
