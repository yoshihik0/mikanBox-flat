<?php
// ビジュアル編集: 元に戻す / やり直す。
// ページのデータそのもの（loadData の中身）を、スナップショットとして持つ。保存先に依らない形（JSON版・SQLite版の両方に使える）。
// 履歴は、データフォルダの中（visual-history/）に置く。データフォルダは、外から読めないように保護されている。

function mbv_hist_dir(string $id, string $kind): string {
    $root = DATA_DIR . '/visual-history';
    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
        @file_put_contents($root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($root . '/index.html', '');
    }
    $d = $root . '/' . hash('sha256', $id) . '/' . $kind;     // ページIDは、そのままフォルダ名にしない
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
function mbv_hist_list(string $id, string $kind): array { $f = glob(mbv_hist_dir($id, $kind) . '/*.json') ?: []; sort($f); return $f; }
function mbv_hist_name(): string { return sprintf('%020d', (int)(microtime(true) * 1000000)) . '.json'; }
function mbv_hist_put(string $id, string $kind, array $data): void { file_put_contents(mbv_hist_dir($id, $kind) . '/' . mbv_hist_name(), json_encode($data, JSON_UNESCAPED_UNICODE)); }
function mbv_counts(string $id): array { return ['undo' => count(mbv_hist_list($id, 'undo')), 'redo' => count(mbv_hist_list($id, 'redo'))]; }

/** 書き換える直前の状態を保存し、「やり直す」側を空にする（最大100回分） */
function mbv_snapshot(string $id): void {
    $cur = loadData(POSTS_DIR, $id); if (!$cur) return;
    mbv_hist_put($id, 'undo', $cur);
    foreach (mbv_hist_list($id, 'redo') as $f) unlink($f);
    $u = mbv_hist_list($id, 'undo'); while (count($u) > 100) unlink(array_shift($u));
}
/** $kind = 'undo' | 'redo'。成功したら true */
function mbv_history_move(string $id, string $kind): bool {
    $to = $kind === 'undo' ? 'redo' : 'undo';
    $list = mbv_hist_list($id, $kind); if (!$list) return false;
    $snap = array_pop($list);
    $data = json_decode((string)file_get_contents($snap), true);
    if (!is_array($data)) return false;
    $cur = loadData(POSTS_DIR, $id); if ($cur) mbv_hist_put($id, $to, $cur);
    if (!saveData(POSTS_DIR, $id, $data)) return false;
    unlink($snap);
    return true;
}
