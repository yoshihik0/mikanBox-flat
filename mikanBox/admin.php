<?php
ob_start();
// ==========================================
// mikanBox Admin Panel (admin.php)
// ==========================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';
define('MIKANBOX', true);

// CSRF token generation (initial or on session timeout)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- Authentication ---
$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Initial setup / Login / Common settings
// If settings.json does not exist, initialize with an empty array
$settings = file_exists(SETTINGS_FILE) ? json_decode(file_get_contents(SETTINGS_FILE), true) : [];
// Pass reference as a global variable (Fix #8)
$GLOBALS['mikanbox_settings'] = &$settings;
$passwordHash = $settings['password_hash'] ?? '';
$isDemoMode = !empty($settings['demo_mode']);
$loginError = ''; // Initialize loginError

// Logout process
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_logged_in']);
    // In demo mode, redirect to login form after logout
    $redirect = $isDemoMode ? basename($_SERVER['PHP_SELF']) . '?login=1' : basename($_SERVER['PHP_SELF']);
    header('Location: ' . $redirect);
    exit;
}

// --- Login / Initial Setup Process ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_action'])) {
    if ($_POST['login_action'] === 'set_initial_password' && empty($passwordHash)) {
        // Initial password setup
        $pass = $_POST['new_password'] ?? '';
        if (strlen($pass) < 4) {
            $loginError = t('err_password_chars');
        } else {
            // Populate defaults on first-time setup
            if (empty($settings)) {
                $settings = [
                    'site_name'   => '🍊mikanBox flat',
                    'description' => '',
                    'keywords'    => '',
                    'memo'        => 'Welcome to 🍊mikanBox flat!',
                    'system_lang' => '',
                    'ssg_structure' => 'directory',
                    'ssg_server_structure' => 'directory',
                    'ssg_export_structure' => 'file'
                ];
            }
            $settings['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            if (file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
                // 初回セットアップ時に .htaccess が未生成であれば自動作成
                $siteRoot = dirname(CORE_DIR);
                $htaccessPath = $siteRoot . '/.htaccess';
                if (!file_exists($htaccessPath)) {
                    $htaccessContent = "DirectoryIndex index.php\n\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{REQUEST_FILENAME} -f [OR]\n    RewriteCond %{REQUEST_FILENAME} -d\n    RewriteRule ^ - [L]\n    RewriteRule ^ index.php [L,QSA]\n</IfModule>\n";
                    @file_put_contents($htaccessPath, $htaccessContent);
                }
                $_SESSION['admin_logged_in'] = true;
                header('Location: ' . basename(__FILE__));
                exit;
            } else {
                $loginError = t('err_save_failed');
            }
        }
    } elseif ($_POST['login_action'] === 'login' && !empty($passwordHash)) {
        // Normal login
        $rateLimitId = getClientIp() . '|login';
        $lockedRemain = checkLoginRateLimit($rateLimitId);
        if ($lockedRemain > 0) {
            $loginError = t('err_rate_limited', (int)ceil($lockedRemain / 60));
        } elseif (password_verify($_POST['password'] ?? '', $passwordHash)) {
            clearLoginAttempts($rateLimitId);
            $_SESSION['admin_logged_in'] = true;
            header('Location: ' . basename(__FILE__));
            exit;
        } else {
            recordLoginFailure($rateLimitId);
            $loginError = t('err_wrong_password');
        }
    }
}

// Show login screen if not logged in
// In demo mode, allow access without login (unless ?login=1 is requested for full access)
if (!$isLoggedIn && (!$isDemoMode || isset($_GET['login']))) {
    if (ob_get_length()) ob_clean();
?>
<!DOCTYPE html>
<html lang="<?= getSystemLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <title>🍊mikanBox flat - <?= t('admin_login') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=block" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="login-body">
    <div class="login-box">
        <div class="login-title"><span>🍊</span><span>mikanBox flat</span></div>
        <?php if (empty($passwordHash)): ?>
            <p><strong><?= t('hint_initial_setup') ?></strong><br><?= t('hint_setup_msg') ?></p>
            <form method="post">
                <input type="hidden" name="login_action" value="set_initial_password">
                <input type="password" name="new_password" placeholder="<?= t('admin_new_password') ?>" required autofocus>
                <button type="submit"><?= t('btn_set_password') ?></button>
            </form>
        <?php else: ?>
            <?php if ($isDemoMode): ?>
            <p><?= t('hint_demo_login') ?></p>
            <?php endif; ?>
            <?php if(!empty($loginError)) echo "<div class='error'>{$loginError}</div>"; ?>
            <form method="post">
                <input type="hidden" name="login_action" value="login">
                <input type="password" name="password" placeholder="<?= t('admin_password') ?>" required autofocus>
                <button type="submit"><?= t('btn_login') ?></button>
            </form>
            <?php if ($isDemoMode): ?>
            <p><a href="<?= basename($_SERVER['PHP_SELF']) ?>"><?= t('btn_demo_back') ?></a></p>
            <?php endif; ?>
        <?php endif; ?>
            <p class="login-hint">
                <?= t('admin_forgot_password_flat') ?><br>
                <?= t('admin_forgot_password_hint_flat') ?>
            </p>
    </div>
</body>
</html>
<?php
    exit;
}

// ==========================================
// Post-login Processing (Routing & Data Saving)
// ==========================================
// Logged in: Load common data
// $settings is already loaded above
$site_name = $settings['site_name'] ?? SITE_NAME;
$view = $_GET['view'] ?? 'pages';
if ($view === 'design') $view = 'components'; // 'design' is an alias for 'components'

// 🍊 Tag Guide Helper (Reusable)
$renderTagGuide = function() {
    global $helpFile;
    ?>
    <details class="hint-accordion">
        <summary><h3 class="accordion-title"><?= t('available_tags_content_css') ?> <span class="accordion-arrow">▼</span></h3></summary>
        <div class="hint-accordion-body">
            <div class="hint-grid hint-grid-tag">
                <div>
                    <strong><?= t('standard_info') ?></strong>
                    <ul class="hint-list hint-list-sm">
                        <li><code>{{TITLE}}</code> : <?= t('page_title') ?></li>
                        <li><code>{{FULL_TITLE}}</code> : <?= t('page_title') ?> - <?= t('site_name') ?></li>
                        <li><code>{{UPDATE_DATE}}</code> : <?= t('hint_update_date_ymd') ?></li>
                        <li><code>{{UPDATE_DATE:JP}}</code> : <?= t('hint_update_date_jp') ?></li>
                        <li><code>{{UPDATE_DATE:SLASH}}</code> : <?= t('hint_update_date_slash') ?></li>
                        <li><code>{{IS_NEW:30}}</code> : <?= t('hint_is_new') ?></li>
                        <li><code>{{DESCRIPTION}}</code> : <?= t('page_description') ?></li>
                        <li><code>{{KEYWORDS}}</code> : <?= t('label_keywords') ?></li>
                        <li><code>{{OGP_IMAGE}}</code> : <?= t('page_thumbnail_ogp_image') ?></li>
                        <li><code>{{PAGE_URL}}</code> : <?= t('page_full_url') ?></li>
                        <li><code>{{SITE_URL}}</code> : <?= t('site_root_url') ?></li>
                        <li><code>{{SITE_NAME}}</code> : <?= t('site_title') ?></li>
                        <li><code>{{SITE_DESCRIPTION}}</code> : <?= t('site_description') ?></li>
                        <li><code>{{SITE_OGP_IMAGE}}</code> : <?= t('site_common_ogp_image') ?></li>
                    </ul>
                    <strong style="display:block;margin-top:12px"><?= t('special_wrapper_design') ?></strong>
                    <ul class="hint-list hint-list-sm">
                        <li><code>{{CONTENT}}</code> : <?= t('page_main_content') ?></li>
                        <li><code>{{HEAD_CSS}}</code> : <?= t('combined_css_components') ?></li>
                        <li><code>{{COMPONENT:_global_head}}</code> : <?= t('common_head_section') ?></li>
                        <li><code>{{COMPONENT:_header}}</code> : <?= t('page_header') ?></li>
                        <li><code>{{COMPONENT:_footer}}</code> : <?= t('page_footer') ?></li>

                    </ul>
                </div>
                <div>
                    <strong><?= t('navigation_components') ?></strong>
                    <ul class="hint-list hint-list-sm">
                        <li><code>{{COMPONENT:ID}}</code> : <?= t('embed_registered_component') ?></li>
                        <li><code>{{IMAGE:<?= t('filename') ?>}}</code> : <?= t('display_static_image') ?></li>
                        <li><code>{{AUDIO:<?= t('filename') ?>}}</code> : <?= t('insert_audio_module') ?></li>
                        <li><code>{{VIDEO:<?= t('filename') ?>}}</code> : <?= t('display_video') ?></li>
                        <li><code>{{POST_MD:pageID}}</code> : <?= t('hint_post_md') ?></li>
                        <li><code>{{EXT_MD:url}}</code> : <?= t('hint_ext_md') ?></li>
                        <li><code>{{NAV_LINKS:category}}</code> : <?= t('link_list') ?><span class="hint-desc"> — li.active</span></li>
                        <li><code>{{NAV_CARDS:category:componentID}}</code> : <?= t('card_list') ?></li>
                        <li class="hint-note"><?= t('nav_links_cards_hint') ?></li>
                        <li style="margin-top:8px"><span class="hint-section-label"><?= t('nav_cards_template_vars') ?></span></li>
                        <li><code>{{PAGE_URL}}</code> <code>{{TITLE}}</code> <code>{{DESCRIPTION}}</code> <code>{{OGP_IMAGE}}</code> <code>{{UPDATE_DATE}}</code> <code>{{IS_NEW:N}}</code> <code>{{POST_MD::key}}</code> <code>{{POST_MD::#rowID:key}}</code></li>
                        <li><code>{{IS_ACTIVE}}</code> : <?= t('hint_is_active') ?></li>
                    </ul>
                </div>
                <div>
                    <strong><?= t('label_database') ?></strong>
                    <ul class="hint-list hint-list-sm">
                        <li>
                            <span class="hint-section-label"><?= t('hint_datarow_def') ?></span><br>
                            <code>{{DATA:key}}<?= t('hint_data_value') ?>{{/DATA}}</code> : <?= t('data_block_visible') ?><br>
                            <code>{{DATA:key:GHOST}}<?= t('hint_data_value') ?>{{/DATA}}</code> : <?= t('data_block_hidden') ?><br>
                            <span class="hint-desc"><?= t('hint_data_ascii_rule') ?></span>
                        </li>
                        <li style="margin-top:8px">
                            <span class="hint-section-label"><?= t('hint_datarow_table_def') ?></span><br>
                            <code>{{DATAROW:rowID}}</code><br>
                            <code>{{DATA:key}}<?= t('hint_data_value') ?>{{/DATA}}</code><br>
                            <code>{{/DATAROW}}</code>
                        </li>
                        <li style="margin-top:8px"><span class="hint-section-label"><?= t('hint_datarow_usage') ?></span></li>
                        <li><code>{{POST_MD::key}}</code> : <?= t('data_from_self') ?></li>
                        <li><code>{{POST_MD:pageID:key}}</code> : <?= t('data_from_page') ?></li>
                        <li><code>{{EXT_MD:url:key}}</code> : <?= t('hint_ext_md_key') ?></li>
                        <li style="margin-top:6px"><code>{{POST_MD::#rowID:key}}</code> : <?= t('hint_datarow_self_table') ?></li>
                        <li><code>{{POST_MD:pageID#rowID:key}}</code> : <?= t('hint_datarow_page_table') ?></li>
                        <li><code>{{EXT_MD:url#rowID:key}}</code> : <?= t('hint_datarow_ext_table') ?></li>
                        <li style="margin-top:6px" class="hint-note"><?= t('hint_db_api_hidden') ?></li>
                        <li class="hint-note"><?= t('hint_db_api_public') ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </details>
    <?php
};

// SSG Path Settings
$ssgDir = $settings['ssg_dir'] ?? ($settings['last_ssg_dir'] ?? '');
// サイトルート = CORE_DIR(mikanBox/)の親ディレクトリ
$siteRoot = dirname(CORE_DIR);
$ssgAbsPath = !empty($ssgDir) ? $siteRoot . '/' . ltrim($ssgDir, '/') : $siteRoot;
// プレビューリンク用（admin.phpからの相対パス）
$lastSsgRelPath = '../' . (($ssgDir !== '') ? rtrim($ssgDir, '/') . '/' : '');

$editId = $_GET['edit'] ?? null;
$message = $_SESSION['admin_message'] ?? '';
unset($_SESSION['admin_message']);

// --- Save / Action Processing (with CSRF verification) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die(t('err_csrf'));
    }

    // Demo mode: block write operations if not logged in with password
    if ($isDemoMode && !$isLoggedIn) {
        $message = t('msg_demo_mode');
        goto skip_post_actions;
    }

    if (in_array($_POST['save_action'], ['system_update', 'system_restore'], true)) {
        require_once __DIR__ . '/lib/updater.php';
        try {
            if ($_POST['save_action'] === 'system_update') {
                $result = mikanBoxInstallUpdate(
                    'yoshihik0/mikanBox-flat',
                    MIKANBOX_VERSION,
                    CORE_DIR,
                    DATA_DIR,
                    $_POST['target_version'] ?? null,
                    $_POST['update_ref'] ?? 'main'
                );
                $message = !empty($result['success'])
                    ? t('msg_system_update_success', $result['version'])
                    : t('update_error_' . ($result['code'] ?? 'internal'));
            } else {
                $result = mikanBoxRestorePreviousVersion(CORE_DIR, DATA_DIR);
                $message = !empty($result['success'])
                    ? t('msg_system_restore_success', $result['version'])
                    : t('update_error_' . ($result['code'] ?? 'internal'));
            }
        } catch (Throwable $e) {
            $message = t('update_error_internal');
        }
        unset($_SESSION['mikanbox_latest_ver_' . md5('yoshihik0/mikanBox-flat')]);
        $_SESSION['admin_message'] = $message;
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: admin.php?view=settings&update_checked=' . rawurlencode((string)microtime(true)), true, 303);
        exit;
    }
    
    // Path Context for Actions
    $activeSsgDir = isset($_POST['ssg_dir']) ? (string)$_POST['ssg_dir'] : $ssgDir;
    // 絶対パスで解決（CWDに依存しない）
    $activeSsgAbsPath = !empty($activeSsgDir) ? $siteRoot . '/' . ltrim($activeSsgDir, '/') : $siteRoot;
    $activeSsgRelPath = '../' . (($activeSsgDir !== '') ? rtrim($activeSsgDir, '/') . '/' : '');

    // Initialize common renderer for actions
    require_once __DIR__ . '/lib/renderer.php';
    $renderer = new MikanBoxRenderer($settings);

    if ($_POST['save_action'] === 'save_page') {
        $id = $_POST['id'] ?: 'page_' . time();
        $status = $_POST['status'] ?? 'draft';
        $oldId = $_POST['old_id'] ?? null;

        // Reserved slug check: block system directory names
        $coreDirName = basename(CORE_DIR); // e.g. "mikanBox"
        $reservedPrefixes = [$coreDirName, 'media', 'api'];
        $isReserved = false;
        foreach ($reservedPrefixes as $r) {
            if (strcasecmp($id, $r) === 0 || stripos($id, $r . '/') === 0) {
                $isReserved = true; break;
            }
        }
        if ($isReserved) {
            $message = t('err_slug_reserved', $id);
        } // Duplicate slug check: warn if creating new page with existing ID
        elseif (empty($oldId) && loadData(POSTS_DIR, $id) !== null) {
            $message = t('err_slug_exists', $id);
        } else {

        $updatedAt = $_POST['updated_at'] ?? date('Y-m-d H:i:s');

        // Auto-set the update date to "now" when a page transitions from non-public
        // (draft/db) to public, so it reflects the actual publish moment rather than
        // whatever was left in the (manually editable) date field while still a draft.
        // Once public, further edits keep whatever date is already in that field
        // (nothing here bumps it automatically), so minor fixes don't move the date.
        $existingPageData = loadData(POSTS_DIR, $oldId ?: $id);
        $wasPublic = $existingPageData && in_array($existingPageData['status'] ?? 'draft', ['public_dynamic', 'public_static'], true);
        $isPublicNow = in_array($status, ['public_dynamic', 'public_static'], true);
        if (!$wasPublic && $isPublicNow) {
            $updatedAt = date('Y-m-d H:i:s');
        }

        $data = [
            'title' => $_POST['title'] ?? '',
            'category' => trim($_POST['category'] ?? ''),
            'status' => $status,
            'description' => $_POST['description'] ?? '',
            'memo' => $_POST['memo'] ?? '',
            'keywords' => $_POST['keywords'] ?? '',
            'ogp_image' => $_POST['ogp_image'] ?? '',
            'content_md' => $_POST['content_md'] ?? '',
            'css' => $_POST['css'] ?? '',
            'is_html' => isset($_POST['is_html']) ? true : false,
            'wrapper_comp' => $_POST['wrapper_comp'] ?: '_layout',
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'updated_at' => $updatedAt
        ];
        if (saveData(POSTS_DIR, $id, $data)) {
            // Delete old file if URL slug (ID) has changed
            $oldId = $_POST['old_id'] ?? null;
            if ($oldId && $oldId !== $id) {
                deleteData(POSTS_DIR, $oldId);
                // Also delete old static files
                require_once __DIR__ . '/lib/ssg.php';
                $ssg = new MikanBoxSSG($renderer, $activeSsgAbsPath);
                $ssg->deletePage($oldId);
            }

            // --- Automatic SSG Build/Delete Check ---
            require_once __DIR__ . '/lib/ssg.php';
            $ssgOpts = [
                'structure' => $settings['ssg_structure'] ?? 'directory',
                'copy_media' => ($settings['ssg_mode'] ?? 'server') === 'export',
                'selected_pages' => [$id]
            ];
            $ssg = new MikanBoxSSG($renderer, $activeSsgAbsPath, $ssgOpts);
            
            if ($status !== 'public_static') {
                $ssg->deletePage($id);
            }
            // ----------------------------------------
            if ($id !== 'index') {
                $editId = $id; 
            }
            $message = t('msg_page_saved', $id);
            
            // Redirect if from preview button
            if (isset($_POST['save_and_preview'])) {
                // Use renderer to get canonical link (already root-relative)
                $previewUrl = $renderer->getPageLink($id, '');
                // Convert to relative if needed, but since it's / based, it should work fine from root
                // Actually, header("Location: /path") works.
                header("Location: " . $previewUrl);
                exit;
            }
            $editId = $id; // Keep in edit mode after creation
        } else {
            $message = t('err_page_save');
        }
        } // end duplicate slug check
    }
    elseif ($_POST['save_action'] === 'save_page_status') {
        $id = $_POST['id'];
        $newStatus = $_POST['status'];
        $data = loadData(POSTS_DIR, $id);
        if ($data) {
            $data['status'] = $newStatus;
            saveData(POSTS_DIR, $id, $data);
            
            // Sync SSG if needed
            require_once __DIR__ . '/lib/ssg.php';
            $ssg = new MikanBoxSSG($renderer, $activeSsgAbsPath, ['selected_pages'=>[$id]]);
            if ($newStatus === 'public_static') {
                $ssg->build();
            } else {
                $ssg->deletePage($id);
            }
            $_SESSION['admin_message'] = t('msg_page_saved', $id);
            header("Location: admin.php?view=pages#pages");
            exit;
        }
    }
    elseif ($_POST['save_action'] === 'delete_page') {
        $id = $_POST['id'];
        // index has no special protection: a missing page (including index) already falls
        // back to the 404 page in MikanBoxRenderer::render(), so there's nothing unsafe
        // about deleting it like any other page.
        if (deleteData(POSTS_DIR, $id)) {
            $_SESSION['admin_message'] = t('msg_page_deleted', $id);
            header("Location: admin.php?view=pages");
            exit;
        } else {
            $message = t('err_page_delete');
        }
    }
    elseif ($_POST['save_action'] === 'save_comp') {
        $id = $_POST['id'];
        $compType = $_POST['comp_type'] ?? 'part';
        if ($compType === 'ai_doc') {
            if (substr(strtolower($id), -3) !== '.md') {
                $id .= '.md';
            }
        }
        if(empty($id)) $id = 'comp_' . time();
        $oldId = $_POST['old_id'] ?? null;

        // Duplicate slug check: warn if creating new component with existing ID
        if (empty($oldId) && loadData(COMPONENTS_DIR, $id) !== null) {
            $message = t('err_slug_exists', $id);
        } else {
        $data = [
            'html' => $_POST['html'],
            'css' => $_POST['css'] ?? '',
            'memo' => $_POST['memo'] ?? '',
            'is_global' => !isset($_POST['use_scope']),
            'is_wrapper' => ($compType === 'wrapper'),
            'is_ai_doc' => ($compType === 'ai_doc'),
        ];
        if (saveData(COMPONENTS_DIR, $id, $data)) {
            // Delete old file if ID has changed
            if ($oldId && $oldId !== $id) {
                deleteData(COMPONENTS_DIR, $oldId);
            }
            $message = t('msg_comp_saved', $id);
            $editId = $id; // Keep in edit mode after saving
        } else {
            $message = t('err_comp_save');
        }
        } // end duplicate slug check
    }
    elseif ($_POST['save_action'] === 'delete_comp') {
        $id = $_POST['id'];
        if (deleteData(COMPONENTS_DIR, $id)) {
            $_SESSION['admin_message'] = t('msg_comp_deleted', $id);
            header("Location: admin.php?view=components#design");
            exit;
        } else {
            $message = t('err_comp_delete');
        }
    }
    elseif ($_POST['save_action'] === 'upload_media') {
        if (isset($_FILES['image'])) {
            $err = $_FILES['image']['error'];
            if ($err === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['image']['tmp_name'];
                $originalName = basename($_FILES['image']['name']);
                
                // Security: Validate Extension
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp3', 'm4a', 'mp4'];
                if (!in_array($ext, $allowedExts)) {
                    $message = t('err_upload_failed') . " (Invalid file extension)";
                } else {
                    $category = $_POST['cat'] ?? $_GET['cat'] ?? '';
                    $resolvedName = resolveMediaSaveName($originalName, $category);
                    $targetPath = MEDIA_DIR . '/' . $resolvedName;
                    
                    if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0777, true);
                    if (move_uploaded_file($tmpPath, $targetPath)) {
                        if ($ext === 'svg') {
                            file_put_contents($targetPath, sanitizeSvgContent(file_get_contents($targetPath)));
                        }
                        $message = t('msg_media_uploaded', $resolvedName);
                    } else {
                        $message = t('err_upload_failed');
                    }
                }
            } else {
                switch($err) {
                    case UPLOAD_ERR_INI_SIZE: $message = t('err_file_size'); break;
                    case UPLOAD_ERR_NO_FILE: $message = t('err_no_file'); break;
                    default: $message = t('err_upload_failed') . " ($err)"; break;
                }
            }
        }
    }
    elseif ($_POST['save_action'] === 'delete_media') {
        $name = basename($_POST['filename']);
        $path = MEDIA_DIR . '/' . $name;
        if (file_exists($path) && unlink($path)) {
            $message = t('msg_media_deleted', $name);
        } else {
            $message = t('err_delete_failed');
        }
    }
    elseif ($_POST['save_action'] === 'resize_media') {
        $name = basename($_POST['filename']);
        $targetPath = MEDIA_DIR . '/' . $name;
        
        if (file_exists($targetPath) && function_exists('imagecreatefromjpeg')) {
            $info = getimagesize($targetPath);
            if ($info) {
                $srcW = $info[0];
                $srcH = $info[1];
                $type = $info[2];
                
                $newWidth = !empty($_POST['new_width']) ? (int)$_POST['new_width'] : null;
                $newHeight = !empty($_POST['new_height']) ? (int)$_POST['new_height'] : null;

                if ($newWidth || $newHeight) {
                    if ($newWidth && !$newHeight) {
                        $newHeight = (int)($srcH * ($newWidth / $srcW));
                    } elseif (!$newWidth && $newHeight) {
                        $newWidth = (int)($srcW * ($newHeight / $srcH));
                    }
                    
                    $dstImg = imagecreatetruecolor($newWidth, $newHeight);
                    
                    switch ($type) {
                        case IMAGETYPE_JPEG: $srcImg = imagecreatefromjpeg($targetPath); break;
                        case IMAGETYPE_PNG: 
                            $srcImg = imagecreatefrompng($targetPath);
                            imagealphablending($dstImg, false);
                            imagesavealpha($dstImg, true);
                            break;
                        case IMAGETYPE_GIF: $srcImg = imagecreatefromgif($targetPath); break;
                        case IMAGETYPE_WEBP: $srcImg = imagecreatefromwebp($targetPath); break;
                        default: $srcImg = null;
                    }
                    
                    if ($srcImg) {
                        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $srcW, $srcH);
                        switch ($type) {
                            case IMAGETYPE_JPEG: imagejpeg($dstImg, $targetPath, 85); break;
                            case IMAGETYPE_PNG: imagepng($dstImg, $targetPath); break;
                            case IMAGETYPE_GIF: imagegif($dstImg, $targetPath); break;
                            case IMAGETYPE_WEBP: imagewebp($dstImg, $targetPath); break;
                        }
                        $message = t('msg_media_resized', $name);
                    }
                }
            }
        } else {
            $message = t('err_resize_failed');
        }
    }
    elseif ($_POST['save_action'] === 'save_settings' || $_POST['save_action'] === 'save_memo') {
        // Shared logic for saving settings (Fix #8)
        if ($_POST['save_action'] === 'save_settings') {
            if (empty($settings['site_id'])) {
                $postedSiteId = trim((string)($_POST['site_id'] ?? ''));
                $settings['site_id'] = preg_match('/^site-[a-f0-9]{16}$/', $postedSiteId)
                    ? $postedSiteId
                    : 'site-' . bin2hex(random_bytes(8));
            }
            if (isset($_POST['site_name'])) $settings['site_name'] = $_POST['site_name'];
            if (isset($_POST['site_url'])) {
                $siteUrl = rtrim(trim((string)$_POST['site_url']), '/');
                $siteUrlScheme = strtolower((string)parse_url($siteUrl, PHP_URL_SCHEME));
                $settings['site_url'] = $siteUrl === '' || (
                    in_array($siteUrlScheme, ['http', 'https'], true)
                    && filter_var($siteUrl, FILTER_VALIDATE_URL)
                ) ? $siteUrl : '';
            }
            $allowedSiteEnvironments = ['production', 'staging', 'development', 'local', 'unspecified'];
            $postedSiteEnvironment = (string)($_POST['site_environment'] ?? 'unspecified');
            $settings['site_environment'] = in_array($postedSiteEnvironment, $allowedSiteEnvironments, true)
                ? $postedSiteEnvironment
                : 'unspecified';
            if (isset($_POST['system_lang'])) $settings['system_lang'] = $_POST['system_lang'];
            if (isset($_POST['ssg_root_url'])) $settings['ssg_root_url'] = $_POST['ssg_root_url'];
            if (isset($_POST['description'])) $settings['description'] = $_POST['description'];
            if (isset($_POST['keywords'])) $settings['keywords'] = $_POST['keywords'];
            if (isset($_POST['ogp_image'])) $settings['ogp_image'] = $_POST['ogp_image'];
            if (isset($_POST['pages_per_page'])) $settings['pages_per_page'] = (int)$_POST['pages_per_page'];
            if (isset($_POST['media_per_page'])) $settings['media_per_page'] = (int)$_POST['media_per_page'];
            if (isset($_POST['category_candidates'])) $settings['category_candidates'] = $_POST['category_candidates'];

            // Password change (Skip current password check for simplicity if from admin panel, but new_password must be set)
            if (!empty($_POST['new_password'])) {
                $settings['password_hash'] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            }
        } elseif ($_POST['save_action'] === 'save_memo') {
            $settings['memo'] = $_POST['memo'] ?? '';
        }
        
        if (file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
            $message = t('msg_update_success');
        } else {
            $message = t('err_save_failed');
        }
    }
    elseif ($_POST['save_action'] === 'add_category') {
        $newCat = trim($_POST['category'] ?? '');
        $newCat = preg_replace('/[^a-zA-Z0-9_\-]/', '', $newCat);
        if (empty($newCat)) {
            $message = t('err_invalid_category');
            $success = false;
        } else {
            $candidates = array_filter(array_map('trim', explode(',', $settings['category_candidates'] ?? '')));
            if (!in_array($newCat, $candidates)) {
                $candidates[] = $newCat;
                $settings['category_candidates'] = implode(', ', $candidates);
                $saved = file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
                if ($saved) {
                    $message = sprintf(t('msg_category_added'), $newCat);
                    $success = true;
                } else {
                    $message = t('err_category_add_failed_msg');
                    $success = false;
                }
            } else {
                $message = sprintf(t('err_category_exists'), $newCat);
                $success = true;
            }
        }
        if (isset($_POST['ajax_request'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }
    }
    elseif ($_POST['save_action'] === 'delete_category') {
        $delCat = trim($_POST['category'] ?? '');
        $confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] === '1';

        if (empty($delCat)) {
            $message = t('err_category_not_specified');
            $success = false;
        } else {
            $allPageIds = getFileList(POSTS_DIR);
            $usageCount = 0;
            foreach ($allPageIds as $pid) {
                $pData = loadData(POSTS_DIR, $pid);
                if ($pData && !empty($pData['category'])) {
                    $cats = array_filter(array_map('trim', explode(',', $pData['category'])));
                    if (in_array($delCat, $cats)) {
                        $usageCount++;
                    }
                }
            }

            if ($usageCount > 0 && !$confirmed) {
                if (isset($_POST['ajax_request'])) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'require_confirm' => true,
                        'usage_count' => $usageCount,
                        'message' => sprintf(t('category_delete_in_use_confirm'), $usageCount)
                    ]);
                    exit;
                } else {
                    $message = sprintf(t('err_category_in_use'), $usageCount);
                    $success = false;
                }
            } else {
                $candidates = array_filter(array_map('trim', explode(',', $settings['category_candidates'] ?? '')));
                $newCandidates = array_diff($candidates, [$delCat]);
                $settings['category_candidates'] = implode(', ', $newCandidates);
                $savedSettings = file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
                
                $updatedPages = 0;
                foreach ($allPageIds as $pid) {
                    $pData = loadData(POSTS_DIR, $pid);
                    if ($pData && !empty($pData['category'])) {
                        $cats = array_filter(array_map('trim', explode(',', $pData['category'])));
                        if (in_array($delCat, $cats)) {
                            $newCats = array_diff($cats, [$delCat]);
                            $pData['category'] = implode(', ', $newCats);
                            $pData['updated_at'] = date('Y-m-d H:i:s');
                            if (saveData(POSTS_DIR, $pid, $pData)) {
                                $updatedPages++;
                            }
                        }
                    }
                }
                
                if ($savedSettings) {
                    $message = sprintf(t('msg_category_deleted'), $delCat) . ($updatedPages > 0 ? sprintf(t('msg_category_removed_from_pages'), $updatedPages) : "");
                    $success = true;
                } else {
                    $message = t('err_category_delete_failed_msg');
                    $success = false;
                }
            }
        }
        if (isset($_POST['ajax_request'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }
    }
    elseif ($_POST['save_action'] === 'rename_media') {
        $oldName = basename($_POST['old_filename']);
        $newName = basename($_POST['new_filename']);
        $newName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $newName);
        
        $oldPath = MEDIA_DIR . '/' . $oldName;
        $newPath = MEDIA_DIR . '/' . $newName;
        
        $confirmed = isset($_POST['confirmed']) ? $_POST['confirmed'] : null;
        $success = false;
        
        if (empty($newName)) {
            $message = t('err_filename_empty');
        } elseif (!file_exists($oldPath)) {
            $message = t('err_original_file_not_found');
        } elseif (file_exists($newPath)) {
            $message = sprintf(t('err_rename_target_exists'), $newName);
        } else {
            // Find references in posts and components
            $affectedPosts = [];
            $postIds = getFileList(POSTS_DIR);
            foreach ($postIds as $pid) {
                $raw = file_get_contents(POSTS_DIR . '/' . $pid . '.json');
                if (strpos($raw, $oldName) !== false) {
                    $affectedPosts[] = $pid;
                }
            }
            
            $affectedComps = [];
            $compIds = getFileList(COMPONENTS_DIR);
            foreach ($compIds as $cid) {
                $raw = file_get_contents(COMPONENTS_DIR . '/' . $cid . '.json');
                if (strpos($raw, $oldName) !== false) {
                    $affectedComps[] = $cid;
                }
            }
            
            $totalCount = count($affectedPosts) + count($affectedComps);
            
            if ($totalCount > 0 && is_null($confirmed)) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'require_confirm' => true,
                    'message' => sprintf(t('media_rename_in_use_confirm'), $totalCount)
                ]);
                exit;
            }
            
            if (rename($oldPath, $newPath)) {
                $message = sprintf(t('msg_media_renamed'), $newName);
                $success = true;
                
                if ($confirmed === '1' && $totalCount > 0) {
                    $updatedCount = 0;
                    foreach ($affectedPosts as $pid) {
                        $filePath = POSTS_DIR . '/' . $pid . '.json';
                        $raw = file_get_contents($filePath);
                        $newRaw = str_replace($oldName, $newName, $raw);
                        if (file_put_contents($filePath, $newRaw) !== false) {
                            $updatedCount++;
                        }
                    }
                    foreach ($affectedComps as $cid) {
                        $filePath = COMPONENTS_DIR . '/' . $cid . '.json';
                        $raw = file_get_contents($filePath);
                        $newRaw = str_replace($oldName, $newName, $raw);
                        if (file_put_contents($filePath, $newRaw) !== false) {
                            $updatedCount++;
                        }
                    }
                    $message .= sprintf(t('msg_media_links_updated'), $updatedCount);
                }
            } else {
                $message = t('err_rename_failed_msg');
            }
        }
        
        if (isset($_POST['ajax_request'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'message' => $message]);
            exit;
        }
    }
    elseif ($_POST['save_action'] === 'ssg_save_settings') {
        $settings['ssg_dir'] = $_POST['ssg_dir'] ?? '';
        $settings['ssg_mode'] = in_array($_POST['ssg_mode'] ?? '', ['server', 'export'], true) ? $_POST['ssg_mode'] : 'server';
        $settings['ssg_server_structure'] = in_array($_POST['ssg_server_structure'] ?? '', ['directory', 'file'], true) ? $_POST['ssg_server_structure'] : 'directory';
        $settings['ssg_export_structure'] = in_array($_POST['ssg_export_structure'] ?? '', ['directory', 'file'], true) ? $_POST['ssg_export_structure'] : 'file';
        $settings['ssg_structure'] = $settings['ssg_mode'] === 'export' ? $settings['ssg_export_structure'] : $settings['ssg_server_structure'];
        $settings['ssg_link_mode'] = in_array($_POST['ssg_link_mode'] ?? '', ['relative', 'absolute'], true) ? $_POST['ssg_link_mode'] : 'relative';
        if (isset($_POST['ssg_root_url'])) $settings['ssg_root_url'] = trim($_POST['ssg_root_url']);
        if (saveSettings($settings)) {
            $message = t('msg_update_success');
        } else {
            $message = t('err_save_failed');
        }
        // Update helper variables for immediate use in UI
        $ssgDir = $settings['ssg_dir'] ?? '';
        $lastSsgRelPath = '../' . (($ssgDir !== '') ? rtrim($ssgDir, '/') . '/' : '');
    }
    elseif ($_POST['save_action'] === 'ssg_build') {
        require_once __DIR__ . '/lib/ssg.php';

        $ssgMode = in_array($_POST['ssg_mode'] ?? ($settings['ssg_mode'] ?? 'server'), ['server', 'export'], true)
            ? ($_POST['ssg_mode'] ?? ($settings['ssg_mode'] ?? 'server'))
            : 'server';
        $ssgLinkMode = in_array($_POST['ssg_link_mode'] ?? ($settings['ssg_link_mode'] ?? 'relative'), ['relative', 'absolute'], true)
            ? ($_POST['ssg_link_mode'] ?? ($settings['ssg_link_mode'] ?? 'relative'))
            : 'relative';
        if ($ssgMode === 'server') $ssgLinkMode = 'absolute';
        if ($ssgMode === 'export' && trim($activeSsgDir) === '') {
            $activeSsgDir = 'export';
            $activeSsgAbsPath = $siteRoot . '/export';
            $activeSsgRelPath = '../export/';
        }

        $ssgServerStructure = in_array($_POST['ssg_server_structure'] ?? ($settings['ssg_server_structure'] ?? 'directory'), ['directory', 'file'], true)
            ? ($_POST['ssg_server_structure'] ?? ($settings['ssg_server_structure'] ?? 'directory'))
            : 'directory';
        $ssgExportStructure = in_array($_POST['ssg_export_structure'] ?? ($settings['ssg_export_structure'] ?? 'file'), ['directory', 'file'], true)
            ? ($_POST['ssg_export_structure'] ?? ($settings['ssg_export_structure'] ?? 'file'))
            : 'file';
        $ssgStructure = in_array($_POST['ssg_structure'] ?? '', ['directory', 'file'], true)
            ? $_POST['ssg_structure']
            : ($ssgMode === 'export' ? $ssgExportStructure : $ssgServerStructure);
        if ($ssgMode === 'export') {
            $ssgExportStructure = $ssgStructure;
        } else {
            $ssgServerStructure = $ssgStructure;
        }

        $settings['ssg_dir'] = $activeSsgDir;
        $settings['ssg_structure'] = $ssgStructure;
        $settings['ssg_server_structure'] = $ssgServerStructure;
        $settings['ssg_export_structure'] = $ssgExportStructure;
        $settings['ssg_mode'] = $ssgMode;
        $settings['ssg_link_mode'] = $ssgLinkMode;
        if (isset($_POST['ssg_root_url'])) $settings['ssg_root_url'] = trim($_POST['ssg_root_url']);
        $renderer = new MikanBoxRenderer($settings);

        $ssgOpts = [
            'structure' => $settings['ssg_structure'],
            'selected_pages' => [], // Build all that are public_static
            'output_mode' => $ssgMode,
            'link_mode' => $ssgLinkMode,
            'copy_media' => $ssgMode === 'export',
        ];

        $ssg = new MikanBoxSSG($renderer, $activeSsgAbsPath, $ssgOpts);
        $ssg->clear(); // Remove old files (handles structure format changes)
        $results = $ssg->build();
        $built = array_filter($results, fn($r) => strpos($r, 'Error') === false);
        $errors = array_filter($results, fn($r) => strpos($r, 'Error') !== false);
        $message = t('msg_ssg_finished', count($built));
        if (!empty($errors)) $message .= ' / ' . implode(', ', $errors);
        if (empty($results)) $message .= t('msg_html_pages_none');
        
        if (!saveSettings($settings)) {
            $message .= ' / ' . t('err_save_failed');
        }
        $_SESSION['admin_message'] = $message;
        header("Location: admin.php?view=settings#ssg");
        exit;
    }
    elseif ($_POST['save_action'] === 'ssg_clear') {
        require_once __DIR__ . '/lib/ssg.php';
        $ssg = new MikanBoxSSG($renderer, $activeSsgAbsPath, []);
        $msg = $ssg->clear();
        $message = "Cleared: " . $msg;
    }
    elseif ($_POST['save_action'] === 'ssg_delete_page') {
        require_once __DIR__ . '/lib/ssg.php';
        $pid = $_POST['id'];
        $ssg = new MikanBoxSSG($renderer, $activeSsgAbsPath, []);
        $count = $ssg->deletePage($pid);
        $message = "Static files for '$pid' deleted ($count files).";
    }
    elseif ($_POST['save_action'] === 'download_backup_data' || $_POST['save_action'] === 'download_backup_media') {
        $mode = $_POST['save_action'] === 'download_backup_data' ? 'data' : 'media';
        $sourceDir = $mode === 'data' ? DATA_DIR : MEDIA_DIR;
        if (!is_dir($sourceDir)) {
            $message = t('error_save_failed');
        } else {
            $zip = new ZipArchive();
            $zipFile = DATA_DIR . "/backup_{$mode}_" . date('YmdHis') . '.zip';
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir), RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($files as $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = $mode . '/' . substr($filePath, strlen($sourceDir) + 1);
                        if ($mode === 'data' && strpos(basename($filePath), 'backup_') === 0) continue; 
                        $zip->addFile($filePath, $relativePath);
                    }
                }
                $zip->close();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename(__DIR__) . '_' . $mode . '_' . date('Ymd') . '.zip"');
                readfile($zipFile); unlink($zipFile); exit;
            }
        }
    }
    elseif ($_POST['save_action'] === 'generate_mcp_key') {
        $newKey = bin2hex(random_bytes(24));
        $settings['mcp_api_key'] = $newKey;
        $saved = (bool)file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (isset($_POST['ajax_request'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'     => $saved,
                'message'     => $saved ? t('msg_mcp_key_generated') : t('err_save_failed'),
                'mcp_api_key' => $saved ? $newKey : '',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $_SESSION['admin_message'] = $saved ? t('msg_mcp_key_generated') : t('err_save_failed');
        header('Location: ' . basename(__FILE__) . '#mcp-api-key');
        exit;
    }
    elseif ($_POST['save_action'] === 'change_status') {
        $id = $_POST['id'];
        $status = $_POST['status'];
        $data = loadData(POSTS_DIR, $id);
        if ($data) {
            $data['status'] = $status;
            if (saveData(POSTS_DIR, $id, $data)) {
                require_once __DIR__ . '/lib/ssg.php';
                $ssgOpts = [
                    'structure' => $settings['ssg_structure'] ?? 'directory',
                    'copy_media' => ($settings['ssg_mode'] ?? 'server') === 'export',
                    'selected_pages' => [$id]
                ];
                $ssg = new MikanBoxSSG($renderer, $activeSsgAbsPath, $ssgOpts);
                if ($status === 'public_static') {
                    $ssg->build();
                } else {
                    $ssg->deletePage($id);
                }
                $message = t('msg_status_changed', $id);
            }
        }
    }
}
skip_post_actions:

// Return JSON response for AJAX saves and skip rendering page
if (isset($_POST['ajax_request'])) {
    $responseData = [
        'success' => true,
        'message' => $message ?? t('msg_update_success'),
        'editId' => $editId ?? null
    ];
    // Compute preview URL for page saves so JS can inject/update the preview button
    if (($_POST['save_action'] ?? '') === 'save_page' && !empty($editId)) {
        $savedStatus = $_POST['status'] ?? 'draft';
        $ssgStruct = $settings['ssg_structure'] ?? 'directory';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $siteDir = dirname(dirname($_SERVER['SCRIPT_NAME']));
        if ($siteDir === '/' || $siteDir === '.') $siteDir = '';
        $siteBaseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $siteDir;
        if ($editId === 'index') {
            $responseData['preview_url'] = $siteBaseUrl . '/';
        } elseif ($savedStatus === 'public_static') {
            $ssgDirForUrl = $settings['ssg_dir'] ?? '';
            $staticRoot = !empty($settings['ssg_root_url'])
                ? rtrim($settings['ssg_root_url'], '/')
                : $siteBaseUrl . (($ssgDirForUrl !== '') ? '/' . trim($ssgDirForUrl, '/') : '');
            $responseData['preview_url'] = $staticRoot . '/' . $editId . ($ssgStruct === 'directory' ? '/' : '.html');
        } else {
            $responseData['preview_url'] = $siteBaseUrl . '/' . $editId;
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) ob_clean();
    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Fetch data for edit mode ---
$editData = null;
// $editId might be set in POST processing, so only fetch from GET if null
if ($editId === null) {
    $editId = isset($_GET['edit']) ? $_GET['edit'] : null;
}

if ($editId) {
    if ($view === 'pages') $editData = loadData(POSTS_DIR, $editId);
    elseif ($view === 'components') $editData = loadData(COMPONENTS_DIR, $editId);
}

// --- AJAX Editor Fragment Endpoint ---
if (isset($_GET['ajax_editor'])) {
    $helpFile = (getSystemLanguage() === 'ja') ? 'https://yoshihiko.com/mikanbox/help_ja.html' : 'https://yoshihiko.com/mikanbox/help_en.html';
    $site_name = $settings['site_name'] ?? SITE_NAME;
    $ssgDir = $settings['ssg_dir'] ?? ($settings['last_ssg_dir'] ?? '');
    $lastSsgRelPath = '../' . (($ssgDir !== '') ? rtrim($ssgDir, '/') . '/' : '');
    ob_start();
    if ($view === 'pages') {
        include __DIR__ . '/views/page-editor.php';
    } elseif ($view === 'components') {
        include __DIR__ . '/views/design-editor.php';
    }
    $htmlFromFragment = ob_get_clean();
    if (ob_get_length()) ob_clean(); // Clean the top-level buffer
    header('Content-Type: text/html; charset=UTF-8');
    echo $htmlFromFragment;
    exit;
}

// --- AJAX Media Fragment Endpoint ---
if (isset($_GET['ajax_media'])) {
    ob_start();
    include __DIR__ . '/views/media.php';
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// --- AJAX Pages List Fragment Endpoint ---
if (isset($_GET['ajax_pages'])) {
    $helpFile = (getSystemLanguage() === 'ja') ? 'https://yoshihiko.com/mikanbox/help_ja.html' : 'https://yoshihiko.com/mikanbox/help_en.html';
    $editId = null;
    ob_start();
    include __DIR__ . '/views/pages.php';
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// --- AJAX Comps List Fragment Endpoint ---
if (isset($_GET['ajax_comps'])) {
    $helpFile = (getSystemLanguage() === 'ja') ? 'https://yoshihiko.com/mikanbox/help_ja.html' : 'https://yoshihiko.com/mikanbox/help_en.html';
    $editId = null;
    ob_start();
    include __DIR__ . '/views/design.php';
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}

// ==========================================
// Admin Panel HTML
// ==========================================
$helpFile = (getSystemLanguage() === 'ja') ? 'https://yoshihiko.com/mikanbox/help_ja.html' : 'https://yoshihiko.com/mikanbox/help_en.html';
if (ob_get_length()) ob_clean();
?>
<!DOCTYPE html>
<html lang="<?= getSystemLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <title>🍊mikanBox flat - <?= t('admin_site_title') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=block" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<body>
<script>(function(){
    // ページ読み込み中の全transitionを抑制（scrollイベントによるフラッシュ防止）
    var s=document.createElement('style');s.id='init-notransition';
    s.textContent='body,.side-nav a{transition:none!important}';
    document.head.appendChild(s);
    var bg=sessionStorage.getItem('mikan_bg');
    if(bg){document.body.style.backgroundColor=bg;sessionStorage.removeItem('mikan_bg');}
})();</script>

<?php
function getIcon($name) {
    $icons = [
        'page' => 'description',
        'component' => 'widgets',
        'media' => 'image',
        'save' => 'save',
        'view' => 'visibility',
        'upload' => 'upload',
        'video' => 'videocam',
        'audio' => 'music_note',
        'cloud' => 'cloud_upload',
        'logout' => 'logout',
        'globe' => 'language',
        'sparkles' => 'auto_awesome',
        'edit' => 'edit',
        'delete' => 'delete',
        'download' => 'download',
        'arrow_back' => 'arrow_back',
        'add' => 'add',
        'copy' => 'content_copy',
        'open_in_new' => 'open_in_new',
        'reset' => 'restart_alt',
        'check' => 'check'
    ];
    $iconName = $icons[$name] ?? '';
    return $iconName ? '<span class="material-symbols-outlined icon">' . $iconName . '</span>' : '';
}
?>


<div id="drop-zone"><?= getIcon('cloud') ?> <?= t('hint_drop_upload') ?></div>

<nav class="side-nav">
    <div class="side-nav-brand">
        <span class="emoji">🍊</span>
        <span class="text">mikanBox<br>flat</span>
    </div>
    
    <a href="#pages" class="nav-pages" title="<?= t('nav_pages') ?>">
        <?= getIcon('page') ?>
        <span><?= t('nav_pages') ?></span>
    </a>

    <?php if ($view === 'pages' && ($editId !== null || isset($_GET['new']))): ?>
    <a href="#page-editor" class="nav-edit active" data-editor-type="page" title="<?= t('btn_edit') ?>">
        <?= getIcon('edit') ?>
        <span><?= t('btn_edit') ?></span>
        <span class="close-badge" data-url="admin.php#pages" title="<?= t('btn_close') ?>">×</span>
    </a>
    <?php endif; ?>
    <a href="#site" class="nav-settings" title="<?= t('nav_settings') ?>">
        <?= getIcon('save') ?>
        <span><?= t('nav_settings') ?></span>
    </a>
    <a href="#design" class="nav-design" title="<?= t('nav_design') ?>">
        <?= getIcon('component') ?>
        <span><?= t('nav_design') ?></span>
    </a>

    <?php if ($view === 'components' && ($editId !== null || isset($_GET['new']))): ?>
    <a href="#design-editor" class="nav-edit active" data-editor-type="design" title="<?= t('btn_edit') ?>">
        <?= getIcon('edit') ?>
        <span><?= t('btn_edit') ?></span>
        <span class="close-badge" data-url="admin.php#design" title="<?= t('btn_close') ?>">×</span>
    </a>
    <?php endif; ?>

    <a href="#media" class="nav-media" title="<?= t('nav_media') ?>">
        <?= getIcon('media') ?>
        <span><?= t('nav_media') ?></span>
    </a>
</nav>
<div class="main">
    <!-- ==============================================
         Main Unified View (All sections via includes)
         ============================================== -->
    <div class="page-top-links">
        <a href="<?= $lastSsgRelPath ?>" target="_blank"><?= t('admin_view_site') ?></a>
        <?php if ($isDemoMode && !$isLoggedIn): ?>
        <a href="?login=1"><?= t('btn_login') ?></a>
        <?php else: ?>
        <a href="?action=logout"><?= t('admin_logout') ?></a>
        <?php endif; ?>
    </div>
    
    <!-- Category Cloud -->
    <?php
    $allPageIds = getFileList(POSTS_DIR);
    $allCategories = [];
    foreach ($allPageIds as $pid) {
        $pData = loadData(POSTS_DIR, $pid);
        if ($pData && !empty($pData['category'])) {
            $cats = array_filter(array_map('trim', explode(',', $pData['category'])));
            foreach ($cats as $c) {
                if ($c !== '') $allCategories[] = $c;
            }
        }
    }
    if (!empty($settings['category_candidates'])) {
        $registeredCats = array_filter(array_map('trim', explode(',', $settings['category_candidates'])));
        foreach ($registeredCats as $c) {
            if ($c !== '') $allCategories[] = $c;
        }
    }
    $allCategories = array_unique($allCategories);
    
    $selectedCat = $_GET['cat'] ?? '';
    if ($selectedCat !== '' && !in_array($selectedCat, $allCategories)) {
        $allCategories[] = $selectedCat;
    }
    sort($allCategories);
    ?>
    <div class="category-cloud-wrap">
        <div class="category-cloud">
            <span class="category-cloud-label"><?= t('category_cloud_label') ?></span>
            <a href="?cat=" class="category-cloud-tag <?= $selectedCat === '' ? 'active' : '' ?>">
                <?= t('all_pages') ?>
            </a>
            <?php foreach ($allCategories as $c): ?>
                <span class="category-cloud-tag-wrap">
                    <a href="?cat=<?= urlencode($c) ?>" class="category-cloud-tag <?= $selectedCat === $c ? 'active' : '' ?>">
                        <?= htmlspecialchars($c) ?>
                    </a>
                    <span class="category-delete-badge" data-category="<?= htmlspecialchars($c) ?>" title="<?= t('category_delete_confirm_title') ?>">&times;</span>
                </span>
            <?php endforeach; ?>
            <span class="category-control-group" style="display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;">
                <span class="category-cloud-divider" style="margin: 0;">|</span>
                <a href="#" class="category-cloud-tag btn-add-cat" style="border-style: dashed; background: transparent; display: inline-flex; align-items: center; gap: 5px;">
                    <?= getIcon('add') ?> <?= t('btn_add') ?>
                </a>
                <a href="#" class="category-cloud-tag btn-toggle-del-cat" style="border-style: dashed; background: transparent; display: inline-flex; align-items: center; gap: 5px;">
                    <?= getIcon('delete') ?> <?= t('btn_delete') ?>
                </a>
                <input type="text" id="new-category-input" placeholder="<?= t('placeholder_new_category') ?>" style="display: none; width: 120px; padding: 4px 10px; border-radius: 8px; border: 1px solid #ff8c00; font-size: 0.85rem; height: 30px; box-sizing: border-box; vertical-align: middle;">
            </span>
        </div>
    </div>

    <?php include __DIR__ . '/views/pages.php'; ?>

    <?php include __DIR__ . '/views/site.php'; ?>

    <?php include __DIR__ . '/views/design.php'; ?>

    <?php include __DIR__ . '/views/media.php'; ?>




    <script>
    // ブラウザのスクロール復元を無効にして競合させない
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    // エディター読み込み時: 遅延なしで即スムーズスクロール開始（window.onload より大幅に早い）
    (function() {
        var hash = window.location.hash;
        if (hash === '#page-editor' || hash === '#design-editor' || hash === '#pages' || hash === '#media') {
            var el = document.querySelector(hash);
            if (el) el.scrollIntoView({ behavior: 'smooth' });
        }
    })();

    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const uploadForm = document.getElementById('upload-form');

    window.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (dropZone) dropZone.classList.add('active');
    });

    window.addEventListener('dragleave', (e) => {
        if (e.relatedTarget === null && dropZone) {
            dropZone.classList.remove('active');
        }
    });

    window.addEventListener('drop', async (e) => {
        e.preventDefault();
        if (dropZone) dropZone.classList.remove('active');
        const files = e.dataTransfer.files;
        if (files.length > 0 && uploadForm) {
            const csrfInput = uploadForm.querySelector('input[name="csrf_token"]');
            const formData = new FormData();
            formData.append('save_action', 'upload_media');
            if (csrfInput) formData.append('csrf_token', csrfInput.value);
            formData.append('image', files[0]);
            await doMediaUpload(formData);
        }
    });

    if (uploadForm) {
        uploadForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            await doMediaUpload(new FormData(uploadForm));
        });
    }

    async function doMediaUpload(formData) {
        const btn = document.getElementById('upload-btn');
        const origHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.textContent = '<?= t('msg_uploading') ?>'; btn.disabled = true; }
        formData.append('ajax_request', '1');
        const urlParams = new URLSearchParams(window.location.search);
        const cat = urlParams.get('cat') || '';
        if (cat && !formData.has('cat')) {
            formData.append('cat', cat);
        }
        try {
            const res = await fetch(window.location.href, { method: 'POST', body: formData });
            const json = await res.json().catch(() => ({}));
            showToast(json.message || '', !json.success);
            if (json.success) {
                if (fileInput) fileInput.value = '';
                await refreshMediaGrid();
            }
        } catch(err) {
            showToast('<?= t('err_upload_failed') ?>', true);
        } finally {
            if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
        }
    }

    async function refreshMediaGrid() {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('ajax_media', '1');
            const res = await fetch('?' + urlParams.toString());
            const html = await res.text();
            const temp = document.createElement('div');
            temp.innerHTML = html;
            const newWrap = temp.querySelector('#media-list-wrap');
            const oldWrap = document.querySelector('#media-list-wrap');
            if (newWrap && oldWrap) oldWrap.outerHTML = newWrap.outerHTML;
        } catch(err) {}
    }

    async function refreshPageList() {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('ajax_pages', '1');
            urlParams.set('view', 'pages');
            const res = await fetch('?' + urlParams.toString());
            const html = await res.text();
            const temp = document.createElement('div');
            temp.innerHTML = html;
            const newWrap = temp.querySelector('#pages-table-wrap');
            const oldWrap = document.querySelector('#pages-table-wrap');
            if (newWrap && oldWrap) oldWrap.outerHTML = newWrap.outerHTML;
            // Also refresh pagination controls if any
            const newPag = temp.querySelector('#pages .pagination');
            const oldPag = document.querySelector('#pages .pagination');
            if (newPag && oldPag) {
                oldPag.outerHTML = newPag.outerHTML;
            } else if (oldPag) {
                oldPag.remove();
            } else if (newPag) {
                const tableWrap = document.querySelector('#pages-table-wrap');
                if (tableWrap) tableWrap.parentNode.insertBefore(newPag, tableWrap.nextSibling);
            }
        } catch(err) {}
    }

    async function refreshCompList() {
        try {
            const res = await fetch('?ajax_comps=1&view=design');
            const html = await res.text();
            const temp = document.createElement('div');
            temp.innerHTML = html;
            const newWrap = temp.querySelector('#comps-table-wrap');
            const oldWrap = document.querySelector('#comps-table-wrap');
            if (newWrap && oldWrap) oldWrap.outerHTML = newWrap.outerHTML;
        } catch(err) {}
    }

    // save_action値がメディア操作（AJAXで別途ハンドリングされる）かどうかを判定
    function isMediaFormAction(action) {
        return action === 'resize_media' || action === 'delete_media' || action === 'rename_media';
    }

    // Resize, rename and delete media forms - event delegation
    document.addEventListener('submit', async function(e) {
        const form = e.target;
        const actionInput = form.querySelector('input[name="save_action"]');
        if (!actionInput) return;
        const action = actionInput.value;
        if (isMediaFormAction(action)) {
            e.preventDefault();
            
            if (action === 'delete_media') {
                const confirmed = await window.showConfirmDialog('<?= t('hint_confirm_delete') ?>', '<?= t('btn_delete') ?>', '<?= t('btn_delete_confirm') ?>', 'btn-red');
                if (!confirmed) return;
            }
            const formData = new FormData(form);
            formData.append('ajax_request', '1');
            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const json = await res.json().catch(() => ({}));
                
                if (action === 'rename_media' && json.require_confirm) {
                    const dialog = document.getElementById('rename-confirm-dialog');
                    const msgEl = document.getElementById('rename-dialog-message');
                    const btnCancel = document.getElementById('btn-rename-cancel');
                    const btnOnly = document.getElementById('btn-rename-only');
                    const btnUpdate = document.getElementById('btn-rename-update');
                    
                    if (dialog && msgEl && btnCancel && btnOnly && btnUpdate) {
                        msgEl.textContent = json.message;
                        dialog.showModal();
                        
                        const choice = await new Promise((resolve) => {
                            const onCancel = () => { dialog.close(); resolve('cancel'); };
                            const onOnly = () => { dialog.close(); resolve('only'); };
                            const onUpdate = () => { dialog.close(); resolve('update'); };
                            
                            btnCancel.addEventListener('click', onCancel, { once: true });
                            btnOnly.addEventListener('click', onOnly, { once: true });
                            btnUpdate.addEventListener('click', onUpdate, { once: true });
                            
                            dialog.addEventListener('close', () => {
                                btnCancel.removeEventListener('click', onCancel);
                                btnOnly.removeEventListener('click', onOnly);
                                btnUpdate.removeEventListener('click', onUpdate);
                                resolve('cancel');
                            }, { once: true });
                        });
                        
                        if (choice === 'update') {
                            formData.append('confirmed', '1');
                            const res2 = await fetch(window.location.href, { method: 'POST', body: formData });
                            const json2 = await res2.json().catch(() => ({}));
                            showToast(json2.message || '', !json2.success);
                            if (json2.success) await refreshMediaGrid();
                        } else if (choice === 'only') {
                            formData.append('confirmed', '0');
                            const res2 = await fetch(window.location.href, { method: 'POST', body: formData });
                            const json2 = await res2.json().catch(() => ({}));
                            showToast(json2.message || '', !json2.success);
                            if (json2.success) await refreshMediaGrid();
                        } else {
                            const newFileInput = form.querySelector('input[name="new_filename"]');
                            const oldFileInput = form.querySelector('input[name="old_filename"]');
                            if (newFileInput && oldFileInput) {
                                newFileInput.value = oldFileInput.value;
                            }
                        }
                    }
                } else {
                    showToast(json.message || '', !json.success);
                    if (json.success) await refreshMediaGrid();
                }
            } catch(err) {
                showToast('<?= t('err_save_failed') ?>', true);
            }
        }
    });

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            alert('<?= t('msg_copied') ?>');
        } catch (err) {
            // Fallback for older browsers or non-secure contexts
            const textArea = document.createElement("textarea");
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                alert('<?= t('msg_copied') ?>');
            } catch (err) {
                alert('<?= t('msg_copy_failed') ?>');
            }
            document.body.removeChild(textArea);
        }
    }
    async function csvConvertAndCopy() {
        const fileInput = document.getElementById('csv-file-input');
        const btn = document.getElementById('csv-copy-btn');
        if (!fileInput.files[0]) { alert('<?= t('csv_no_file') ?>'); return; }
        const buffer = await fileInput.files[0].arrayBuffer();
        const bytes = new Uint8Array(buffer);
        let encoding = 'UTF-8';
        if (bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
            encoding = 'UTF-8'; // UTF-8 BOM
        } else {
            const probe = new TextDecoder('UTF-8', { fatal: false }).decode(buffer);
            if (probe.includes('\uFFFD')) encoding = 'Shift_JIS';
        }
        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const rows = [];
            let cur = '', inQ = false;
            const fields = [];
            for (let i = 0; i <= text.length; i++) {
                const c = text[i];
                if (c === '"') {
                    if (inQ && text[i+1] === '"') { cur += '"'; i++; }
                    else inQ = !inQ;
                } else if ((c === ',' && !inQ)) {
                    fields.push(cur); cur = '';
                } else if ((c === '\n' && !inQ) || c === undefined) {
                    fields.push(cur); cur = '';
                    if (fields.some(f => f.trim())) rows.push([...fields]);
                    fields.length = 0;
                } else {
                    cur += c;
                }
            }
            if (rows.length < 2) return;
            const headers = rows[0].map(h => h.trim().replace(/[^a-zA-Z0-9]/g, '_').replace(/^_+|_+$/g, ''));
            let output = '';
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                output += `{{DATAROW:${i}}}\n`;
                headers.forEach((h, j) => {
                    if (h) output += `{{DATA:${h}}}${(row[j] || '').trim()}{{/DATA}}\n`;
                });
                output += `{{/DATAROW}}\n\n`;
            }
            copyToClipboard(output).then(() => {
                const orig = btn.innerHTML;
                btn.textContent = '<?= t('csv_copied') ?>';
                setTimeout(() => btn.innerHTML = orig, 2000);
            });
        };
        reader.readAsText(fileInput.files[0], encoding);
    }
    function copyAiPrompt() {
        const textarea = document.getElementById('ai-prompt-editor');
        copyToClipboard(textarea.value).then(() => {
            alert('<?= t('msg_prompt_copied') ?>');
        });
    }
    async function changePageStatus(id, newStatus) {
        const formData = new FormData();
        formData.append('save_action', 'save_page_status');
        formData.append('id', id);
        formData.append('status', newStatus);
        formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
        formData.append('ajax_request', '1');
        try {
            const res = await fetch(window.location.href, { method: 'POST', body: formData });
            if (res.ok) {
                const json = await res.json().catch(()=>({}));
                showToast(json.message || '<?= t('msg_update_success') ?? '保存しました' ?>');
                
                // Update class color dynamically without reload
                const selectEl = document.querySelector(`select[onchange*="'${id}'"]`);
                if (selectEl) {
                    selectEl.classList.remove('static', 'dynamic', 'draft');
                    if (newStatus === 'public_static') selectEl.classList.add('static');
                    else if (newStatus === 'public_dynamic') selectEl.classList.add('dynamic');
                    else selectEl.classList.add('draft');
                }
            } else {
                showToast('<?= t('err_save_failed') ?>', true);
            }
        } catch(err) {
            showToast('<?= t('err_network_error') ?>', true);
        }
    }
    let isNavigating = false;
    function updateScrollPos() {
        // ナビゲーション中はスクロール位置の更新をスキップ（アイコンの一瞬の色変化を防ぐ）
        if (isNavigating) return;
        const sections = [
            { id: 'pages', color: '#e0f2fe', navId: 'nav-pages' }, // page-list
            { id: 'site', color: '#ffffff', navId: 'nav-settings' }, // site settings start
            { id: 'ssg-accordion', color: '#ffffff', navId: 'nav-settings' },
            { id: 'settings', color: '#ffffff', navId: 'nav-settings' },
            { id: 'backup', color: '#ffffff', navId: 'nav-settings' },
            { id: 'design', color: '#fffbeb', navId: 'nav-design' }, // design
            { id: 'media', color: '#f0fdf4', navId: 'nav-media' }    // media
        ];

        let scrollY = window.scrollY + window.innerHeight / 3;
        let current = sections[0];

        for (const sec of sections) {
            const el = document.getElementById(sec.id);
            if (el) {
                const rect = el.getBoundingClientRect();
                const top = rect.top + window.scrollY - 100;
                if (scrollY >= top) {
                    current = sec;
                }
            }
        }

        if (current) {
            document.body.style.backgroundColor = current.color;
            document.querySelectorAll('.side-nav a:not(.nav-edit)').forEach(btn => {
                const shouldBeActive = btn.classList.contains(current.navId);
                if (shouldBeActive && !btn.classList.contains('active')) {
                    btn.classList.add('active');
                } else if (!shouldBeActive && btn.classList.contains('active')) {
                    btn.classList.remove('active');
                }
            });
        }
    }

    // Scroll to parent section first, then navigate after scroll completes
    function navigateAfterScroll(targetUrl) {
        if (isNavigating) return;
        isNavigating = true;

        // Extract hash from targetUrl to find scroll target
        const hashIndex = targetUrl.indexOf('#');
        const hash = hashIndex !== -1 ? targetUrl.substring(hashIndex) : null;
        const scrollTarget = hash ? document.querySelector(hash) : null;

        // ナビアイコンのtransitionを停止してスクロール中のピクつきを防ぐ
        document.querySelectorAll('.side-nav a').forEach(a => a.style.transition = 'none');

        // Apply closing animation to nav-edit button if it exists
        const navEdit = document.querySelector('.side-nav .nav-edit');
        const hasClosingAnim = navEdit && targetUrl.indexOf('edit=') === -1 && targetUrl.indexOf('new=1') === -1;
        if (hasClosingAnim) {
            navEdit.classList.add('closing');
        }

        function doNavigate() {
            window.isDirty = false;
            sessionStorage.setItem('mikan_bg', document.body.style.backgroundColor || '');
            window.location.href = targetUrl;
        }

        // Wait for closing animation to finish (500ms) before navigating
        const animDelay = hasClosingAnim ? 500 : 0;

        if (scrollTarget) {
            scrollTarget.scrollIntoView({ behavior: 'smooth' });
            // Wait for scroll to finish, then navigate
            let scrollTimer;
            const onScrollEnd = () => {
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(() => {
                    window.removeEventListener('scroll', onScrollEnd);
                    // Ensure animation has completed before navigating
                    const elapsed = performance.now() - navStartTime;
                    const remaining = Math.max(0, animDelay - elapsed);
                    setTimeout(doNavigate, remaining);
                }, 150); // 150ms after last scroll event = scroll finished
            };
            const navStartTime = performance.now();
            window.addEventListener('scroll', onScrollEnd);
            // Fallback: if we're already at the target (no scroll happens)
            scrollTimer = setTimeout(() => {
                window.removeEventListener('scroll', onScrollEnd);
                const elapsed = performance.now() - navStartTime;
                const remaining = Math.max(0, animDelay - elapsed);
                setTimeout(doNavigate, remaining);
            }, 600);
        } else {
            setTimeout(doNavigate, animDelay);
        }
    }

    // ==========================================
    // SPA Editor Open / Close
    // ==========================================
    const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';

    function createNavEditButton(type) {
        // type: 'page' or 'design'
        const hash = type === 'page' ? '#page-editor' : '#design-editor';
        const closeUrl = type === 'page' ? 'admin.php#pages' : 'admin.php#design';
        const a = document.createElement('a');
        a.href = hash;
        a.className = 'nav-edit active';
        a.dataset.editorType = type;
        a.title = '<?= t('btn_edit') ?>';
        a.innerHTML = '<?= getIcon('edit') ?><span><?= t('btn_edit') ?></span>' +
            '<span class="close-badge" data-url="' + closeUrl + '" title="<?= t('btn_close') ?>">×</span>';
        return a;
    }

    function getNavEditAnchor(type) {
        // nav-edit を挿入すべき位置の直後の兄弟要素を返す
        if (type === 'page') return document.querySelector('.side-nav .nav-settings');
        return document.querySelector('.side-nav .nav-media');
    }

    function bindDirtyTrackers(container) {
        container.querySelectorAll('input, textarea, select').forEach(el => {
            el.addEventListener('input', () => { window.isDirty = true; });
            el.addEventListener('change', () => { window.isDirty = true; });
        });
    }

    let _spaEditorAbortController = null;

    function spaOpenEditor(type, editId) {
        // type: 'page' or 'design'
        const view = type === 'page' ? 'pages' : 'design';
        const slotId = type === 'page' ? 'page-editor-slot' : 'design-editor-slot';
        const editorId = type === 'page' ? 'page-editor' : 'design-editor';
        const param = editId ? 'edit=' + encodeURIComponent(editId) : 'new=1';
        const url = 'admin.php?view=' + view + '&' + param + '&ajax_editor=1';

        // 前のfetchが進行中なら中断する（ダブルクリック対策）
        if (_spaEditorAbortController) {
            _spaEditorAbortController.abort();
        }
        _spaEditorAbortController = new AbortController();

        // 同じtypeのエディタが既に開いていれば閉じる（別typeは維持）
        const existing = document.getElementById(editorId);
        if (existing) {
            const slot = document.getElementById(slotId);
            if (slot) slot.innerHTML = '';
        }
        const oldNav = document.querySelector(`.side-nav .nav-edit[data-editor-type="${type}"]`);
        if (oldNav) oldNav.remove();

        fetch(url, { signal: _spaEditorAbortController.signal })
            .then(r => r.text())
            .then(html => {
                const slot = document.getElementById(slotId);
                if (!slot) return;

                // エディタHTMLをDOMに挿入
                slot.innerHTML = html;
                
                // 動的にスクリプトを実行する
                slot.querySelectorAll('script').forEach(oldScript => {
                    const newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });

                const editor = document.getElementById(editorId);
                if (!editor) return;

                // nav-editボタンを追加
                const navBtn = createNavEditButton(type);
                const anchor = getNavEditAnchor(type);
                if (anchor) anchor.parentNode.insertBefore(navBtn, anchor);

                // isDirtyトラッカーをバインド
                bindDirtyTrackers(editor);
                window.isDirty = false;

                // URLを更新
                const newUrl = 'admin.php?view=' + view + '&' + param + '#' + editorId;
                history.pushState({ spaEditor: true, type, editId }, '', newUrl);

                // レイアウト確定を待ってからスクロール→アニメーション開始
                requestAnimationFrame(() => {
                    editor.scrollIntoView({ behavior: 'smooth' });
                    // スクロール開始後にフェードインアニメーション
                    requestAnimationFrame(() => {
                        editor.classList.add('spa-entering');
                        editor.addEventListener('animationend', () => {
                            editor.classList.remove('spa-entering');
                        }, { once: true });
                    });
                });
            })
            .catch(err => {
                if (err.name === 'AbortError') return; // ダブルクリックによるキャンセルは無視
                showToast('<?= t('err_editor_load_failed') ?>', true);
                console.error(err);
            });
    }

    function spaCloseEditor(type) {
        const editorId = type === 'page' ? 'page-editor' : 'design-editor';
        const slotId = type === 'page' ? 'page-editor-slot' : 'design-editor-slot';
        const sectionId = type === 'page' ? 'pages' : 'design';
        const editor = document.getElementById(editorId);
        const navEdit = document.querySelector(`.side-nav .nav-edit[data-editor-type="${type}"]`);

        isNavigating = true;
        document.querySelectorAll('.side-nav a').forEach(a => a.style.transition = 'none');

        // nav-editの閉じるアニメーション
        if (navEdit) navEdit.classList.add('closing');

        if (editor) {
            // Phase 1: フェードアウト (opacity + transform)
            editor.classList.add('spa-leaving');

            // Phase 2: フェード完了後、高さをスムーズに収縮
            const fadeDuration = 350;
            setTimeout(() => {
                const h = editor.offsetHeight;
                // アニメーションをリセットし、高さを固定してからtransitionで収縮
                editor.style.animation = 'none';
                editor.style.opacity = '0';
                editor.style.height = h + 'px';
                editor.style.overflow = 'hidden';
                editor.style.padding = '0';
                editor.style.margin = '0';
                // reflow
                editor.offsetHeight;
                editor.style.transition = 'height 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
                editor.style.height = '0';
            }, fadeDuration);

            // Phase 3: 収縮完了後にDOM除去
            const totalDuration = fadeDuration + 320;
            setTimeout(() => {
                const slot = document.getElementById(slotId);
                if (slot) slot.innerHTML = '';
                if (navEdit) navEdit.remove();

                // Refresh the list to reflect any changes made in the editor
                if (type === 'page') refreshPageList();
                else refreshCompList();

                // URL更新
                history.pushState({ spaEditor: false }, '', 'admin.php#' + sectionId);

                // 閉じ先セクションの色とナビ状態を明示的にセット
                const sectionColors = { pages: '#e0f2fe', design: '#fffbeb', media: '#f0fdf4' };
                const sectionNavs = { pages: 'nav-pages', design: 'nav-design', media: 'nav-media' };
                document.body.style.backgroundColor = sectionColors[sectionId] || '#ffffff';
                const targetNav = sectionNavs[sectionId];
                if (targetNav) {
                    document.querySelectorAll('.side-nav a:not(.nav-edit)').forEach(btn => {
                        btn.classList.toggle('active', btn.classList.contains(targetNav));
                    });
                }

                // セクションへスムーズスクロール
                const section = document.getElementById(sectionId);
                if (section) section.scrollIntoView({ behavior: 'smooth' });

                window.isDirty = false;

                // スクロール完了を待ってからナビゲーション状態を解除
                setTimeout(() => {
                    isNavigating = false;
                    document.querySelectorAll('.side-nav a').forEach(a => a.style.transition = '');
                }, 400);
            }, totalDuration);
        } else {
            // エディタがない場合（フォールバック）
            if (navEdit) navEdit.remove();
            history.pushState({ spaEditor: false }, '', 'admin.php#' + sectionId);
            window.isDirty = false;
            isNavigating = false;
            document.querySelectorAll('.side-nav a').forEach(a => a.style.transition = '');
            updateScrollPos();
        }
    }

    function getEditorTypeFromUrl(url) {
        if (url.indexOf('view=pages') !== -1 || url.indexOf('#page-editor') !== -1 || url.indexOf('#pages') !== -1) return 'page';
        if (url.indexOf('view=design') !== -1 || url.indexOf('#design-editor') !== -1 || url.indexOf('#design') !== -1) return 'design';
        return null;
    }

    function spaHandlePendingNavigation() {
        const url = window.pendingTargetUrl;
        if (!url) return;

        // 閉じるボタンからの場合 → SPA close
        if (window.pendingCloseBtn) {
            const type = (url.indexOf('#pages') !== -1) ? 'page' : 'design';
            spaCloseEditor(type);
            return;
        }

        // 編集リンクからの場合 → SPA open
        if (url.indexOf('edit=') !== -1 || url.indexOf('new=1') !== -1) {
            const type = url.indexOf('#page-editor') !== -1 ? 'page' : 'design';
            const urlObj = new URL(url, window.location.origin);
            const editId = urlObj.searchParams.get('edit') || null;
            spaOpenEditor(type, editId);
            return;
        }

        // それ以外 → 通常のナビゲーション
        window.location.href = url;
    }

    // popstate: ブラウザの戻る/進む対応
    window.addEventListener('popstate', function(e) {
        const state = e.state;
        if (state && state.spaEditor === false) {
            // 閉じた状態に戻る
            const pageEditor = document.getElementById('page-editor');
            const designEditor = document.getElementById('design-editor');
            if (pageEditor) { document.getElementById('page-editor-slot').innerHTML = ''; }
            if (designEditor) { document.getElementById('design-editor-slot').innerHTML = ''; }
            const navEdit = document.querySelector('.side-nav .nav-edit');
            if (navEdit) navEdit.remove();
            window.isDirty = false;
            updateScrollPos();
        } else if (state && state.spaEditor === true) {
            // 開いた状態に戻る → フェッチして再表示
            spaOpenEditor(state.type, state.editId);
        }
    });

    window.onload = function() {
        // 早期スクリプトで注入した transition 抑制を解除する前に正しい状態を設定
        updateScrollPos();
        // scroll/resizeリスナーはonload内で登録（ハッシュスクロール中の誤発火を防ぐ）
        window.addEventListener('scroll', updateScrollPos, {passive: true});
        window.addEventListener('resize', updateScrollPos, {passive: true});
        requestAnimationFrame(() => requestAnimationFrame(() => {
            // 正しいbg・nav状態が設定された後にtransition抑制を解除
            var fix = document.getElementById('init-notransition');
            if (fix) fix.remove();
        }));
        
        // Unsaved changes tracker
        // Scoped to the page/design editor containers only (if server-rendered open on initial load).
        // Elements outside an open editor (e.g. inline status dropdowns in the list) must not
        // mark the app as dirty, or the unsaved-changes modal pops up with no editor open.
        window.isDirty = false;
        const initialPageEditor = document.getElementById('page-editor');
        const initialDesignEditor = document.getElementById('design-editor');
        if (initialPageEditor) bindDirtyTrackers(initialPageEditor);
        if (initialDesignEditor) bindDirtyTrackers(initialDesignEditor);
        
        window.addEventListener('beforeunload', function (e) {
            if (window.isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // ページ内リンクの滑らかなスクロール（CSS全体への指定を外すための代替処理）
        document.querySelectorAll('.side-nav a[href^="#"]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (e.target.closest('.close-badge')) return; // close-badge は body ハンドラーに任せる
                const target = document.querySelector(this.hash);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth' });
                    history.pushState(null, null, this.hash);
                }
            });
        });

        // Global unsaved changes interceptor (Replaces default confirm & covers internal navigations)
        document.body.addEventListener('click', function(e) {
            // --- SPA: 編集リンクのインターセプト ---
            const editLink = e.target.closest('a[href*="edit="][href*="#page-editor"], a[href*="new=1"][href*="#page-editor"], a[href*="edit="][href*="#design-editor"], a[href*="new=1"][href*="#design-editor"]');
            if (editLink && !e.target.closest('.close-badge')) {
                e.preventDefault();
                e.stopPropagation();

                if (window.isDirty) {
                    window.pendingTargetUrl = editLink.href;
                    window.pendingCloseBtn = false;
                    const modal = document.getElementById('unsaved-modal');
                    if (modal) modal.style.display = 'flex';
                    return;
                }

                const href = editLink.getAttribute('href');
                const type = href.indexOf('#page-editor') !== -1 ? 'page' : 'design';
                const urlParams = new URLSearchParams(href.split('?')[1]?.split('#')[0] || '');
                const editId = urlParams.get('edit') || null;
                spaOpenEditor(type, editId);
                return;
            }

            // --- 閉じるボタン（SPA処理） ---
            const closeBtn = e.target.closest('.editor-focus-bg a.btn-gray[href^="admin.php#"]') || e.target.closest('.side-nav .close-badge');

            if (closeBtn) {
                e.preventDefault();
                e.stopPropagation();

                const targetUrl = closeBtn.dataset.url || closeBtn.href || closeBtn.closest('a').href;

                if (window.isDirty) {
                    window.pendingTargetUrl = targetUrl;
                    window.pendingCloseBtn = true;
                    const modal = document.getElementById('unsaved-modal');
                    if (modal) modal.style.display = 'flex';
                } else {
                    // SPA close
                    const type = (targetUrl.indexOf('#pages') !== -1) ? 'page' : 'design';
                    spaCloseEditor(type);
                }
            } else if (window.isDirty) {
                // Internal app navigation that leaves the editor
                const link = e.target.closest('a');
                if (link && link.href && link.href.startsWith(window.location.origin) && link.href !== window.location.href && !link.href.includes('javascript:') && !link.hasAttribute('download')) {
                    // Let native scroll happen for safe sidebar jumps
                    const isSideNav = link.closest('.side-nav') && link.getAttribute('href') && link.getAttribute('href').startsWith('#');
                    
                    if (!isSideNav && link.target !== '_blank') {
                        // This navigation discards the current view (like opening another page). Show custom modal!
                        e.preventDefault();
                        e.stopPropagation();
                        window.pendingTargetUrl = link.href;
                        // It's not the close button, we just want to navigate to the new page after save
                        window.pendingCloseBtn = false; 
                        const modal = document.getElementById('unsaved-modal');
                        if (modal) modal.style.display = 'flex';
                    }
                }
            }
        }, true);
        
        // Modal Handlers
        const unsavedModal = document.getElementById('unsaved-modal');
        if (unsavedModal) {
            document.getElementById('btn-modal-cancel').onclick = () => unsavedModal.style.display = 'none';
            document.getElementById('btn-modal-discard').onclick = () => {
                unsavedModal.style.display = 'none';
                window.isDirty = false;
                spaHandlePendingNavigation();
            };
            document.getElementById('btn-modal-save').onclick = async () => {
                const saveBtn = document.querySelector('.editor-focus-bg form button[name="save_action"], .editor-focus-bg form button[type="submit"]');
                if (saveBtn) {
                    const form = saveBtn.closest('form');
                    const originalBtnText = saveBtn.innerHTML;
                    const originalModalText = document.getElementById('btn-modal-save').innerHTML;
                    
                    const savingHtml = '<span class="material-symbols-outlined icon" style="animation: spin 1s linear infinite;">sync</span> ' + <?= json_encode(t('msg_saving')) ?>;
                    saveBtn.innerHTML = savingHtml;
                    saveBtn.disabled = true;
                    document.getElementById('btn-modal-save').innerHTML = savingHtml;
                    document.getElementById('btn-modal-save').disabled = true;
                    
                    try {
                        const formData = new FormData(form);
                        // Only override save_action when the button itself declares a name
                        // (e.g. delete buttons). Some save buttons (like the component editor's)
                        // rely solely on the form's hidden save_action input and have no name/value
                        // of their own — appending an empty pair here would clobber that hidden value.
                        if (saveBtn.name) {
                            formData.append(saveBtn.name, saveBtn.value);
                        }
                        formData.append('ajax_request', '1');
                        const res = await fetch(window.location.href, { method: 'POST', body: formData });
                        if (res.ok) {
                            const json = await res.json().catch(() => ({}));
                            if (json.success) {
                                window.isDirty = false;
                                unsavedModal.style.display = 'none';
                                spaHandlePendingNavigation();
                            } else {
                                showToast(json.message || '<?= t('err_save_failed') ?>', true);
                            }
                        } else {
                            showToast('<?= t('err_save_failed') ?>', true);
                            unsavedModal.style.display = 'none';
                        }
                    } catch(e) {
                        showToast('<?= t('err_network_error_detail') ?>', true);
                        unsavedModal.style.display = 'none';
                    } finally {
                        saveBtn.innerHTML = originalBtnText;
                        saveBtn.disabled = false;
                        document.getElementById('btn-modal-save').innerHTML = originalModalText;
                        document.getElementById('btn-modal-save').disabled = false;
                    }
                } else {
                    document.getElementById('btn-modal-discard').click();
                }
            };
        }
        
        // Modeless AJAX Savelogic
        document.addEventListener('submit', async function(e) {
            const form = e.target;
            const submitter = e.submitter;
            const actionInput = (submitter && submitter.name === 'save_action') ? submitter : form.querySelector('input[name="save_action"]');
            const action = actionInput ? actionInput.value : '';
            
            const ajaxActions = ['save_page', 'save_comp', 'save_settings', 'save_memo', 'ssg_save_settings', 'generate_mcp_key'];
            
            if (ajaxActions.includes(action)) {
                e.preventDefault();
                const originalText = submitter.innerHTML;
                submitter.innerHTML = '<span class="material-symbols-outlined icon" style="animation: spin 1s linear infinite;">sync</span> ' + <?= json_encode(t('msg_saving')) ?>;
                submitter.disabled = true;
                
                try {
                    const formData = new FormData(form);
                    if (submitter && submitter.name) formData.append(submitter.name, submitter.value);
                    if (!formData.has('save_action') && actionInput) formData.append('save_action', actionInput.value);
                    formData.append('ajax_request', '1');
                    
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    if (res.ok) {
                        const json = await res.json().catch(()=>({}));
                        window.isDirty = false;
                        showToast(json.message || '<?= t('msg_update_success') ?? '保存しました' ?>');
                        if (action === 'generate_mcp_key' && json.mcp_api_key) {
                            const keyDisplay = document.getElementById('mcp-key-display');
                            if (keyDisplay) keyDisplay.value = json.mcp_api_key;
                        }

                        // Language change requires full reload to apply server-side translations
                        if (action === 'save_settings' && formData.has('system_lang')) {
                            setTimeout(() => { window.location.reload(); }, 600);
                            return;
                        }

                        // update old_id for new records seamlessly without reload
                        const idInput = form.querySelector('input[name="id"]');
                        const oldIdInput = form.querySelector('input[name="old_id"]');
                        if (oldIdInput && idInput && !oldIdInput.value) {
                            oldIdInput.value = idInput.value;
                        }

                        // Refresh page/comp list in background after save
                        if (action === 'save_page') {
                            refreshPageList();
                            // Inject or update preview button after first save of a new page
                            if (json.preview_url) {
                                const editor = document.getElementById('page-editor');
                                if (editor) {
                                    let previewBtn = editor.querySelector('.preview-btn');
                                    if (!previewBtn) {
                                        const saveBtn = editor.querySelector('button[value="save_page"]');
                                        if (saveBtn) {
                                            previewBtn = document.createElement('a');
                                            previewBtn.target = '_blank';
                                            previewBtn.className = 'btn btn-blue preview-btn';
                                            previewBtn.innerHTML = '<span class="material-symbols-outlined icon">visibility</span> ' + <?= json_encode(t('btn_preview')) ?>;
                                            saveBtn.insertAdjacentElement('afterend', previewBtn);
                                        }
                                    }
                                    if (previewBtn) previewBtn.href = json.preview_url;
                                }
                            }
                        } else if (action === 'save_comp') {
                            refreshCompList();
                        }
                    } else {
                        showToast('<?= t('err_save_failed') ?>', true);
                    }
                } catch(err) {
                    showToast('<?= t('err_network_error') ?>', true);
                } finally {
                    submitter.innerHTML = originalText;
                    submitter.disabled = false;
                }
            }
        });
    };
    
    function showToast(msg, isErr=false) {
        let t = document.getElementById('ajax-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'ajax-toast';
            t.style.cssText = 'position:fixed; bottom:30px; right:30px; background:rgba(0,0,0,0.8); color:white; padding:12px 24px; border-radius:8px; z-index:10000; transition:opacity 0.3s; opacity:0; pointer-events:none;';
            document.body.appendChild(t);
        }
        t.style.background = isErr ? 'rgba(220,53,69,0.9)' : 'rgba(0,0,0,0.8)';
        t.textContent = msg;
        t.style.opacity = '1';
        setTimeout(() => t.style.opacity = '0', 3000);
    }

    window.showConfirmDialog = function(message, title = '', okText = '', okClass = 'btn-red') {
        return new Promise((resolve) => {
            const dialog = document.getElementById('global-confirm-dialog');
            const titleEl = document.getElementById('global-confirm-title');
            const msgEl = document.getElementById('global-confirm-message');
            const btnCancel = document.getElementById('btn-global-confirm-cancel');
            const btnOk = document.getElementById('btn-global-confirm-ok');
            
            if (!dialog || !msgEl || !btnCancel || !btnOk) {
                resolve(confirm(message));
                return;
            }
            
            if (titleEl) titleEl.textContent = title || '<?= t('btn_delete') ?>';
            msgEl.textContent = message;
            btnOk.textContent = okText || '<?= t('btn_delete_confirm') ?>';
            
            btnOk.className = 'btn btn-small ' + okClass;
            
            dialog.showModal();
            
            const onCancel = () => { dialog.close(); resolve(false); };
            const onOk = () => { dialog.close(); resolve(true); };
            
            btnCancel.addEventListener('click', onCancel, { once: true });
            btnOk.addEventListener('click', onOk, { once: true });
            
            dialog.addEventListener('close', () => {
                btnCancel.removeEventListener('click', onCancel);
                btnOk.removeEventListener('click', onOk);
                resolve(false);
            }, { once: true });
        });
    };

    // Global submit event listener to intercept native delete operations
    document.addEventListener('submit', async function(e) {
        const form = e.target;
        
        // Skip AJAX forms (like media forms) that are handled elsewhere
        const actionInput = form.querySelector('input[name="save_action"]');
        const action = actionInput ? actionInput.value : '';
        if (isMediaFormAction(action)) {
            return;
        }

        if (form.dataset.confirmed === '1') {
            return;
        }
        
        const submitter = e.submitter;
        const isDeleteAction = 
            (submitter && submitter.name === 'save_action' && (submitter.value === 'delete_page' || submitter.value === 'delete_comp')) ||
            (action === 'delete_user');
            
        if (isDeleteAction) {
            e.preventDefault();
            e.stopPropagation();
            
            let msg = '<?= t('hint_confirm_delete') ?>';
            if (action === 'delete_user') {
                const dispName = form.dataset.displayName || '';
                msg = `本当にこのユーザー「${dispName}」を削除しますか？`;
            }
            
            const confirmed = await window.showConfirmDialog(msg, '<?= t('btn_delete') ?>', '<?= t('btn_delete_confirm') ?>', 'btn-red');
            if (confirmed) {
                form.dataset.confirmed = '1';
                if (submitter && submitter.name && submitter.value) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = submitter.name;
                    hidden.value = submitter.value;
                    form.appendChild(hidden);
                }
                form.submit();
            }
        }
    });
    
    if (!document.getElementById('spin-keyframes')) {
        const style = document.createElement('style');
        style.id = 'spin-keyframes';
        style.textContent = `@keyframes spin { 100% { transform: rotate(360deg); } }`;
        document.head.appendChild(style);
    }

    // Category Cloud control handlers
    document.addEventListener('click', async function(e) {
        const addBtn = e.target.closest('.btn-add-cat');
        if (addBtn) {
            e.preventDefault();
            addBtn.style.display = 'none';
            const input = document.getElementById('new-category-input');
            if (input) {
                input.style.display = 'inline-block';
                input.focus();
            }
            return;
        }

        const toggleDelBtn = e.target.closest('.btn-toggle-del-cat');
        if (toggleDelBtn) {
            e.preventDefault();
            const wrap = document.querySelector('.category-cloud-wrap');
            if (wrap) {
                const isActive = wrap.classList.toggle('delete-mode');
                if (isActive) {
                    toggleDelBtn.innerHTML = '<?= getIcon("check") ?> <?= t('btn_done') ?>';
                    toggleDelBtn.style.color = '#15803d';
                    toggleDelBtn.style.borderColor = '#bbf7d0';
                } else {
                    toggleDelBtn.innerHTML = '<?= getIcon("delete") ?> <?= t('btn_delete') ?>';
                    toggleDelBtn.style.color = '';
                    toggleDelBtn.style.borderColor = '';
                }
            }
            return;
        }

        const deleteBadge = e.target.closest('.category-delete-badge');
        if (deleteBadge) {
            e.preventDefault();
            const val = deleteBadge.getAttribute('data-category');
            if (!val) return;

            const sendDeleteRequest = async (confirmed = false) => {
                const formData = new FormData();
                formData.append('save_action', 'delete_category');
                formData.append('category', val);
                formData.append('ajax_request', '1');
                if (confirmed) {
                    formData.append('confirmed', '1');
                }
                const csrfInput = document.querySelector('input[name="csrf_token"]');
                if (csrfInput) formData.append('csrf_token', csrfInput.value);

                try {
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    const json = await res.json().catch(() => ({}));

                    if (json.success) {
                        const wrap = deleteBadge.closest('.category-cloud-tag-wrap');
                        if (wrap) wrap.remove();

                        const url = new URL(window.location.href);
                        if (url.searchParams.get('cat') === val) {
                            url.searchParams.delete('cat');
                            url.searchParams.delete('p_pages');
                            url.searchParams.delete('p_media');
                            url.searchParams.delete('media_all');
                            history.pushState(null, '', url.pathname + url.search + url.hash);

                            document.querySelectorAll('.category-cloud-tag').forEach(el => el.classList.remove('active'));
                            const allTag = document.querySelector('a.category-cloud-tag[href="?cat="]');
                            if (allTag) allTag.classList.add('active');
                        }

                        await Promise.all([refreshPageList(), refreshMediaGrid()]);
                        showToast(json.message);
                    } else if (json.require_confirm) {
                        const dialog = document.getElementById('cat-delete-confirm-dialog');
                        const msgEl = document.getElementById('cat-delete-dialog-message');
                        const btnCancel = document.getElementById('btn-cat-delete-cancel');
                        const btnOk = document.getElementById('btn-cat-delete-ok');
                        
                        if (dialog && msgEl && btnCancel && btnOk) {
                            msgEl.textContent = json.message;
                            dialog.showModal();
                            
                            const confirmed = await new Promise((resolve) => {
                                const onCancel = () => { dialog.close(); resolve(false); };
                                const onOk = () => { dialog.close(); resolve(true); };
                                
                                btnCancel.addEventListener('click', onCancel, { once: true });
                                btnOk.addEventListener('click', onOk, { once: true });
                                
                                dialog.addEventListener('close', () => {
                                    btnCancel.removeEventListener('click', onCancel);
                                    btnOk.removeEventListener('click', onOk);
                                    resolve(false);
                                }, { once: true });
                            });
                            
                            if (confirmed) {
                                await sendDeleteRequest(true);
                            }
                        }
                    } else {
                        showToast(json.message, true);
                    }
                } catch (err) {
                    showToast('<?= t('err_category_delete_failed') ?>', true);
                }
            };

            await sendDeleteRequest(false);
        }
    });

    const newCatInput = document.getElementById('new-category-input');
    if (newCatInput) {
        newCatInput.addEventListener('keydown', async function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const val = newCatInput.value.trim();
                if (val) {
                    const formData = new FormData();
                    formData.append('save_action', 'add_category');
                    formData.append('category', val);
                    formData.append('ajax_request', '1');
                    const csrfInput = document.querySelector('input[name="csrf_token"]');
                    if (csrfInput) formData.append('csrf_token', csrfInput.value);

                    try {
                        const res = await fetch(window.location.href, { method: 'POST', body: formData });
                        const json = await res.json().catch(() => ({}));
                        
                        if (json.success) {
                            let existingTag = null;
                            document.querySelectorAll('.category-cloud-tag').forEach(tag => {
                                if (tag.textContent.trim() === val) existingTag = tag;
                            });

                            if (!existingTag) {
                                const wrap = document.createElement('span');
                                wrap.className = 'category-cloud-tag-wrap';

                                const newTag = document.createElement('a');
                                newTag.href = `?cat=${encodeURIComponent(val)}`;
                                newTag.className = 'category-cloud-tag';
                                newTag.textContent = val;

                                const badge = document.createElement('span');
                                badge.className = 'category-delete-badge';
                                badge.setAttribute('data-category', val);
                                badge.title = '<?= t('category_delete_confirm_title') ?>';
                                badge.innerHTML = '&times;';

                                wrap.appendChild(newTag);
                                wrap.appendChild(badge);

                                const controlGroup = document.querySelector('.category-control-group');
                                if (controlGroup) {
                                    controlGroup.parentNode.insertBefore(wrap, controlGroup);
                                } else {
                                    newCatInput.parentNode.insertBefore(wrap, newCatInput);
                                }
                            }
                            showToast(json.message);
                        } else {
                            showToast(json.message, true);
                        }
                    } catch(err) {
                        showToast('<?= t('err_category_add_failed') ?>', true);
                    } finally {
                        newCatInput.value = '';
                        newCatInput.style.display = 'none';
                        const btn = document.querySelector('.btn-add-cat');
                        if (btn) btn.style.display = 'inline-flex';
                    }
                }
            }
        });
    }

    // Category Cloud click handler
    document.addEventListener('click', async function(e) {
        const tag = e.target.closest('.category-cloud-tag');
        if (!tag) return;
        if (tag.classList.contains('btn-add-cat') || tag.classList.contains('btn-toggle-del-cat')) return;

        e.preventDefault();
        const url = new URL(tag.href, window.location.href);
        document.querySelectorAll('.category-cloud-tag').forEach(el => el.classList.remove('active'));
        tag.classList.add('active');

        url.searchParams.delete('p_pages');
        url.searchParams.delete('p_media');
        url.searchParams.delete('media_all');
        history.pushState(null, '', url.pathname + url.search + url.hash);
        await Promise.all([refreshPageList(), refreshMediaGrid()]);
    });

    // Media filter toggle click handler
    document.addEventListener('click', async function(e) {
        const btn = e.target.closest('.media-filter-toggle-btn');
        if (!btn) return;

        e.preventDefault();
        const url = new URL(btn.href, window.location.href);
        history.pushState(null, '', url.pathname + url.search + url.hash);
        await refreshMediaGrid();
    });

    // Modeless AJAX Pagination click handler
    document.addEventListener('click', async function(e) {
        const link = e.target.closest('.pagination-link');
        if (!link) return;

        e.preventDefault();
        const url = new URL(link.href, window.location.href);
        const pPages = url.searchParams.get('p_pages');
        const pMedia = url.searchParams.get('p_media');

        history.pushState(null, '', url.pathname + url.search + url.hash);

        if (pPages) {
            await refreshPageList();
        } else if (pMedia) {
            await refreshMediaGrid();
        }

        if (url.hash) {
            const target = document.querySelector(url.hash);
            if (target) target.scrollIntoView({ behavior: 'smooth' });
        }
    });
    </script>
</div>

<footer>
    &copy; 2026 🍊mikanBox flat v<?= MIKANBOX_VERSION ?> by <a href="http://yoshihiko.com" target="_blank">yoshihiko.com</a>
</footer>

<style>
.unsaved-modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 10000;
    display: flex; align-items: center; justify-content: center;
    animation: fadeIn 0.2s ease forwards;
}
.unsaved-modal-content {
    background: #fff; padding: 30px; border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2); max-width: 500px; text-align: center;
    font-family: system-ui, sans-serif;
}
.unsaved-modal-actions {
    display: flex; gap: 10px; justify-content: center; margin-top: 20px;
}
.unsaved-modal-actions .btn {
    white-space: nowrap;
}
.unsaved-modal-content h3 { margin-top: 0; font-size: 1.2rem; color: #333; }
.unsaved-modal-content p { color: #666; font-size: 0.95rem; margin-bottom: 20px; line-height: 1.5; }
@keyframes fadeIn { 0% { opacity: 0; } 100% { opacity: 1; } }
</style>

<!-- Native Dialog for category delete confirmation -->
<dialog id="cat-delete-confirm-dialog" class="custom-dialog">
    <h3 style="margin-top:0; margin-bottom:12px; font-size:1.05rem; display:flex; align-items:center; gap:8px; color:var(--text);">
        <span class="material-symbols-outlined" style="color: #ef4444;">warning</span> <?= t('category_delete_confirm_title') ?>
    </h3>
    <p id="cat-delete-dialog-message" style="font-size:0.9rem; line-height:1.5; margin-bottom:20px; color:#475569;"></p>
    <div class="dialog-buttons" style="display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap;">
        <button id="btn-cat-delete-cancel" class="btn btn-small" style="background:#f1f5f9; color:#1e293b; border: 1px solid #cbd5e1;"><?= t('btn_cancel') ?></button>
        <button id="btn-cat-delete-ok" class="btn btn-small btn-red"><?= t('btn_delete_confirm') ?></button>
    </div>
</dialog>

<!-- Native Dialog for media rename confirmation -->
<dialog id="rename-confirm-dialog" class="custom-dialog">
    <h3 style="margin-top:0; margin-bottom:12px; font-size:1.05rem; display:flex; align-items:center; gap:8px; color:var(--text);">
        <span class="material-symbols-outlined" style="color: #f59e0b;">warning</span> <?= t('media_rename_confirm_title') ?>
    </h3>
    <p id="rename-dialog-message" style="font-size:0.9rem; line-height:1.5; margin-bottom:20px; color:#475569;"></p>
    <div class="dialog-buttons" style="display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap;">
        <button id="btn-rename-cancel" class="btn btn-small" style="background:#f1f5f9; color:#1e293b; border: 1px solid #cbd5e1;"><?= t('btn_rename_cancel') ?></button>
        <button id="btn-rename-only" class="btn btn-small" style="background:#f1f5f9; color:#1e293b; border: 1px solid #cbd5e1;"><?= t('btn_rename_only') ?></button>
        <button id="btn-rename-update" class="btn btn-small btn-blue"><?= t('btn_rename_update') ?></button>
    </div>
</dialog>

<!-- Native Dialog for global confirmation -->
<dialog id="global-confirm-dialog" class="custom-dialog">
    <h3 style="margin-top:0; margin-bottom:12px; font-size:1.05rem; display:flex; align-items:center; gap:8px; color:var(--text);">
        <span class="material-symbols-outlined" style="color: #ef4444;">warning</span> <span id="global-confirm-title"><?= t('btn_delete') ?></span>
    </h3>
    <p id="global-confirm-message" style="font-size:0.9rem; line-height:1.5; margin-bottom:20px; color:#475569;"></p>
    <div class="dialog-buttons" style="display:flex; justify-content:flex-end; gap:8px; flex-wrap:wrap;">
        <button id="btn-global-confirm-cancel" class="btn btn-small" style="background:#f1f5f9; color:#1e293b; border: 1px solid #cbd5e1;"><?= t('btn_cancel') ?></button>
        <button id="btn-global-confirm-ok" class="btn btn-small btn-red"><?= t('btn_delete_confirm') ?></button>
    </div>
</dialog>


<div id="unsaved-modal" class="unsaved-modal-overlay" style="display: none;">
    <div class="unsaved-modal-content">
        <h3><?= t('modal_unsaved_title') ?></h3>
        <p><?= t('modal_unsaved_text') ?></p>
        <div class="unsaved-modal-actions">
            <button id="btn-modal-save" class="btn btn-blue"><?= getIcon('save') ?> <?= t('btn_save_and_close') ?></button>
            <button id="btn-modal-discard" class="btn btn-red"><?= getIcon('delete') ?> <?= t('btn_discard_and_close') ?></button>
            <button id="btn-modal-cancel" class="btn btn-gray"><?= t('btn_cancel') ?></button>
        </div>
    </div>
</div>

</body>
</html>
