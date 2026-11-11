<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="app-base" content="<?= e(base_path()) ?>">
<meta name="theme-color" content="#0b5cff">
<title><?= e($title ?? 'Masuk') ?> · Cashflow HME</title>
<link rel="icon" type="image/png" href="<?= e(asset('images/logo-hme.png')) ?>">
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>" crossorigin="use-credentials">
<link rel="apple-touch-icon" href="<?= e(asset('images/apple-touch-icon.png')) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Cashflow HME">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">
<?= \App\Core\View::partial('partials/icons') ?>
<?= $content ?>
<?= \App\Core\View::partial('partials/watermark') ?>
<div id="toasts" aria-live="polite"></div>
<script id="flash-data" type="application/json"><?= json_encode($flash ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/pwa.js')) ?>"></script>
<script src="<?= e(asset('js/login.js')) ?>"></script>
</body>
</html>
