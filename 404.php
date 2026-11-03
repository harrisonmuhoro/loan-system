<?php http_response_code(404); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 – Page Not Found | Nyeri County Loan Management System</title>
    <meta name="description" content="The page you are looking for could not be found in the Nyeri County Loan Management System.">
    <script src="/loan-system/js/lucide.min.js"></script>
    <style>
        :root {
            --green-dark:     #2e7d32;
            --green-deeper:   #1b5e20;
            --blue:           #3a6b9a;
            --bg-primary:     #f5f9f7;
            --bg-secondary:   #fff;
            --text-primary:   #333;
            --text-secondary: #6c757d;
            --border-color:   #e6e6e6;
            --shadow:         0 2px 10px rgba(0,0,0,.10);
            --shadow-hover:   0 8px 30px rgba(0,0,0,.16);
        }
        [data-theme="dark"] {
            --bg-primary:     #1a1a1a;
            --bg-secondary:   #2d2d2d;
            --text-primary:   #f0f0f0;
            --text-secondary: #a0a0a0;
            --border-color:   #444;
            --shadow:         0 2px 10px rgba(0,0,0,.35);
            --shadow-hover:   0 8px 30px rgba(0,0,0,.55);
            --green-dark:     #4caf50;
            --green-deeper:   #388e3c;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            transition: background-color .3s, color .3s;
            overflow: hidden; position: relative;
        }
        body::before, body::after {
            content: ''; position: fixed; border-radius: 50%;
            pointer-events: none; z-index: 0;
        }
        body::before {
            width: 520px; height: 520px;
            background: radial-gradient(circle, rgba(46,125,50,.12) 0%, transparent 70%);
            top: -130px; left: -130px;
            animation: blobDrift 12s ease-in-out infinite alternate;
        }
        body::after {
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(58,107,154,.10) 0%, transparent 70%);
            bottom: -90px; right: -90px;
            animation: blobDrift 16s ease-in-out infinite alternate-reverse;
        }
        @keyframes blobDrift {
            from { transform: translate(0,0) scale(1); }
            to   { transform: translate(28px,18px) scale(1.07); }
        }
        .theme-toggle {
            position: fixed; top: 18px; right: 18px;
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 50%; width: 42px; height: 42px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: var(--shadow); transition: all .3s;
            z-index: 100; color: var(--text-primary);
        }
        .theme-toggle:hover { box-shadow: var(--shadow-hover); transform: scale(1.08); }
        .card {
            position: relative; z-index: 10;
            background: var(--bg-secondary);
            border-radius: 20px; box-shadow: var(--shadow-hover);
            padding: 52px 48px 44px; max-width: 520px; width: 90%;
            text-align: center; border: 1px solid var(--border-color);
            animation: cardIn .6s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(40px) scale(.95); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .emblem-wrap {
            width: 80px; height: 80px; margin: 0 auto 20px;
            background: #f5f5f5; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; border: 2px solid #e0e0e0; transition: all .3s;
        }
        .emblem-wrap:hover { transform: scale(1.08); box-shadow: 0 5px 15px rgba(0,0,0,.18); border-color: var(--green-dark); }
        .emblem-wrap img { width: 100%; height: 100%; object-fit: cover; }
        .emblem-fallback {
            display: none; width: 100%; height: 100%;
            justify-content: center; align-items: center;
            font-size: 36px; color: #2e7d32;
        }
        .error-code {
            font-size: clamp(72px, 18vw, 110px); font-weight: 800; line-height: 1;
            background: linear-gradient(135deg, var(--green-dark) 0%, var(--blue) 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; letter-spacing: -4px; margin-bottom: 4px;
            animation: glow 3s ease-in-out infinite;
        }
        @keyframes glow {
            0%, 100% { filter: brightness(1); }
            50%       { filter: brightness(1.18); }
        }
        .lost-icon {
            color: var(--green-dark); margin: 10px auto;
            display: flex; align-items: center; justify-content: center;
            animation: float 4s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-9px); }
        }
        .divider {
            width: 50px; height: 3px;
            background: linear-gradient(90deg, var(--green-dark), var(--blue));
            border-radius: 4px; margin: 0 auto 22px;
        }
        h1 { font-size: 22px; font-weight: 700; color: var(--text-primary); margin-bottom: 10px; }
        .subtitle { font-size: 14px; color: var(--text-secondary); line-height: 1.7; margin-bottom: 30px; }
        .actions { display: flex; flex-direction: column; gap: 12px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 13px 24px; border-radius: 8px; font-size: 15px; font-weight: 600;
            cursor: pointer; text-decoration: none; border: none; transition: all .25s; letter-spacing: .3px;
        }
        .btn-primary { background: var(--green-dark); color: #fff; box-shadow: 0 4px 14px rgba(46,125,50,.35); }
        .btn-primary:hover { background: var(--green-deeper); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(46,125,50,.45); }
        .btn-primary:active { transform: translateY(0); }
        .btn-secondary { background: transparent; color: var(--text-primary); border: 1.5px solid var(--border-color); }
        .btn-secondary:hover { border-color: var(--green-dark); color: var(--green-dark); transform: translateY(-2px); box-shadow: var(--shadow); }
        .quick-links { margin-top: 26px; padding-top: 22px; border-top: 1px solid var(--border-color); }
        .quick-links p { font-size: 11px; color: var(--text-secondary); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        .link-chips { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .chip {
            display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px;
            background: var(--bg-primary); border: 1px solid var(--border-color);
            border-radius: 20px; font-size: 12px; color: var(--text-secondary);
            text-decoration: none; transition: all .2s;
        }
        .chip:hover { background: var(--green-dark); color: #fff; border-color: var(--green-dark); transform: translateY(-1px); }
        .footer-note { position: fixed; bottom: 16px; font-size: 11px; color: var(--text-secondary); z-index: 10; text-align: center; }
        @media (max-width: 480px) { .card { padding: 36px 22px 30px; } }
    </style>
</head>
<body>
    <button class="theme-toggle" id="themeToggle" title="Toggle dark mode" aria-label="Toggle theme">
        <i data-lucide="moon" width="18" height="18" id="themeIcon"></i>
    </button>

    <div class="card" role="main" aria-labelledby="errorHeading">
        <div class="emblem-wrap">
            <img src="/loan-system/logo.jpg" alt="Nyeri County Government Emblem"
                 onerror="this.style.display='none'; document.getElementById('emblFallback').style.display='flex';">
            <div id="emblFallback" class="emblem-fallback">
                <i data-lucide="landmark"></i>
            </div>
        </div>

        <div class="error-code" aria-label="Error 404">404</div>

        <div class="lost-icon">
            <i data-lucide="map-pin-off" width="42" height="42"></i>
        </div>

        <div class="divider"></div>

        <h1 id="errorHeading">Page Not Found</h1>
        <p class="subtitle">
            The page you are looking for does not exist or may have been moved.<br>
            Please check the URL or return to the system.
        </p>

        <div class="actions">
            <a href="/loan-system/index.php" class="btn btn-primary" id="homeBtn">
                <i data-lucide="home" width="17" height="17"></i>
                Back to Login
            </a>
            <a href="javascript:history.back()" class="btn btn-secondary" id="backBtn">
                <i data-lucide="arrow-left" width="17" height="17"></i>
                Go Back
            </a>
        </div>

        <div class="quick-links">
            <p>Quick Links</p>
            <div class="link-chips">
                <a href="/loan-system/index.php" class="chip" id="chipLogin">
                    <i data-lucide="log-in" width="12" height="12"></i> Login
                </a>
                <a href="/loan-system/register.php" class="chip" id="chipRegister">
                    <i data-lucide="user-plus" width="12" height="12"></i> Register
                </a>
                <a href="/loan-system/loans.php" class="chip" id="chipLoans">
                    <i data-lucide="file-text" width="12" height="12"></i> Loans
                </a>
            </div>
        </div>
    </div>

    <div class="footer-note">
        &copy; <span id="yr"></span> Nyeri County Government &mdash; All rights reserved.
    </div>

    <script>
        lucide.createIcons();
        document.getElementById('yr').textContent = new Date().getFullYear();

        const toggleBtn = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');

        function applyTheme(dark) {
            document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            themeIcon.setAttribute('data-lucide', dark ? 'sun' : 'moon');
            lucide.createIcons();
        }

        let isDark = localStorage.getItem('theme') === 'dark';
        applyTheme(isDark);

        toggleBtn.addEventListener('click', () => {
            isDark = !isDark;
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            applyTheme(isDark);
        });
    </script>
</body>
</html>
