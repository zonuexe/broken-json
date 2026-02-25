# broken-json-decoder 設計メモ

## 1. 目的
- 末尾欠損・途中破損した JSON を可能な限り復元し、PHP 値として利用可能にする。
- 主対象は「RDBMS に保存された巨大 JSON ログの末尾欠損」。
- 正常 JSON のデコードは `json_decode()` と同等に扱い、破損時のみ補間を行う。

## 2. 非目標
- 任意に壊れた JSON を完全に元データへ復元すること（情報損失は不可逆）。
- JSON5 / コメント付き JSON / 単一引用符など非 JSON 仕様の完全対応。
- 破損箇所の意味論的推測（例: 欠損した文字列内容の再生成）。

## 3. 想定する破損パターン
- EOF で文字列が閉じていない（`"message":"...`）。
- EOF でオブジェクト/配列の閉じ括弧が足りない。
- EOF で `\u` エスケープや `\` エスケープが途中。
- 末尾が `,` や `:` で終わる。
- 途中に制御文字・不正 UTF-8 が混入（ログ転送由来）。

## 4. 要件
- ストリーム処理で O(n) 時間、メモリ上限を制御可能にする。
- 復元の有無・補間内容をメタ情報として返す。
- 復元ポリシーを段階化する（保守的/積極的）。
- 失敗時は「どこまで復元したか」を診断情報として返す。

## 5. 公開 API 案
```php
<?php

namespace zonuexe\BrokenJson;

final class DecoderFactory
{
    public static function create(?DecodeOptions $options = null): Decoder;
}

final class Decoder
{
    public function decodeString(string $json): DecodeResult;
    /** @param resource $stream */
    public function decodeStream($stream): DecodeResult;
    public function decodeFile(string $path): DecodeResult;
}

final class DecodeResult
{
    public mixed $value;            // 復元後に json_decode した値
    public bool $isRecovered;       // 補間が入ったか
    public bool $isPartial;         // 欠損を含む推定結果か
    /** @var list<RepairAction> */
    public array $repairs;          // 実施した補間
    /** @var list<DecodeIssue> */
    public array $issues;           // 復元不能/警告
}
```

## 6. 復元アルゴリズム（コア）
- 方針: 「寛容トークナイズ + 最小補間 + 最後に `json_decode` で正規検証」。
- 1 パスで入力を走査し、状態機械を維持する。
  - コンテナスタック: `{` / `[` の入れ子。
  - 文字列状態: 通常 / エスケープ中 / `\u` 読み取り中。
  - 期待トークン状態: key/value/colon/comma/end。
- EOF 到達時に以下を順序適用:
  1. 文字列途中なら閉じ quote を追加。
  2. 途中の `\` や `\u12` を安全値へ補間（例: `\uFFFD`）。
  3. `:` 直後や `,` 直後で値欠損なら `null` を挿入（オプション）。
  4. 末尾 `,` は削除。
  5. スタック残数分の `]` / `}` を補完。
- 生成 JSON は最終的に `json_decode(..., flags: JSON_THROW_ON_ERROR)` で検証。

## 7. 復元ポリシー
- `conservative`:
  - 自明な閉じ処理のみ（quote/bracket/comma除去）。
  - 値挿入（`null`）はしない。
- `balanced`（デフォルト）:
  - `conservative` + `null` 補間 + 不正 UTF-8 置換。
- `aggressive`:
  - `balanced` + 制御文字除去や軽微な区切り修復を許容。
  - 誤復元リスクが高いため監査ログ必須。

## 8. `vendor/halaxa/json-machine` 活用可否
### 8.1 使える点
- 巨大 JSON をストリームで低メモリ反復できる。
- JSON Pointer 指定で一部だけ読む用途に強い。

### 8.2 今回の要件とのギャップ
- `Parser` は厳密 JSON 前提で、EOF 欠損時は `UnexpectedEndSyntaxErrorException` を投げる。
- 補間ロジックを差し込む拡張ポイントが公開 API としては薄い。
- 内部クラス依存（`Parser`, `Tokens`）で拡張すると、将来更新で破綻しやすい。

### 8.3 結論
- 復元ロジックの中核はライブラリ非依存で直接実装するのが妥当。
- `json-machine` は「復元済み JSON をさらに部分走査する補助用途」で任意採用は可能。
- 初期リリースでは `ext-json` だけで完結させる設計を推奨。

## 9. 実装構成（提案）
- `src/DecodeOptions.php`
- `src/DecodeResult.php`
- `src/RepairAction.php`
- `src/DecodeIssue.php`
- `src/Decoder.php`
- `src/Repair/RepairingScanner.php`（状態機械）
- `src/Repair/RepairPlan.php`（補間操作の記録）
- `src/Internal/Utf8Sanitizer.php`

## 10. テスト戦略
- 例ベース:
  - 正常 JSON（補間なし）。
  - 末尾欠損（string/object/array）。
  - `\u` 中断、バックスラッシュ終端、末尾カンマ。
- 性質ベース:
  - ランダム JSON を末尾ランダム切断し、`decode` がクラッシュしないこと。
  - `isRecovered`/`repairs` が再現可能であること。
- 性能:
  - 10MB/100MB クラス入力でメモリ上限・処理時間を計測。

## 11. リスクと対策
- 誤補間による意味改変:
  - 補間履歴を必ず返す。`conservative` を用意。
- 巨大文字列処理時のメモリ圧迫:
  - チャンク処理 + 出力バッファ上限 + 早期打ち切り。
- 入力が JSON でないケース:
  - 失敗理由を `issues` に積み、例外/結果型のどちらでも扱えるようにする。

## 12. 段階的リリース案
1. v0: `decodeString` + `conservative` のみ。
2. v1: `decodeStream`/`decodeFile`、`balanced` 導入。
3. v1.1: 監査情報強化（位置、補間前後の抜粋）。
4. v1.2: 必要なら `json-machine` 連携アダプタ（復元後部分走査）を追加。
