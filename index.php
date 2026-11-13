<?php
/**
 * ====================================================================
 * PS4 Manager Tools 🚀 (PS4) - EDICIÓN MODULAR TERMUX
 * Mantenido por AJ · Basado en proyecto original de SeBaS
 * RUTA: index.php
 * ====================================================================
 */
error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('memory_limit', '512M'); 

function app_asset_url(string $relative): string {
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $version = is_file($file) ? (string)@filemtime($file) : '1';
    return '/' . htmlspecialchars($relative, ENT_QUOTES, 'UTF-8') . '?v=' . rawurlencode($version);
}
$ghm_runtime = is_file(__DIR__ . '/user/.termux-runtime') ? 'termux' : 'pc';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Firma original de SeBaS + mención de modificación
$firma = chr(83).chr(101).chr(66).chr(97).chr(83) . " (Mod AJ)"; 
header('X-Author: ' . $firma);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>PS4 Manager Tools | Consola PS4</title>

    <link rel="manifest" href="<?= app_asset_url('manifest.json') ?>">
    <meta name="theme-color" content="#060913">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PS4 Manager Tools">
    <link rel="apple-touch-icon" href="<?= app_asset_url('js/icon-192.png') ?>">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    
    <style>
        html { background-color: #060913; }
        body { font-family: var(--app-font, 'Outfit'), sans-serif; background-color: transparent !important; overflow: hidden; height: 100dvh; }
        body :not(i):not(svg):not([class*="fa-"]) { font-family: var(--app-font, 'Outfit'), sans-serif !important; }
        .bg-radial-glow { background: transparent !important; }
        
        .app-layer { 
            position: fixed; inset: 0; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); opacity: 0; pointer-events: none; transform: scale(0.97); z-index: 10; 
            background-color: rgba(4, 7, 16, 0.65) !important; 
        }
        .app-layer.active { opacity: 1; pointer-events: auto; transform: scale(1); }
        #launcher-grid, #launcher-preview-grid { gap:clamp(18px,4vw,30px) !important; }
        
        .launcher-card { 
            background: rgba(10, 13, 24, 0.55) !important; border: 1px solid rgba(255, 255, 255, 0.05) !important; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 10px 30px rgba(0,0,0,0.3); backdrop-filter: blur(8px); 
        }
        .launcher-card:active { transform: scale(0.95); border-color: rgba(34, 211, 238, 0.4) !important; background: rgba(10, 13, 24, 0.7) !important; }
        .launcher-card > span { width:100%; text-align:center; line-height:1.35; }
        .launcher-section-label { grid-column:1 / -1; margin:1.5rem 0 .15rem; padding:.7rem .45rem .55rem; border-bottom:1px solid rgba(148,163,184,.16); color:rgba(148,163,184,.82); font:900 9px/1.25 ui-monospace,monospace; letter-spacing:.2em; text-transform:uppercase; }
        :is(#launcher-grid, #launcher-preview-grid) .launcher-card > div:first-child { transition: border-radius .2s ease, filter .2s ease, box-shadow .2s ease, background-color .2s ease; }
        :is(#launcher-grid, #launcher-preview-grid).shape-rounded .launcher-card { border-radius:2rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-circle .launcher-card { border-radius:50% !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-square .launcher-card { border-radius:.7rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-diamond .launcher-card { border-radius:1rem !important; clip-path:polygon(8% 0,92% 0,100% 50%,92% 100%,8% 100%,0 50%); }
        :is(#launcher-grid, #launcher-preview-grid).shape-pill .launcher-card { border-radius:2.6rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-squircle .launcher-card { border-radius:1.35rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-hexagon .launcher-card { clip-path:polygon(25% 0,75% 0,100% 50%,75% 100%,25% 100%,0 50%); }
        :is(#launcher-grid, #launcher-preview-grid).shape-soft-square .launcher-card { border-radius:1rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-cut-corners .launcher-card { border-radius:.8rem !important; clip-path:polygon(0 0,92% 0,100% 12%,100% 100%,8% 100%,0 88%); }
        :is(#launcher-grid, #launcher-preview-grid).shape-rounded .launcher-card > div:first-child { border-radius:1rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-circle .launcher-card > div:first-child { border-radius:9999px !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-square .launcher-card > div:first-child { border-radius:.35rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-diamond .launcher-card > div:first-child { border-radius:.45rem !important; transform:rotate(45deg) scale(.82); }
        :is(#launcher-grid, #launcher-preview-grid).shape-diamond .launcher-card > div:first-child > i { transform:rotate(-45deg); }
        :is(#launcher-grid, #launcher-preview-grid).shape-pill .launcher-card > div:first-child { border-radius:9999px !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-squircle .launcher-card > div:first-child { border-radius:1.35rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-hexagon .launcher-card > div:first-child { clip-path:polygon(25% 0,75% 0,100% 50%,75% 100%,25% 100%,0 50%); }
        :is(#launcher-grid, #launcher-preview-grid).shape-soft-square .launcher-card > div:first-child { border-radius:.6rem !important; }
        :is(#launcher-grid, #launcher-preview-grid).shape-cut-corners .launcher-card > div:first-child { border-radius:.4rem !important; clip-path:polygon(0 0,82% 0,100% 18%,100% 100%,18% 100%,0 82%); }
        :is(#launcher-grid, #launcher-preview-grid).icons-glow .launcher-card > div:first-child { filter:drop-shadow(0 0 8px currentColor); }
        :is(#launcher-grid, #launcher-preview-grid).icons-outline .launcher-card > div:first-child { background-color:transparent !important; border:1px solid currentColor !important; box-shadow:inset 0 0 0 1px rgba(255,255,255,.05); }
        :is(#launcher-grid, #launcher-preview-grid).icons-outline .launcher-card > div:first-child > i { filter:drop-shadow(0 0 4px currentColor); }
        :is(#launcher-grid, #launcher-preview-grid).icons-mono .launcher-card > div:first-child { background:rgba(148,163,184,.12) !important; border-color:rgba(203,213,225,.28) !important; color:#e2e8f0 !important; filter:grayscale(1); }
        :is(#launcher-grid, #launcher-preview-grid).motion-float .launcher-card { transition:transform .18s ease, border-color .18s ease, box-shadow .18s ease; }
        :is(#launcher-grid, #launcher-preview-grid).motion-float .launcher-card:hover, :is(#launcher-grid, #launcher-preview-grid).motion-float .launcher-card:focus-visible { transform:translateY(-4px); box-shadow:0 12px 28px rgba(34,211,238,.12); }
        :is(#launcher-grid, #launcher-preview-grid).motion-float .launcher-card:active { transform:translateY(-1px) scale(.985); }
        :is(#launcher-grid, #launcher-preview-grid).motion-tilt .launcher-card { transition:transform .2s ease, box-shadow .2s ease; }
        :is(#launcher-grid, #launcher-preview-grid).motion-tilt .launcher-card:hover, :is(#launcher-grid, #launcher-preview-grid).motion-tilt .launcher-card:focus-visible { transform:perspective(500px) rotateX(3deg) rotateY(-3deg) translateY(-2px); box-shadow:0 12px 28px rgba(167,139,250,.18); }
        :is(#launcher-grid, #launcher-preview-grid).motion-pulse .launcher-card > div:first-child { animation:launcher-icon-pulse 3s ease-in-out infinite; }
        :is(#launcher-grid, #launcher-preview-grid).motion-rise .launcher-card { animation:launcher-card-rise .42s both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-rise .launcher-card:nth-child(2n) { animation-delay:.035s; }
        :is(#launcher-grid, #launcher-preview-grid).motion-rise .launcher-card:nth-child(3n) { animation-delay:.07s; }
        :is(#launcher-grid, #launcher-preview-grid).motion-glide .launcher-card { animation:launcher-card-glide .38s both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-glide .launcher-card:nth-child(odd) { --launcher-from:18px; }
        :is(#launcher-grid, #launcher-preview-grid).motion-glide .launcher-card:nth-child(even) { --launcher-from:-18px; }
        :is(#launcher-grid, #launcher-preview-grid).motion-fade .launcher-card { animation:launcher-card-fade .32s ease-out both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-bounce .launcher-card { animation:launcher-card-bounce .42s cubic-bezier(.2,.9,.3,1.3) both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-orbit .launcher-card { animation:launcher-card-orbit .62s cubic-bezier(.2,.8,.2,1) both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-cascade .launcher-card { animation:launcher-card-cascade .52s cubic-bezier(.2,.8,.2,1) both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-stagger .launcher-card { animation:launcher-card-stagger .48s ease-out both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-zoom .launcher-card { animation:launcher-card-zoom .36s cubic-bezier(.2,.8,.2,1) both; }
        :is(#launcher-grid, #launcher-preview-grid).motion-cascade .launcher-card:nth-child(4n+2), :is(#launcher-grid, #launcher-preview-grid).motion-stagger .launcher-card:nth-child(4n+2) { animation-delay:.05s; }
        :is(#launcher-grid, #launcher-preview-grid).motion-cascade .launcher-card:nth-child(4n+3), :is(#launcher-grid, #launcher-preview-grid).motion-stagger .launcher-card:nth-child(4n+3) { animation-delay:.1s; }
        :is(#launcher-grid, #launcher-preview-grid).motion-cascade .launcher-card:nth-child(4n+4), :is(#launcher-grid, #launcher-preview-grid).motion-stagger .launcher-card:nth-child(4n+4) { animation-delay:.15s; }
        :is(#launcher-grid, #launcher-preview-grid).theme-solid .launcher-card { background:rgba(8,12,22,.9) !important; backdrop-filter:none; border-color:rgba(148,163,184,.13) !important; }
        :is(#launcher-grid, #launcher-preview-grid).theme-aurora .launcher-card { border-color:rgba(34,211,238,.28) !important; box-shadow:0 8px 28px rgba(34,211,238,.08), inset 0 0 22px rgba(139,92,246,.06); }
        :is(#launcher-grid, #launcher-preview-grid).theme-gradient .launcher-card { background:linear-gradient(145deg,rgba(14,20,36,.88),rgba(35,18,58,.62)) !important; border-color:rgba(167,139,250,.2) !important; }
        @keyframes launcher-card-rise { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        @keyframes launcher-card-glide { from { opacity:0; transform:translateX(var(--launcher-from,18px)); } to { opacity:1; transform:translateX(0); } }
        @keyframes launcher-card-fade { from { opacity:0; } to { opacity:1; } }
        @keyframes launcher-card-bounce { from { opacity:0; transform:translateY(14px) scale(.94); } to { opacity:1; transform:translateY(0) scale(1); } }
        @keyframes launcher-card-orbit { from { opacity:0; transform:rotate(-5deg) scale(.86) translateY(12px); } to { opacity:1; transform:rotate(0) scale(1) translateY(0); } }
        @keyframes launcher-card-cascade { from { opacity:0; transform:translateY(-18px) scale(.97); filter:blur(3px); } to { opacity:1; transform:translateY(0) scale(1); filter:blur(0); } }
        @keyframes launcher-card-stagger { from { opacity:0; transform:translateX(16px) scale(.96); } to { opacity:1; transform:translateX(0) scale(1); } }
        @keyframes launcher-card-zoom { from { opacity:0; transform:scale(.82); } to { opacity:1; transform:scale(1); } }
        @keyframes launcher-icon-pulse { 0%,100% { transform:scale(1); filter:brightness(1); } 50% { transform:scale(1.035); filter:brightness(1.12); } }
        @media (prefers-reduced-motion: reduce) { :is(#launcher-grid, #launcher-preview-grid).motion-rise .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-glide .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-pulse .launcher-card > div:first-child, :is(#launcher-grid, #launcher-preview-grid).motion-fade .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-bounce .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-orbit .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-cascade .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-stagger .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-zoom .launcher-card { animation:none !important; } :is(#launcher-grid, #launcher-preview-grid).motion-float .launcher-card, :is(#launcher-grid, #launcher-preview-grid).motion-tilt .launcher-card { transition:none !important; transform:none !important; } }
        :is(#launcher-grid, #launcher-preview-grid).launcher-vertical { grid-template-columns:minmax(0,1fr); max-width:32rem; }
        :is(#launcher-grid, #launcher-preview-grid).launcher-vertical .launcher-card { aspect-ratio:auto; min-height:74px; display:grid; grid-template-columns:3rem minmax(0,1fr); grid-template-rows:auto auto; justify-items:start; align-content:center; padding:.8rem 1rem; }
        :is(#launcher-grid, #launcher-preview-grid).launcher-vertical .launcher-card > div:first-child { grid-row:1 / span 2; width:2.65rem;height:2.65rem;margin:0 .7rem 0 0; }
        :is(#launcher-grid, #launcher-preview-grid).launcher-vertical .launcher-card > span { width:auto;text-align:left;justify-self:stretch; }
        body.layout-desktop #layer-launcher { padding:2rem clamp(2rem,5vw,6rem); }
        body.layout-desktop #layer-launcher > .w-full.max-w-4xl { max-width:1100px; }
        body.layout-desktop :is(#launcher-grid, #launcher-preview-grid) { grid-template-columns:repeat(4,minmax(0,1fr)); max-width:1100px; gap:1.2rem; }
        body.layout-desktop :is(#launcher-grid, #launcher-preview-grid) .launcher-card { aspect-ratio:1.45/1; }
        body.layout-desktop :is(#launcher-grid, #launcher-preview-grid).launcher-vertical { grid-template-columns:minmax(0,1fr); max-width:760px; }
        body.layout-desktop :is(#launcher-grid, #launcher-preview-grid).launcher-vertical .launcher-card { aspect-ratio:auto; }
        body.layout-desktop .app-layer:not(#layer-launcher) { padding-left:max(24px,calc((100vw - 1120px)/2)) !important; padding-right:max(24px,calc((100vw - 1120px)/2)) !important; }
        @media (max-width:1050px) { body.layout-desktop :is(#launcher-grid, #launcher-preview-grid) { grid-template-columns:repeat(3,minmax(0,1fr)); } }
        @media (max-width:760px) { body.layout-desktop :is(#launcher-grid, #launcher-preview-grid) { grid-template-columns:repeat(2,minmax(0,1fr)); } }

        .app-layer .bg-\[\#0a0f1a\], 
        .app-layer .bg-\[\#02040a\], 
        .glass-premium {
            background-color: rgba(10, 15, 26, 0.60) !important;
            backdrop-filter: blur(8px) !important;
            border: 1px solid rgba(255,255,255,0.05) !important;
        }

        .modal-pop,
        #modal-selector-juegos-content,
        #radar-caja,
        .dropdown-options,
        div.fixed.bottom-0,
        div.fixed.inset-x-0.bottom-0 {
            background-color: #060913 !important; 
            background-image: none !important;
            backdrop-filter: blur(40px) !important;
            -webkit-backdrop-filter: blur(40px) !important;
            box-shadow: 0 -20px 60px rgba(0,0,0,0.95) !important;
            border-top: 1px solid rgba(34, 211, 238, 0.15) !important;
            opacity: 1 !important;
            z-index: 9999 !important;
        }

        .bg-black\/80, .bg-black\/90 {
            background-color: rgba(0, 0, 0, 0.85) !important;
        }

        .scanner-line { width: 100%; height: 2px; background: #10b981; position: absolute; left: 0; top: 0; box-shadow: 0 0 15px #10b981, 0 0 5px #10b981; opacity: 0.8; animation: scan 1.5s linear infinite; z-index: 20; }
        @keyframes scan { 0% { top: 0%; opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { top: 100%; opacity: 0; } }
        .radar-galaxy { position:relative;height:130px;overflow:hidden;border-radius:16px;background:radial-gradient(ellipse at 50% 55%,rgba(16,185,129,.24),rgba(17,24,39,.88) 42%,rgba(5,10,20,.98) 76%);isolation:isolate;transition:transform .9s cubic-bezier(.2,.8,.2,1),filter .7s ease; }
        .radar-galaxy::before,.radar-galaxy::after { content:"";position:absolute;inset:-60%;border-radius:50%;pointer-events:none;background:repeating-conic-gradient(from 0deg,transparent 0deg 16deg,rgba(34,211,238,.09) 17deg 19deg,transparent 20deg 42deg);filter:blur(2px);animation:radar-nebula 22s linear infinite; }
        .radar-galaxy::after { inset:-30%;background:repeating-radial-gradient(circle at 50% 50%,transparent 0 18px,rgba(167,243,208,.11) 19px 20px,transparent 21px 35px);animation-duration:35s;animation-direction:reverse; }
        .radar-orbit { position:absolute;left:50%;top:50%;width:82px;height:82px;border:1px solid rgba(52,211,153,.38);border-radius:50%;transform:translate(-50%,-50%) rotateX(66deg);animation:radar-spin 5s linear infinite; }
        .radar-orbit:nth-child(2){width:126px;height:126px;animation-duration:8s;border-color:rgba(34,211,238,.23)}
        .radar-sun { position:absolute;left:50%;top:50%;width:25px;height:25px;border:1px solid rgba(191,219,254,.72);border-radius:50%;transform:translate(-50%,-50%);background:radial-gradient(circle at 32% 28%,#dbeafe 0 7%,#38bdf8 15%,#2563eb 48%,#102659 78%);box-shadow:0 0 25px rgba(56,189,248,.8),inset -5px -3px 9px rgba(2,6,23,.8); }
        .radar-planet { position:absolute;left:calc(50% + 40px);top:50%;width:11px;height:11px;border-radius:50%;background:#22d3ee;box-shadow:0 0 15px #22d3ee;animation:radar-planet 5s linear infinite;z-index:2;transition:left .85s cubic-bezier(.2,.8,.2,1),top .85s cubic-bezier(.2,.8,.2,1); }
        .radar-galaxy.approach { transform:scale(1.13);filter:brightness(1.16) saturate(1.16); }
        .radar-galaxy.found { transform:scale(1.2);filter:brightness(1.25) saturate(1.2); }
        .radar-galaxy.found .radar-planet { left:50%;top:50%;width:16px;height:16px;background:#fbbf24;box-shadow:0 0 24px #fbbf24;animation:radar-dock .85s cubic-bezier(.2,.8,.2,1) forwards; }
        .radar-stars { position:absolute;inset:0;z-index:1;background-image:radial-gradient(2px 2px at 12% 28%,#a7f3d0 50%,transparent 55%),radial-gradient(1px 1px at 25% 68%,#67e8f9 50%,transparent 55%),radial-gradient(2px 2px at 78% 34%,#d8b4fe 50%,transparent 55%),radial-gradient(1px 1px at 84% 75%,#fff 50%,transparent 55%),radial-gradient(1px 1px at 60% 18%,#fff 50%,transparent 55%);opacity:.75; }
        @keyframes radar-nebula { to { transform:rotate(360deg) scale(1.04); } }
        @keyframes radar-spin { to { transform:translate(-50%,-50%) rotateX(66deg) rotateZ(360deg); } }
        @keyframes radar-planet { to { transform:rotate(360deg) translateX(40px) rotate(-360deg); } }
        @keyframes radar-dock { 0% { transform:translate(-50%,-50%) scale(.75); } 65% { transform:translate(-50%,-50%) scale(1.45); } 100% { transform:translate(-50%,-50%) scale(1); } }
        @media (prefers-reduced-motion: reduce) { .radar-galaxy,.radar-orbit,.radar-planet,.radar-galaxy::before,.radar-galaxy::after { animation:none !important; transition:none !important; } }

        .hide-scrollbar::-webkit-scrollbar { display: none !important; }
        .hide-scrollbar { -ms-overflow-style: none !important; scrollbar-width: none !important; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        html[data-theme="light"] { background: #e8edf5; color: #101827; }
        html[data-theme="light"] body { color: #101827; }
        html[data-theme="light"] .app-layer,
        html[data-theme="light"] .glass-premium,
        html[data-theme="light"] .app-layer .bg-\[\#0a0f1a\],
        html[data-theme="light"] .app-layer .bg-\[\#02040a\] { background-color: rgba(237, 242, 247, .94) !important; }
        html[data-theme="light"] .app-layer .text-white { color: #101827 !important; }
        html[data-theme="light"] .app-layer .text-gray-200,
        html[data-theme="light"] .app-layer .text-gray-300,
        html[data-theme="light"] .app-layer .text-gray-400 { color: #4b5563 !important; }
        .ghm-loading { --loader-color: #67e8f9; --loader-soft: rgba(34,211,238,.13); display:flex; min-height:14rem; max-width:28rem; margin:auto; padding:1.5rem; flex-direction:column; align-items:center; justify-content:center; gap:.65rem; text-align:center; border:1px solid color-mix(in srgb,var(--loader-color) 20%,transparent); border-radius:1.5rem; background:linear-gradient(180deg,var(--loader-soft),transparent); }
        #layer-ajustes.bg-preview-transparent { background:transparent !important; }
        .ghm-loading[data-accent="rose"] { --loader-color:#fda4af; --loader-soft:rgba(251,113,133,.12); }
        .ghm-loading[data-accent="violet"] { --loader-color:#c4b5fd; --loader-soft:rgba(167,139,250,.12); }
        .ghm-loading[data-accent="amber"] { --loader-color:#fcd34d; --loader-soft:rgba(251,191,36,.12); }
        .ghm-loading[data-accent="emerald"] { --loader-color:#6ee7b7; --loader-soft:rgba(52,211,153,.12); }
        .ghm-loader-art { position:relative; display:grid; place-items:center; width:5rem; height:5rem; margin-bottom:.5rem; color:var(--loader-color); }
        .ghm-loader-ring { position:absolute; display:block; inset:.15rem; border:1px solid color-mix(in srgb,var(--loader-color) 25%,transparent); border-radius:50%; }
        .ghm-loader-ring-a { border:2px solid transparent; border-top-color:var(--loader-color); border-right-color:#a78bfa; animation:ghm-loader-spin .95s linear infinite; }
        .ghm-loader-ring-b { inset:.55rem; border-style:dashed; animation:ghm-loader-spin 4s linear infinite reverse; }
        .ghm-loader-ring-c { inset:1rem; border-color:color-mix(in srgb,var(--loader-color) 20%,transparent); }
        .ghm-loader-symbol { z-index:1; font-size:1.35rem; filter:drop-shadow(0 0 9px color-mix(in srgb,var(--loader-color) 70%,transparent)); animation:ghm-loader-breathe 1.6s ease-in-out infinite; }
        .ghm-loader-sweep { display:none; position:absolute; left:0; right:0; top:50%; height:1px; background:var(--loader-color); box-shadow:0 0 12px var(--loader-color); transform-origin:left; animation:ghm-loader-scan 1.5s ease-in-out infinite; }
        .ghm-loader-title { color:var(--loader-color); font-size:.72rem; font-weight:900; letter-spacing:.16em; text-transform:uppercase; }
        .ghm-loader-detail { color:#9ca3af; font-size:.65rem; line-height:1.5; }
        .ghm-loader-track { display:block; width:10rem; height:3px; margin-top:.5rem; overflow:hidden; border-radius:99px; background:rgba(255,255,255,.08); }
        .ghm-loader-track i { display:block; width:35%; height:100%; border-radius:inherit; background:linear-gradient(90deg,var(--loader-color),#a78bfa); animation:ghm-loader-progress 1.6s ease-in-out infinite; }
        .ghm-loader-caption { color:#6b7280; font:700 8px/1.3 ui-monospace,monospace; letter-spacing:.12em; text-transform:uppercase; }
        .ghm-loader-pulse .ghm-loader-ring-a { animation:ghm-loader-ripple 1.7s ease-out infinite; border:1px solid var(--loader-color); }
        .ghm-loader-pulse .ghm-loader-ring-b { animation:ghm-loader-ripple 1.7s ease-out .55s infinite; border:1px solid var(--loader-color); }
        .ghm-loader-pulse .ghm-loader-ring-c { animation:ghm-loader-ripple 1.7s ease-out 1.1s infinite; border:1px solid var(--loader-color); }
        .ghm-loader-scan .ghm-loader-ring-a { inset:.55rem; border-radius:.75rem; transform:rotate(45deg); animation:ghm-loader-spin 2.4s linear infinite; }
        .ghm-loader-scan .ghm-loader-ring-b { inset:1rem; border-style:solid; animation:ghm-loader-spin 3.2s linear infinite reverse; }
        .ghm-loader-scan .ghm-loader-sweep { display:block; }
        .ghm-loader-minimal .ghm-loader-art { width:3.5rem;height:3.5rem; }
        .ghm-loader-minimal .ghm-loader-ring-a { inset:0; border-width:3px; }
        .ghm-loader-minimal .ghm-loader-ring-b,.ghm-loader-minimal .ghm-loader-ring-c,.ghm-loader-minimal .ghm-loader-sweep { display:none; }
        .ghm-loader-minimal .ghm-loader-symbol { font-size:.95rem; }
        .ghm-loader-comet .ghm-loader-ring-a { border-width:3px;border-top-color:transparent;border-right-color:var(--loader-color);animation:ghm-loader-spin .72s cubic-bezier(.25,.7,.4,1) infinite; }
        .ghm-loader-comet .ghm-loader-ring-b { inset:.45rem;border-style:solid;border-top-color:#a78bfa;animation:ghm-loader-spin 2.8s linear infinite reverse; }
        .ghm-loader-matrix .ghm-loader-art { border-radius:1.2rem;background:radial-gradient(circle at 50% 50%,color-mix(in srgb,var(--loader-color) 24%,transparent),transparent 64%); }
        .ghm-loader-matrix .ghm-loader-ring-a,.ghm-loader-matrix .ghm-loader-ring-b,.ghm-loader-matrix .ghm-loader-ring-c { inset:auto;width:7px;height:7px;border:0;border-radius:50%;background:var(--loader-color);box-shadow:0 0 10px var(--loader-color);animation:ghm-loader-matrix-dot 1.3s ease-in-out infinite; }
        .ghm-loader-matrix .ghm-loader-ring-a { left:25%;top:30%; }
        .ghm-loader-matrix .ghm-loader-ring-b { left:75%;top:30%;animation-delay:.18s; }
        .ghm-loader-matrix .ghm-loader-ring-c { left:50%;top:75%;animation-delay:.36s; }
        .ghm-loader-matrix .ghm-loader-symbol { font-size:1rem; }
        .ghm-loader-prism .ghm-loader-ring-a { border-width:3px;border-color:transparent #a78bfa var(--loader-color) #67e8f9;animation:ghm-loader-spin 2.2s linear infinite; }
        .ghm-loader-prism .ghm-loader-ring-b { inset:.42rem;border-width:2px;border-color:#f0abfc transparent #67e8f9 transparent;animation:ghm-loader-spin 1.8s linear infinite reverse; }
        .ghm-loader-prism .ghm-loader-symbol { filter:drop-shadow(0 0 12px #a78bfa); }
        .ghm-loader-nova .ghm-loader-ring-a { animation:ghm-loader-ripple 1.25s ease-out infinite;border:1px solid var(--loader-color); }
        .ghm-loader-nova .ghm-loader-ring-b { animation:ghm-loader-ripple 1.25s ease-out .4s infinite;border:1px solid #a78bfa; }
        .ghm-loader-nova .ghm-loader-ring-c { animation:ghm-loader-ripple 1.25s ease-out .8s infinite;border:1px solid #67e8f9; }
        .ghm-loader-warp .ghm-loader-ring-a { border-width:3px;border-color:transparent var(--loader-color) #a78bfa transparent;animation:ghm-loader-spin .48s linear infinite; }
        .ghm-loader-warp .ghm-loader-ring-b { inset:.5rem;border-style:solid;border-color:#67e8f9 transparent transparent #a78bfa;animation:ghm-loader-spin 1.15s linear infinite reverse; }
        .ghm-loader-warp .ghm-loader-ring-c { inset:1rem;border-style:dotted;animation:ghm-loader-spin 3s linear infinite; }
        .ghm-loader-vortex .ghm-loader-ring-a { border-width:2px;border-color:transparent var(--loader-color) #a78bfa transparent;animation:ghm-loader-spin .85s cubic-bezier(.55,.05,.35,.95) infinite; }
        .ghm-loader-vortex .ghm-loader-ring-b { inset:.4rem;border-style:solid;border-color:#67e8f9 transparent var(--loader-color) transparent;animation:ghm-loader-spin 1.3s linear infinite reverse; }
        .ghm-loader-vortex .ghm-loader-ring-c { inset:1.1rem;border-width:2px;border-color:transparent #f0abfc transparent var(--loader-color);animation:ghm-loader-spin 2s linear infinite; }
        .ghm-loader-eclipse .ghm-loader-ring-a { inset:.2rem;border-width:3px;border-color:var(--loader-color) transparent #a78bfa transparent;animation:ghm-loader-spin 2s linear infinite; }
        .ghm-loader-eclipse .ghm-loader-ring-b { inset:.55rem;border-width:5px;border-color:transparent color-mix(in srgb,var(--loader-color) 48%,transparent) transparent transparent;animation:ghm-loader-spin 1.5s linear infinite reverse; }
        .ghm-loader-eclipse .ghm-loader-ring-c { inset:1.05rem;background:radial-gradient(circle,color-mix(in srgb,var(--loader-color) 30%,transparent),transparent 70%); }
        .ghm-loader-signal .ghm-loader-ring-a { inset:.4rem;border-width:2px;border-color:var(--loader-color);animation:ghm-loader-ripple 1.4s ease-out infinite; }
        .ghm-loader-signal .ghm-loader-ring-b { inset:.75rem;border-style:solid;border-color:color-mix(in srgb,var(--loader-color) 72%,transparent);animation:ghm-loader-ripple 1.4s ease-out .45s infinite; }
        .ghm-loader-signal .ghm-loader-ring-c { inset:1.1rem;border:0;border-radius:50%;background:var(--loader-color);box-shadow:0 0 14px var(--loader-color);animation:ghm-loader-breathe .8s ease-in-out infinite; }
        .ghm-loader-holo .ghm-loader-art { border-radius:1.2rem;background:linear-gradient(135deg,color-mix(in srgb,var(--loader-color) 16%,transparent),transparent 60%); }
        .ghm-loader-holo .ghm-loader-ring-a { border-radius:1rem;border-style:dashed;animation:ghm-loader-scan 1.25s ease-in-out infinite alternate; }
        .ghm-loader-holo .ghm-loader-ring-b { inset:.5rem;border-radius:.75rem;border-style:solid;animation:ghm-loader-breathe 1.1s ease-in-out infinite; }
        .ghm-loader-holo .ghm-loader-sweep { display:block; }
        @keyframes ghm-loader-spin { to { transform:rotate(360deg); } }
        @keyframes ghm-loader-breathe { 50% { transform:scale(1.12);opacity:.72; } }
        @keyframes ghm-loader-ripple { 0% { transform:scale(.5);opacity:.8; } 100% { transform:scale(1.45);opacity:0; } }
        @keyframes ghm-loader-scan { 0%,100% { transform:scaleX(.2);opacity:.35; } 50% { transform:scaleX(1);opacity:1; } }
        @keyframes ghm-loader-progress { 0% { transform:translateX(-110%); } 100% { transform:translateX(320%); } }
        @keyframes ghm-loader-matrix-dot { 0%,100% { opacity:.25;transform:scale(.65); } 50% { opacity:1;transform:scale(1.2); } }
        .ghm-loader-quantum .ghm-loader-ring-a { inset:.25rem;border-width:3px;border-color:var(--loader-color) transparent #f0abfc transparent;animation:ghm-loader-spin .72s linear infinite; }
        .ghm-loader-quantum .ghm-loader-ring-b { inset:.65rem;border-style:dashed;border-color:#67e8f9 var(--loader-color) transparent #67e8f9;animation:ghm-loader-spin 1.1s linear infinite reverse; }
        .ghm-loader-quantum .ghm-loader-ring-c { inset:1.18rem;border-width:2px;border-color:transparent var(--loader-color) #f0abfc transparent;animation:ghm-loader-spin .8s linear infinite; }
        .ghm-loader-helix .ghm-loader-ring-a { inset:.25rem;border-width:2px;border-color:var(--loader-color);animation:ghm-loader-spin 1.2s ease-in-out infinite; }
        .ghm-loader-helix .ghm-loader-ring-b { inset:.55rem;border-width:2px;border-color:transparent #f0abfc var(--loader-color) #67e8f9;animation:ghm-loader-spin .9s linear infinite reverse; }
        .ghm-loader-helix .ghm-loader-ring-c { inset:.9rem;border-width:2px;border-color:#67e8f9 transparent var(--loader-color) transparent;animation:ghm-loader-spin .65s linear infinite; }
        .ghm-loader-portal .ghm-loader-ring-a { inset:.2rem;border-width:4px;border-color:var(--loader-color) transparent #a78bfa transparent;animation:ghm-loader-spin 1.7s cubic-bezier(.6,.05,.4,.95) infinite; }
        .ghm-loader-portal .ghm-loader-ring-b { inset:.7rem;border-width:3px;border-color:transparent #f0abfc var(--loader-color) #67e8f9;animation:ghm-loader-spin 1s linear infinite reverse; }
        .ghm-loader-portal .ghm-loader-ring-c { inset:1.25rem;border:0;border-radius:50%;background:radial-gradient(circle,var(--loader-color),transparent 72%);animation:ghm-loader-breathe 1s ease-in-out infinite; }
        @media (prefers-reduced-motion: reduce) { .ghm-loading *, .ghm-loader-track i { animation:none !important; } .ghm-loader-track i { width:35%; transform:none !important; } }
    </style>
</head>
<body class="text-white select-none hide-scrollbar bg-radial-glow" data-ghm-runtime="<?= htmlspecialchars($ghm_runtime, ENT_QUOTES, 'UTF-8') ?>">

    <?php include 'modulos/wallpapers.php'; ?>
    <div id="intro-wrapper" class="fixed inset-0 z-[9999] bg-[#05050a] flex items-center justify-center">
        <?php include 'modulos/intros.php'; ?>
    </div>

    <div id="layer-launcher" class="app-layer active flex flex-col justify-between p-5 h-screen w-full overflow-y-auto hide-scrollbar z-10">
        
        <div class="text-center w-full pt-1 shrink-0 z-10 flex flex-col items-center">
            <div class="flex items-center justify-center gap-2 mb-1.5 hover:opacity-100 transition-opacity">
                <span class="text-[8px] font-black tracking-[0.25em] text-gray-400 uppercase">PS4 MANAGER TOOL</span>
                <i class="fa-solid fa-gamepad text-cyan-400 text-[10px] opacity-70"></i>
            </div>
            <h1 class="text-4xl font-black tracking-tighter text-transparent bg-clip-text bg-gradient-to-b from-[#ffffff] to-[#9aa0ab] uppercase leading-none drop-shadow-md">
                PS4 Manager Tools
            </h1>
            <div class="flex items-center justify-center gap-3 mt-2.5 opacity-80">
                <span class="h-[1px] w-6 bg-gradient-to-r from-transparent to-cyan-500/80"></span>
                <span class="text-[9px] font-mono tracking-[0.4em] font-bold text-cyan-400">VERSION 4.5.12 • AJ</span>
                <span class="h-[1px] w-6 bg-gradient-to-l from-transparent to-cyan-500/80"></span>
            </div>
        </div>

        <div class="launcher-connection-panel w-full max-w-4xl mx-auto z-20 mt-6 mb-6 shrink-0">
            <button onclick="abrirBusquedaGlobal()" class="mb-3 flex w-full items-center gap-3 rounded-2xl border border-violet-500/20 bg-violet-500/5 px-4 py-3 text-left active:scale-[.99] transition-all hover:bg-violet-500/10">
                <i class="fa-solid fa-magnifying-glass text-violet-300"></i><span class="flex-1 text-[10px] font-bold tracking-wide text-violet-100">Buscar en PS4 Manager Tools…</span><span class="text-[8px] uppercase tracking-widest text-violet-300/70">Global</span>
            </button>
            <div class="glass-premium p-4 rounded-[2rem] flex flex-col gap-3 shadow-2xl">
                
                <div class="flex items-center justify-between px-1 w-full">
                    <div class="flex items-center gap-2">
                        <div id="connection-ping-indicator" class="w-2 h-2 bg-red-500 rounded-full shadow-[0_0_8px_#ef4444]"></div>
                        <span class="text-[9px] font-black tracking-widest text-gray-400 uppercase">Centro de conexión PS4</span>
                    </div>
                    <span class="text-[9px] font-mono text-red-400 font-bold uppercase" id="console-status-label">Desconectado</span>
                </div>

                <div class="flex flex-col gap-2.5 w-full mt-1">
                    <div class="flex gap-2 items-center w-full">
                    <div class="relative flex-grow h-11 bg-black/60 border border-white/10 rounded-xl focus-within:border-cyan-400 overflow-hidden shadow-inner">
                        <i class="fa-solid fa-network-wired absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-600 text-[10px]"></i>
                        <input type="text" id="ps-ip-full-input" value="192.168." placeholder="192.168.xxx.xxx" class="w-full h-full bg-transparent pl-8 pr-1 text-[13px] font-mono font-bold tracking-wider text-white outline-none">
                    </div>

                    <div class="flex items-center w-24 bg-black/60 border border-white/10 rounded-xl h-11 overflow-hidden focus-within:border-cyan-400 shrink-0">
                        <input type="number" id="ps-port-input" value="2121" class="w-full h-full bg-transparent text-[11px] font-mono font-bold text-gray-300 outline-none text-center [appearance:textfield]">
                    </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 w-full">
                        <button onclick="conectarIPManualValidando()" class="h-10 rounded-xl bg-cyan-600/10 border border-cyan-500/20 flex items-center justify-center gap-2 text-cyan-300 active:scale-95 transition-all hover:bg-cyan-600/20 text-[10px] font-black uppercase tracking-widest"><i class="fa-solid fa-link text-[11px]"></i> Conectar</button>
                        <button onclick="lanzarRadarVentanaEmergente()" class="h-10 rounded-xl bg-cyan-600/20 border border-cyan-500/30 flex items-center justify-center gap-2 text-cyan-300 font-bold text-[10px] tracking-wider active:scale-95 transition-all hover:bg-cyan-600/30"><i class="fa-solid fa-satellite-dish" id="radar-icon-loop"></i> Radar de red</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="launcher-grid" class="grid grid-cols-2 gap-5 max-w-sm mx-auto w-full my-auto z-10 shrink-0 px-1">
            <p class="launcher-section-label">Biblioteca y datos</p>
            <div onclick="abrirModulo('biblioteca')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-cyan-900/30 text-cyan-400 flex items-center justify-center mb-3 border border-cyan-500/10"><i class="fa-solid fa-gamepad text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Biblioteca</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Juegos e Iconos</span></div>
            <div onclick="abrirModulo('savegames')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-cyan-900/30 text-cyan-300 flex items-center justify-center mb-3 border border-cyan-500/10"><i class="fa-solid fa-floppy-disk text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">SaveGames</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Partidas y respaldos</span></div>
            <div onclick="abrirModuloNativo('limpieza')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-rose-900/30 text-rose-300 flex items-center justify-center mb-3 border border-rose-500/10"><i class="fa-solid fa-broom text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Limpieza</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Partidas sin juego instalado</span></div>
            <div onclick="abrirModuloNativo('almacenamiento')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-emerald-900/30 text-emerald-300 flex items-center justify-center mb-3 border border-emerald-500/10"><i class="fa-solid fa-chart-pie text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Almacenamiento</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Uso por categoría</span></div>
            <div onclick="abrirModuloNativo('capturas')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-pink-900/30 text-pink-300 flex items-center justify-center mb-3 border border-pink-500/10"><i class="fa-solid fa-images text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Capturas</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Ver, guardar y eliminar</span></div>
            <p class="launcher-section-label">Juegos y organización</p>
            <div onclick="abrirModuloNativo('parches-juego')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-violet-900/30 text-violet-300 flex items-center justify-center mb-3 border border-violet-500/10"><i class="fa-solid fa-wand-magic-sparkles text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Parches de juego</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Estados GoldHEN</span></div>
            <div onclick="abrirModulo('organizador-apps')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-fuchsia-900/30 text-fuchsia-300 flex items-center justify-center mb-3 border border-fuchsia-500/10"><i class="fa-solid fa-layer-group text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Organizar Apps</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Sistema y carpetas</span></div>
            <div onclick="abrirModulo('explorador')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-emerald-900/30 text-emerald-400 flex items-center justify-center mb-3 border border-emerald-500/10"><i class="fa-solid fa-folder-open text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Explorador FTP</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Raíz Consola</span></div>
            <div onclick="abrirModulo('modding')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-purple-900/30 text-purple-400 flex items-center justify-center mb-3 border border-purple-500/10"><i class="fa-solid fa-wand-magic-sparkles text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Modding</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Inyectar Portadas</span></div>
            <p class="launcher-section-label">Consola, red y ajustes</p>
            <div onclick="abrirModulo('transferir')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-amber-900/30 text-amber-400 flex items-center justify-center mb-3 border border-amber-500/10"><i class="fa-solid fa-cloud-arrow-up text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Transferencias</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Chunks de PKG</span></div>
            <div onclick="abrirModuloNativo('mods')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-indigo-900/30 text-indigo-400 flex items-center justify-center mb-3 border border-indigo-500/10"><i class="fa-solid fa-cubes text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Game Mods</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Trucos y Parches</span></div>
            <div onclick="abrirModulo('plugins')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-violet-900/30 text-violet-400 flex items-center justify-center mb-3 border border-violet-500/10"><i class="fa-solid fa-plug text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Plugins</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">GoldHEN Loader</span></div>
            <div onclick="abrirModulo('payloads')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-amber-900/30 text-amber-400 flex items-center justify-center mb-3 border border-amber-500/10"><i class="fa-solid fa-rocket text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Payloads</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">BinLoader 9090</span></div>
            <div onclick="abrirModulo('coolers')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-cyan-900/30 text-cyan-300 flex items-center justify-center mb-3 border border-cyan-500/10"><i class="fa-solid fa-fan text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Ventilador</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Control de temperatura</span></div>
            <div onclick="abrirModulo('actualizaciones-fw')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-rose-900/30 text-rose-300 flex items-center justify-center mb-3 border border-rose-500/10"><i class="fa-solid fa-shield-halved text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Actualizaciones FW</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Permitir o bloquear</span></div>
            <div onclick="abrirModulo('backup')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-emerald-900/30 text-emerald-300 flex items-center justify-center mb-3 border border-emerald-500/10"><i class="fa-solid fa-box-archive text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Respaldo</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Migrar Configuración</span></div>
            <div onclick="window.location.href='store/store.php'" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-cyan-900/30 text-cyan-300 flex items-center justify-center mb-3 border border-cyan-500/10"><i class="fa-solid fa-store text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Store</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Catálogo autorizado</span></div>
            <div onclick="abrirModulo('ajustes')" class="launcher-card p-5 rounded-[2rem] flex flex-col items-center justify-center cursor-pointer aspect-square"><div class="w-12 h-12 rounded-[1rem] bg-gray-700/30 text-gray-300 flex items-center justify-center mb-3 border border-gray-500/10"><i class="fa-solid fa-sliders text-xl"></i></div><span class="text-xs font-black tracking-wider uppercase text-white">Ajustes</span><span class="text-[8px] font-bold tracking-widest text-gray-500 uppercase mt-1">Live BGs e Intros</span></div>
        </div>

        <div class="w-full text-center pt-2 pb-1 shrink-0 text-[9px] tracking-widest font-mono uppercase text-cyan-300/70">PS4 Manager Tools <span class="text-gray-500">· Base de SeBaS</span></div>
    </div>

    <div id="modal-busqueda-global" class="fixed inset-0 z-[10060] hidden items-start justify-center bg-black/75 backdrop-blur-sm p-4 pt-[12dvh]" onclick="if(event.target===this)cerrarBusquedaGlobal()">
        <div class="w-full max-w-xl rounded-3xl border border-violet-500/25 bg-[#0a0f1a] p-4 shadow-2xl">
            <div class="flex items-center gap-2"><i class="fa-solid fa-magnifying-glass text-violet-300"></i><input id="global-search-input" oninput="ejecutarBusquedaGlobal()" placeholder="Buscar módulos, juegos sincronizados, archivos actuales, plugins o payloads…" class="flex-1 bg-transparent py-3 text-[12px] text-white outline-none"><button onclick="cerrarBusquedaGlobal()" class="w-9 h-9 rounded-xl bg-white/5 text-gray-300"><i class="fa-solid fa-xmark"></i></button></div>
            <p class="border-t border-white/5 pt-2 text-[9px] text-gray-500">Los juegos requieren una Biblioteca sincronizada y los archivos corresponden a la carpeta FTP abierta.</p>
            <div id="global-search-results" class="mt-3 max-h-[60dvh] overflow-y-auto space-y-3"></div>
        </div>
    </div>

    <div id="modal-radar-emergente" class="fixed inset-0 z-[150] bg-black/90 backdrop-blur-sm hidden flex items-center justify-center p-6 opacity-0 transition-opacity duration-300">
        <div id="radar-caja" class="glass-premium w-full max-w-sm rounded-[2rem] p-6 shadow-[0_0_60px_rgba(16,185,129,0.15)] flex flex-col modal-pop scale-90 border-emerald-500/30">
            
            <div class="flex justify-between items-center mb-4 border-b border-emerald-900/50 pb-3">
                <div class="flex items-center gap-3">
                    <i class="fa-solid fa-satellite-dish text-emerald-400 animate-pulse text-lg"></i>
                    <div>
                        <h3 class="text-xs font-black tracking-widest uppercase text-white">RADAR DE RED</h3>
                        <p class="text-[9px] text-emerald-700 font-mono" id="radar-subnet-txt">ANALYZING.SUBNETS</p>
                    </div>
                </div>
                <button onclick="abortarYEstabilizarRadar()" class="w-8 h-8 rounded-full bg-emerald-950/30 flex items-center justify-center text-emerald-600 active:bg-red-900/50 active:text-red-500 transition-colors">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div id="radar-galaxy" class="radar-galaxy mb-3"><div class="radar-stars"></div><div class="radar-orbit"></div><div class="radar-orbit"></div><div class="radar-sun"></div><div class="radar-planet"></div><span class="absolute bottom-2 left-0 right-0 z-10 text-center text-[7px] tracking-[.35em] text-emerald-200/70">BUSCANDO PS4 EN LA RED</span></div>
            <div class="relative bg-black rounded-xl p-3 h-40 overflow-hidden font-mono text-[9px] border border-emerald-900/50 shadow-inner">
                <div class="scanner-line"></div>
                <div id="radar-log-terminal" class="absolute inset-0 p-3 overflow-y-auto custom-scrollbar flex flex-col gap-1 text-emerald-500 z-10"></div>
            </div>
            
            <div class="mt-4 text-center text-[8px] font-mono tracking-widest text-emerald-800 uppercase flex items-center justify-center gap-2">
                <div class="w-1.5 h-1.5 bg-emerald-600 rounded-full animate-ping"></div>
                ESCÁNER LAN · SERVICIOS GOLDHEN
            </div>
        </div>
    </div>

    <div id="modal-fw-risk" class="fixed inset-0 z-[20000] hidden items-center justify-center bg-black/85 p-5 backdrop-blur-md" role="dialog" aria-modal="true" aria-labelledby="fw-risk-title">
        <div class="w-full max-w-lg rounded-3xl border border-amber-400/30 bg-[#0a0f1a] p-6 shadow-2xl">
            <div class="mb-4 flex items-center gap-3"><i class="fa-solid fa-triangle-exclamation text-amber-400 text-xl"></i><h2 id="fw-risk-title" class="text-sm font-black uppercase tracking-widest text-amber-200">Advertencia de firmware</h2></div>
            <p class="text-sm leading-relaxed text-gray-200">Este módulo puede dañar tu consola si eliges el archivo que NO CORRESPONDA a tu versión de PS4. ¿Entiendes los riesgos y aceptas usarlo bajo tu responsabilidad?</p>
            <p class="mt-3 text-[10px] leading-relaxed text-gray-400">Verifica el firmware exacto antes de enviar cualquier payload. La aceptación se guardará en este navegador para no repetir el aviso en cada visita.</p>
            <div class="mt-6 grid grid-cols-2 gap-3"><button onclick="cancelarAccesoFirmware()" class="rounded-xl border border-white/10 bg-white/5 py-3 text-[10px] font-black uppercase text-gray-300">Cancelar</button><button onclick="aceptarAccesoFirmware()" class="rounded-xl bg-amber-400 py-3 text-[10px] font-black uppercase text-black">Entiendo y acepto</button></div>
        </div>
    </div>

    <?php include 'modulos/biblioteca.php'; ?>
    <?php include 'modulos/modding.php'; ?>
    <?php include 'modulos/explorador.php'; ?>
    <?php include 'modulos/transferir.php'; ?>
    <?php include 'modulos/mods.php'; ?>
    <?php include 'modulos/ajustes.php'; ?>
    <?php include 'modulos/plugins.php'; ?>
    <?php include 'modulos/payloads.php'; ?>
    <?php include 'modulos/coolers.php'; ?>
    <?php include 'modulos/actualizaciones_fw.php'; ?>
    <?php include 'modulos/backup.php'; ?>
    <?php include 'modulos/organizador_apps.php'; ?>
    <?php include 'modulos/saves.php'; ?>
    <?php include 'modulos/limpieza.php'; ?>
    <?php include 'modulos/almacenamiento.php'; ?>
    <?php include 'modulos/capturas.php'; ?>
    <?php include 'modulos/parches_juego.php'; ?>

    <script src="<?= app_asset_url('js/app.js') ?>"></script>
    <script src="<?= app_asset_url('js/android_storage.js') ?>"></script>
    <script src="<?= app_asset_url('js/loaders.js') ?>"></script>
    <script src="<?= app_asset_url('js/biblioteca.js') ?>"></script>
    <script src="<?= app_asset_url('js/modding.js') ?>"></script>
    <script src="<?= app_asset_url('js/explorador.js') ?>"></script>
    <script src="<?= app_asset_url('js/transferir.js') ?>"></script>
    <script src="<?= app_asset_url('js/mods.js') ?>"></script>
    <script src="<?= app_asset_url('js/afr_mods.js') ?>"></script>
    <script src="<?= app_asset_url('js/plugins.js') ?>"></script>
    <script src="<?= app_asset_url('js/backup.js') ?>"></script>
    <script src="<?= app_asset_url('js/payloads.js') ?>"></script>
    <script src="<?= app_asset_url('js/coolers.js') ?>"></script>
    <script src="<?= app_asset_url('js/actualizaciones_fw.js') ?>"></script>
    <script src="<?= app_asset_url('js/organizador_apps.js') ?>"></script>
    <script src="<?= app_asset_url('js/saves.js') ?>"></script>
    <script src="<?= app_asset_url('js/limpieza.js') ?>"></script>
    <script src="<?= app_asset_url('js/almacenamiento.js') ?>"></script>
    <script src="<?= app_asset_url('js/capturas.js') ?>"></script>
    <script src="<?= app_asset_url('js/parches_juego.js') ?>"></script>
    <script src="<?= app_asset_url('js/i18n.js') ?>"></script>

    <script>
        history.replaceState({ page: 'launcher' }, "Launcher", "");

        let moduloFirmwarePendiente = null;
        const aceptarRiesgosFirmware = { coolers: 'ghm_fw_risk_fan_accepted', 'actualizaciones-fw': 'ghm_fw_risk_updates_accepted' };

        window.abrirModuloNativo = function(idModulo) {
            const storageKey = aceptarRiesgosFirmware[idModulo];
            if (storageKey && localStorage.getItem(storageKey) !== 'true') {
                moduloFirmwarePendiente = idModulo;
                const warning = document.getElementById('modal-fw-risk');
                warning.classList.remove('hidden'); warning.classList.add('flex');
                return;
            }
            history.pushState({ page: idModulo, ruta: '/' }, "Modulo", "");
            activarCapaVisual(idModulo);

            if (idModulo === 'explorador' && typeof cargarRutaFtp === 'function') {
                cargarRutaFtp('/', true); 
                if (typeof renderizarAccesosRapidos === 'function') renderizarAccesosRapidos();
            }
            if (idModulo === 'organizador-apps' && typeof organizadorCargar === 'function') organizadorCargar();
                    if (idModulo === 'savegames' && typeof savesRecargar === 'function') savesRecargar();
                    if (idModulo === 'limpieza' && typeof limpiezaCargar === 'function') limpiezaCargar();
                    if (idModulo === 'almacenamiento' && typeof almacenamientoCargar === 'function') almacenamientoCargar();
                    if (idModulo === 'capturas' && typeof capturasCargar === 'function') capturasCargar(true);
        };

        window.aceptarAccesoFirmware = function() {
            if (!moduloFirmwarePendiente) return;
            const moduleId = moduloFirmwarePendiente;
            localStorage.setItem(aceptarRiesgosFirmware[moduleId], 'true');
            moduloFirmwarePendiente = null;
            const warning = document.getElementById('modal-fw-risk');
            warning.classList.add('hidden'); warning.classList.remove('flex');
            abrirModuloNativo(moduleId);
        };
        window.cancelarAccesoFirmware = function() {
            moduloFirmwarePendiente = null;
            const warning = document.getElementById('modal-fw-risk');
            warning.classList.add('hidden'); warning.classList.remove('flex');
        };

        function activarCapaVisual(idModulo) {
            document.querySelectorAll('.app-layer').forEach(layer => {
                layer.classList.remove('active', 'flex');
                layer.classList.add('hidden');
            });
            
            const target = document.getElementById('layer-' + idModulo);
            if (target) {
                target.classList.remove('hidden');
                setTimeout(() => { target.classList.add('active', 'flex');
                    if (idModulo === 'plugins' && typeof pluginsRecargar === 'function') pluginsRecargar();
                    if (idModulo === 'payloads' && typeof payloadsRecargar === 'function') payloadsRecargar();
                    if (idModulo === 'parches-juego' && typeof parchesRecargar === 'function') parchesRecargar();
                }, 10);
            }
        }

        window.addEventListener('popstate', function(event) {
            let modals = Array.from(document.querySelectorAll('.fixed.inset-0:not(.hidden)'));
            modals = modals.filter(m => m.style.display !== 'none' && m.id !== 'layer-launcher' && !m.classList.contains('app-layer') && m.id !== 'intro-wrapper');
            
            if (modals.length > 0) {
                history.pushState(event.state, "", ""); 
                let topModal = modals[modals.length - 1];
                let closeBtn = topModal.querySelector('[onclick*="cerrar"], [onclick*="cancelar"]');
                
                if (closeBtn) { closeBtn.click(); } else {
                    topModal.classList.remove('opacity-100');
                    setTimeout(() => topModal.classList.add('hidden'), 300);
                }
                return; 
            }

            if (event.state && event.state.page) {
                if (event.state.page === 'launcher') {
                    activarCapaVisual('launcher');
                } else if (event.state.page === 'explorador' || event.state.page === 'ftp_folder') {
                    // El botón de volver del Explorador siempre regresa al launcher.
                    // La navegación entre directorios se controla con la flecha "arriba".
                    if (event.state.page === 'ftp_folder') {
                        history.replaceState({ page: 'launcher' }, 'Launcher', '');
                        activarCapaVisual('launcher');
                        return;
                    }
                    activarCapaVisual('explorador');
                    let rutaDestino = event.state.ruta || '/';
                    if (typeof cargarRutaFtp === 'function') {
                        cargarRutaFtp(rutaDestino, true); 
                    }
                } else {
                    activarCapaVisual(event.state.page);
                }
            } else {
                activarCapaVisual('launcher');
            }
        });

        window.volverAlLauncher = function() {
            if (typeof cancelarPrevisualizacionVisual === 'function') cancelarPrevisualizacionVisual();
            history.replaceState({ page: 'launcher' }, 'Launcher', '');
            activarCapaVisual('launcher');
        };
    </script>
</body>
</html>
