/**
 * TM_Onboarding.js — First-login walkthrough tooltip overlay
 * Feature 11 — HCI101 Week 2 (Learnability & Memorability)
 *
 * Shows a sequential tooltip overlay pointing to key UI elements.
 * Only fires when the PHP page sets window.TM_SHOW_ONBOARDING = true
 * (controlled by TM_UserPrefs.onboarding_done in the database).
 *
 * When the user completes or dismisses the walkthrough, a fetch() call
 * to TM_OnboardingActions.php?action=mark_done persists the completion
 * so the overlay never appears again.
 */

(function () {
    'use strict';

    // ── Step definitions ───────────────────────────────────────────────────────
    // Each step targets an element by CSS selector and shows a tooltip.
    // If the target element is not found on this page, the step is skipped.
    const STEPS = [
        {
            selector: '[data-onboard="add-task"], .open-modal-btn, [id*="addTask"], [id*="add-task"], #openAddModal',
            fallback: '.task-table-card, .tasks-page',
            title: '➕ Add a Task',
            body:  'Click here to create your first task. Give it a name, dates, priority, and category.',
            position: 'bottom',
        },
        {
            selector: 'a[href*="TM_Calendar"], nav a[href*="Calendar"]',
            fallback: 'nav, .navbar',
            title: '📅 Calendar View',
            body:  'Switch to the Calendar to see your tasks laid out by date — great for planning ahead.',
            position: 'bottom',
        },
        {
            selector: '#notifBell, .notif-bell, [id*="notif-bell"], [data-onboard="notif-bell"]',
            fallback: 'nav, .navbar',
            title: '🔔 Notifications',
            body:  'The bell icon alerts you to overdue tasks and upcoming deadlines.',
            position: 'bottom',
        },
        {
            selector: 'a[href*="TM_Activity"], nav a[href*="Activity"]',
            fallback: 'nav, .navbar',
            title: '📋 Activity Feed',
            body:  'Track every change made to your tasks — edits, completions, and more.',
            position: 'bottom',
        },
        {
            selector: 'a[href*="TM_Analytics"], nav a[href*="Analytics"]',
            fallback: 'nav, .navbar',
            title: '📊 Analytics',
            body:  'View completion rates, missed deadlines, and productivity trends over time.',
            position: 'bottom',
        },
    ];

    // ── State ──────────────────────────────────────────────────────────────────
    let currentStep = 0;
    let overlay, tooltip, spotlight;
    const ANIM_MS  = 260;

    // ── Helpers ────────────────────────────────────────────────────────────────
    function q(sel) {
        try { return document.querySelector(sel); } catch (e) { return null; }
    }

    function resolveTarget(step) {
        let el = q(step.selector);
        if (!el && step.fallback) el = q(step.fallback);
        return el;
    }

    function getRect(el) {
        const r = el.getBoundingClientRect();
        return {
            top:    r.top    + window.scrollY,
            left:   r.left   + window.scrollX,
            width:  r.width,
            height: r.height,
        };
    }

    // ── Mark done in the database ──────────────────────────────────────────────
    function persistDone() {
        const fd = new FormData();
        fd.append('action', 'mark_done');
        fetch('TM_PHP/TM_OnboardingActions.php', { method: 'POST', body: fd })
            .catch(() => { /* silently ignore network errors */ });
    }

    // ── Destroy the overlay ────────────────────────────────────────────────────
    function destroy() {
        if (overlay) {
            overlay.style.opacity = '0';
            setTimeout(() => {
                if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
                overlay = tooltip = spotlight = null;
            }, ANIM_MS);
        }
        persistDone();
    }

    // ── Position tooltip next to the target element ───────────────────────────
    function positionTooltip(targetRect) {
        const TW = tooltip.offsetWidth  || 300;
        const TH = tooltip.offsetHeight || 160;
        const VP_W = window.innerWidth;
        const PAD  = 14; // gap between spotlight and tooltip

        let top, left;
        const spCx = targetRect.left + targetRect.width  / 2;
        const spCy = targetRect.top  + targetRect.height / 2;

        // Prefer: below the element
        top  = targetRect.top + targetRect.height + PAD;
        left = Math.max(12, Math.min(spCx - TW / 2, VP_W - TW - 12));

        // If too low, try above
        if (top + TH > window.scrollY + window.innerHeight - 20) {
            top = targetRect.top - TH - PAD;
        }
        // Clamp top
        top = Math.max(window.scrollY + 12, top);

        tooltip.style.top  = top  + 'px';
        tooltip.style.left = left + 'px';
    }

    // ── Render one step ────────────────────────────────────────────────────────
    function renderStep(index) {
        if (index >= STEPS.length) { destroy(); return; }

        const step   = STEPS[index];
        const target = resolveTarget(step);

        if (!target) { renderStep(index + 1); return; } // skip missing elements

        // Scroll target into view smoothly
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(() => {
            const rect = getRect(target);

            // ── Spotlight (highlight box around the target) ──────────────────
            const pad = 6;
            spotlight.style.top    = (rect.top  - pad) + 'px';
            spotlight.style.left   = (rect.left - pad) + 'px';
            spotlight.style.width  = (rect.width  + pad * 2) + 'px';
            spotlight.style.height = (rect.height + pad * 2) + 'px';

            // ── Tooltip content ──────────────────────────────────────────────
            const isLast = (index === STEPS.length - 1);
            tooltip.innerHTML = `
                <button class="tm-ob-close" aria-label="Close walkthrough">&#x2715;</button>
                <div class="tm-ob-title">${step.title}</div>
                <div class="tm-ob-body">${step.body}</div>
                <div class="tm-ob-footer">
                    <span class="tm-ob-progress">${index + 1} / ${STEPS.length}</span>
                    ${index > 0
                        ? '<button class="tm-ob-btn tm-ob-back">Back</button>'
                        : ''}
                    <button class="tm-ob-btn tm-ob-next">${isLast ? 'Got it! 🎉' : 'Next →'}</button>
                </div>`;

            positionTooltip(rect);
            tooltip.style.opacity = '1';
            tooltip.style.transform = 'translateY(0) scale(1)';

            // Events
            tooltip.querySelector('.tm-ob-close').onclick = destroy;
            tooltip.querySelector('.tm-ob-next').onclick  = () => {
                tooltip.style.opacity = '0';
                tooltip.style.transform = 'translateY(6px) scale(.97)';
                setTimeout(() => renderStep(index + 1), ANIM_MS);
            };
            const backBtn = tooltip.querySelector('.tm-ob-back');
            if (backBtn) backBtn.onclick = () => {
                tooltip.style.opacity = '0';
                setTimeout(() => renderStep(index - 1), ANIM_MS);
            };
        }, 350); // wait for smooth scroll to settle
    }

    // ── Build DOM ──────────────────────────────────────────────────────────────
    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.id = 'tm-onboarding-overlay';
        overlay.style.cssText = `
            position:absolute; top:0; left:0; width:100%; height:100%;
            pointer-events:none; z-index:9998;
            transition:opacity ${ANIM_MS}ms ease;`;

        // Semi-transparent backdrop (CSS box-shadow punch-through technique)
        spotlight = document.createElement('div');
        spotlight.id = 'tm-ob-spotlight';
        spotlight.style.cssText = `
            position:absolute; border-radius:10px;
            box-shadow: 0 0 0 9999px rgba(0,0,0,0.52);
            transition: top .3s,left .3s,width .3s,height .3s;
            pointer-events:none; z-index:9999;`;

        tooltip = document.createElement('div');
        tooltip.id = 'tm-ob-tooltip';
        tooltip.style.cssText = `
            position:absolute; width:300px; background:#fff; border-radius:14px;
            padding:18px 18px 14px; box-shadow:0 8px 40px rgba(0,0,0,0.22);
            font-family:'Poppins',system-ui,sans-serif; font-size:13px;
            z-index:10000; pointer-events:all;
            opacity:0; transform:translateY(10px) scale(.96);
            transition:opacity ${ANIM_MS}ms ease, transform ${ANIM_MS}ms ease;`;

        // Inject CSS for inner elements
        const style = document.createElement('style');
        style.textContent = `
            #tm-ob-tooltip .tm-ob-title{font-weight:700;font-size:15px;margin-bottom:6px;color:#111;}
            #tm-ob-tooltip .tm-ob-body{color:#555;line-height:1.55;margin-bottom:14px;}
            #tm-ob-tooltip .tm-ob-footer{display:flex;align-items:center;gap:8px;}
            #tm-ob-tooltip .tm-ob-progress{font-size:11px;color:#999;margin-right:auto;}
            #tm-ob-tooltip .tm-ob-btn{
                padding:7px 16px;border-radius:50px;font-size:12px;font-weight:600;
                cursor:pointer;border:none;font-family:inherit;transition:all .15s;}
            #tm-ob-tooltip .tm-ob-next{background:#111;color:#fff;}
            #tm-ob-tooltip .tm-ob-next:hover{opacity:.85;}
            #tm-ob-tooltip .tm-ob-back{background:#f3f4f6;color:#555;}
            #tm-ob-tooltip .tm-ob-back:hover{background:#e5e7eb;}
            #tm-ob-tooltip .tm-ob-close{
                position:absolute;top:10px;right:12px;background:none;border:none;
                font-size:14px;color:#aaa;cursor:pointer;line-height:1;padding:2px 5px;}
            #tm-ob-tooltip .tm-ob-close:hover{color:#111;}`;

        document.head.appendChild(style);
        overlay.appendChild(spotlight);
        overlay.appendChild(tooltip);
        // Append to body with absolute positioning anchored to document
        document.body.style.position = 'relative';
        document.body.appendChild(overlay);
    }

    // ── Entry point ────────────────────────────────────────────────────────────
    function init() {
        // Guard: only run when the PHP page sets this flag
        if (!window.TM_SHOW_ONBOARDING) return;

        // Wait for the page to fully render before measuring positions
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }

        buildOverlay();
        // Small delay so animations start after initial paint
        setTimeout(() => renderStep(0), 500);
    }

    init();
})();
