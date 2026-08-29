<?php
/**
 * FreeImg 安装向导（4 步入口）
 * 拆分后的精简入口，业务逻辑在 Installer.php
 */

session_start();

$rootPath    = dirname(__DIR__);
$lockFile    = $rootPath . '/install/install.lock';
$configFile  = $rootPath . '/config/config.php';
$installer   = __DIR__ . '/Installer.php';

if (!file_exists($installer)) {
    die('Installer.php 丢失');
}
require $installer;

// 二次安装保护
if (file_exists($lockFile)) {
    // 安装完成后，step 4 是 OK 的（显示完成页），其他步骤直接拒绝
    $currentStep = (int)($_GET['step'] ?? 1);
    if ($currentStep !== 4) {
        die('<h1>已安装</h1><p>FreeImg 已经安装完成。如需重新安装，请先删除 install/install.lock 文件。</p>');
    }
}

$step   = (int)($_GET['step'] ?? 1);
$errors = [];
$viewData = [];

// Step 2: 接收 DB 配置 → session
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['install_db'] = [
        'host'     => trim($_POST['db_host'] ?? '127.0.0.1'),
        'port'     => (int)($_POST['db_port'] ?? 3306),
        'dbname'   => trim($_POST['db_name'] ?? ''),
        'username' => trim($_POST['db_user'] ?? ''),
        'password' => $_POST['db_pass'] ?? '',
    ];
    header('Location: ?step=3');
    exit;
}

// Step 3: 接收管理员 → 安装
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser  = trim($_POST['admin_username'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_password'] ?? '';

    if (empty($_SESSION['install_db'])) {
        $errors[] = '数据库配置丢失，请回到 Step 2 重新填写';
    }
    if (strlen($adminUser) < 3) {
        $errors[] = '管理员用户名至少 3 个字符';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '邮箱格式不正确';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = '管理员密码至少 8 位';
    }

    if (!$errors) {
        try {
            $inst = new Installer($_SESSION['install_db']);
            $inst->ensureDatabase($_SESSION['install_db']['dbname']);
            $pdo = $inst->getPdo();
            // ⚠️ ensureDatabase 里的 CREATE DATABASE / USE 是 DDL，
            //    MySQL 会隐式 commit，所以事务要在 USE 之后开始
            $pdo->beginTransaction();
            try {
                $inst->runSqlFile(__DIR__ . '/install.sql');
                // ⚠️ runSqlFile 跑 CREATE TABLE（DDL）也会隐式 commit，
                //    需要重新开启事务才能包住后面的 DML（createAdmin 等）
                $pdo->beginTransaction();
                // 重复装检测：users 表已有 admin → 拒绝
                $existing = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
                if ($existing > 0) {
                    throw new RuntimeException('数据库中已存在管理员账号，安装中止。如需重新安装请先清空 users 表或使用新库');
                }
                $adminId = $inst->createAdmin($adminUser, $adminEmail, $adminPass);
                $inst->createDefaultStorage($adminId, $_SERVER['HTTP_HOST']);
                $inst->seedSettings();
                $inst->seedCompressionProfiles();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            // 事务外做文件系统操作（不参与事务回滚）
            $inst->ensureDirectories($rootPath);
            $inst->writeConfig($configFile, $_SESSION['install_db'], 'https://' . $_SERVER['HTTP_HOST']);
            $inst->createLock($lockFile, $adminId);
            unset($_SESSION['install_db']);

            header('Location: ?step=4');
            exit;
        } catch (Exception $e) {
            $errors[] = '安装失败：' . $e->getMessage();
        }
    }
}

// Step 1: 环境检测数据
if ($step === 1) {
    $viewData['checks'] = Installer::checkEnvironment($rootPath, $lockFile);
    $viewData['allPass'] = !in_array(false, array_column($viewData['checks'], 1), true);
}

$pageTitle = 'FreeImg 安装向导';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="assets/install.css">
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🖼️ FreeImg 自由图床</h1>
        <p>轻量级个人图床系统 · 安装向导</p>
    </div>

    <div class="steps">
        <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'done' : '' ?>">
            <div class="step-num">1</div><div>环境检测</div>
        </div>
        <div class="step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'done' : '' ?>">
            <div class="step-num">2</div><div>数据库配置</div>
        </div>
        <div class="step <?= $step >= 3 ? 'active' : '' ?> <?= $step > 3 ? 'done' : '' ?>">
            <div class="step-num">3</div><div>创建管理员</div>
        </div>
        <div class="step <?= $step >= 4 ? 'active' : '' ?>">
            <div class="step-num">4</div><div>完成</div>
        </div>
    </div>

    <div class="content">
        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div>❌ <?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
        $stepFile = __DIR__ . "/steps/step{$step}.php";
        if (file_exists($stepFile)) {
            extract($viewData, EXTR_SKIP);
            require $stepFile;
        } else {
            echo '<div class="alert alert-error">未知步骤</div>';
        }
        ?>
    </div>
</div>
</body>
</html>