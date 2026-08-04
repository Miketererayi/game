// Attract-mode background for the landing page. Purely decorative: lanes of
// Pac-Man chases drift behind the page content, eating dot trails. No game
// state and no network — sprites come from config/sprites.php like the match
// client, so an art-pack swap carries over here for free.

const canvas = document.getElementById('attract-canvas');
if (canvas) init(canvas);

function init(canvas) {
    const ctx = canvas.getContext('2d');
    const spriteConfig = JSON.parse(canvas.dataset.sprites);

    const SPRITE = 32;              // drawn sprite size in px
    const LANE_GAP = 155;           // vertical spacing between chase lanes
    const TRAIL_GAP = 46;           // spacing between members of a chase
    const DOT_GAP = 28;
    const BASE_SPEED = 78;          // px per second
    const POWER_SPEED = 118;
    const POWER_SECONDS = 5.5;
    const WOBBLE = 3.5;             // ghost bob amplitude in px

    // ---- sprite loading (mirrors the match client) ------------------------
    const loadImage = (src) => {
        const img = new Image();
        // Sprites decode after the first frame is painted, so refresh the
        // static frame as they arrive (the rAF loop covers the animated case).
        img.addEventListener('load', () => {
            if (!running && lanes.length) draw(performance.now());
        });
        img.src = src;
        return img;
    };

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

    const frameOf = (sprite, nowMs, dir) => {
        const strip = sprite.byDir ? (sprite.byDir[dir] ?? sprite.byDir.right) : sprite.images;
        if (!sprite.fps || strip.length < 2) return strip[0];
        return strip[Math.floor(nowMs / (1000 / sprite.fps)) % strip.length];
    };

    // ---- lane setup -------------------------------------------------------
    const rand = (min, max) => min + Math.random() * (max - min);

    // A lane is one chase: Pac-Man plus 2-4 ghosts trailing behind him along a
    // horizontal line, over a row of dots he eats as he goes.
    // Distinct colours per chase, the way the four ghosts read in the arcade.
    const ghostSlots = (count) => {
        const slots = sprites.ghosts.map((_, i) => i);
        for (let i = slots.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [slots[i], slots[j]] = [slots[j], slots[i]];
        }
        return slots.slice(0, count);
    };

    function makeLane(y, index, width) {
        const dir = index % 2 === 0 ? 1 : -1;
        const ghostCount = Math.round(rand(2, 4));
        const dotCount = Math.ceil(width / DOT_GAP) + 2;

        return {
            y,
            dir,
            // Which side of Pac-Man the ghosts sit on. Fixed for the lane's
            // life, so a power-pellet reversal leaves them out front — being
            // chased — rather than dragging them along behind.
            side: dir,
            // Spread the chases across the viewport so the page looks alive on
            // load rather than waiting for them to walk in from the edge.
            x: rand(-0.15, 1.15) * width,
            speed: BASE_SPEED * rand(0.85, 1.15),
            ghosts: ghostSlots(ghostCount).map((slot, i) => ({
                slot,
                phase: rand(0, Math.PI * 2),
                gap: TRAIL_GAP * rand(0.9, 1.15) * (i + 1),
            })),
            dots: Array.from({ length: dotCount }, () => true),
            pelletIndex: Math.floor(rand(dotCount * 0.25, dotCount * 0.75)),
            frightenedUntil: 0,
        };
    }

    let lanes = [];
    let width = 0;
    let height = 0;

    function layout() {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = window.innerWidth;
        height = window.innerHeight;

        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.imageSmoothingEnabled = false; // crisp pixel-art scaling

        const laneCount = Math.max(2, Math.floor(height / LANE_GAP));
        const margin = (height - (laneCount - 1) * LANE_GAP) / 2;
        lanes = Array.from({ length: laneCount }, (_, i) => makeLane(margin + i * LANE_GAP, i, width));
    }

    // Reset a lane once its whole chase has left the screen, flipping the
    // direction so the same row doesn't always run the same way.
    function recycle(lane) {
        const tail = lane.x - lane.side * lane.ghosts.at(-1).gap;
        const off = lane.dir === 1
            ? Math.min(lane.x, tail) > width + SPRITE
            : Math.max(lane.x, tail) < -SPRITE;
        if (!off) return;

        lane.dir *= -1;
        lane.side = lane.dir;
        lane.x = lane.dir === 1 ? -SPRITE * 2 : width + SPRITE * 2;
        lane.speed = BASE_SPEED * rand(0.85, 1.15);
        lane.frightenedUntil = 0;
        lane.dots = lane.dots.map(() => true);
        lane.pelletIndex = Math.floor(rand(lane.dots.length * 0.25, lane.dots.length * 0.75));

        const slots = ghostSlots(lane.ghosts.length);
        lane.ghosts.forEach((ghost, i) => (ghost.slot = slots[i]));
    }

    // ---- simulation -------------------------------------------------------
    function update(dt, nowMs) {
        for (const lane of lanes) {
            const frightened = lane.frightenedUntil > nowMs;
            lane.x += lane.dir * (frightened ? POWER_SPEED : lane.speed) * dt;

            // Eat whatever dot Pac-Man's mouth is currently over.
            const index = Math.round(lane.x / DOT_GAP);
            if (index >= 0 && index < lane.dots.length && lane.dots[index]) {
                lane.dots[index] = false;

                if (index === lane.pelletIndex && !frightened) {
                    // Classic power-pellet behaviour: Pac-Man turns around, so
                    // the ghosts he was fleeing are suddenly the ones running.
                    lane.frightenedUntil = nowMs + POWER_SECONDS * 1000;
                    lane.dir *= -1;
                }
            }

            // Power wears off: turn back around and resume the original chase.
            if (lane.frightenedUntil && lane.frightenedUntil <= nowMs) {
                lane.frightenedUntil = 0;
                lane.dir *= -1;
            }

            recycle(lane);
        }
    }

    // ---- drawing ----------------------------------------------------------
    function drawLane(lane, nowMs) {
        const frightened = lane.frightenedUntil > nowMs;
        const dirName = lane.dir === 1 ? 'right' : 'left';

        // dot trail
        for (let i = 0; i < lane.dots.length; i++) {
            if (!lane.dots[i]) continue;
            const x = i * DOT_GAP;
            const pellet = i === lane.pelletIndex;

            ctx.beginPath();
            ctx.arc(x, lane.y, pellet ? 5.5 : 2.5, 0, Math.PI * 2);
            ctx.fillStyle = pellet ? 'rgba(250, 204, 21, 0.55)' : 'rgba(148, 163, 184, 0.28)';
            if (pellet) {
                ctx.globalAlpha = 0.65 + 0.35 * Math.sin(nowMs / 260);
            }
            ctx.fill();
            ctx.globalAlpha = 1;
        }

        // ghosts, furthest back drawn first so the trail overlaps naturally
        for (let i = lane.ghosts.length - 1; i >= 0; i--) {
            const ghost = lane.ghosts[i];
            const sprite = frightened ? sprites.frightened : sprites.ghosts[ghost.slot % sprites.ghosts.length];
            const x = lane.x - lane.side * ghost.gap;
            const y = lane.y + Math.sin(nowMs / 260 + ghost.phase) * WOBBLE;

            ctx.save();
            if (sprite.hue) ctx.filter = `hue-rotate(${sprite.hue}deg)`;
            ctx.drawImage(frameOf(sprite, nowMs, dirName), x - SPRITE / 2, y - SPRITE / 2, SPRITE, SPRITE);
            ctx.restore();
        }

        // pac-man
        ctx.save();
        if (frightened) {
            ctx.shadowColor = '#f9a8d4';
            ctx.shadowBlur = 16;
        }
        const pac = frameOf(sprites.pacman, nowMs, dirName);
        ctx.drawImage(pac, lane.x - SPRITE / 2, lane.y - SPRITE / 2, SPRITE, SPRITE);
        ctx.restore();
    }

    function draw(nowMs) {
        ctx.clearRect(0, 0, width, height);
        ctx.globalAlpha = 0.5; // stay quiet behind the page content
        for (const lane of lanes) {
            drawLane(lane, nowMs);
        }
        ctx.globalAlpha = 1;
    }

    // ---- loop -------------------------------------------------------------
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    let last = performance.now();
    let running = false;

    function frame(nowMs) {
        if (!running) return;

        // Clamp dt so a backgrounded tab doesn't teleport every chase on return.
        const dt = Math.min((nowMs - last) / 1000, 0.05);
        last = nowMs;

        update(dt, nowMs);
        draw(nowMs);
        requestAnimationFrame(frame);
    }

    function start() {
        if (running || reducedMotion.matches || document.hidden) return;
        running = true;
        last = performance.now();
        requestAnimationFrame(frame);
    }

    function stop() {
        running = false;
    }

    layout();
    draw(performance.now()); // paint immediately; a page opened in a background
    start();                 // tab gets no rAF until it becomes visible

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            layout();
            if (!running) draw(performance.now());
        }, 150);
    });

    document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
    reducedMotion.addEventListener('change', (e) => (e.matches ? stop() : start()));
}
