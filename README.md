# UK Like or Bad

WordPress ショートコードで「参考になった / 参考にならなかった」ボタンを表示し、クリック数をポストメタに保存します。文言は管理画面から変更可能。Cookie により一定期間の再投票を抑止します。

## 使い方
1. プラグインを `wp-content/plugins/uk-like-or-bad` に設置し、管理画面で有効化。
2. 記事や固定ページにショートコードを配置:

   `[uk_like_or_bad]`

   - 別の投稿IDのカウントを表示したい場合は `post_id` 属性を指定:
     `[uk_like_or_bad post_id="123"]`

3. 管理画面 → 設定 → "UK Like or Bad" からラベル文言と、再投票を禁止する日数を調整できます。

## 表示/スタイル
- ルート要素: `.uklob`
- ボタン: `.uklob-btn`, `.uklob-like`, `.uklob-bad`
- ラベル: `.uklob-label`
- カウント: `.uklob-count`

必要に応じて `assets/css/uklob.css` を編集、あるいはテーマ側CSSで上書きしてください。

## 仕組み
- クリックは AJAX (admin-ajax.php) で受け取り、
  - 「参考になった」: `_uklob_like`
  - 「参考にならなかった」: `_uklob_bad`
 というポストメタで加算します。
- Cookie により同一投稿について一定日数の再投票をクライアント側で無効化し、サーバー側でも軽く検知します。

## 注意
- サーバー側 Cookie は `COOKIEPATH/COOKIE_DOMAIN` に従います。SSL 環境では `secure` で送出します。
- 強い不正対策（IP/ユーザー単位、Rate Limit 等）が必要な場合は追加実装が必要です。
