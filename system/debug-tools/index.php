<?php
/**
 * System - Debug Tools Library
 *
 * Catalogue of diagnostics for troubleshooting failed flows. Each diagnostic
 * is a self-contained PHP script under api/system/debug-tools/ that returns a
 * single plain-text report the user can copy-paste back to support.
 *
 * To add a new diagnostic:
 *   1. Drop a new file at api/system/debug-tools/Dnnn_short_name.php that
 *      outputs plain text with === SECTION === headers.
 *   2. Add an entry to $diagnostics below.
 */
session_start();
require_once '../../config.php';

$current_page = 'debug-tools';
$path_prefix = '../../';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}

// Registry of diagnostics. id = filename stem under api/system/debug-tools/.
$diagnostics = [
    [
        'id'          => 'D001',
        'file'        => 'D001_demo_core_import.php',
        'title'       => 'Demo Core Data Import',
        'category'    => 'Demo Data',
        'when'        => 'Run this when you click "Import Core Data" on the Demo Data screen and it fails, hangs, or appears to do nothing.',
        'checks'      => [
            'PHP version, OS, loaded extensions, session state, memory & post limits',
            'config.php and db_config.php presence + DB credentials defined',
            'Required files: import_demo_data.php, core.json, functions.php',
            'core.json parses and how many records it would import per table',
            'Database connection — server version, database name, character set',
            'Each of the 9 core tables: exists, row count, actual columns vs expected',
            'Write probe — inserts one sentinel row per table inside a rolled-back transaction',
            'Live import attempt — runs the real import in-process and captures the response + any PHP warnings',
        ],
        'duration'    => '~2 seconds',
        'persists'    => 'The live-import step will populate demo data if it succeeds. Otherwise nothing persists.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Debug Tools</title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css">
    <style>
        .debug-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px 40px;
        }

        .debug-header { margin-bottom: 24px; }
        .debug-header h2 { margin: 0; font-size: 22px; color: #333; }
        .debug-header p { margin: 6px 0 0 0; font-size: 13px; color: #888; max-width: 720px; line-height: 1.5; }

        .intro-card {
            background: #e8f4fd;
            border: 1px solid #90caf9;
            border-radius: 8px;
            padding: 14px 18px;
            margin: 18px 0 28px 0;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .intro-card svg { color: #1976d2; flex-shrink: 0; margin-top: 2px; }
        .intro-card .intro-text { font-size: 13px; color: #1565c0; line-height: 1.5; }
        .intro-card .intro-text strong { color: #0d47a1; }

        .diagnostic-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
        }

        .diag-card {
            background: #fff;
            border-radius: 10px;
            padding: 22px 24px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            border: 1px solid #eee;
        }

        .diag-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 6px;
        }

        .diag-id {
            background: #546e7a;
            color: white;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 4px 10px;
            border-radius: 4px;
            font-family: 'Consolas', monospace;
        }

        .diag-title {
            font-size: 17px;
            color: #333;
            margin: 0;
            flex: 1;
        }

        .diag-category {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            background: #f5f5f5;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .diag-when {
            font-size: 13px;
            color: #555;
            line-height: 1.5;
            margin: 8px 0 14px 0;
        }

        .diag-section-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            margin: 14px 0 6px 0;
            font-weight: 600;
        }

        .diag-checks {
            margin: 0 0 6px 18px;
            padding: 0;
            font-size: 12.5px;
            color: #555;
            line-height: 1.6;
        }

        .diag-meta {
            font-size: 12px;
            color: #777;
            display: flex;
            gap: 22px;
            flex-wrap: wrap;
            padding: 12px 0;
            border-top: 1px solid #f0f0f0;
            margin-top: 14px;
        }

        .diag-meta strong { color: #333; }

        .diag-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 6px;
        }

        .run-btn {
            background: #546e7a;
            color: white;
            border: none;
            padding: 9px 22px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .run-btn:hover { background: #37474f; }
        .run-btn:disabled { background: #bbb; cursor: not-allowed; }

        .copy-btn {
            background: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
            padding: 8px 16px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            display: none;
        }
        .copy-btn:hover { background: #eee; }
        .copy-btn.copied { background: #2e7d32; color: white; border-color: #2e7d32; }

        .output-panel {
            display: none;
            margin-top: 16px;
            background: #1e1e1e;
            border-radius: 6px;
            padding: 14px 16px;
            color: #d4d4d4;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
            max-height: 500px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .spinner-inline {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container" style="display: block; background: #f5f7fa;">
        <div class="debug-container">
            <div class="debug-header">
                <h2>Debug Tools</h2>
                <p>Library of self-contained diagnostics. When something doesn't work, run the relevant tool and send the output back to support — each diagnostic captures enough environment and runtime detail to identify the cause without a back-and-forth.</p>
            </div>

            <div class="intro-card">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <div class="intro-text">
                    <strong>How to use:</strong> Support will tell you which diagnostic to run (e.g. "run D001"). Click <strong>Run</strong>, wait for the output to appear, then click <strong>Copy</strong> and paste the entire report into your reply. Diagnostics are read-mostly — any that write to the database say so on the card.
                </div>
            </div>

            <div class="diagnostic-grid">
                <?php foreach ($diagnostics as $d): ?>
                    <div class="diag-card" data-diag-id="<?php echo htmlspecialchars($d['id']); ?>" data-diag-file="<?php echo htmlspecialchars($d['file']); ?>">
                        <div class="diag-head">
                            <span class="diag-id"><?php echo htmlspecialchars($d['id']); ?></span>
                            <h3 class="diag-title"><?php echo htmlspecialchars($d['title']); ?></h3>
                            <span class="diag-category"><?php echo htmlspecialchars($d['category']); ?></span>
                        </div>

                        <p class="diag-when"><?php echo htmlspecialchars($d['when']); ?></p>

                        <div class="diag-section-label">What it checks</div>
                        <ul class="diag-checks">
                            <?php foreach ($d['checks'] as $c): ?>
                                <li><?php echo htmlspecialchars($c); ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="diag-meta">
                            <span><strong>Runtime:</strong> <?php echo htmlspecialchars($d['duration']); ?></span>
                            <span><strong>Side effects:</strong> <?php echo htmlspecialchars($d['persists']); ?></span>
                        </div>

                        <div class="diag-actions">
                            <button class="copy-btn">Copy</button>
                            <button class="run-btn">Run</button>
                        </div>

                        <div class="output-panel"></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    document.querySelectorAll('.diag-card').forEach(card => {
        const runBtn  = card.querySelector('.run-btn');
        const copyBtn = card.querySelector('.copy-btn');
        const panel   = card.querySelector('.output-panel');
        const file    = card.getAttribute('data-diag-file');

        runBtn.addEventListener('click', async () => {
            runBtn.disabled = true;
            const original = runBtn.innerHTML;
            runBtn.innerHTML = '<span class="spinner-inline"></span> Running…';
            panel.style.display = 'block';
            panel.textContent = 'Running diagnostic…';
            copyBtn.style.display = 'none';

            try {
                const res = await fetch('<?php echo $path_prefix; ?>api/system/debug-tools/' + file, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Cache-Control': 'no-cache' }
                });
                const text = await res.text();
                panel.textContent = text;
                copyBtn.style.display = 'inline-block';
            } catch (e) {
                panel.textContent = 'Failed to fetch diagnostic: ' + e.message;
            } finally {
                runBtn.disabled = false;
                runBtn.innerHTML = original;
            }
        });

        copyBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(panel.textContent);
                copyBtn.classList.add('copied');
                copyBtn.textContent = 'Copied';
                setTimeout(() => {
                    copyBtn.classList.remove('copied');
                    copyBtn.textContent = 'Copy';
                }, 1800);
            } catch (e) {
                // Fallback: select the panel text so the user can Ctrl-C
                const range = document.createRange();
                range.selectNodeContents(panel);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }
        });
    });
    </script>
</body>
</html>
