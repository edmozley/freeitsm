<?php
/**
 * Delivery Dash - FreeITSM Food Delivery Simulator
 * A pseudo-3D driving game with sat-nav and temperature mechanics
 */
session_start();
require_once '../../../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$current_page = 'games';
$path_prefix = '../../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Dash - FreeITSM</title>
    <link rel="stylesheet" href="../../../assets/css/inbox.css">
    <style>
        .game-container {
            display: flex;
            height: calc(100vh - 48px);
            background: #1a1a2e;
            overflow: hidden;
        }

        .game-main {
            flex: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #gameCanvas {
            display: block;
            background: #000;
        }

        .game-sidebar {
            width: 280px;
            background: #0f0f23;
            border-left: 1px solid #2a2a4a;
            display: flex;
            flex-direction: column;
            gap: 0;
            flex-shrink: 0;
            overflow-y: auto;
        }

        .sidebar-section {
            padding: 14px 16px;
            border-bottom: 1px solid #2a2a4a;
        }

        .sidebar-section h4 {
            margin: 0 0 10px;
            font-size: 11px;
            font-weight: 600;
            color: #6a6a9a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        #minimapCanvas {
            width: 100%;
            height: 200px;
            border-radius: 6px;
            background: #16213e;
        }

        .order-card {
            background: #16213e;
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #2a2a4a;
        }

        .order-card.active {
            border-color: #ff6b35;
        }

        .order-card.pickup {
            border-color: #4caf50;
        }

        .order-restaurant {
            font-size: 14px;
            font-weight: 700;
            color: #ff6b35;
            margin-bottom: 4px;
        }

        .order-food {
            font-size: 13px;
            color: #e0e0e0;
            margin-bottom: 6px;
        }

        .order-customer {
            font-size: 12px;
            color: #8888aa;
        }

        .order-status {
            margin-top: 8px;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
        }

        .order-status.picking-up {
            background: #1b3a1b;
            color: #4caf50;
        }

        .order-status.delivering {
            background: #3a1b1b;
            color: #ff6b35;
        }

        .order-empty {
            color: #5a5a7a;
            font-size: 13px;
            font-style: italic;
        }

        .temp-bar-container {
            background: #1a1a2e;
            border-radius: 4px;
            height: 18px;
            overflow: hidden;
            border: 1px solid #2a2a4a;
        }

        .temp-bar {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s, background-color 0.3s;
        }

        .temp-label {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #8888aa;
            margin-top: 4px;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
        }

        .stat-label {
            font-size: 12px;
            color: #6a6a9a;
        }

        .stat-value {
            font-size: 16px;
            font-weight: 700;
            color: #e0e0e0;
            font-family: 'Consolas', monospace;
        }

        .stat-value.score {
            color: #ffd700;
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            max-width: 120px;
            margin: 0 auto;
        }

        .key {
            background: #2a2a4a;
            border: 1px solid #3a3a5a;
            border-radius: 4px;
            padding: 4px;
            text-align: center;
            font-size: 11px;
            color: #8888aa;
            font-family: monospace;
        }

        .key.active-key {
            background: #3a3a6a;
            border-color: #5a5a9a;
            color: #e0e0e0;
        }

        .key.spacer { background: none; border: none; }

        .start-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }

        .start-overlay h1 {
            font-size: 42px;
            color: #ff6b35;
            margin: 0 0 8px;
            font-weight: 800;
        }

        .start-overlay .subtitle {
            font-size: 16px;
            color: #8888aa;
            margin-bottom: 30px;
        }

        .start-overlay .press-start {
            font-size: 18px;
            color: #ffd700;
            animation: pulse 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .notification {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            z-index: 5;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .notification.pickup { background: #4caf50; color: white; }
        .notification.deliver { background: #ff6b35; color: white; }
        .notification.score { background: #ffd700; color: #333; }
        .notification.fail { background: #f44336; color: white; }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>

    <div class="game-container">
        <div class="game-main">
            <canvas id="gameCanvas"></canvas>
            <div class="start-overlay" id="startOverlay">
                <h1>Delivery Dash</h1>
                <div class="subtitle">FreeITSM Food Delivery Simulator</div>
                <div class="press-start">Press ENTER to start</div>
            </div>
            <div class="notification" id="notification" style="opacity:0;"></div>
        </div>

        <div class="game-sidebar">
            <div class="sidebar-section">
                <h4>Sat Nav</h4>
                <canvas id="minimapCanvas"></canvas>
            </div>
            <div class="sidebar-section">
                <h4>Current Order</h4>
                <div id="orderCard">
                    <div class="order-empty">Waiting for order...</div>
                </div>
            </div>
            <div class="sidebar-section">
                <h4>Food Temperature</h4>
                <div class="temp-bar-container">
                    <div class="temp-bar" id="tempBar" style="width:0%;background:#666;"></div>
                </div>
                <div class="temp-label">
                    <span>Cold</span>
                    <span id="tempPct">--</span>
                    <span>Hot</span>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="stat-row">
                    <span class="stat-label">Score</span>
                    <span class="stat-value score" id="scoreDisplay">0</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Deliveries</span>
                    <span class="stat-value" id="deliveryCount">0</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Speed</span>
                    <span class="stat-value" id="speedDisplay">0 mph</span>
                </div>
            </div>
            <div class="sidebar-section">
                <h4>Controls</h4>
                <div class="controls-grid">
                    <div class="key spacer"></div>
                    <div class="key" id="keyUp">&uarr;</div>
                    <div class="key spacer"></div>
                    <div class="key" id="keyLeft">&larr;</div>
                    <div class="key" id="keyDown">&darr;</div>
                    <div class="key" id="keyRight">&rarr;</div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        // ═══════════════════════════════════════════════════════════
        // CONSTANTS
        // ═══════════════════════════════════════════════════════════
        const canvas = document.getElementById('gameCanvas');
        const ctx = canvas.getContext('2d');
        const miniCanvas = document.getElementById('minimapCanvas');
        const miniCtx = miniCanvas.getContext('2d');

        const ROAD_W = 2000;
        const SEG_LEN = 200;
        const DRAW_DIST = 120;
        const CAM_HEIGHT = 1000;
        const FOV = 100;
        const CAM_DEPTH = 1 / Math.tan((FOV / 2) * Math.PI / 180);
        const MAX_SPEED = SEG_LEN * 1.2;
        const ACCEL = MAX_SPEED / 80;
        const BRAKE = -MAX_SPEED / 40;
        const DECEL = -MAX_SPEED / 500;
        const OFF_ROAD_DECEL = -MAX_SPEED / 20;
        const STEER_SPEED = 3.5;
        const PICKUP_RANGE = 15;

        // ITSM-themed restaurants and food
        const RESTAURANTS = [
            { name: 'Ticket Tacos', foods: ['Priority 1 Burrito', 'SLA Nachos', 'Incident Taco Box'] },
            { name: 'SLA Pizza', foods: ['Escalation Pepperoni', 'Deployment Deep Dish', 'Hotfix Hawaiian'] },
            { name: 'The Patch Kitchen', foods: ['Zero-Day Ramen', 'Firewall Fried Rice', 'Patch Tuesday Pad Thai'] },
            { name: 'Incident Burgers', foods: ['Major Incident Burger', 'P1 Double Stack', 'Root Cause Cheese'] },
            { name: 'Change Control Curry', foods: ['CAB Korma', 'Rollback Rogan Josh', 'Emergency Vindaloo'] },
            { name: 'DevOps Diner', foods: ['CI/CD Club Sandwich', 'Pipeline Pancakes', 'Docker Donuts'] },
        ];
        const CUSTOMERS = ['Alex from HR', 'Jordan in Finance', 'Sam the CTO', 'Pat from Marketing',
            'Casey in Legal', 'Morgan the PM', 'Riley from Sales', 'Taylor in Support',
            'Quinn from Dev', 'Avery the CISO', 'Drew from Ops', 'Jamie the DBA'];

        const BUILDING_COLORS = [
            '#8B7355','#A0896B','#B8A88A','#9E9E9E','#78909C','#607D8B',
            '#8D6E63','#A1887F','#BCAAA4','#90A4AE','#B0BEC5','#CFD8DC',
            '#C8B89A','#D4C5A9','#7B8B6F','#95A37A','#BDB76B'
        ];

        // ═══════════════════════════════════════════════════════════
        // STATE
        // ═══════════════════════════════════════════════════════════
        let W, H, MW, MH;
        let road = [];
        let minimapPath = [];
        let playerPos = 0, playerX = 0, speed = 0;
        let score = 0, deliveries = 0;
        let gameStarted = false;
        let keys = {};
        let order = null;         // { restaurant, food, customer, pickupSeg, deliverySeg, pickedUp, temp }
        let orderCooldown = 0;
        let notifyTimer = 0;
        let notifyTimeout = null;

        // ═══════════════════════════════════════════════════════════
        // ROAD GENERATION
        // ═══════════════════════════════════════════════════════════
        function buildRoad() {
            road = [];
            function addSegments(n, curve) {
                for (let i = 0; i < n; i++) {
                    road.push({ curve: curve ? curve * Math.sin((i / n) * Math.PI) : 0 });
                }
            }
            // City circuit with lots of turns
            addSegments(40, 0);       // straight
            addSegments(25, 0.6);     // gentle right
            addSegments(30, 0);       // straight
            addSegments(20, -0.9);    // left turn
            addSegments(50, 0);       // long straight
            addSegments(30, 0.4);     // gentle right
            addSegments(20, 0);
            addSegments(25, -0.7);    // left
            addSegments(35, 0);
            addSegments(20, 1.0);     // sharp right
            addSegments(15, 0);
            addSegments(25, -0.5);    // gentle left
            addSegments(20, 0.5);     // S-curve
            addSegments(20, -0.5);
            addSegments(40, 0);       // straight
            addSegments(30, 0.8);     // right
            addSegments(25, 0);
            addSegments(20, -0.6);    // left
            addSegments(45, 0);       // straight
            addSegments(25, -0.4);    // gentle left to close
            addSegments(30, 0);
        }

        function buildMinimapPath() {
            let x = 0, y = 0, angle = -Math.PI / 2;
            minimapPath = [];
            for (let i = 0; i < road.length; i++) {
                angle += road[i].curve * 0.015;
                x += Math.cos(angle);
                y += Math.sin(angle);
                minimapPath.push({ x, y });
            }
            // Normalize to fit minimap
            let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
            for (const p of minimapPath) {
                minX = Math.min(minX, p.x); maxX = Math.max(maxX, p.x);
                minY = Math.min(minY, p.y); maxY = Math.max(maxY, p.y);
            }
            const rangeX = maxX - minX || 1, rangeY = maxY - minY || 1;
            const pad = 20;
            for (const p of minimapPath) {
                p.nx = pad + ((p.x - minX) / rangeX) * (MW - pad * 2);
                p.ny = pad + ((p.y - minY) / rangeY) * (MH - pad * 2);
            }
        }

        // ═══════════════════════════════════════════════════════════
        // BUILDING HASH (deterministic per segment)
        // ═══════════════════════════════════════════════════════════
        function bhash(idx, side) {
            let h = ((idx * 127 + side * 311 + 7919) * 2654435761) >>> 0;
            return h;
        }

        // ═══════════════════════════════════════════════════════════
        // ORDERS
        // ═══════════════════════════════════════════════════════════
        function spawnOrder() {
            const rest = RESTAURANTS[Math.floor(Math.random() * RESTAURANTS.length)];
            const food = rest.foods[Math.floor(Math.random() * rest.foods.length)];
            const cust = CUSTOMERS[Math.floor(Math.random() * CUSTOMERS.length)];
            const playerSeg = Math.floor(playerPos / SEG_LEN) % road.length;

            // Pickup: 80-200 segments ahead
            const pickupSeg = (playerSeg + 80 + Math.floor(Math.random() * 120)) % road.length;
            // Delivery: 80-180 segments after pickup
            const deliverySeg = (pickupSeg + 80 + Math.floor(Math.random() * 100)) % road.length;

            order = {
                restaurant: rest.name, food, customer: cust,
                pickupSeg, deliverySeg,
                pickedUp: false, temp: 100
            };
            updateOrderCard();
            showNotify('New order!', 'pickup');
        }

        function updateOrderCard() {
            const el = document.getElementById('orderCard');
            if (!order) {
                el.innerHTML = '<div class="order-empty">Waiting for order...</div>';
                return;
            }
            const statusClass = order.pickedUp ? 'delivering' : 'picking-up';
            const statusText = order.pickedUp ? 'DELIVERING' : 'PICK UP';
            el.innerHTML = `
                <div class="order-card ${statusClass}">
                    <div class="order-restaurant">${order.restaurant}</div>
                    <div class="order-food">${order.food}</div>
                    <div class="order-customer">${order.customer}</div>
                    <div class="order-status ${statusClass}">${statusText}</div>
                </div>`;
        }

        // ═══════════════════════════════════════════════════════════
        // NOTIFICATION
        // ═══════════════════════════════════════════════════════════
        function showNotify(text, type) {
            const el = document.getElementById('notification');
            el.textContent = text;
            el.className = 'notification ' + type;
            el.style.opacity = '1';
            clearTimeout(notifyTimeout);
            notifyTimeout = setTimeout(() => { el.style.opacity = '0'; }, 2000);
        }

        // ═══════════════════════════════════════════════════════════
        // RESIZE
        // ═══════════════════════════════════════════════════════════
        function resize() {
            const main = canvas.parentElement;
            W = main.clientWidth;
            H = main.clientHeight;
            canvas.width = W;
            canvas.height = H;

            const mc = document.getElementById('minimapCanvas');
            MW = mc.clientWidth;
            MH = mc.clientHeight;
            miniCanvas.width = MW;
            miniCanvas.height = MH;

            if (road.length > 0) buildMinimapPath();
        }

        // ═══════════════════════════════════════════════════════════
        // RENDERING
        // ═══════════════════════════════════════════════════════════
        function drawQuad(c, x1, y1, w1, x2, y2, w2, color) {
            c.fillStyle = color;
            c.beginPath();
            c.moveTo(x1 - w1, y1);
            c.lineTo(x1 + w1, y1);
            c.lineTo(x2 + w2, y2);
            c.lineTo(x2 - w2, y2);
            c.closePath();
            c.fill();
        }

        function render() {
            ctx.clearRect(0, 0, W, H);

            // Sky gradient
            const skyGrad = ctx.createLinearGradient(0, 0, 0, H / 2);
            skyGrad.addColorStop(0, '#4a90d9');
            skyGrad.addColorStop(1, '#87ceeb');
            ctx.fillStyle = skyGrad;
            ctx.fillRect(0, 0, W, H / 2);

            // Ground
            ctx.fillStyle = '#555';
            ctx.fillRect(0, H / 2, W, H / 2);

            // Project segments
            const baseSeg = Math.floor(playerPos / SEG_LEN);
            const segPct = (playerPos % SEG_LEN) / SEG_LEN;
            const projected = [];
            let cx = 0, dx = 0;

            for (let n = 0; n < DRAW_DIST; n++) {
                const idx = (baseSeg + n) % road.length;
                const seg = road[idx];
                dx += seg.curve;
                cx += dx;

                const z = (n - segPct + 1);
                if (z <= 0) continue;
                const scale = CAM_DEPTH / z;
                const projY = H / 2 - scale * CAM_HEIGHT * H * 0.0006;
                const projX = W / 2 + (cx * 0.8 - playerX * CAM_DEPTH) * scale * W * 0.3;
                const projW = scale * ROAD_W * W * 0.0003;

                projected.push({ x: projX, y: projY, w: projW, scale, idx, n: n });
            }

            // Draw from far to near
            for (let i = projected.length - 2; i >= 0; i--) {
                const p1 = projected[i + 1]; // far
                const p2 = projected[i];     // near

                if (p2.y <= p1.y) continue;

                const dark = (p1.idx % 2 === 0);

                // Sidewalk / ground
                const groundColor = dark ? '#707070' : '#686868';
                ctx.fillStyle = groundColor;
                ctx.fillRect(0, p1.y, W, p2.y - p1.y);

                // Road
                drawQuad(ctx, p1.x, p1.y, p1.w, p2.x, p2.y, p2.w, dark ? '#404040' : '#3a3a3a');

                // Road edge rumble strips
                const rumbleW1 = p1.w * 1.08, rumbleW2 = p2.w * 1.08;
                drawQuad(ctx, p1.x, p1.y, rumbleW1, p2.x, p2.y, rumbleW2, dark ? '#cc4444' : '#fff');

                // Re-draw road on top of rumble
                drawQuad(ctx, p1.x, p1.y, p1.w, p2.x, p2.y, p2.w, dark ? '#404040' : '#3a3a3a');

                // Center line (dashed)
                if (p1.idx % 6 < 3) {
                    const lw1 = p1.w * 0.02, lw2 = p2.w * 0.02;
                    drawQuad(ctx, p1.x, p1.y, lw1, p2.x, p2.y, lw2, '#ddd');
                }

                // Lane lines
                const lane1 = p1.w * 0.5, lane2 = p2.w * 0.5;
                if (p1.idx % 4 < 2) {
                    const llw1 = p1.w * 0.01, llw2 = p2.w * 0.01;
                    drawQuad(ctx, p1.x - lane1, p1.y, llw1, p2.x - lane2, p2.y, llw2, '#888');
                    drawQuad(ctx, p1.x + lane1, p1.y, llw1, p2.x + lane2, p2.y, llw2, '#888');
                }

                // Buildings (both sides)
                for (const side of [-1, 1]) {
                    const hash = bhash(p1.idx, side > 0 ? 1 : 0);
                    if (hash % 7 === 0) continue; // gap

                    const bHeight = (40 + (hash % 90)) * p1.scale * H * 0.8;
                    const bWidth = p1.w * (0.3 + (hash % 5) * 0.15);

                    const edgeFar = p1.x + side * p1.w * 1.2;
                    const bTop = p1.y - bHeight;

                    const color = BUILDING_COLORS[hash % BUILDING_COLORS.length];
                    ctx.fillStyle = color;
                    ctx.fillRect(
                        side > 0 ? edgeFar : edgeFar - bWidth,
                        bTop, bWidth, bHeight
                    );

                    // Windows
                    if (bHeight > 10) {
                        ctx.fillStyle = 'rgba(255,255,200,0.3)';
                        const winRows = Math.min(Math.floor(bHeight / 8), 6);
                        const winCols = Math.min(Math.floor(bWidth / 6), 4);
                        const winH = bHeight / (winRows + 1);
                        const winW = bWidth / (winCols + 1);
                        for (let r = 0; r < winRows; r++) {
                            for (let c = 0; c < winCols; c++) {
                                const lit = (bhash(p1.idx * 13 + r * 7 + c, side) % 3) !== 0;
                                if (!lit) continue;
                                ctx.fillStyle = 'rgba(255,255,150,0.5)';
                                const wx = (side > 0 ? edgeFar : edgeFar - bWidth) + winW * (c + 0.6);
                                const wy = bTop + winH * (r + 0.5);
                                ctx.fillRect(wx, wy, winW * 0.4, winH * 0.4);
                            }
                        }
                    }
                }

                // Order markers on road
                if (order) {
                    const targetSeg = order.pickedUp ? order.deliverySeg : order.pickupSeg;
                    if (p1.idx === targetSeg) {
                        const markerColor = order.pickedUp ? '#ff6b35' : '#4caf50';
                        ctx.fillStyle = markerColor;
                        ctx.globalAlpha = 0.7;
                        drawQuad(ctx, p1.x, p1.y, p1.w * 0.8, p2.x, p2.y, p2.w * 0.8, markerColor);
                        ctx.globalAlpha = 1.0;

                        // Arrow/marker above road
                        const markerSize = p1.w * 0.15;
                        ctx.fillStyle = markerColor;
                        ctx.beginPath();
                        ctx.moveTo(p1.x, p1.y - markerSize * 3);
                        ctx.lineTo(p1.x - markerSize, p1.y - markerSize * 1.5);
                        ctx.lineTo(p1.x + markerSize, p1.y - markerSize * 1.5);
                        ctx.closePath();
                        ctx.fill();
                    }
                }
            }

            // Dashboard overlay (car interior hint)
            const dashGrad = ctx.createLinearGradient(0, H - 60, 0, H);
            dashGrad.addColorStop(0, 'rgba(30,30,30,0)');
            dashGrad.addColorStop(0.3, 'rgba(30,30,30,0.6)');
            dashGrad.addColorStop(1, 'rgba(20,20,20,0.9)');
            ctx.fillStyle = dashGrad;
            ctx.fillRect(0, H - 60, W, 60);

            // Speed on canvas
            const mph = Math.round((speed / MAX_SPEED) * 120);
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 18px Consolas, monospace';
            ctx.textAlign = 'left';
            ctx.fillText(mph + ' mph', 16, H - 16);

            // Distance to target
            if (order) {
                const playerSeg = Math.floor(playerPos / SEG_LEN) % road.length;
                const targetSeg = order.pickedUp ? order.deliverySeg : order.pickupSeg;
                let dist = targetSeg - playerSeg;
                if (dist < 0) dist += road.length;
                const distText = order.pickedUp ? 'Delivery: ' : 'Pickup: ';
                ctx.textAlign = 'right';
                ctx.fillStyle = order.pickedUp ? '#ff6b35' : '#4caf50';
                ctx.fillText(distText + dist + ' blocks', W - 16, H - 16);
            }
        }

        // ═══════════════════════════════════════════════════════════
        // MINIMAP
        // ═══════════════════════════════════════════════════════════
        function renderMinimap() {
            miniCtx.fillStyle = '#16213e';
            miniCtx.fillRect(0, 0, MW, MH);

            if (minimapPath.length === 0) return;

            // Draw road
            miniCtx.strokeStyle = '#3a4a6a';
            miniCtx.lineWidth = 3;
            miniCtx.beginPath();
            miniCtx.moveTo(minimapPath[0].nx, minimapPath[0].ny);
            for (let i = 1; i < minimapPath.length; i++) {
                miniCtx.lineTo(minimapPath[i].nx, minimapPath[i].ny);
            }
            miniCtx.stroke();

            // Order markers
            if (order) {
                const target = order.pickedUp ? order.deliverySeg : order.pickupSeg;
                const tp = minimapPath[target];
                if (tp) {
                    miniCtx.fillStyle = order.pickedUp ? '#ff6b35' : '#4caf50';
                    miniCtx.beginPath();
                    miniCtx.arc(tp.nx, tp.ny, 6, 0, Math.PI * 2);
                    miniCtx.fill();

                    // Pin stem
                    miniCtx.strokeStyle = order.pickedUp ? '#ff6b35' : '#4caf50';
                    miniCtx.lineWidth = 2;
                    miniCtx.beginPath();
                    miniCtx.moveTo(tp.nx, tp.ny + 6);
                    miniCtx.lineTo(tp.nx, tp.ny + 12);
                    miniCtx.stroke();
                }

                // If not picked up, also show delivery as dimmer marker
                if (!order.pickedUp) {
                    const dp = minimapPath[order.deliverySeg];
                    if (dp) {
                        miniCtx.fillStyle = 'rgba(255,107,53,0.3)';
                        miniCtx.beginPath();
                        miniCtx.arc(dp.nx, dp.ny, 5, 0, Math.PI * 2);
                        miniCtx.fill();
                    }
                }
            }

            // Player dot
            const playerSeg = Math.floor(playerPos / SEG_LEN) % road.length;
            const pp = minimapPath[playerSeg];
            if (pp) {
                // Glow
                miniCtx.fillStyle = 'rgba(0,150,255,0.3)';
                miniCtx.beginPath();
                miniCtx.arc(pp.nx, pp.ny, 8, 0, Math.PI * 2);
                miniCtx.fill();
                // Dot
                miniCtx.fillStyle = '#00aaff';
                miniCtx.beginPath();
                miniCtx.arc(pp.nx, pp.ny, 4, 0, Math.PI * 2);
                miniCtx.fill();
            }

            // Distance label
            if (order) {
                const targetSeg = order.pickedUp ? order.deliverySeg : order.pickupSeg;
                let dist = targetSeg - playerSeg;
                if (dist < 0) dist += road.length;
                miniCtx.fillStyle = '#8888aa';
                miniCtx.font = '11px sans-serif';
                miniCtx.textAlign = 'center';
                miniCtx.fillText((order.pickedUp ? 'Deliver: ' : 'Pickup: ') + dist + ' blocks', MW / 2, MH - 6);
            }
        }

        // ═══════════════════════════════════════════════════════════
        // UPDATE
        // ═══════════════════════════════════════════════════════════
        function update(dt) {
            if (!gameStarted) return;

            // Steering
            if (keys['ArrowLeft'] || keys['a'])  playerX -= STEER_SPEED * (speed / MAX_SPEED);
            if (keys['ArrowRight'] || keys['d']) playerX += STEER_SPEED * (speed / MAX_SPEED);

            // Speed
            if (keys['ArrowUp'] || keys['w']) {
                speed += ACCEL;
            } else if (keys['ArrowDown'] || keys['s']) {
                speed += BRAKE;
            } else {
                speed += DECEL;
            }

            // Off-road slowdown
            if (Math.abs(playerX) > 1.0) {
                speed += OFF_ROAD_DECEL;
                playerX = Math.max(-2.5, Math.min(2.5, playerX));
            }

            speed = Math.max(0, Math.min(MAX_SPEED, speed));

            // Centrifugal force (curve pushes player)
            const seg = road[Math.floor(playerPos / SEG_LEN) % road.length];
            if (seg) playerX += seg.curve * speed * 0.003;

            playerPos += speed;
            if (playerPos >= road.length * SEG_LEN) playerPos -= road.length * SEG_LEN;

            // Update HUD
            const mph = Math.round((speed / MAX_SPEED) * 120);
            document.getElementById('speedDisplay').textContent = mph + ' mph';
            document.getElementById('scoreDisplay').textContent = score;
            document.getElementById('deliveryCount').textContent = deliveries;

            // Key highlights
            document.getElementById('keyUp').classList.toggle('active-key', !!(keys['ArrowUp'] || keys['w']));
            document.getElementById('keyDown').classList.toggle('active-key', !!(keys['ArrowDown'] || keys['s']));
            document.getElementById('keyLeft').classList.toggle('active-key', !!(keys['ArrowLeft'] || keys['a']));
            document.getElementById('keyRight').classList.toggle('active-key', !!(keys['ArrowRight'] || keys['d']));

            // Order logic
            const playerSeg = Math.floor(playerPos / SEG_LEN) % road.length;

            if (!order) {
                orderCooldown -= dt;
                if (orderCooldown <= 0) spawnOrder();
            } else {
                // Check pickup
                if (!order.pickedUp) {
                    let dist = Math.abs(playerSeg - order.pickupSeg);
                    if (dist > road.length / 2) dist = road.length - dist;
                    if (dist < PICKUP_RANGE && speed < MAX_SPEED * 0.5) {
                        order.pickedUp = true;
                        order.temp = 100;
                        updateOrderCard();
                        showNotify('Food collected! Deliver fast!', 'deliver');
                    }
                } else {
                    // Temperature drops
                    order.temp -= dt * 6; // ~17 seconds to go cold

                    // Update temp bar
                    const pct = Math.max(0, order.temp);
                    const tempBar = document.getElementById('tempBar');
                    tempBar.style.width = pct + '%';
                    const r = Math.round(255 * (pct / 100));
                    const b = Math.round(255 * (1 - pct / 100));
                    tempBar.style.backgroundColor = `rgb(${r},${Math.round(80 * pct / 100)},${b})`;
                    document.getElementById('tempPct').textContent = Math.round(pct) + '%';

                    // Check delivery
                    let dist = Math.abs(playerSeg - order.deliverySeg);
                    if (dist > road.length / 2) dist = road.length - dist;
                    if (dist < PICKUP_RANGE && speed < MAX_SPEED * 0.5) {
                        const bonus = Math.round(pct * 10);
                        score += bonus;
                        deliveries++;
                        showNotify('Delivered! +' + bonus + ' points', 'score');
                        order = null;
                        orderCooldown = 3;
                        resetTemp();
                        updateOrderCard();
                        return;
                    }

                    // Food went cold
                    if (order.temp <= 0) {
                        showNotify('Food went cold! Order failed', 'fail');
                        order = null;
                        orderCooldown = 3;
                        resetTemp();
                        updateOrderCard();
                    }
                }
            }
        }

        function resetTemp() {
            document.getElementById('tempBar').style.width = '0%';
            document.getElementById('tempBar').style.backgroundColor = '#666';
            document.getElementById('tempPct').textContent = '--';
        }

        // ═══════════════════════════════════════════════════════════
        // GAME LOOP
        // ═══════════════════════════════════════════════════════════
        let lastTime = 0;
        function gameLoop(timestamp) {
            const dt = Math.min((timestamp - lastTime) / 1000, 0.05);
            lastTime = timestamp;

            update(dt);
            render();
            renderMinimap();

            requestAnimationFrame(gameLoop);
        }

        // ═══════════════════════════════════════════════════════════
        // INPUT
        // ═══════════════════════════════════════════════════════════
        document.addEventListener('keydown', (e) => {
            keys[e.key] = true;
            if (e.key === 'Enter' && !gameStarted) {
                gameStarted = true;
                document.getElementById('startOverlay').style.display = 'none';
                orderCooldown = 2;
            }
            if (['ArrowUp','ArrowDown','ArrowLeft','ArrowRight',' '].includes(e.key)) {
                e.preventDefault();
            }
        });
        document.addEventListener('keyup', (e) => { keys[e.key] = false; });

        // ═══════════════════════════════════════════════════════════
        // INIT
        // ═══════════════════════════════════════════════════════════
        window.addEventListener('resize', resize);
        resize();
        buildRoad();
        buildMinimapPath();
        requestAnimationFrame(gameLoop);
    })();
    </script>
</body>
</html>
