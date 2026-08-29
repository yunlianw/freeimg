<?php
/** @var string $content */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(($title ?? '') . ' - FreeImg') ?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="layout-blank">
<?= $content ?>
</body>
</html>