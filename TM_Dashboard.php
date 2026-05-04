<?php
require_once 'TM_PHP/TM_Session.php';
tm_require_login();
$flash     = tm_get_flash();
$firstName = tm_uname();
$fullName  = $firstName . ' ' . ($_SESSION['tm_last_name'] ?? '');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Welcome - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
html,body{margin:0;padding:0;background:#0a0a0a!important;height:100%;}

/* ── MODAL ───────────────────────────────────── */
.pc-modal-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,0.45);z-index:1000;
    align-items:center;justify-content:center;
}
.pc-modal-overlay.active{display:flex;}
.pc-modal-box{
    background:var(--white,#fff);border-radius:var(--radius-lg,16px);
    padding:2rem;max-width:420px;width:90%;
    box-shadow:0 20px 60px rgba(0,0,0,0.2);text-align:center;
}
.pc-modal-icon{
    width:58px;height:58px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 1rem;font-size:1.5rem;
}
.pc-modal-title{
    font-size:1.1rem;font-weight:700;
    color:var(--text,#1a1a1a);margin-bottom:.5rem;
}
.pc-modal-body{
    font-size:13px;color:var(--text-mid,#666);
    margin-bottom:1.5rem;line-height:1.6;
}
.pc-modal-btns{display:flex;gap:10px;justify-content:center;}
.pc-modal-cancel{
    padding:9px 22px;border-radius:50px;
    font-size:13px;font-weight:600;
    border:1.5px solid var(--border,#ddd);
    background:var(--white,#fff);color:var(--text-mid,#666);
    cursor:pointer;font-family:'Poppins',sans-serif;
    transition:all .2s;
}
.pc-modal-cancel:hover{background:var(--border,#eee);}
.pc-modal-confirm-red{
    padding:9px 22px;border-radius:50px;
    font-size:13px;font-weight:700;
    background:linear-gradient(135deg,#e74c3c,#c0392b);
    color:#fff;border:none;
    cursor:pointer;font-family:'Poppins',sans-serif;
    transition:all .2s;
    display:inline-flex;align-items:center;gap:6px;
}
.pc-modal-confirm-red:hover{opacity:.9;transform:translateY(-1px);}
    </style>
</head>
<body>
<nav class="navbar">
    <div class="navbar-logo">Task<span>Mate</span></div>
    <div class="navbar-right">
        <a href="TM_Calendar.php" class="btn-logout">Calendar</a>
        <!-- Secret Admin: Alt+Shift+C -->
        <script>document.addEventListener("keydown",function(e){if(e.altKey&&e.shiftKey&&e.key==="C"){window.location.href='TM_UserList.php';}});</script>
        <a href="#" class="btn-logout" id="logoutBtn">Log Out</a>
    </div>
</nav>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="pc-modal-overlay">
    <div class="pc-modal-box">
        <div class="pc-modal-icon" style="background:rgba(231,76,60,.12);">
            <i class="fa-solid fa-arrow-right-from-bracket" style="color:#e74c3c;"></i>
        </div>
        <div class="pc-modal-title">Log Out?</div>
        <div class="pc-modal-body">You'll need to sign in again to access your tasks.</div>
        <div class="pc-modal-btns">
            <button class="pc-modal-cancel" id="logoutCancel">Cancel</button>
            <a href="TM_PHP/TM_AuthActions.php?action=logout" class="pc-modal-confirm-red">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Log Out
            </a>
        </div>
    </div>
</div>

<script>
(function(){
    var btn    = document.getElementById('logoutBtn');
    var modal  = document.getElementById('logoutModal');
    var cancel = document.getElementById('logoutCancel');
    btn.addEventListener('click', function(e){ e.preventDefault(); modal.classList.add('active'); });
    cancel.addEventListener('click', function(){ modal.classList.remove('active'); });
    modal.addEventListener('click', function(e){ if(e.target===modal) modal.classList.remove('active'); });
})();
</script>

<?php if ($flash): ?>
    <div class="<?= $flash['type']==='error'?'validation-summary':'success-banner' ?>" style="display:none">
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
<?php endif; ?>

<div class="welcome-page">
    <div class="welcome-page-inner">
        <div class="welcome-label">Welcome to</div>
        <div class="welcome-brand">TaskMate</div>
        <div class="welcome-typing-wrap">
            <span class="welcome-typing" id="typingText"></span><span class="typing-cursor">|</span>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>
<script>const fullName = <?= json_encode(trim($fullName)) ?>;</script>
<script src="TM_JS/TM_App.js"></script>
</body>
</html>