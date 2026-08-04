// Canvas match client. Renders exclusively from server broadcasts —
// the client never simulates game logic, it only interpolates between
// ticks for smoothness and forwards direction intents.

const root = document.getElementById('game-root');
if (root) init(root);

function init(root) {
    const gameId = root.dataset.gameId;
    const playerId = Number(root.dataset.playerId);
    const inputUrl = root.dataset.inputUrl;
    const abilityUrl = root.dataset.abilityUrl;
    const lobbyUrl = root.dataset.lobbyUrl;
    const maze = JSON.parse(root.dataset.maze);
    const spriteConfig = JSON.parse(root.dataset.sprites);
    const soundConfig = JSON.parse(root.dataset.sounds ?? '{}');

    const canvas = document.getElementById('game-canvas');
    const ctx = canvas.getContext('2d');

    const TILE = 26;
    const TICK_MS = 1000 / 15;
    const FOG_RADIUS = 4.5; // tiles a ghost can see

    canvas.width = maze.width * TILE;
    canvas.height = maze.height * TILE;

    // ---- sprite loading -------------------------------------------------
    const loadImage = (src) => {
        const img = new Image();
        img.src = src;
        return img;
    };

    // A sprite either has one frame strip ('frames', optionally rotated to
    // face movement) or pre-drawn per-direction strips ('frames_by_direction').
    const loadSprite = (cfg) => {
        if (cfg.frames_by_direction) {
            const byDir = Object.fromEntries(
                Object.entries(cfg.frames_by_direction).map(([dir, frames]) => [dir, frames.map(loadImage)]),
            );
            return { ...cfg, byDir };
        }
        return { ...cfg, images: cfg.frames.map(loadImage) };
    };

    const sprites = {
        pacman: loadSprite(spriteConfig.pacman),
        ghosts: spriteConfig.ghosts.map(loadSprite),
        frightened: loadSprite(spriteConfig.ghost_frightened),
    };

    ctx.imageSmoothingEnabled = false; // crisp pixel-art scaling

    // ---- sound ------------------------------------------------------------
    // Preloaded from config/sounds.php; cloned per play so effects overlap.
    // Browsers block audio until a user gesture, so failed plays are ignored.
    const soundBank = Object.fromEntries(
        Object.entries(soundConfig).map(([name, cfg]) => {
            const audio = new Audio(cfg.src);
            audio.preload = 'auto';
            return [name, { audio, volume: cfg.volume ?? 0.5 }];
        }),
    );

    let muted = localStorage.getItem('mazechase-muted') === '1';
    const muteBtn = document.getElementById('hud-mute');

    const renderMute = () => { muteBtn.textContent = muted ? '🔇' : '🔊'; };
    renderMute();

    const toggleMute = () => {
        muted = !muted;
        localStorage.setItem('mazechase-muted', muted ? '1' : '0');
        renderMute();
    };
    muteBtn.addEventListener('click', toggleMute);

    const sound = (name) => {
        const entry = soundBank[name];
        if (!entry || muted) return;
        const node = entry.audio.cloneNode();
        node.volume = entry.volume;
        node.play().catch(() => {});
    };

    let chompFlip = false;
    let lastChompAt = 0;
    const chomp = () => {
        const now = performance.now();
        if (now - lastChompAt < 90) return; // don't stack when eating fast
        lastChompAt = now;
        chompFlip = !chompFlip;
        sound(chompFlip ? 'chomp1' : 'chomp2');
    };

    // ---- state ----------------------------------------------------------
    let pellets = new Set(maze.pellets.map(([x, y]) => `${x},${y}`));
    let powerPellets = new Set(maze.power_pellets.map(([x, y]) => `${x},${y}`));
    let closedWalls = new Set();
    let tempWalls = new Set();
    let prevSnap = null;
    let snap = null;
    let snapAt = 0;
    let myRole = null;
    let powerUntil = 0;
    let serverClockOffset = 0; // server_now - client_now
    let gameOver = false;

    // ---- echo listeners ---------------------------------------------------
    const channel = window.Echo.private(`game.${gameId}`);

    let powerWarned = false;

    channel.listen('GameStateUpdated', (e) => {
        const s = e.state ?? e;
        const firstSnap = snap === null;
        prevSnap = snap;
        snap = s;
        snapAt = performance.now();
        serverClockOffset = s.now * 1000 - Date.now();
        powerUntil = s.power_pellet_until * 1000;

        if (firstSnap) sound('start');

        if ((s.eaten ?? []).length) chomp();
        if ((s.power_eaten ?? []).length) {
            sound('power');
            powerWarned = false;
        }

        const powerLeft = powerUntil - (Date.now() + serverClockOffset);
        if (powerLeft > 0 && powerLeft < 2000 && !powerWarned) {
            powerWarned = true;
            sound('power_warn');
        }

        for (const [x, y] of s.eaten ?? []) pellets.delete(`${x},${y}`);
        for (const [x, y] of s.power_eaten ?? []) powerPellets.delete(`${x},${y}`);
        if (s.pellets) pellets = new Set(s.pellets);
        if (s.power_pellets) powerPellets = new Set(s.power_pellets);
        closedWalls = new Set(s.closed_walls ?? []);
        tempWalls = new Set(s.temp_walls ?? []);

        const me = s.players[playerId];
        if (me && me.role !== myRole) {
            if (myRole !== null) {
                banner(me.role === 'pacman' ? 'You are now PAC-MAN!' : 'You are now a GHOST!', me.role === 'pacman' ? '#facc15' : '#22d3ee');
            }
            myRole = me.role;
        }
        updateHud(s);
    });

    channel.listen('PlayerCaught', (e) => {
        if (e.outcome === 'ghost_respawn') {
            sound('ghost_eaten');
            if (e.caughtId === playerId) banner('Eaten! Respawning…', '#3b82f6');
        } else {
            sound('rotate');
        }
    });

    channel.listen('PowerPelletActivated', () => {
        if (myRole === 'ghost') banner('Pac-Man is powered — RUN!', '#3b82f6', 1500);
    });

    channel.listen('GameEnded', (e) => {
        gameOver = true;
        const iWon = (e.winnerRole === 'pacman') === (myRole === 'pacman');
        sound(iWon ? 'win' : 'lose');
        const lines = [
            e.winnerRole === 'pacman' ? 'PAC-MAN WINS' : 'GHOSTS WIN',
            iWon ? 'Victory!' : 'Defeat',
            '',
            ...e.scores.sort((a, b) => b.score - a.score).map((s) => `${s.name}: ${s.score}`),
        ];
        endScreen(lines, e.winnerRole === 'pacman' ? '#facc15' : '#22d3ee', lobbyUrl);
        // Back to the team room, not the front page — the squad stays together
        // for the next round and the full scoreboard lives there.
        setTimeout(() => (window.location = lobbyUrl), 6000);
    });

    // ---- input ------------------------------------------------------------
    const KEYMAP = {
        ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right',
        w: 'up', s: 'down', a: 'left', d: 'right',
        W: 'up', S: 'down', A: 'left', D: 'right',
    };

    let lastSent = '';
    let lastSentAt = 0;

    const sendIntent = (dir) => {
        const now = performance.now();
        // debounce: never faster than the tick rate, skip repeats
        if (dir === lastSent || now - lastSentAt < TICK_MS) return;
        lastSent = dir;
        lastSentAt = now;
        window.axios.post(inputUrl, { direction: dir }).catch(() => {
            lastSent = ''; // allow retry on failure
        });
    };

    let lastAbilityAt = 0;
    const useAbility = () => {
        const now = performance.now();
        if (gameOver || myRole !== 'ghost' || now - lastAbilityAt < 250) return;
        lastAbilityAt = now;
        window.axios.post(abilityUrl).then(() => sound('ability')).catch(() => {});
    };

    window.addEventListener('keydown', (e) => {
        if (e.key === 'm' || e.key === 'M') {
            toggleMute();
            return;
        }
        if ((e.key === ' ' || e.key === 'e' || e.key === 'E') && !gameOver) {
            e.preventDefault();
            useAbility();
            return;
        }
        const dir = KEYMAP[e.key];
        if (!dir || gameOver) return;
        e.preventDefault();
        sendIntent(dir);
    });

    document.querySelectorAll('#dpad [data-dir]').forEach((btn) => {
        btn.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            sendIntent(btn.dataset.dir);
        });
    });

    // ---- hud ----------------------------------------------------------------
    const hudRole = document.getElementById('hud-role');
    const hudPellets = document.getElementById('hud-pellets');
    const hudPower = document.getElementById('hud-power');
    const hudTimer = document.getElementById('hud-timer');
    const hudAbility = document.getElementById('hud-ability');
    hudAbility.addEventListener('pointerdown', (e) => { e.preventDefault(); useAbility(); });

    const ABILITY_LABEL = { speed_burst: 'Speed burst', blink: 'Blink', wall_drop: 'Wall drop' };

    function updateHud(s) {
        hudPellets.textContent = `Pellets: ${s.pellets_remaining}`;

        const me = s.players[playerId];
        if (me?.role === 'ghost' && me.ability) {
            hudAbility.classList.remove('hidden');
            const cd = me.ability_ready_at * 1000 - (Date.now() + serverClockOffset);
            hudAbility.textContent = cd > 0
                ? `${ABILITY_LABEL[me.ability]} ${Math.ceil(cd / 1000)}s`
                : `${ABILITY_LABEL[me.ability]} [Space]`;
            hudAbility.style.opacity = cd > 0 ? 0.4 : 1;
        } else {
            hudAbility.classList.add('hidden');
        }
        if (myRole === 'pacman') {
            hudRole.textContent = 'PAC-MAN';
            hudRole.style.color = '#facc15';
        } else if (myRole === 'ghost') {
            // Name the colour too, so "the green ghost" means something.
            const label = sprites.ghosts[Math.max(0, me?.ghost_slot ?? 0) % sprites.ghosts.length]?.label;
            hudRole.textContent = label ? `${label.toUpperCase()} GHOST` : 'GHOST';
            hudRole.style.color = '#22d3ee';
        }
    }

    function hudClock() {
        if (!snap) return;
        const serverNow = Date.now() + serverClockOffset;
        const left = Math.max(0, snap.ends_at * 1000 - serverNow);
        const m = Math.floor(left / 60000);
        const s = Math.floor((left % 60000) / 1000);
        hudTimer.textContent = `${m}:${String(s).padStart(2, '0')}`;

        const powerLeft = powerUntil - serverNow;
        hudPower.textContent = powerLeft > 0 ? `POWER ${(powerLeft / 1000).toFixed(1)}s` : '';
    }

    // ---- banners ---------------------------------------------------------------
    const bannerEl = document.getElementById('game-banner');
    const bannerText = document.getElementById('game-banner-text');
    let bannerTimeout;

    function banner(text, color, ms = 2500) {
        bannerText.textContent = text;
        bannerText.style.color = color;
        bannerEl.classList.remove('hidden');
        clearTimeout(bannerTimeout);
        bannerTimeout = setTimeout(() => bannerEl.classList.add('hidden'), ms);
    }

    function endScreen(lines, color, continueUrl) {
        bannerText.innerHTML = lines
            .map((l, i) => `<div class="${i === 0 ? '' : 'text-lg font-semibold'}">${l}</div>`)
            .join('');

        if (continueUrl) {
            const link = document.createElement('a');
            link.href = continueUrl;
            link.textContent = 'See full results →';
            link.className = 'mt-4 inline-block text-base font-semibold text-slate-300 underline';
            bannerText.appendChild(link);
        }

        bannerText.style.color = color;
        bannerEl.classList.remove('hidden');
        bannerEl.classList.remove('pointer-events-none'); // the link needs clicks
        clearTimeout(bannerTimeout);
    }

    // ---- rendering ------------------------------------------------------------
    const px = (tileCoord) => (tileCoord + 0.5) * TILE;

    function lerpPlayers() {
        if (!snap) return {};
        const t = Math.min((performance.now() - snapAt) / TICK_MS, 1.5);
        const out = {};

        for (const [pid, cur] of Object.entries(snap.players)) {
            const prev = prevSnap?.players?.[pid];
            if (!prev || Math.abs(cur.x - prev.x) > 2 || Math.abs(cur.y - prev.y) > 2) {
                out[pid] = { ...cur }; // teleport/tunnel: don't interpolate
            } else {
                out[pid] = {
                    ...cur,
                    x: prev.x + (cur.x - prev.x) * t,
                    y: prev.y + (cur.y - prev.y) * t,
                };
            }
        }

        return out;
    }

    function drawMaze() {
        ctx.fillStyle = '#0b1020';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.fillStyle = '#1e3a8a';
        for (let y = 0; y < maze.height; y++) {
            for (let x = 0; x < maze.width; x++) {
                if (maze.tiles[y][x] === 1) {
                    ctx.fillRect(x * TILE + 1, y * TILE + 1, TILE - 2, TILE - 2);
                }
            }
        }

        // ghost wall drops: cyan blocks that only stop pacman
        ctx.fillStyle = 'rgba(34, 211, 238, 0.7)';
        for (const key of tempWalls) {
            const [x, y] = key.split(',').map(Number);
            ctx.fillRect(x * TILE + 2, y * TILE + 2, TILE - 4, TILE - 4);
        }

        // dynamic walls: closed = solid amber, open = faint outline
        for (const [x, y] of maze.toggleable_walls ?? []) {
            const key = `${x},${y}`;
            if (closedWalls.has(key)) {
                ctx.fillStyle = '#b45309';
                ctx.fillRect(x * TILE + 2, y * TILE + 2, TILE - 4, TILE - 4);
            } else {
                ctx.strokeStyle = 'rgba(180, 83, 9, 0.35)';
                ctx.strokeRect(x * TILE + 4, y * TILE + 4, TILE - 8, TILE - 8);
            }
        }
    }

    function drawPellets(nowMs) {
        ctx.fillStyle = '#fde68a';
        for (const key of pellets) {
            const [x, y] = key.split(',').map(Number);
            ctx.beginPath();
            ctx.arc(px(x), px(y), 2.5, 0, Math.PI * 2);
            ctx.fill();
        }

        const pulse = 4.5 + Math.sin(nowMs / 150) * 1.5;
        ctx.fillStyle = '#f9a8d4';
        for (const key of powerPellets) {
            const [x, y] = key.split(',').map(Number);
            ctx.beginPath();
            ctx.arc(px(x), px(y), pulse, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    function frameOf(sprite, nowMs, dir) {
        const strip = sprite.byDir ? (sprite.byDir[dir] ?? sprite.byDir.right) : sprite.images;
        if (!sprite.fps || strip.length < 2) return strip[0];
        return strip[Math.floor(nowMs / (1000 / sprite.fps)) % strip.length];
    }

    const ROTATION = { right: 0, down: Math.PI / 2, left: Math.PI, up: -Math.PI / 2 };

    function drawPlayers(players, nowMs) {
        const serverNow = Date.now() + serverClockOffset;
        const powered = powerUntil > serverNow;

        for (const p of Object.values(players)) {
            const respawning = p.respawn_until * 1000 > serverNow;
            ctx.save();
            ctx.translate(px(p.x), px(p.y));

            if (respawning) ctx.globalAlpha = 0.35 + 0.25 * Math.sin(nowMs / 90);

            if (p.role === 'pacman') {
                const img = frameOf(sprites.pacman, nowMs, p.dir);
                if (!sprites.pacman.byDir && sprites.pacman.direction === 'rotate') {
                    ctx.rotate(ROTATION[p.dir] ?? 0);
                }
                if (powered) {
                    ctx.shadowColor = '#f9a8d4';
                    ctx.shadowBlur = 14;
                }
                ctx.drawImage(img, -TILE * 0.65, -TILE * 0.65, TILE * 1.3, TILE * 1.3);
            } else {
                const sprite = powered && !respawning
                    ? sprites.frightened
                    : sprites.ghosts[Math.max(0, p.ghost_slot) % sprites.ghosts.length];
                // Slots past the pack's four painted ghosts are recoloured.
                if (sprite.hue) ctx.filter = `hue-rotate(${sprite.hue}deg)`;
                ctx.drawImage(frameOf(sprite, nowMs, p.dir), -TILE * 0.6, -TILE * 0.62, TILE * 1.2, TILE * 1.2);
            }
            ctx.restore();

            // Name tag above every sprite — your own included, with a marker,
            // because "which one am I?" is the first thing a full lobby asks.
            const mine = p.id === playerId;
            ctx.save();
            ctx.font = mine ? 'bold 10px monospace' : '9px monospace';
            ctx.textAlign = 'center';
            ctx.fillStyle = mine ? '#facc15' : 'rgba(226, 232, 240, 0.75)';
            ctx.strokeStyle = 'rgba(2, 6, 23, 0.9)';
            ctx.lineWidth = 3;

            const label = mine ? `▼ ${p.name}` : p.name;
            const ty = px(p.y) - TILE * (mine ? 0.85 : 0.75);
            ctx.strokeText(label, px(p.x), ty); // outline keeps names legible on pellets
            ctx.fillText(label, px(p.x), ty);
            ctx.restore();
        }
    }

    // Ghost players only see a radius around themselves. Win/pellet logic
    // stays server-side regardless — this occlusion is what forces the
    // ghost pack to communicate.
    function applyFog(players) {
        const me = players[playerId];
        if (!me || myRole !== 'ghost') return;

        ctx.save();
        ctx.globalCompositeOperation = 'destination-in';
        const g = ctx.createRadialGradient(
            px(me.x), px(me.y), FOG_RADIUS * TILE * 0.55,
            px(me.x), px(me.y), FOG_RADIUS * TILE,
        );
        g.addColorStop(0, 'rgba(0,0,0,1)');
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.restore();

        // other ghosts stay visible as faint outlines so the pack can coordinate
        ctx.save();
        ctx.globalAlpha = 0.4;
        for (const p of Object.values(players)) {
            if (p.role !== 'ghost' || p.id === playerId) continue;
            const sprite = sprites.ghosts[Math.max(0, p.ghost_slot) % sprites.ghosts.length];
            ctx.save();
            if (sprite.hue) ctx.filter = `hue-rotate(${sprite.hue}deg)`;
            ctx.drawImage(frameOf(sprite, 0, p.dir), px(p.x) - TILE * 0.6, px(p.y) - TILE * 0.62, TILE * 1.2, TILE * 1.2);
            ctx.restore();
        }
        ctx.restore();
    }

    function render(nowMs) {
        drawMaze();
        drawPellets(nowMs);
        const players = lerpPlayers();
        drawPlayers(players, nowMs);
        applyFog(players);
        hudClock();
        requestAnimationFrame(render);
    }

    requestAnimationFrame(render);
}
