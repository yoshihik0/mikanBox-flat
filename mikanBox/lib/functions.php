<?php
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(403); exit; } // direct request guard
// ==========================================
// mikanBox Core Functions (lib/functions.php)
// ==========================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions-common.php';

// ---- Setup token: the first registration on a server (not needed on this computer itself) ----

const MIKANBOX_SETUP_TOKEN_MIN_LENGTH = 20;
const MIKANBOX_SETUP_TOKEN_MIN_DISTINCT = 8;
const MIKANBOX_SETUP_TOKEN_TEMPLATE = "# ここに、ランダムな文字列（20文字以上）を1行で書いてください。「#」で始まる行は読み込まれません。\n"
    . "# 1度しか使わないので、長く覚えておく必要はありません。\n"
    . "# Write a random string (at least 20 characters) on one line below. Lines starting with \"#\" are ignored.\n"
    . "# It is used only once.\n";

function mikanBoxSetupTokenDir(): string {
    return dirname(CORE_DIR) . '/setup-token';
}

function mikanBoxSetupTokenFile(): string {
    return mikanBoxSetupTokenDir() . '/setup-token.txt';
}

/** The folder ships with mikanBox; recreate whatever an update or upload left out. */
function mikanBoxSetupTokenEnsureFolder(): void {
    $dir = mikanBoxSetupTokenDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755)) return;
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/setup-token.txt')) @file_put_contents($dir . '/setup-token.txt', MIKANBOX_SETUP_TOKEN_TEMPLATE);
}

/** The first line of the token file that is not blank and not a "#" comment, or ''. */
function mikanBoxSetupToken(): string {
    $file = mikanBoxSetupTokenFile();
    if (!is_file($file) || !is_readable($file)) return '';
    foreach (preg_split('/\r\n|\n|\r/', (string)file_get_contents($file)) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') return $line;
    }
    return '';
}

/** The token is spent: put the instructions back so no used value lingers on the server. */
function mikanBoxSetupTokenReset(): void {
    if (is_dir(mikanBoxSetupTokenDir())) @file_put_contents(mikanBoxSetupTokenFile(), MIKANBOX_SETUP_TOKEN_TEMPLATE);
}

/** 'missing' | 'weak' | 'ready' */
function mikanBoxSetupTokenState(): string {
    $token = mikanBoxSetupToken();
    if ($token === '') return 'missing';
    if (strlen($token) < MIKANBOX_SETUP_TOKEN_MIN_LENGTH || count(array_unique(str_split($token))) < MIKANBOX_SETUP_TOKEN_MIN_DISTINCT) return 'weak';
    return 'ready';
}

function mikanBoxSetupTokenMatches(string $input): bool {
    return mikanBoxSetupTokenState() === 'ready' && hash_equals(mikanBoxSetupToken(), trim($input));
}

/**
 * Save data as JSON format.
 * @param string $dir Target directory (POSTS_DIR, COMPONENTS_DIR, etc.)
 * @param string $id File ID (e.g., 'index', 'header')
 * @param array $data Data to save
 * @return bool Success or failure
 */
function mikanBoxStorageSaveData($dir, $id, $data) {
    $filePath = resolveSafeDataPath($dir, $id, '.json', true);
    if ($filePath === false) return false;

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents($filePath, $json) !== false;
}

/**
 * Load data from JSON format.
 * @param string $dir Target directory
 * @param string $id File ID
 * @return array|null The data as an associative array, or null on failure
 */
function loadData($dir, $id) {
    $data = mikanBoxLoadDataRaw($dir, $id);
    // Pages saved before 2.18.0 with the "db" status read as output format "mikanBox DB".
    return $dir === POSTS_DIR ? mikanBoxNormalizeLegacyPage($data) : $data;
}

function mikanBoxLoadDataRaw($dir, $id) {
    $filePath = resolveSafeDataPath($dir, $id, '.json');
    if ($filePath === false || !file_exists($filePath)) {
        // ディレクトリ型ページ: coffee → coffee/index.json
        $indexPath = resolveSafeDataPath($dir, rtrim((string)$id, '/') . '/index', '.json');
        if ($indexPath !== false && file_exists($indexPath)) {
            $filePath = $indexPath;
        } else {
            return null;
        }
    }

    $json = file_get_contents($filePath);
    return json_decode($json, true);
}

/**
 * ディレクトリ内の全JSONファイルのリストを取得
 * @param string $dir 対象ディレクトリ
 * @return array ファイルのID(拡張子なし)の配列
 */
function getFileList($dir) {
    $files = [];
    if (!is_dir($dir)) return $files;
    
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'json') {
            // Calculate relative path from $dir and strip extension
            $relPath = substr($file->getPathname(), strlen($dir) + 1);
            $files[] = substr($relPath, 0, -5); // remove .json
        }
    }
    return $files;
}

/**
 * ソート済みの全ページIDリストを取得
 * sort_order (昇順) -> タイトル (昇順) の順でソートする
 */
function getSortedPostIds() {
    $ids = getFileList(POSTS_DIR);
    $dataList = [];
    foreach ($ids as $id) {
        $data = loadData(POSTS_DIR, $id);
        if (!$data) continue;
        $dataList[] = [
            'id' => $id,
            'sort_order' => (isset($data['sort_order']) && is_numeric($data['sort_order'])) ? (int)$data['sort_order'] : 0,
            'updated_at_ts' => isset($data['updated_at']) ? strtotime($data['updated_at']) : 0,
            'title' => $data['title'] ?? $id
        ];
    }

    usort($dataList, function($a, $b) {
        if ($a['sort_order'] !== $b['sort_order']) {
            return $a['sort_order'] <=> $b['sort_order'];
        }
        // 順序が同じなら更新日時の新しい順(降順)
        return $b['updated_at_ts'] <=> $a['updated_at_ts'];
    });

    return array_column($dataList, 'id');
}

/**
 * データの削除
 * @param string $dir 保存先ディレクトリ
 * @param string $id ファイルのID
 * @return bool
 */
function deleteData($dir, $id) {
    $filePath = resolveSafeDataPath($dir, $id, '.json');
    if ($filePath !== false && file_exists($filePath)) {
        return unlink($filePath);
    }
    return false;
}

/**
 * Load settings from json file.
 */
function loadSettings() {
    $settingsFile = SETTINGS_FILE;
    if (file_exists($settingsFile)) {
        return json_decode(file_get_contents($settingsFile), true) ?: [];
    }
    return [];
}

/**
 * Save settings to json file.
 */
function mikanBoxStorageSaveSettings($settings) {
    $settingsFile = SETTINGS_FILE;
    $lock = fopen(DATA_DIR . '/settings-lock.php', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return false;
    try {
        $current = loadSettings();
        $expected = $GLOBALS['mikanbox_initial_password_hash'] ?? (string)($current['password_hash'] ?? '');
        if (!hash_equals((string)($current['password_hash'] ?? ''), $expected)) return false;
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) return false;
        $tmp = tempnam(DATA_DIR, '.settings-');
        if ($tmp === false) return false;
        $ok = file_put_contents($tmp, $json) !== false && rename($tmp, $settingsFile);
        if (is_file($tmp)) unlink($tmp);
        if ($ok) $GLOBALS['mikanbox_initial_password_hash'] = (string)($settings['password_hash'] ?? '');
        return $ok;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/**
 * Fresh installation path: on first run, detect the language and copy the
 * matching seed content from import/{lang} directly into data/posts and
 * data/components. No-op once that seed content already exists.
 */
function bootstrapFreshInstallFromImport() {
    if (file_exists(POSTS_DIR . '/index.json')) {
        return;
    }

    $lang = getSystemLanguage();
    $importBase = __DIR__ . '/../import';
    $srcPreset = $importBase . '/' . $lang;

    if (!is_dir($srcPreset)) {
        $srcPreset = $importBase . '/ja'; // Fallback to ja
    }
    if (!is_dir($srcPreset)) {
        return; // No seed content available at all
    }

    @mkdir(POSTS_DIR, 0777, true);
    @mkdir(COMPONENTS_DIR, 0777, true);

    $dirMap = ['posts' => POSTS_DIR, 'components' => COMPONENTS_DIR];
    foreach ($dirMap as $sub => $destDir) {
        $srcDir = $srcPreset . '/' . $sub;
        if (!is_dir($srcDir)) continue;

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'json') continue;
            $relPath = substr($file->getPathname(), strlen($srcDir) + 1);
            $relPath = str_replace('\\', '/', $relPath);
            $destPath = $destDir . '/' . $relPath;
            $destParent = dirname($destPath);
            if (!is_dir($destParent)) @mkdir($destParent, 0777, true);
            copy($file->getPathname(), $destPath);
        }
    }
}
bootstrapFreshInstallFromImport();


