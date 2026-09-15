<?php

/**
 * MOS-GOV standalone error page (used for CSRF rejections and 404s).
 *
 * Expected variables:
 * - $message safe, pre-validated error text
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MOS-GOV &mdash; Request rejected</title>
</head>
<body>
    <h1>Request rejected</h1>
    <p><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></p>
    <p><a href="javascript:history.back()">Go back</a></p>
</body>
</html>
