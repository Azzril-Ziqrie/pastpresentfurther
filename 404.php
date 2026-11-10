<?php
header("HTTP/1.0 404 Not Found");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | PASTPRESENTFURTHER</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{background:#fff;color:#111;font-family:Helvetica,Arial,sans-serif;text-transform:uppercase;letter-spacing:.04em;display:flex;height:100vh;align-items:center;justify-content:center;text-align:center}
        a{color:inherit;text-decoration:none}
        .content{max-width:80%}
        h1{font-size:26px;font-weight:300;letter-spacing:.06em;margin-bottom:10px}
        p{font-size:11px;letter-spacing:.08em;margin-bottom:20px;color:#444}
        .btn{display:inline-block;font-size:10px;letter-spacing:.14em;color:#111;border-bottom:1px solid #111;padding-bottom:2px;transition:opacity .2s}
        .btn:hover{opacity:.4}
    </style>
</head>
<body>
    <div class="content">
        <h1>404</h1>
        <p>Page not found</p>
        <a href="/pastpresent/" class="btn">Return Home</a>
    </div>
</body>
</html>