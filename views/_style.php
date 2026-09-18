<?php

/**
 * MOS-GOV presentation-layer design system (shared CSS).
 *
 * A single, framework-free stylesheet that every MOS-GOV view requires after
 * Header.php. It gives all governance pages one quiet, professional visual
 * language on top of the ChurchCRM (Tabler/Bootstrap 5) look:
 *
 *   - .mg-nav        compact grouped section navigation
 *   - .mg-card       unified card (light border, soft shadow, rounded)
 *   - .mg-summary    "我的治理摘要" hero
 *   - .mg-item       list entry (title + stacked meta lines + trailing chip)
 *   - .mg-chip       status / capability chip
 *   - .mg-task       task work-area entry with a status accent border
 *   - .mg-empty      friendly empty state
 *
 * No external fonts, CDN or UI framework is introduced; everything renders
 * offline. Presentation only — no data, permission or scope logic.
 */
?>
<style>
    /* ---------- tokens ---------- */
    .mos-gov {
        --mg-border: rgba(98, 105, 118, .16);
        --mg-border-soft: rgba(98, 105, 118, .10);
        --mg-muted: #667382;
        --mg-blue: #206bc4;
        --mg-blue-bg: #e9f2ff;
        --mg-green: #2fb344;
        --mg-green-bg: #ebf9ef;
        --mg-orange: #f76707;
        --mg-orange-bg: #fff3e6;
        --mg-red: #d63939;
        --mg-red-bg: #fdeef0;
        --mg-radius: 12px;
    }

    .mos-gov .mg-card {
        border: 1px solid var(--mg-border);
        border-radius: var(--mg-radius);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        background: #fff;
        overflow: hidden;
    }
    .mos-gov .mg-card > .card-header,
    .mos-gov .mg-card-head {
        background: #fafbfc;
        border-bottom: 1px solid var(--mg-border-soft);
        padding: .85rem 1.05rem;
    }
    .mos-gov .mg-card > .card-body {
        padding: 1.05rem;
    }
    .mos-gov .mg-sec-title {
        display: flex;
        align-items: center;
        gap: .6rem;
        margin: 0;
        font-size: .98rem;
        font-weight: 700;
        color: #1f2d3d;
    }
    .mos-gov .mg-no {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.45rem;
        height: 1.45rem;
        padding: 0 .3rem;
        border-radius: 999px;
        background: var(--mg-blue-bg);
        color: var(--mg-blue);
        font-size: .74rem;
        font-weight: 700;
    }
    .mos-gov .mg-sec-desc {
        margin: .35rem 0 0 2.05rem;
        color: var(--mg-muted);
        font-size: .8rem;
    }

    /* ---------- summary hero ---------- */
    .mos-gov .mg-summary {
        border: 1px solid rgba(32, 107, 196, .16);
        background: #f7fbff;
        border-radius: var(--mg-radius);
        padding: 1.05rem 1.15rem;
        margin-bottom: 1.1rem;
    }
    .mos-gov .mg-summary-name {
        font-size: 1.28rem;
        font-weight: 700;
        line-height: 1.35;
    }
    .mos-gov .mg-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .7rem;
        margin-top: .9rem;
    }
    .mos-gov .mg-stat {
        background: rgba(255, 255, 255, .9);
        border: 1px solid var(--mg-border-soft);
        border-radius: 10px;
        padding: .7rem .85rem;
        display: block;
        color: inherit;
        text-decoration: none;
    }
    a.mg-stat:hover {
        border-color: rgba(32, 107, 196, .35);
    }
    .mos-gov .mg-stat-label {
        color: var(--mg-muted);
        font-size: .78rem;
        margin-bottom: .15rem;
    }
    .mos-gov .mg-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .mos-gov .mg-stat-value small {
        font-size: .78rem;
        font-weight: 500;
        color: var(--mg-muted);
        margin-left: .2rem;
    }
    .mos-gov .mg-stat-todo .mg-stat-value {
        color: var(--mg-orange);
    }

    /* ---------- list entries ---------- */
    .mos-gov .mg-list {
        display: grid;
        gap: .6rem;
    }
    .mos-gov .mg-item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .9rem;
        padding: .7rem .85rem;
        border: 1px solid var(--mg-border-soft);
        border-radius: 10px;
        background: #fff;
    }
    .mos-gov .mg-item-main {
        min-width: 0;
    }
    .mos-gov .mg-item-title {
        font-weight: 600;
        line-height: 1.45;
        color: #1f2d3d;
        overflow-wrap: anywhere;
    }
    .mos-gov .mg-item-title a {
        color: inherit;
        text-decoration: none;
    }
    .mos-gov .mg-item-title a:hover {
        color: var(--mg-blue);
        text-decoration: underline;
    }
    .mos-gov .mg-meta-line {
        margin-top: .2rem;
        color: var(--mg-muted);
        font-size: .8rem;
        line-height: 1.5;
    }
    .mos-gov .mg-internal {
        color: #9aa2ad;
        font-size: .74rem;
    }
    .mos-gov .mg-internal code {
        color: inherit;
        background: #f4f6f8;
        padding: 0 .3rem;
        border-radius: 4px;
    }

    /* ---------- chips ---------- */
    .mos-gov .mg-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        align-items: center;
    }
    .mos-gov .mg-chip {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .26rem .55rem;
        border-radius: 999px;
        font-size: .76rem;
        line-height: 1.35;
        white-space: nowrap;
    }
    .mos-gov .mg-chip-blue  { background: var(--mg-blue-bg);   color: var(--mg-blue); }
    .mos-gov .mg-chip-green { background: var(--mg-green-bg);  color: #237a37; }
    .mos-gov .mg-chip-orange{ background: var(--mg-orange-bg); color: #b35400; }
    .mos-gov .mg-chip-red   { background: var(--mg-red-bg);    color: var(--mg-red); }
    .mos-gov .mg-chip-gray  { background: #f1f3f5;             color: #52606d; }

    /* ---------- permission capability groups ---------- */
    .mos-gov .mg-cap-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: .6rem;
    }
    .mos-gov .mg-cap {
        border: 1px solid var(--mg-border-soft);
        border-radius: 10px;
        padding: .7rem .8rem;
        background: #fff;
    }
    .mos-gov .mg-cap-name {
        font-weight: 700;
        font-size: .86rem;
        margin-bottom: .45rem;
        color: #1f2d3d;
    }

    /* ---------- task work area ---------- */
    .mos-gov .mg-task-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
        gap: .6rem;
    }
    .mos-gov .mg-task {
        position: relative;
        border: 1px solid var(--mg-border-soft);
        border-left: 3px solid #cdd4dc;
        border-radius: 10px;
        padding: .7rem .85rem .7rem 1rem;
        background: #fff;
    }
    .mos-gov .mg-task--open     { border-left-color: var(--mg-orange); background: #fffdf9; }
    .mos-gov .mg-task--progress { border-left-color: var(--mg-blue); }
    .mos-gov .mg-task--done     { border-left-color: var(--mg-green); opacity: .82; }
    .mos-gov .mg-task-title {
        font-weight: 600;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }
    .mos-gov .mg-task-title a {
        color: inherit;
        text-decoration: none;
    }
    .mos-gov .mg-task-title a:hover {
        color: var(--mg-blue);
        text-decoration: underline;
    }
    .mos-gov .mg-task-due {
        margin-top: .25rem;
        color: var(--mg-muted);
        font-size: .8rem;
    }
    .mos-gov .mg-task-due.is-overdue {
        color: var(--mg-red);
        font-weight: 600;
    }

    /* ---------- empty state ---------- */
    .mos-gov .mg-empty {
        color: var(--mg-muted);
        font-size: .86rem;
        padding: .55rem .8rem;
        border: 1px dashed var(--mg-border);
        border-radius: 10px;
        background: #fbfcfd;
    }
    .mos-gov .mg-empty-box {
        border: 1px solid var(--mg-border-soft);
        border-radius: 10px;
        background: #fbfcfd;
        padding: .85rem .9rem;
        height: 100%;
    }
    .mos-gov .mg-empty-box .mg-stat-label {
        font-weight: 600;
        color: #3e4c59;
    }

    /* ---------- section navigation ---------- */
    .mos-gov .mg-nav {
        border: 1px solid var(--mg-border);
        border-radius: var(--mg-radius);
        background: #fff;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        padding: .55rem .75rem;
    }
    .mos-gov .mg-nav-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .15rem .45rem;
    }
    .mos-gov .mg-nav-row + .mg-nav-row {
        margin-top: .3rem;
    }
    .mos-gov .mg-nav-label {
        color: var(--mg-muted);
        font-size: .72rem;
        letter-spacing: .03em;
        margin-right: .15rem;
        white-space: nowrap;
    }
    .mos-gov .mg-nav-link {
        display: inline-block;
        padding: .28rem .6rem;
        border-radius: .45rem;
        font-size: .82rem;
        color: #4b5563;
        text-decoration: none;
        white-space: nowrap;
    }
    .mos-gov .mg-nav-link:hover {
        background: #f1f3f5;
        color: #1f2d3d;
    }
    .mos-gov .mg-nav-link.active {
        background: var(--mg-blue);
        color: #fff;
        font-weight: 600;
    }
    .mos-gov .mg-nav-primary .mg-nav-link {
        font-weight: 600;
    }
    .mos-gov .mg-nav-divider {
        align-self: stretch;
        width: 1px;
        background: var(--mg-border-soft);
        margin: .15rem .3rem;
    }

    /* ---------- page header strip ---------- */
    .mos-gov .mg-pagehead {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: .4rem .8rem;
        margin-bottom: .9rem;
    }
    .mos-gov .mg-pagehead-note {
        color: var(--mg-muted);
        font-size: .84rem;
    }

    /* ---------- responsive ---------- */
    @media (max-width: 767.98px) {
        .mos-gov .mg-stats {
            grid-template-columns: 1fr;
        }
        .mos-gov .mg-item {
            flex-direction: column;
            gap: .45rem;
        }
        .mos-gov .mg-item > .mg-chip-row {
            margin-left: 0;
        }
        .mos-gov .mg-cap-grid,
        .mos-gov .mg-task-grid {
            grid-template-columns: 1fr;
        }
        .mos-gov .mg-summary {
            padding: .85rem .9rem;
        }
        .mos-gov .mg-nav-divider {
            display: none;
        }
    }
</style>
