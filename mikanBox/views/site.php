<?php defined('MIKANBOX') or die(); ?>
    <?php
    $dataLocationNotice = $GLOBALS['mikanbox_data_location_notice'] ?? null;
    $dataLocationNeedsAttention = $dataLocationNotice
        && !in_array($dataLocationNotice, ['created', 'migrated'], true);
    ?>
    <?php if ($dataLocationNeedsAttention): ?>
        <div class="alert alert-warning" style="background:#fffbeb; border:1px solid #fef3c7; color:#92400e; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
            <?= getIcon('warning') ?>
            <?= htmlspecialchars(t('data_location_warning', DATA_DIR)) ?>
        </div>
    <?php endif; ?>
    <!-- Site Settings & Management Memo -->
<?php require __DIR__ . '/site-sections/memo.php'; ?>

    <!-- Version Info -->
    <?php
    require_once __DIR__ . '/../lib/updater.php';
    $latestVersion = null;
    $latestRef = null;
    $githubRepo = 'yoshihik0/mikanBox-flat';
    $vCacheKey = 'mikanbox_latest_ver_' . md5($githubRepo);
    $cachedVersion = $_SESSION[$vCacheKey] ?? null;
    $cachedLatest = is_array($cachedVersion) ? ($cachedVersion['version'] ?? null) : null;
    $cacheIsCurrent = !$cachedLatest
        || version_compare(ltrim($cachedLatest, 'vV'), ltrim(MIKANBOX_VERSION, 'vV'), '>=');
    if (is_array($cachedVersion) && $cacheIsCurrent && (time() - ($cachedVersion['checked_at'] ?? 0)) < 21600) {
        $latestVersion = $cachedVersion['version'] ?? null;
        $latestRef = $cachedVersion['ref'] ?? 'main';
    } else {
        $latestInfo = mikanBoxFetchLatestVersion($githubRepo);
        $latestVersion = $latestInfo['version'];
        $latestRef = $latestInfo['ref'];
        $_SESSION[$vCacheKey] = ['version' => $latestVersion, 'ref' => $latestRef, 'checked_at' => time()];
    }
    $isOutdated = $latestVersion
        && version_compare(ltrim($latestVersion, 'vV'), ltrim(MIKANBOX_VERSION, 'vV'), '>');
    $updateBackup = mikanBoxGetUpdateBackup(DATA_DIR);
    ?>
    <div class="section-container section-tight">
        <div style="font-size:0.82em; color:var(--text-sub,#888); padding:6px 2px; display:flex; gap:1.5em; align-items:center; flex-wrap:wrap;">
            <span><?= t('version_current') ?>: <?= htmlspecialchars(MIKANBOX_VERSION) ?></span>
            <div style="display:inline-flex; gap:8px; align-items:center;">
                <?= t('version_latest') ?>: <?= $latestVersion ? htmlspecialchars($latestVersion) : '—' ?>
                <?php if ($isOutdated): ?>
                    <form method="post" style="display:inline;" onsubmit="if (!confirm(<?= htmlspecialchars(json_encode(t('confirm_system_update', $latestVersion), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)) return false; const button = this.querySelector('button[type=submit]'); button.disabled = true; button.textContent = <?= htmlspecialchars(json_encode(t('msg_system_updating'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>; this.setAttribute('aria-busy', 'true'); return true;">
                        <?= csrfField() ?>
                        <input type="hidden" name="save_action" value="system_update">
                        <input type="hidden" name="target_version" value="<?= htmlspecialchars($latestVersion, ENT_QUOTES) ?>">
                        <input type="hidden" name="update_ref" value="<?= htmlspecialchars($latestRef ?? 'main', ENT_QUOTES) ?>">
                        <button type="submit" class="btn btn-blue btn-small"><?= t('btn_system_update') ?></button>
                    </form>
                <?php elseif ($latestVersion): ?>
                    <span><?= t('version_latest_current') ?></span>
                <?php endif; ?>
            </div>
            <a href="https://github.com/<?= htmlspecialchars($githubRepo) ?>" target="_blank" rel="noopener" style="color:inherit;">GitHub</a>
            <?php if ($updateBackup): ?>
                <div style="display:inline-flex; gap:8px; align-items:center;">
                    <?= t('version_restore_available') ?>: <?= htmlspecialchars($updateBackup['from_version']) ?>
                    <form method="post" style="display:inline;" onsubmit="if (!confirm(<?= htmlspecialchars(json_encode(t('confirm_system_restore', $updateBackup['from_version']), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)) return false; const button = this.querySelector('button[type=submit]'); button.disabled = true; button.textContent = <?= htmlspecialchars(json_encode(t('msg_system_restoring'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>; this.setAttribute('aria-busy', 'true'); return true;">
                        <?= csrfField() ?>
                        <input type="hidden" name="save_action" value="system_restore">
                        <button type="submit" style="font:inherit; color:inherit; background:none; border:0; padding:0; text-decoration:underline; cursor:pointer;"><?= t('btn_system_restore') ?></button>
                    </form>
                </div>
            <?php endif; ?>
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
