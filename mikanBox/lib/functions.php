<?php
// ==========================================
// mikanBox Core Functions (lib/functions.php)
// ==========================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions-common.php';

/**
 * Save data as JSON format.
 * @param string $dir Target directory (POSTS_DIR, COMPONENTS_DIR, etc.)
 * @param string $id File ID (e.g., 'index', 'header')
 * @param array $data Data to save
 * @return bool Success or failure
 */
function saveData($dir, $id, $data) {
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
function saveSettings($settings) {
    $settingsFile = SETTINGS_FILE;
    return file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
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

