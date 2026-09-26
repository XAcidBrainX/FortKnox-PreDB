<?php
// admin/irc.php – muss hinter deiner Auth‑Schicht liegen
//require_once __DIR__.'/auth.php';   // deine eigene Session‑Prüfung

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>IRC‑Monitor – FortKnox Bot</title>
    <style>
        body,html{margin:0;height:100%;overflow:hidden;background:#111;}
        iframe{border:none;width:100%;height:100%;}
    </style>
</head>
<body>
    <!-- TheLounge UI wird in einem Full‑Size‑Iframe geladen -->
    <iframe src="https://fortknox.cloud:9000/"></iframe>
</body>
</html>
