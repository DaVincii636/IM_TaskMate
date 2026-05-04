<?php
require_once 'TM_PHP/TM_Session.php';
if (tm_is_logged_in()) { header('Location: TM_Dashboard.php'); exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="TM_CSS/TM_Landing.css"/>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;">
<div class="landing-page">
    <div class="landing-inner">
        <div class="landing-label">Welcome to</div>
        <div class="landing-brand-wrap">
            <span class="landing-brand" id="landingTyping"></span><span class="landing-cursor">|</span>
        </div>
        <a href="TM_Login.php" class="btn-plan">Plan Your Day</a>
    </div>
</div>
<script>
    const fullText='TaskMate',splitAt=4;let i=0,deleting=false;
    const el=document.getElementById('landingTyping');
    function renderText(n){const t=fullText.slice(0,Math.min(n,splitAt)),m=n>splitAt?fullText.slice(splitAt,n):'';
    el.innerHTML=(t?'<span style="color:#fff">'+t+'</span>':'')+(m?'<span style="color:#9a9a9a">'+m+'</span>':'');}
    function type(){if(!deleting){renderText(i+1);i++;if(i===fullText.length){deleting=true;setTimeout(type,2200);return;}}
    else{renderText(i-1);i--;if(i===0){deleting=false;setTimeout(type,500);return;}}setTimeout(type,deleting?50:100);}
    setTimeout(type,400);
</script>
</body>
</html>
