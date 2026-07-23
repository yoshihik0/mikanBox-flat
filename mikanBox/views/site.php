<?php defined('MIKANBOX') or die(); ?>
    <!-- Site Settings & Management Memo -->
<?php require __DIR__ . '/site-sections/memo.php'; ?>

    <!-- Version Info -->
    <?php
    $latestVersion = null;
    $githubRepo = 'yoshihik0/mikanBox-flat';
    $vCacheKey = 'mikanbox_latest_ver_' . md5($githubRepo);
    $cachedVersion = $_SESSION[$vCacheKey] ?? null;
    if (is_array($cachedVersion) && (time() - ($cachedVersion['checked_at'] ?? 0)) < 21600) {
        $latestVersion = $cachedVersion['version'] ?? null;
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => 3,
            'ignore_errors' => true,
            'header' => "User-Agent: mikanBox-admin\r\nAccept: application/vnd.github+json\r\n",
        ]]);
        $json = @file_get_contents("https://api.github.com/repos/{$githubRepo}/releases/latest", false, $ctx);
        if ($json) {
            $vData = json_decode($json, true);
            $latestVersion = $vData['tag_name'] ?? null;
        }
        if (!$latestVersion) {
            $json = @file_get_contents("https://api.github.com/repos/{$githubRepo}/tags", false, $ctx);
            if ($json) {
                $vData = json_decode($json, true);
                $versions = array_values(array_filter(array_map(
                    fn($tag) => $tag['name'] ?? null,
                    is_array($vData) ? $vData : []
                )));
                usort($versions, fn($a, $b) => version_compare(ltrim($b, 'vV'), ltrim($a, 'vV')));
                $latestVersion = $versions[0] ?? null;
            }
        }
        if (!$latestVersion) {
            $configPhp = @file_get_contents("https://raw.githubusercontent.com/{$githubRepo}/main/mikanBox/config.php", false, $ctx);
            if ($configPhp && preg_match('/define\\s*\\(\\s*[\'"]MIKANBOX_VERSION[\'"]\\s*,\\s*[\'"]([^\'"]+)[\'"]\\s*\\)/', $configPhp, $versionMatch)) {
                $latestVersion = $versionMatch[1];
            }
        }
        $_SESSION[$vCacheKey] = ['version' => $latestVersion, 'checked_at' => time()];
    }
    $isOutdated = $latestVersion
        && version_compare(ltrim($latestVersion, 'vV'), ltrim(MIKANBOX_VERSION, 'vV'), '>');
    ?>
    <div class="section-container section-tight">
        <div style="font-size:0.82em; color:var(--text-sub,#888); padding:6px 2px; display:flex; gap:1.5em; align-items:center; flex-wrap:wrap;">
            <span><?= t('version_current') ?>: <?= htmlspecialchars(MIKANBOX_VERSION) ?></span>
            <span><?= t('version_latest') ?>: <?= $latestVersion ? htmlspecialchars($latestVersion) : '—' ?><?php if ($isOutdated): ?> <span style="color:#e07000;">▲ <?= t('version_update_available') ?></span><?php endif; ?></span>
            <a href="https://github.com/<?= htmlspecialchars($githubRepo) ?>" target="_blank" rel="noopener" style="color:inherit;">GitHub</a>
        </div>
    </div>

    <!-- 1. SSG Build Section -->
<?php require __DIR__ . '/site-sections/ssg.php'; ?>

    <!-- 2. Language Section -->
<?php require __DIR__ . '/site-sections/language.php'; ?>

    <!-- 3. MCP API Key Section -->
<?php require __DIR__ . '/site-sections/mcp-key.php'; ?>

    <!-- 4. CSV Import Section -->
<?php require __DIR__ . '/site-sections/csv-import.php'; ?>

    <!-- 5. Backup Section -->
    <div id="backup">
        <div class="section-container section-tight">
            <details class="section-accordion">
                <summary class="header section-header accordion-summary">
                    <h2 class="accordion-title">
                        <?= t('backup_head_flat') ?> <span class="accordion-arrow">▼</span>
                    </h2>
                </summary>
                <div class="editor-container editor-container-sub">
                    <small class="sub-text sub-text-intro"><?= t('backup_hint_flat') ?></small>
                    <div class="flex-row gap-10">
                        <form method="post"><?= csrfField() ?><input type="hidden" name="save_action" value="download_backup_data"><button type="submit" class="btn btn-gray btn-small"><?= getIcon('download') ?> <?= t('backup_data_json') ?></button></form>
                        <form method="post"><?= csrfField() ?><input type="hidden" name="save_action" value="download_backup_media"><button type="submit" class="btn btn-gray btn-small"><?= getIcon('download') ?> <?= t('backup_media_flat') ?></button></form>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- 6. Site Settings Section -->
<?php require __DIR__ . '/site-sections/site-settings.php'; ?>

    <!-- 7. Password Section -->
    <div id="password">
        <div class="section-container section-tight">
            <details class="section-accordion">
                <summary class="header section-header accordion-summary">
                    <h2 class="accordion-title">
                        <?= t('label_change_password') ?> <span class="accordion-arrow">▼</span>
                    </h2>
                </summary>
                <div class="editor-container editor-container-sub">
                    <form method="post">
                        <input type="hidden" name="save_action" value="save_settings">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label><?= t('label_current_password') ?></label>
                            <input type="password" name="current_password" style="max-width:300px;">
                        </div>
                        <div class="form-group">
                            <label><?= t('admin_new_password') ?></label>
                            <input type="password" name="new_password" style="max-width:300px;">
                        </div>
                        <button type="submit" class="btn btn-blue btn-small"><?= getIcon('save') ?> <?= t('btn_save') ?></button>
                    </form>
                </div>
            </details>
        </div>
    </div>

