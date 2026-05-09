<?php
require_once 'TM_PHP/TM_Session.php';
if (tm_is_logged_in()) { header('Location: TM_Dashboard.php'); exit; }
$flash = tm_get_flash();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Register - TaskMate</title>
    <link rel="stylesheet" href="TM_CSS/TM_Style.css"/>
</head>
<body>
<div class="auth-page">
    <div class="auth-left">
        <div class="auth-logo">Task<span>Mate</span></div>
        <div class="auth-headline">Stay on top of everything.</div>
        <p class="auth-subtext">Create your account and start managing tasks with a clean, minimal workspace built for focus.</p>
    </div>
    <div class="auth-right">
        <div class="auth-form-title">Create Account</div>
        <p class="auth-form-sub">Already have an account? <a href="TM_Login.php">Sign in</a></p>

        <?php if ($flash): ?>
            <div class="<?= $flash['type']==='error' ? 'validation-summary' : 'success-banner' ?>" style="display:none">
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="TM_PHP/TM_AuthActions.php">
            <input type="hidden" name="action" value="register"/>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="firstName" class="form-input" placeholder="Juan" required/>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="lastName" class="form-input" placeholder="Dela Cruz" required/>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-input" placeholder="juan@email.com" required/>
            </div>
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="tel" name="phone" class="form-input" placeholder="09XXXXXXXXX"
                    required maxlength="11" pattern="[0-9]{11}" inputmode="numeric"
                    oninput="this.value=this.value.replace(/[^0-9]/g,'')"/>
            </div>
            <div class="form-group">
                <label class="form-label">ORGANIZATION CODE <span style="color:var(--gray-400);font-weight:400;">(optional)</span></label>
                <input type="text" name="org_code" class="form-input" placeholder="Leave blank to use Default Organization" value="<?= htmlspecialchars($_POST['org_code'] ?? '') ?>"/>
                 <p style="font-size:11px;color:var(--gray-400);margin:.35rem 0 0;">
                   Enter your organization's exact name if you were given one.
              </p>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div style="position:relative">
                    <input type="password" name="password" class="form-input" id="reg_password" placeholder="Min. 6 characters" required/>
                    <button type="button" class="toggle-password" data-target="reg_password"
                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;display:flex;align-items:center;color:var(--gray-300);padding:0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div style="position:relative">
                    <input type="password" name="confirmPassword" class="form-input" id="reg_confirm" placeholder="Repeat password" required/>
                    <button type="button" class="toggle-password" data-target="reg_confirm"
                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;display:flex;align-items:center;color:var(--gray-300);padding:0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-primary">Create Account</button>
        </form>
    </div>
</div>
<div class="toast" id="toast"></div>
<script src="TM_JS/TM_App.js"></script>
</body>
</html>
