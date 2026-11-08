<?php

namespace App\Support;

/**
 * The inline icon set behind <x-ds.icon name="...">. One drawing grammar: a 24 x 24 grid, 1.75 stroke, round caps and joins,
 * `currentColor` only. No icon font, no CDN. Each value is the inner markup of the <svg>. The names are listed in DESIGN.md
 * (section "Icons"); add to both together. Status icons are chosen so that no two statuses share a silhouette.
 */
final class Icons
{
    /** @var array<string, string> */
    private const SET = [
        // Navigation and actions
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'cart' => '<path d="M3 4h2.5l2 11h10.5l2-8H6.5"/><circle cx="9" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>',
        'bag' => '<path d="M5 8h14l-1 12H6z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>',
        'heart' => '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.4 4.3 4.3 0 0 1 19.5 10C19.5 15.4 12 20 12 20z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-up' => '<path d="m6 15 6-6 6 6"/>',
        'chevron-left' => '<path d="m15 6-6 6 6 6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'arrow-right' => '<path d="M4 12h15M13 6l6 6-6 6"/>',
        'arrow-left' => '<path d="M20 12H5M11 6l-6 6 6 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'sort' => '<path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5V5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
        'pin' => '<path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'store' => '<path d="M4 4h16l1.5 5.5a3 3 0 0 1-6 0 3 3 0 0 1-7 0 3 3 0 0 1-6 0z"/><path d="M5 12.5V20h14v-7.5M10 20v-4.5h4V20"/>',
        'tag' => '<path d="M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8-9 9z"/><circle cx="8.5" cy="8.5" r="1.3"/>',
        'bell' => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 21h4"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8v.4"/>',
        'trash' => '<path d="M4 7h16M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13M10 11v6M14 11v6"/>',
        'edit' => '<path d="M4 20h4L19.5 8.5l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'image' => '<rect x="3.5" y="4.5" width="17" height="15" rx="1.5"/><circle cx="9" cy="10" r="1.6"/><path d="m4 18 5.5-5 4 3.5 3-3 3.5 3.5"/>',
        'grid' => '<path d="M4 4h6.5v6.5H4zM13.5 4H20v6.5h-6.5zM4 13.5h6.5V20H4zM13.5 13.5H20V20h-6.5z"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.5M4 12h.5M4 18h.5"/>',
        'home' => '<path d="m3.5 11 8.5-7 8.5 7M5.5 9.5V20h13V9.5M10 20v-6h4v6"/>',
        'receipt' => '<path d="M6 3.5h12V21l-2-1.5-2 1.5-2-1.5-2 1.5-2-1.5L6 21z"/><path d="M9 8h6M9 12h6"/>',
        'sliders' => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
        'logout' => '<path d="M10 4H5v16h5M14 8l4 4-4 4M18 12H9"/>',
        'help' => '<circle cx="12" cy="12" r="8.5"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 16.6v.4"/>',
        'lock' => '<rect x="5" y="10.5" width="14" height="10" rx="1.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
        'shield-check' => '<path d="M12 3.5 19.5 6v5.5c0 4.5-3.2 7.8-7.5 9-4.3-1.2-7.5-4.5-7.5-9V6z"/><path d="m8.8 12 2.4 2.4 4-4.4"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6"/>',
        'copy' => '<rect x="8.5" y="8.5" width="11" height="11" rx="1.5"/><path d="M15.5 8.5v-3a1 1 0 0 0-1-1h-9a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h3"/>',
        'bookmark' => '<path d="M6.5 3.5h11v17L12 16.5l-5.5 4z"/>',
        'mail' => '<rect x="3" y="5.5" width="18" height="13" rx="1.5"/><path d="m3.5 7 8.5 6.5L20.5 7"/>',
        'loader' => '<path d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5"/>',
        'chart' => '<path d="M4 4v16h16M8 16v-4M12 16V8M16 16v-6"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4.5 17v3h15v-3"/>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="7" cy="7" r="2"/><circle cx="17" cy="17" r="2"/>',
        'message' => '<path d="M4 5h16v11H9l-5 4z"/>',
        'dots' => '<circle cx="5.5" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="18.5" cy="12" r="1.4" fill="currentColor" stroke="none"/>',
        'zoom-in' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5M11 8v6M8 11h6"/>',
        'star' => '<path d="m12 3.8 2.5 5.2 5.7.8-4.1 4 1 5.7L12 16.8l-5.1 2.7 1-5.7-4.1-4 5.7-.8z"/>',
        'eye' => '<path d="M3 12s3.5-6.5 9-6.5 9 6.5 9 6.5-3.5 6.5-9 6.5S3 12 3 12z"/><circle cx="12" cy="12" r="2.5"/>',
        'calendar' => '<path d="M4 6h16v14H4zM4 10h16M8 3.5v4M16 3.5v4"/>',
        'phone' => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
        'building' => '<path d="M5 20V4h10v16M15 9h4v11M3 20h18M8.5 8h.01M8.5 12h.01M8.5 16h.01M11.5 8h.01M11.5 12h.01"/>',
        'sparkle' => '<path d="M12 4v5M12 15v5M4 12h5M15 12h5"/>',

        // Order status (each its own silhouette)
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3 2"/>',
        'card' => '<rect x="3" y="5.5" width="18" height="13" rx="1.5"/><path d="M3 10h18M7 15h4"/>',
        'box' => '<path d="M3.5 7.5 12 3.5l8.5 4v9L12 20.5l-8.5-4z"/><path d="M3.5 7.5 12 11.5l8.5-4M12 11.5v9"/>',
        'truck' => '<path d="M2.5 6.5h11v10h-11z"/><path d="M13.5 10h4l3 3v3.5h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'package-check' => '<path d="M3.5 7.5 12 3.5l8.5 4v9L12 20.5l-8.5-4z"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'x-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'undo' => '<path d="M4 9v5h5"/><path d="M4.5 14A8 8 0 1 0 7 7.5"/>',
        'return' => '<path d="M20 5v8a2 2 0 0 1-2 2H5"/><path d="m9 11-4 4 4 4"/>',
        'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'ban' => '<circle cx="12" cy="12" r="8.5"/><path d="m6 6 12 12"/>',

        // Product status
        'in-stock' => '<path d="M4 12.5 9 17.5 20 6.5"/>',
        'alert-triangle' => '<path d="M12 4 21.5 20h-19z"/><path d="M12 10v4.5M12 17.2v.3"/>',
        'minus-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="M8 12h8"/>',
        'eye-off' => '<path d="M3 12s3.5-6.5 9-6.5c1.3 0 2.5.4 3.6.9M21 12s-3.5 6.5-9 6.5c-1.3 0-2.5-.4-3.6-.9"/><circle cx="12" cy="12" r="2.5"/><path d="M4 4l16 16"/>',
        'hourglass' => '<path d="M6.5 3.5h11M6.5 20.5h11M7.5 3.5v3.5l4.5 5-4.5 5v3.5M16.5 3.5v3.5L12 12l4.5 5v3.5"/>',
        'x-octagon' => '<path d="M8.5 3.5h7l5 5v7l-5 5h-7l-5-5v-7z"/><path d="m9.5 9.5 5 5M14.5 9.5l-5 5"/>',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::SET);
    }

    public static function has(string $name): bool
    {
        return isset(self::SET[$name]);
    }

    /** The inner SVG markup for an icon, or a plain square for an unknown name (so a typo is visible, never a crash). */
    public static function markup(string $name): string
    {
        return self::SET[$name] ?? '<rect x="5" y="5" width="14" height="14" rx="2"/>';
    }
}
