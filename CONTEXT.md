# War of Dots — Project Context

This document captures the original brief and references used when starting the project.

## Origin

- Scaffolded from [tmwclaxton/baseProject](https://github.com/tmwclaxton/baseProject) (Laravel Vue starter kit).
- Local path: `LaravelProjects/warofdots`.
- Git history from the base project was removed intentionally — this repo is a fresh application built on that scaffolding, not a fork.

## What we’re building

An RTS game in Laravel that **replicates [War of Dots](https://warofdots.net/)** gameplay, with:

- **Planning like a simplified HOI4** — draw attack/movement paths, then commit orders (plan first, execute later).
- **Visual style inspired by [Historia Civilis](https://www.youtube.com/c/HistoriaCivilis)** — flat tactical maps, colored dots for units, simple terrain blobs, black curved attack arrows, yellow markers for cities/objectives. Minimal, diagrammatic, readable at a glance — not a high-fidelity sprite game.

## Core gameplay (from War of Dots reference)

Reference implementation: [gamepycoder/War-of-dots](https://github.com/gamepycoder/War-of-dots) (unofficial Python/Pygame clone; original credit goes to [warofdots.net](https://warofdots.net/)).

Key systems to mirror:

| System | Summary |
|--------|---------|
| **Terrain** | Water, plains, forest, hills, mountains — affects speed, combat, and vision |
| **Cities** | Produce troops on a timer; capturable; assign production paths |
| **Troops** | Follow paths, fight nearby enemies, heal near friendly cities; contribute to vision and borders |
| **Vision / fog** | Enemy units hidden until scouted; terrain and cities always visible |
| **Borders** | Territory influence; encirclement applies healing debuffs to enemies |
| **Controls** | Draw paths (left-click drag), pan (right-click), zoom (scroll), confirm (Space), clear (C), pause (P — all players must pause) |

## Multiplayer & scope

- **2–6 players per match** (same range as the Python reference).
- **Quick match / open lobbies** — host creates a room, sets player count, shares a join code; not team-based matchmaking for MVP.
- **MVP milestone**: playable core loop (terrain gen, path drawing, fog of war, win condition, lobby flow, landing page).
- **Out of MVP (for now)**: team-based matchmaking, replays, ranked play, spectating, AI opponents.

## Visual direction (Historia Civilis)

Reference thumbnails described a style with:

- Light green/yellow **plains**, dark green **forest** blobs, bright blue **rivers/water**
- Grey **hills/mountains** (sometimes with simple contour feel)
- **Red and blue dots** (extend to six faction colors in multiplayer)
- **Yellow stars/diamonds** for cities and objectives
- **Black curved arrows** for planned maneuvers (pincer movements, frontal assaults)
- Clean, flat, 2D — historical battle diagram, not textured 3D

## Product surface

- Public **landing page** — “Command. Conquer. Dominate.”, explain rules briefly, CTA to play
- **Lobby** — create/join by code, waiting room with slot grid
- **Battlefield** — full-screen canvas game view with HUD for execute / clear / pause

## Technical direction (agreed in planning)

- **Laravel 13 + Inertia + Vue 3** from baseProject scaffold
- **WorkOS** auth (login required to play)
- **Server-authoritative simulation** — game rules on the backend, not client-trusted
- **Redis** for live match state
- **Dedicated tick worker** (`game:tick --daemon`) at ~30 Hz
- **Laravel Reverb + Echo** for per-player fog-filtered realtime updates
- **Canvas 2D** renderer on the frontend

## Links

- Original game: https://warofdots.net/
- Python reference: https://github.com/gamepycoder/War-of-dots
- Visual inspiration: https://www.youtube.com/c/HistoriaCivilis
- This repo: https://github.com/tmwclaxton/warofdots (private)
