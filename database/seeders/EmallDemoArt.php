<?php

namespace Database\Seeders;

use GdImage;

/**
 * The demo marketplace's product pictures: one honest silhouette per kind of product (a laptop is a laptop, a kettle a kettle),
 * drawn with GD, no text and no brand marks. Production sellers upload real photographs; this exists only so a demo shelf shows
 * the product it names (D-170).
 *
 * Deterministic: the same (name, hue, frame) always gives the same bytes. Rows are told apart by the silhouette, the colour
 * (hue and a small lightness/saturation step taken from the name), the backdrop (soft gradient, wall and table, spotlight, halo,
 * a bolder tone) and the frame (1 is mirrored on another backdrop, 2 is a close-up crop).
 *
 * A picture is 800 x 800; the object stands on y = 640.
 */
final class EmallDemoArt
{
    public const SIZE = 800;

    /** Name pattern => kind. First match wins, so the specific patterns come before the general ones. */
    private const KINDS = [
        '/potjie/i' => 'pot', '/skillet/i' => 'skillet', '/pan set/i' => 'panset', '/pressure cooker/i' => 'cooker',
        '/mug/i' => 'mugs', '/serving board/i' => 'board', '/stoneware bowl/i' => 'bowls', '/throw blanket/i' => 'blanket',
        '/seagrass basket/i' => 'basket', '/taper/i' => 'tapers', '/bud vase/i' => 'vase', '/table runner/i' => 'runner',
        '/storage jars/i' => 'jars', '/spice rub/i' => 'spice', '/tongs/i' => 'tongs', '/wall clock/i' => 'clock',
        '/soy candle/i' => 'jarcandle', '/air fryer/i' => 'airfryer', '/kettle/i' => 'kettle', '/blender/i' => 'blender',
        '/toaster/i' => 'toaster', '/coffee maker/i' => 'coffee', '/bar fridge/i' => 'fridge', '/fridge freezer/i' => 'fridge2',
        '/sofa/i' => 'sofa', '/coffee table/i' => 'table', '/bookcase/i' => 'bookcase', '/pedestal/i' => 'pedestal',
        '/duvet/i' => 'duvet', '/tent/i' => 'tent', '/sleeping bag/i' => 'sleepingbag', '/sleeping mat/i' => 'mat',
        '/trekking poles/i' => 'poles', '/daypack/i' => 'daypack', '/dry bag/i' => 'drybag', '/insulated.*bottle/i' => 'flask',
        '/headlamp/i' => 'headlamp', '/camp chair/i' => 'chair', '/camping stove/i' => 'stove', '/travel towel/i' => 'towel',
        '/plate set/i' => 'plates', '/multi-tool/i' => 'multitool', '/sun hat/i' => 'sunhat', '/cookset/i' => 'cookset',
        '/base layer tee/i' => 'tee', '/hoodie/i' => 'hoodie', '/fleece pullover/i' => 'pullover', '/hiking pants/i' => 'pants',
        '/rain jacket/i' => 'jacket', '/leggings/i' => 'leggings', '/fleece jacket/i' => 'fleecejacket', '/hiking shirt/i' => 'shirt',
        '/boots/i' => 'boot', '/running shoes/i' => 'shoe', '/poncho/i' => 'poncho', '/helmet/i' => 'helmet',
        '/bicycle light/i' => 'bikelight', '/floor pump/i' => 'pump', '/bike lock/i' => 'lock', '/facial oil/i' => 'dropper',
        '/face mask|hair mask|body cream/i' => 'tub', '/hand cream|aloe|sunscreen/i' => 'tube', '/rooibos tea/i' => 'teabox',
        '/deodorant/i' => 'stick', '/soap/i' => 'soap', '/body scrub/i' => 'scrub', '/lip balm/i' => 'lipbalm', '/face mist/i' => 'mist',
        '/hair brush/i' => 'brush', '/essential oil/i' => 'oilkit', '/bath salts/i' => 'saltjar', '/shampoo|conditioner/i' => 'pumpbottle',
        '/vitamin|magnesium|omega/i' => 'vitamin', '/honey/i' => 'honey', '/first aid/i' => 'firstaid', '/headphones/i' => 'headphones',
        '/earbuds/i' => 'earbuds', '/speaker/i' => 'speaker', '/charger/i' => 'charger', '/power bank/i' => 'powerbank',
        '/usb-c cable/i' => 'cable', '/laptop stand/i' => 'stand', '/keyboard/i' => 'keyboard', '/mouse/i' => 'mouse',
        '/webcam/i' => 'webcam', '/phone case/i' => 'phonecase', '/smart plug/i' => 'plug', '/monitor arm/i' => 'monitorarm',
        '/notebook/i' => 'notebook', '/gel pen/i' => 'pens', '/laptop|macbook|ideapad|vivobook/i' => 'laptop', '/smartphone/i' => 'phone',
        '/tablet|\btab\b/i' => 'tablet', '/monitor/i' => 'monitor', '/\btv\b/i' => 'tv',
    ];

    /** Kinds laid flat or hung on a wall: no floor shadow. */
    private const FLAT = ['tongs', 'clock', 'tee', 'pullover', 'jacket', 'fleecejacket', 'hoodie', 'shirt', 'pants', 'leggings', 'poncho', 'blanket', 'towel', 'runner', 'mat', 'pens', 'cable', 'poles', 'monitorarm', 'multitool', 'brush'];

    private GdImage $im;

    /** @var array<string, int> */
    private array $c = [];

    private int $hue;

    private int $seed;

    private string $kind;

    /**
     * The kind of product a name is, or '' when no drawing fits it (the seeder test fails on that).
     */
    public static function kindFor(string $name): string
    {
        foreach (self::KINDS as $pattern => $kind) {
            if (preg_match($pattern, $name) === 1) {
                return $kind;
            }
        }

        return '';
    }

    /** @return list<string> every kind that has a drawing */
    public static function kinds(): array
    {
        return array_values(array_unique(array_values(self::KINDS)));
    }

    public static function render(string $name, int $hue, int $frame): string
    {
        $art = new self;

        return $art->draw(self::kindFor($name) ?: 'parcel', $hue, $frame, crc32($name));
    }

    public function draw(string $kind, int $hue, int $frame, int $seed): string
    {
        $s = self::SIZE;
        $this->kind = $kind;
        $this->hue = $hue;
        $this->seed = $seed;
        $this->im = imagecreatetruecolor($s, $s);
        imageantialias($this->im, true);
        imagealphablending($this->im, true);

        $this->palette();
        $this->backdrop($frame);

        if (! in_array($kind, self::FLAT, true)) {
            imagefilledellipse($this->im, 400, 648, 440, 44, $this->hsl($this->bgHue(), 0.3, 0.3, 105));
            imagefilledellipse($this->im, 400, 646, 340, 28, $this->hsl($this->bgHue(), 0.3, 0.22, 92));
        }

        $method = 'k_'.$kind;
        method_exists($this, $method) ? $this->{$method}() : $this->k_parcel();

        if ($frame === 1) {
            imageflip($this->im, IMG_FLIP_HORIZONTAL);
        }

        if ($frame === 2) {
            $cropped = imagecrop($this->im, ['x' => 110, 'y' => 120, 'width' => 580, 'height' => 580]);
            if ($cropped !== false) {
                $scaled = imagescale($cropped, $s, $s, IMG_BICUBIC);
                if ($scaled !== false) {
                    $this->im = $scaled;
                }
            }
        }

        ob_start();
        imagejpeg($this->im, null, 86);

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------ colour and backdrop

    private function rgb(float $h, float $s, float $l): array
    {
        $h = fmod(fmod($h, 360) + 360, 360) / 360;
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = static function (float $t) use ($p, $q): float {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return [(int) round($f($h + 1 / 3) * 255), (int) round($f($h) * 255), (int) round($f($h - 1 / 3) * 255)];
    }

    private function hsl(float $h, float $s, float $l, int $alpha = 0): int
    {
        [$r, $g, $b] = $this->rgb($h, $s, max(0, min(1, $l)));

        return imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
    }

    private function palette(): void
    {
        $h = $this->hue;
        $step = (($this->seed >> 3) % 7) - 3;
        $s = 0.5 + $step * 0.03;
        $l = 0.5 + $step * 0.018;

        $this->c = [
            'main' => $this->hsl($h, $s, $l),
            'light' => $this->hsl($h, $s, $l + 0.14),
            'lighter' => $this->hsl($h, $s * 0.8, $l + 0.28),
            'dark' => $this->hsl($h, $s, $l - 0.14),
            'darker' => $this->hsl($h, $s, $l - 0.26),
            'pale' => $this->hsl($h, 0.25, 0.93),
            'white' => $this->hsl($h, 0.1, 0.98),
            'ink' => $this->hsl($h, 0.18, 0.17),
            'ink2' => $this->hsl($h, 0.14, 0.28),
            'steel' => $this->hsl($h, 0.08, 0.74),
            'steelL' => $this->hsl($h, 0.06, 0.88),
            'steelD' => $this->hsl($h, 0.1, 0.5),
            'glass' => $this->hsl(200, 0.3, 0.92, 55),
            'glassL' => $this->hsl(200, 0.3, 0.98, 70),
            'wood' => $this->hsl(32, 0.45, 0.58),
            'woodL' => $this->hsl(34, 0.5, 0.7),
            'woodD' => $this->hsl(28, 0.45, 0.4),
            'red' => $this->hsl(2, 0.65, 0.48),
            'amber' => $this->hsl(32, 0.8, 0.38),
            'honey' => $this->hsl(38, 0.9, 0.52),
            'flame' => $this->hsl(38, 0.95, 0.58),
            'flameL' => $this->hsl(50, 0.95, 0.8),
            'screenDark' => $this->hsl($h, 0.4, 0.16),
        ];
    }

    private function bgHue(): int
    {
        return $this->hue + (($this->seed % 17) - 8) + (int) ((($this->seed >> 5) % 3) * 22);
    }

    private function backdrop(int $frame): void
    {
        $im = $this->im;
        $s = self::SIZE;
        $style = (($this->seed >> 7) + ($frame === 1 ? 2 : 0)) % 5;
        $bold = ($this->seed >> 11) % 4 === 0;
        $hue = $this->bgHue();

        if ($bold) {
            $hue = $this->hue + 150 + ($this->seed % 50);
        }

        $top = $bold ? 0.7 : [0.94, 0.9, 0.86][$frame % 3];
        $bottom = $bold ? 0.58 : [0.84, 0.78, 0.9][$frame % 3];
        $sat = $bold ? 0.38 : 0.22;

        for ($y = 0; $y < $s; $y++) {
            $l = $top + ($bottom - $top) * ($y / $s);
            imageline($im, 0, $y, $s, $y, $this->hsl($hue, $sat, $l));
        }

        if ($style === 1) {
            // A wall above a table edge.
            imagefilledrectangle($im, 0, 600, $s, $s, $this->hsl($hue + 14, $sat + 0.06, $bottom - 0.12));
            imagefilledrectangle($im, 0, 596, $s, 602, $this->hsl($hue + 14, $sat, $bottom - 0.2));
        } elseif ($style === 2) {
            // A spotlight pool.
            for ($r = 700; $r > 120; $r -= 36) {
                imagefilledellipse($im, 400, 470, $r, (int) ($r * 0.84), $this->hsl($hue, $sat, ($bold ? 0.62 : 0.88) + (700 - $r) / 3200, 112));
            }
        } elseif ($style === 3) {
            // A pale disc behind the object.
            imagefilledellipse($im, 400, 410, 560, 560, $this->hsl($hue + 10, $sat, ($bold ? 0.78 : 0.97), 70));
        } elseif ($style === 4) {
            // A diagonal band of a second tone.
            imagefilledpolygon($im, [0, 520, $s, 300, $s, 480, 0, 700], $this->hsl($hue + 25, $sat, $bottom - 0.05, 80));
        }
    }

    // ------------------------------------------------------------------ primitives

    private function rr(int $x1, int $y1, int $x2, int $y2, int $r, string|int $c): void
    {
        $col = is_string($c) ? $this->c[$c] : $c;
        $r = min($r, intdiv(min($x2 - $x1, $y2 - $y1), 2));
        imagefilledrectangle($this->im, $x1 + $r, $y1, $x2 - $r, $y2, $col);
        imagefilledrectangle($this->im, $x1, $y1 + $r, $x2, $y2 - $r, $col);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($this->im, $cx, $cy, $r * 2, $r * 2, $col);
        }
    }

    private function el(float $cx, float $cy, float $w, float $h, string|int $c): void
    {
        imagefilledellipse($this->im, (int) round($cx), (int) round($cy), (int) round($w), (int) round($h), is_string($c) ? $this->c[$c] : $c);
    }

    /** @param  list<float|int>  $pts */
    private function poly(array $pts, string|int $c): void
    {
        imagefilledpolygon($this->im, array_map(static fn ($v) => (int) round($v), $pts), is_string($c) ? $this->c[$c] : $c);
    }

    private function ln(float $x1, float $y1, float $x2, float $y2, float $w, string|int $c): void
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $l = hypot($dx, $dy);

        if ($l < 0.5) {
            return;
        }

        $nx = -$dy / $l * $w / 2;
        $ny = $dx / $l * $w / 2;
        $this->poly([$x1 + $nx, $y1 + $ny, $x2 + $nx, $y2 + $ny, $x2 - $nx, $y2 - $ny, $x1 - $nx, $y1 - $ny], $c);
        $this->el($x1, $y1, $w, $w, $c);
        $this->el($x2, $y2, $w, $w, $c);
    }

    private function arc(float $cx, float $cy, float $w, float $h, int $from, int $to, int $thick, string|int $c): void
    {
        imagesetthickness($this->im, $thick);
        imagearc($this->im, (int) round($cx), (int) round($cy), (int) round($w), (int) round($h), $from, $to, is_string($c) ? $this->c[$c] : $c);
        imagesetthickness($this->im, 1);
    }

    private function rect(int $x1, int $y1, int $x2, int $y2, string|int $c): void
    {
        imagefilledrectangle($this->im, $x1, $y1, $x2, $y2, is_string($c) ? $this->c[$c] : $c);
    }

    /** A picture on a screen: a sky, a sun and two hills in the product's hue. */
    private function scene(int $x1, int $y1, int $x2, int $y2, int $variant = 0): void
    {
        $h = $this->hue + $variant * 40;
        $w = $x2 - $x1;
        $hh = $y2 - $y1;

        for ($y = $y1; $y <= $y2; $y++) {
            $t = ($y - $y1) / max(1, $hh);
            imageline($this->im, $x1, $y, $x2, $y, $this->hsl($h, 0.55, 0.28 + 0.34 * (1 - $t)));
        }

        $this->el($x1 + $w * 0.72, $y1 + $hh * 0.3, $hh * 0.2, $hh * 0.2, $this->hsl($h + 40, 0.9, 0.82));
        $this->poly([$x1, $y2, $x1, $y1 + $hh * 0.66, $x1 + $w * 0.3, $y1 + $hh * 0.5, $x1 + $w * 0.62, $y2], $this->hsl($h - 20, 0.5, 0.22));
        $this->poly([$x1 + $w * 0.3, $y2, $x1 + $w * 0.7, $y1 + $hh * 0.58, $x2, $y1 + $hh * 0.72, $x2, $y2], $this->hsl($h + 15, 0.45, 0.16));
    }

    // ------------------------------------------------------------------ cookware and kitchen

    private function k_parcel(): void
    {
        $this->rr(230, 330, 570, 640, 14, 'wood');
        $this->rect(230, 330, 570, 390, 'woodL');
        $this->rect(385, 330, 415, 640, 'woodD');
    }

    private function k_pot(): void
    {
        foreach ([300, 400, 500] as $x) {
            $this->poly([$x - 24, 600, $x + 24, 600, $x + 14, 650, $x - 14, 650], 'ink');
        }
        $this->arc(400, 400, 470, 420, 200, 340, 14, 'ink');
        $this->el(400, 500, 400, 300, 'ink2');
        $this->el(400, 490, 380, 270, 'ink');
        $this->rr(250, 380, 550, 412, 16, 'ink2');
        $this->el(400, 372, 270, 50, 'ink2');
        $this->el(400, 364, 40, 40, 'ink');
        $this->poly([330, 470, 360, 450, 350, 560, 320, 540], $this->hsl($this->hue, 0.1, 0.3));
    }

    private function k_skillet(): void
    {
        $this->rr(520, 466, 740, 500, 16, 'ink');
        $this->el(715, 483, 22, 14, 'steel');
        $this->el(350, 510, 470, 190, 'ink');
        $this->el(350, 490, 470, 190, 'ink2');
        $this->el(350, 490, 420, 150, 'ink');
        $this->el(330, 480, 220, 60, $this->hsl($this->hue, 0.1, 0.24));
    }

    private function k_panset(): void
    {
        foreach ([[250, 400, 270, 'ink', -28], [420, 460, 230, 'ink2', 0], [560, 520, 190, 'dark', 28]] as [$x, $y, $d, $col, $dy]) {
            $this->rr($x + (int) ($d / 2) - 10, $y + $dy - 14, $x + (int) ($d / 2) + 100, $y + $dy + 14, 12, 'ink');
            $this->el($x, $y + $dy, $d, $d, $col);
            $this->el($x, $y + $dy, $d - 36, $d - 36, $this->hsl($this->hue, 0.12, 0.2));
            $this->el($x - 16, $y + $dy - 14, $d - 120, $d - 120, $this->hsl($this->hue, 0.1, 0.26));
        }
    }

    private function k_cooker(): void
    {
        $this->rr(150, 430, 250, 468, 16, 'ink');
        $this->rr(550, 430, 650, 468, 16, 'ink');
        $this->rr(240, 390, 560, 640, 28, 'steel');
        $this->rect(262, 410, 300, 620, 'steelL');
        $this->rr(230, 320, 570, 400, 30, 'main');
        $this->rect(250, 395, 550, 402, 'dark');
        $this->rr(380, 270, 420, 330, 8, 'ink');
        $this->el(400, 262, 56, 34, 'ink2');
        $this->rr(300, 350, 340, 372, 10, 'light');
    }

    private function k_mugs(): void
    {
        foreach ([[250, 'main', 560], [430, 'dark', 600], [570, 'light', 520]] as [$x, $col, $base]) {
            $this->mug($x, $base, 'enamel', $col);
        }
    }

    private function mug(int $cx, int $base, string $style, string $col): void
    {
        $this->arc($cx + 110, $base - 120, 120, 150, 270, 90, 22, 'ink2');
        $this->rr($cx - 85, $base - 220, $cx + 85, $base, 20, $col);
        $this->rect($cx - 70, $base - 198, $cx - 50, $base - 24, 'lighter');
        $this->el($cx, $base - 220, 170, 34, 'ink2');
        $this->el($cx, $base - 220, 150, 26, 'pale');
        $this->rect($cx - 85, $base - 215, $cx + 85, $base - 200, 'ink2');
    }

    private function k_board(): void
    {
        $this->rr(110, 310, 530, 580, 70, 'woodD');
        $this->rr(110, 296, 530, 566, 70, 'wood');
        $this->rr(500, 390, 700, 466, 38, 'wood');
        $this->el(666, 428, 34, 34, 'woodD');
        foreach ([350, 400, 450, 500] as $i => $y) {
            $this->ln(150, $y + $i * 4, 480, $y + 12 - $i * 3, 4, 'woodL');
        }
    }

    private function k_bowls(): void
    {
        $this->bowl(300, 580, 380, 170, 'main');
        $this->bowl(500, 520, 300, 130, 'light');
        $this->bowl(330, 640, 440, 190, 'dark');
    }

    private function bowl(int $cx, int $base, int $w, int $h, string $col): void
    {
        imagefilledarc($this->im, $cx, $base - $h, $w, $h * 2, 0, 180, $this->c[$col], IMG_ARC_PIE);
        $this->el($cx, $base - $h, $w, (int) ($h * 0.3), 'pale');
        $this->el($cx, $base - $h + 2, $w - 30, (int) ($h * 0.22), $this->hsl($this->hue, 0.4, 0.4));
        $this->rr($cx - (int) ($w * 0.2), $base - 12, $cx + (int) ($w * 0.2), $base + 6, 6, 'darker');
    }

    private function k_blanket(): void
    {
        foreach ([[170, 470, 'darker'], [160, 400, 'dark'], [150, 330, 'main']] as $i => [$x, $y, $col]) {
            $this->rr($x, $y, $x + 480, $y + 130, 20, $col);
            $this->rect($x + 40, $y + 34, $x + 440, $y + 44, 'lighter');
            $this->rect($x + 40, $y + 80, $x + 440, $y + 90, 'lighter');
            for ($f = 0; $f < 18; $f++) {
                $this->ln($x + 482, $y + 8 + $f * 7, $x + 520, $y + 8 + $f * 7, 3, $col);
            }
        }
    }

    private function k_basket(): void
    {
        $this->arc(260, 400, 120, 160, 90, 270, 22, 'woodD');
        $this->arc(540, 400, 120, 160, 270, 90, 22, 'woodD');
        $this->poly([220, 380, 580, 380, 540, 640, 260, 640], 'wood');
        for ($y = 400; $y < 640; $y += 28) {
            $this->ln(228, $y, 572, $y, 4, 'woodD');
        }
        for ($x = 270; $x < 560; $x += 36) {
            $this->ln($x, 386, $x - ($x - 400) * 0.1, 636, 4, 'woodL');
        }
        $this->rr(200, 360, 600, 396, 16, 'woodD');
    }

    private function k_tapers(): void
    {
        foreach ([[300, 250, 'pale'], [400, 190, 'woodL'], [500, 280, 'pale']] as [$x, $top, $col]) {
            $this->rr($x - 20, $top, $x + 20, 640, 10, $col);
            $this->rect($x - 2, $top - 26, $x + 2, $top + 2, 'ink');
            $this->el($x, $top - 52, 34, 70, 'flame');
            $this->el($x, $top - 44, 16, 40, 'flameL');
        }
        $this->rr(270, 520, 530, 546, 8, 'dark');
    }

    private function k_vase(): void
    {
        $this->el(400, 510, 320, 280, 'main');
        $this->rr(358, 250, 442, 410, 10, 'main');
        $this->el(400, 252, 130, 34, 'dark');
        $this->el(400, 252, 100, 22, 'ink');
        $this->el(330, 490, 50, 150, 'light');
        $this->ln(400, 250, 470, 130, 8, $this->hsl(110, 0.4, 0.38));
        $this->el(480, 118, 70, 36, $this->hsl(110, 0.4, 0.42));
    }

    private function k_runner(): void
    {
        // A strip of linen laid along a table: perspective, a stripe near each end, loose fringe.
        $this->rect(0, 470, self::SIZE, 480, $this->hsl($this->bgHue() + 12, 0.2, 0.7));
        $this->poly([170, 400, 630, 400, 740, 600, 60, 600], 'light');
        $this->poly([170, 400, 630, 400, 636, 412, 164, 412], 'lighter');
        foreach ([0.12, 0.2, 0.8, 0.88] as $t) {
            $this->poly([170 + 460 * $t, 400, 170 + 460 * $t + 16, 400, 60 + 680 * $t + 22, 600, 60 + 680 * $t, 600], 'dark');
        }
        for ($f = 0; $f < 28; $f++) {
            $this->ln(64 + $f * 24.5, 602, 60 + $f * 24.5, 626, 3, 'lighter');
        }
        $this->ln(60, 600, 740, 600, 4, 'main');
    }

    private function k_jars(): void
    {
        $this->jar(210, 640, 190, 250, 'woodL', $this->hsl(35, 0.7, 0.62));
        $this->jar(400, 640, 190, 330, 'wood', 'pale');
        $this->jar(590, 640, 190, 210, 'woodL', $this->hsl(14, 0.6, 0.52));
    }

    private function jar(int $cx, int $base, int $w, int $h, string|int $lid, string|int $fill, bool $label = false): void
    {
        $top = $base - $h;
        $this->rr($cx - (int) ($w / 2), $top, $cx + (int) ($w / 2), $base, 22, 'glass');
        $this->rr($cx - (int) ($w / 2) + 6, $top + (int) ($h * 0.3), $cx + (int) ($w / 2) - 6, $base - 6, 18, $fill);
        $this->rect($cx - (int) ($w / 2) + 14, $top + 20, $cx - (int) ($w / 2) + 28, $base - 20, 'glassL');
        $this->rr($cx - (int) ($w / 2) - 4, $top - 38, $cx + (int) ($w / 2) + 4, $top + 6, 10, $lid);
        $this->rect($cx - (int) ($w / 2) - 2, $top - 6, $cx + (int) ($w / 2) + 2, $top, 'woodD');
        if ($label) {
            $this->rr($cx - (int) ($w * 0.3), $top + (int) ($h * 0.42), $cx + (int) ($w * 0.3), $top + (int) ($h * 0.78), 6, 'white');
            $this->rect($cx - (int) ($w * 0.2), $top + (int) ($h * 0.52), $cx + (int) ($w * 0.2), $top + (int) ($h * 0.56), 'main');
        }
    }

    private function k_spice(): void
    {
        $this->jar(400, 640, 280, 360, 'ink', $this->hsl(14, 0.6, 0.36), true);
        $this->rect(330, 250, 470, 262, 'ink2');
    }

    private function k_scrub(): void
    {
        $this->jar(400, 640, 320, 300, 'woodL', $this->hsl($this->hue, 0.2, 0.9), true);
        $this->el(400, 390, 280, 36, $this->hsl($this->hue, 0.2, 0.84));
    }

    private function k_saltjar(): void
    {
        $this->jar(400, 640, 300, 330, 'steelL', $this->hsl(270, 0.45, 0.78), true);
        $this->ln(520, 520, 600, 470, 6, $this->hsl(270, 0.4, 0.5));
        $this->el(606, 466, 26, 40, $this->hsl(270, 0.45, 0.58));
    }

    private function k_honey(): void
    {
        $this->jar(400, 640, 320, 330, 'dark', 'honey', true);
        $this->el(400, 400, 250, 22, $this->hsl(40, 0.95, 0.6));
        $this->rr(250, 310, 550, 332, 8, 'ink2');
    }

    private function k_tongs(): void
    {
        $a = -34;
        $pt = fn (float $x, float $y): array => $this->rot($x, $y, 400, 400, $a);
        [$ax, $ay] = $pt(400, 150);
        foreach ([-1, 1] as $side) {
            $prev = [$ax, $ay];
            for ($i = 1; $i <= 10; $i++) {
                $t = $i / 10;
                $x = 400 + $side * (14 + 62 * sin($t * M_PI * 0.62));
                $y = 150 + $t * 480;
                [$px, $py] = $pt($x, $y);
                $this->ln($prev[0], $prev[1], $px, $py, 24, 'steelD');
                $this->ln($prev[0], $prev[1], $px, $py, 14, 'steel');
                $prev = [$px, $py];
            }
            [$sx, $sy] = $pt(400 + $side * 52, 628);
            $this->el($sx, $sy, 100, 56, 'steelD');
            $this->el($sx, $sy, 84, 44, 'steel');
        }
        [$lx, $ly] = $pt(400, 160);
        $this->el($lx, $ly, 70, 56, 'steelD');
        $this->el($lx, $ly, 38, 28, $this->hsl($this->hue, 0.1, 0.84));
    }

    /** @return array{0: float, 1: float} */
    private function rot(float $x, float $y, float $cx, float $cy, float $deg): array
    {
        $r = deg2rad($deg);

        return [$cx + ($x - $cx) * cos($r) - ($y - $cy) * sin($r), $cy + ($x - $cx) * sin($r) + ($y - $cy) * cos($r)];
    }

    private function k_clock(): void
    {
        $this->el(412, 416, 470, 470, $this->hsl(28, 0.3, 0.3, 90));
        $this->el(400, 400, 470, 470, 'woodD');
        $this->el(400, 400, 440, 440, 'wood');
        $this->el(400, 400, 380, 380, 'pale');
        for ($i = 0; $i < 12; $i++) {
            $a = deg2rad($i * 30);
            $this->ln(400 + sin($a) * 150, 400 - cos($a) * 150, 400 + sin($a) * 174, 400 - cos($a) * 174, $i % 3 === 0 ? 10 : 5, 'amber');
        }
        $this->ln(400, 400, 400 + sin(deg2rad(70)) * 100, 400 - cos(deg2rad(70)) * 100, 14, 'ink');
        $this->ln(400, 400, 400 + sin(deg2rad(-20)) * 150, 400 - cos(deg2rad(-20)) * 150, 9, 'ink');
        $this->el(400, 400, 26, 26, 'amber');
    }

    private function k_jarcandle(): void
    {
        $this->rr(260, 330, 540, 640, 36, 'glass');
        $this->rr(268, 400, 532, 634, 30, 'pale');
        $this->rr(300, 470, 500, 580, 8, 'white');
        $this->rect(330, 505, 470, 513, 'main');
        $this->rect(398, 340, 402, 402, 'ink');
        $this->el(400, 308, 44, 90, 'flame');
        $this->el(400, 318, 20, 50, 'flameL');
        $this->rect(280, 352, 292, 620, 'glassL');
    }

    private function k_airfryer(): void
    {
        $this->rr(200, 230, 600, 640, 90, 'ink');
        $this->rr(230, 270, 570, 420, 40, 'ink2');
        $this->rr(300, 300, 500, 340, 10, 'screenDark');
        $this->el(330, 380, 40, 40, 'steel');
        $this->el(470, 380, 40, 40, 'steel');
        $this->rr(280, 470, 520, 600, 22, 'ink2');
        $this->rr(330, 450, 470, 482, 14, 'steelD');
        foreach ([300, 340, 380] as $x) {
            $this->rect($x + 200, 300, $x + 214, 310, 'steel');
        }
        $this->rect(330, 316, 350, 322, 'main');
    }

    private function k_kettle(): void
    {
        $this->arc(540, 440, 150, 230, 270, 90, 26, 'ink2');
        $this->poly([280, 330, 520, 330, 540, 620, 260, 620], 'glass');
        $this->poly([280, 330, 190, 300, 200, 350, 290, 420], 'glass');
        $this->poly([285, 480, 515, 480, 536, 620, 264, 620], $this->hsl(200, 0.5, 0.78, 50));
        $this->rr(250, 610, 550, 650, 16, 'ink');
        $this->el(400, 632, 280, 14, $this->hsl(205, 0.95, 0.6));
        $this->rr(340, 290, 460, 336, 14, 'ink2');
        $this->el(400, 282, 40, 24, 'ink');
        $this->rect(296, 350, 312, 600, 'glassL');
    }

    private function k_blender(): void
    {
        $this->rr(290, 510, 510, 640, 22, 'ink');
        $this->el(400, 575, 40, 40, 'steel');
        $this->poly([310, 270, 490, 270, 500, 515, 300, 515], 'glass');
        $this->poly([306, 400, 494, 400, 500, 515, 300, 515], $this->hsl(340, 0.7, 0.62));
        $this->el(400, 400, 190, 24, $this->hsl(340, 0.7, 0.72));
        $this->rr(296, 238, 504, 282, 12, 'ink2');
        $this->rect(330, 290, 344, 500, 'glassL');
        $this->rr(570, 440, 690, 640, 22, 'glass');
        $this->rr(578, 500, 682, 634, 16, $this->hsl(340, 0.7, 0.62));
        $this->rr(566, 410, 694, 450, 10, 'ink2');
    }

    private function k_toaster(): void
    {
        foreach ([[270, 'woodL'], [430, 'wood']] as [$x, $col]) {
            $this->rr($x, 300, $x + 110, 400, 30, $col);
        }
        $this->rr(170, 360, 630, 640, 70, 'steel');
        $this->rr(190, 380, 610, 420, 20, 'steelL');
        $this->rr(240, 392, 380, 408, 8, 'ink');
        $this->rr(420, 392, 560, 408, 8, 'ink');
        $this->rect(190, 470, 610, 480, 'steelL');
        $this->rr(610, 450, 660, 530, 14, 'ink');
        $this->el(300, 570, 36, 36, 'ink2');
        $this->el(400, 570, 36, 36, 'ink2');
        $this->rr(180, 624, 620, 650, 10, 'ink');
    }

    private function k_coffee(): void
    {
        $this->rr(230, 590, 600, 645, 14, 'ink');
        $this->rr(470, 190, 590, 600, 24, 'ink2');
        $this->rr(250, 180, 590, 290, 30, 'ink');
        $this->rr(300, 250, 440, 300, 10, 'ink2');
        $this->rect(420, 295, 428, 330, 'steel');
        $this->poly([290, 380, 430, 380, 452, 580, 268, 580], 'glass');
        $this->poly([276, 490, 444, 490, 452, 580, 268, 580], $this->hsl(24, 0.55, 0.22));
        $this->arc(440, 480, 80, 140, 270, 90, 14, 'ink2');
        $this->rr(280, 366, 440, 386, 6, 'ink');
        $this->el(560, 330, 24, 24, $this->hsl(120, 0.6, 0.5));
    }

    private function k_fridge(): void
    {
        $this->rr(250, 160, 550, 640, 20, 'steelL');
        $this->rr(250, 160, 550, 300, 20, 'steel');
        $this->rect(250, 296, 550, 304, 'steelD');
        $this->rr(504, 190, 518, 280, 6, 'steelD');
        $this->rr(504, 340, 518, 520, 6, 'steelD');
        $this->rect(270, 330, 282, 610, 'white');
        $this->rect(270, 190, 282, 280, 'steelL');
        $this->rr(270, 636, 300, 652, 4, 'ink');
        $this->rr(500, 636, 530, 652, 4, 'ink');
    }

    private function k_fridge2(): void
    {
        $this->rr(200, 110, 600, 640, 22, 'steelL');
        $this->rect(398, 110, 402, 640, 'steelD');
        $this->rect(200, 300, 398, 304, 'steelD');
        $this->rr(374, 160, 388, 300, 6, 'steelD');
        $this->rr(414, 160, 428, 300, 6, 'steelD');
        $this->rr(250, 350, 340, 450, 10, 'ink');
        $this->el(295, 380, 30, 30, 'screenDark');
        $this->rect(255, 420, 335, 428, $this->hsl(200, 0.7, 0.6));
        $this->rr(214, 636, 250, 654, 4, 'ink');
        $this->rr(550, 636, 586, 654, 4, 'ink');
    }

    private function k_sofa(): void
    {
        $this->rr(150, 320, 650, 500, 50, 'dark');
        $this->rr(110, 410, 210, 610, 40, 'main');
        $this->rr(590, 410, 690, 610, 40, 'main');
        foreach ([212, 340, 468] as $x) {
            $this->rr($x, 440, $x + 124, 580, 22, 'light');
        }
        foreach ([[240, 340], [470, 340]] as [$x, $y]) {
            $this->rr($x, $y + 6, $x + 90, $y + 100, 24, 'lighter');
        }
        foreach ([150, 630] as $x) {
            $this->rr($x - 12, 600, $x + 12, 648, 4, 'woodD');
        }
    }

    private function k_table(): void
    {
        $this->poly([120, 440, 680, 440, 730, 500, 70, 500], 'woodL');
        $this->poly([70, 500, 730, 500, 730, 520, 70, 520], 'wood');
        foreach ([[110, 640], [690, 640], [180, 590], [620, 590]] as [$x, $bottom]) {
            $this->ln($x, 520, $x, $bottom, 22, 'woodD');
        }
        $this->poly([140, 590, 660, 590, 690, 620, 110, 620], 'wood');
        $this->ln(150, 440, 640, 440, 2, 'wood');
        $this->ln(220, 460, 560, 460, 3, 'wood');
    }

    private function k_bookcase(): void
    {
        $this->rr(200, 110, 600, 640, 10, 'woodD');
        $this->rect(220, 130, 580, 620, 'wood');
        $palette = [0, 24, 205, 140, 330, 48, 260];
        for ($row = 0; $row < 5; $row++) {
            $y = 130 + $row * 98;
            $this->rect(220, $y + 94, 580, $y + 100, 'woodD');
            $x = 228;
            $i = 0;
            while ($x < 560 && $row !== 3) {
                $w = 16 + (($this->seed >> ($i % 20)) + $i * 7 + $row * 3) % 12;
                $h = 56 + (($i * 13 + $row * 7) % 30);
                $this->rect($x, $y + 94 - $h, $x + $w, $y + 94, $this->hsl($palette[($i + $row) % 7] + $this->hue % 30, 0.45, 0.4 + (($i * 5) % 20) / 100));
                $x += $w + 3;
                $i++;
            }
        }
        $this->el(300, 414 + 40, 80, 80, $this->hsl($this->hue, 0.4, 0.55));
    }

    private function k_pedestal(): void
    {
        foreach ([262, 538] as $x) {
            $this->poly([$x - 14, 600, $x + 14, 600, $x + 10, 650, $x - 10, 650], 'woodD');
        }
        $this->rr(240, 300, 560, 610, 12, 'main');
        $this->rr(230, 280, 570, 316, 10, 'dark');
        foreach ([336, 470] as $y) {
            $this->rr(262, $y, 538, $y + 118, 8, 'light');
            $this->rr(370, $y + 40, 430, $y + 56, 8, 'steel');
        }
    }

    private function k_duvet(): void
    {
        $this->rr(110, 560, 690, 620, 14, 'woodD');
        $this->rr(100, 330, 140, 640, 10, 'woodD');
        $this->rr(660, 410, 700, 640, 10, 'woodD');
        $this->rr(160, 340, 360, 440, 44, 'white');
        $this->rr(380, 350, 580, 450, 44, 'pale');
        $this->poly([140, 440, 660, 440, 700, 580, 100, 580], 'light');
        for ($i = 0; $i < 6; $i++) {
            $this->poly([140 + $i * 90, 440, 140 + $i * 90 + 44, 440, 130 + $i * 98 + 44, 580, 100 + $i * 98, 580], $i % 2 ? 'lighter' : 'light');
        }
        $this->poly([140, 440, 660, 440, 668, 470, 132, 470], 'main');
    }

    // ------------------------------------------------------------------ camping and outdoors

    private function k_tent(): void
    {
        imagefilledarc($this->im, 400, 620, 660, 540, 180, 360, $this->c['main'], IMG_ARC_PIE);
        imagefilledarc($this->im, 470, 620, 330, 400, 180, 360, $this->c['dark'], IMG_ARC_PIE);
        imagefilledarc($this->im, 470, 620, 230, 300, 180, 360, $this->c['ink'], IMG_ARC_PIE);
        $this->arc(400, 620, 660, 540, 180, 360, 6, 'darker');
        $this->arc(400, 620, 470, 520, 190, 350, 6, 'darker');
        $this->rect(66, 618, 734, 640, 'darker');
        $this->ln(470, 404, 470, 640, 4, 'steel');
        foreach ([90, 710] as $x) {
            $this->ln($x, 640, $x + ($x < 400 ? -30 : 30), 660, 4, 'ink');
        }
        $this->poly([400, 360, 560, 410, 400, 395], $this->hsl($this->hue, 0.5, 0.58));
    }

    private function k_sleepingbag(): void
    {
        $this->rr(570, 440, 700, 640, 40, 'dark');
        $this->el(635, 440, 130, 36, 'darker');
        $this->ln(590, 520, 680, 520, 4, 'light');
        $this->poly([300, 250, 500, 250, 540, 420, 500, 640, 300, 640, 260, 420], 'main');
        $this->el(400, 250, 200, 150, 'main');
        $this->el(400, 248, 150, 90, 'darker');
        $this->el(400, 252, 120, 56, 'ink');
        foreach ([330, 400, 470, 540] as $i => $y) {
            $this->arc(400, $y + 40, 260, 40, 0, 180, 5, 'dark');
        }
        $this->ln(400, 300, 400, 640, 5, 'darker');
        $this->rr(392, 380, 408, 410, 4, 'steel');
    }

    private function k_mat(): void
    {
        $this->poly([200, 380, 600, 380, 690, 620, 110, 620], 'main');
        for ($i = 0; $i < 7; $i++) {
            $t0 = $i / 7;
            $t1 = ($i + 1) / 7;
            $y0 = 380 + 240 * $t0;
            $y1 = 380 + 240 * $t1;
            $x0l = 200 - 90 * $t0;
            $x0r = 600 + 90 * $t0;
            $x1l = 200 - 90 * $t1;
            $x1r = 600 + 90 * $t1;
            $this->poly([$x0l + 4, $y0 + 4, $x0r - 4, $y0 + 4, $x1r - 4, $y1 - 2, $x1l + 4, $y1 - 2], $i % 2 ? 'light' : 'main');
        }
        $this->rr(560, 590, 640, 616, 8, 'ink');
        $this->rr(620, 560, 700, 580, 8, 'ink2');
    }

    private function k_poles(): void
    {
        foreach ([[260, 190, 340, 650, 0], [540, 190, 460, 650, 1]] as [$x1, $y1, $x2, $y2, $k]) {
            $this->ln($x1, $y1, $x2, $y2, 16, 'steelD');
            $this->ln($x1, $y1, $x2, $y2, 9, 'steel');
            $dx = ($x2 - $x1) / ($y2 - $y1);
            $this->ln($x1 - 10 * $dx, $y1 - 20, $x1 + 150 * $dx, $y1 + 150, 38, 'woodL');
            $this->ln($x1 - 10 * $dx, $y1 - 20, $x1 + 150 * $dx, $y1 + 150, 38, 'wood');
            $this->ln($x1 - 10 * $dx, $y1 - 20, $x1 + 150 * $dx, $y1 + 150, 24, 'woodL');
            $this->el($x1 + 330 * $dx, $y1 + 330, 14, 14, 'main');
            $this->el($x2 - 60 * $dx, $y2 - 60, 80, 30, 'ink2');
            $this->ln($x1 + 200 * $dx, $y1 + 200, $x1 + 200 * $dx, $y1 + 214, 22, $k ? 'dark' : 'main');
        }
    }

    private function k_daypack(): void
    {
        $this->arc(400, 215, 120, 90, 180, 360, 14, 'ink');
        $this->rr(240, 200, 560, 640, 80, 'main');
        $this->rr(250, 200, 550, 330, 70, 'dark');
        $this->rr(300, 420, 500, 620, 34, 'dark');
        $this->rect(305, 450, 495, 458, 'steel');
        $this->rr(380, 440, 420, 480, 8, 'steel');
        $this->rr(196, 440, 262, 600, 24, 'dark');
        $this->rr(538, 440, 604, 600, 24, 'dark');
        $this->rect(206, 470, 252, 476, 'steel');
        $this->rect(548, 470, 594, 476, 'steel');
        $this->rr(340, 300, 460, 350, 14, 'ink2');
        $this->ln(280, 360, 280, 600, 6, 'light');
        $this->ln(520, 360, 520, 600, 6, 'light');
    }

    private function k_drybag(): void
    {
        $this->rr(270, 290, 530, 640, 40, 'main');
        $this->rr(260, 190, 540, 320, 34, 'dark');
        $this->rect(272, 244, 528, 254, 'darker');
        $this->rect(272, 282, 528, 292, 'darker');
        $this->rr(370, 300, 430, 360, 12, 'ink');
        $this->rect(270, 470, 530, 500, 'dark');
        $this->rr(370, 462, 430, 508, 10, 'ink');
        $this->rect(290, 330, 306, 460, 'lighter');
        $this->rr(290, 530, 510, 600, 20, 'light');
    }

    private function k_flask(): void
    {
        $this->rr(310, 220, 490, 640, 56, 'main');
        $this->rr(330, 120, 470, 236, 22, 'ink2');
        $this->rr(300, 190, 500, 238, 16, 'ink');
        $this->rect(330, 250, 354, 610, 'lighter');
        $this->rect(310, 380, 490, 392, 'darker');
        $this->rr(300, 616, 500, 644, 14, 'ink2');
        $this->arc(400, 130, 120, 80, 180, 360, 12, 'ink');
    }

    private function k_headlamp(): void
    {
        $this->arc(400, 410, 520, 330, 0, 360, 30, 'ink2');
        $this->arc(400, 410, 520, 330, 200, 340, 30, 'ink');
        $this->rr(290, 330, 510, 470, 44, 'main');
        $this->rr(300, 340, 500, 460, 38, 'dark');
        $this->el(400, 398, 110, 110, 'steel');
        $this->el(400, 398, 82, 82, 'pale');
        $this->el(400, 398, 44, 44, $this->hsl(52, 0.95, 0.85));
        $this->rr(380, 466, 420, 490, 8, 'ink');
        $this->rect(300, 445, 500, 452, 'red');
    }

    private function k_chair(): void
    {
        $this->ln(300, 470, 520, 640, 18, 'steelD');
        $this->ln(500, 470, 290, 640, 18, 'steelD');
        $this->ln(520, 470, 560, 640, 18, 'steel');
        $this->ln(250, 470, 220, 640, 18, 'steel');
        $this->poly([230, 430, 280, 430, 310, 200, 250, 190], 'dark');
        $this->poly([240, 420, 550, 430, 555, 480, 245, 470], 'main');
        $this->poly([240, 420, 550, 430, 548, 440, 242, 432], 'light');
        $this->ln(260, 200, 540, 440, 10, 'steelD');
        $this->rr(520, 396, 590, 420, 10, 'ink');
        $this->poly([230, 430, 300, 430, 310, 200, 250, 190], 'main');
        $this->rect(262, 240, 296, 252, 'light');
    }

    private function k_stove(): void
    {
        $this->rr(330, 470, 470, 640, 28, 'main');
        $this->rect(330, 520, 470, 534, 'dark');
        $this->rr(380, 430, 420, 480, 10, 'steel');
        $this->rr(300, 424, 500, 456, 14, 'ink2');
        $this->el(400, 400, 190, 54, 'ink');
        $this->el(400, 398, 130, 30, 'screenDark');
        $this->el(400, 398, 60, 14, $this->hsl(210, 0.95, 0.6));
        foreach ([[300, 380], [500, 380], [400, 350]] as [$x, $y]) {
            $this->ln($x, $y + 20, $x < 400 ? 250 : ($x > 400 ? 550 : 400), $y - 20 + ($x === 400 ? 0 : 0), 14, 'steelD');
        }
        $this->ln(220, 340, 580, 340, 6, 'steel');
        $this->ln(400, 330, 400, 380, 6, 'steel');
        $this->rr(540, 520, 600, 560, 12, 'ink2');
        $this->ln(480, 440, 540, 540, 8, 'ink2');
    }

    private function k_towel(): void
    {
        foreach ([[240, 480, 'dark'], [230, 400, 'main'], [220, 320, 'light']] as [$x, $y, $col]) {
            $this->rr($x, $y, $x + 340, $y + 100, 18, $col);
            $this->rect($x + 20, $y + 40, $x + 320, $y + 50, 'white');
            $this->rect($x + 20, $y + 56, $x + 320, $y + 62, 'white');
        }
        $this->el(640, 520, 90, 90, 'darker');
        $this->el(640, 520, 38, 38, 'dark');
        $this->rr(560, 480, 640, 560, 30, 'darker');
    }

    private function k_plates(): void
    {
        $y = 620;
        foreach ([0, 1, 2, 3] as $i) {
            $this->el(400, $y - $i * 38, 460, 130, $i % 2 ? 'white' : 'pale');
            $this->el(400, $y - $i * 38 - 4, 460, 126, 'main');
            $this->el(400, $y - $i * 38 - 6, 430, 108, $i % 2 ? 'white' : 'pale');
        }
        $this->el(400, 540 - 38 * 2 + 22, 270, 66, $this->hsl($this->hue, 0.2, 0.9));
        $this->el(400, 462, 460, 130, 'main');
        $this->el(400, 458, 430, 110, 'white');
        $this->el(400, 458, 320, 70, 'pale');
    }

    private function k_multitool(): void
    {
        $this->rr(210, 340, 640, 410, 26, 'steel');
        $this->rr(210, 420, 640, 490, 26, 'steelD');
        $this->poly([210, 340, 120, 372, 120, 392, 210, 410], 'steel');
        $this->poly([210, 420, 120, 450, 120, 470, 210, 490], 'steelD');
        $this->rect(230, 372, 630, 376, 'steelL');
        $this->rect(230, 452, 630, 456, 'steel');
        $this->el(560, 415, 34, 34, 'ink2');
        $this->rr(250, 530, 470, 560, 14, 'main');
        $this->rr(520, 530, 640, 560, 14, 'dark');
    }

    private function k_sunhat(): void
    {
        $this->el(400, 470, 660, 170, 'woodD');
        $this->el(400, 454, 660, 170, 'woodL');
        imagefilledarc($this->im, 400, 440, 300, 380, 180, 360, $this->c['wood'], IMG_ARC_PIE);
        $this->el(400, 440, 300, 80, 'woodL');
        $this->rr(250, 410, 550, 450, 8, 'main');
        $this->rect(250, 410, 550, 416, 'dark');
        $this->arc(400, 470, 220, 150, 20, 160, 4, 'ink');
        $this->el(400, 546, 24, 30, 'ink');
    }

    private function k_cookset(): void
    {
        $this->cookpot(150, 260, 330, 'steel');
        $this->cookpot(430, 220, 270, 'main');
        $this->rr(260, 540, 640, 580, 14, 'ink');
        $this->el(180, 560, 90, 40, 'ink');
    }

    private function cookpot(int $x, int $w, int $h, string $col): void
    {
        $top = 640 - $h;
        $this->rr($x, $top, $x + $w, 640, 20, $col);
        $this->rr($x - 6, $top - 36, $x + $w + 6, $top + 10, 14, 'dark');
        $this->rr($x + (int) ($w / 2) - 28, $top - 56, $x + (int) ($w / 2) + 28, $top - 28, 10, 'ink');
        $this->rect($x + 20, $top + 30, $x + 40, 616, 'steelL');
        $this->ln($x + $w, $top + 40, $x + $w + 60, $top + 40, 14, 'ink');
    }

    // ------------------------------------------------------------------ clothing

    private function garment(string $kind): void
    {
        $long = in_array($kind, ['pullover', 'jacket', 'fleecejacket', 'hoodie', 'shirt'], true);
        $body = 'main';

        // Sleeves first, then the body over their top.
        if ($long) {
            $this->poly([270, 230, 150, 480, 150, 640, 230, 640, 280, 400, 300, 300], 'dark');
            $this->poly([530, 230, 650, 480, 650, 640, 570, 640, 520, 400, 500, 300], 'dark');
            $this->rr(150, 600, 232, 640, 10, 'darker');
            $this->rr(568, 600, 650, 640, 10, 'darker');
        } else {
            $this->poly([270, 230, 130, 400, 220, 450, 300, 330], 'dark');
            $this->poly([530, 230, 670, 400, 580, 450, 500, 330], 'dark');
        }

        if ($kind === 'hoodie') {
            $this->el(400, 220, 220, 150, 'darker');
        }

        $this->poly([300, 200, 500, 200, 540, 260, 540, 640, 260, 640, 260, 260], $body);

        switch ($kind) {
            case 'tee':
                $this->el(400, 205, 130, 64, 'darker');
                $this->el(400, 198, 100, 40, 'ink2');
                $this->rect(262, 610, 538, 640, 'dark');
                break;
            case 'pullover':
                $this->poly([340, 200, 460, 200, 440, 330, 360, 330], 'darker');
                $this->ln(400, 210, 400, 330, 5, 'steel');
                $this->rr(260, 606, 540, 640, 8, 'darker');
                $this->poly([360, 440, 440, 440, 430, 500, 370, 500], 'dark');
                break;
            case 'jacket':
                $this->arc(400, 222, 240, 170, 180, 360, 26, 'darker');
                $this->ln(400, 232, 400, 640, 8, 'ink');
                $this->rr(290, 420, 370, 450, 8, 'dark');
                $this->rr(430, 420, 510, 450, 8, 'dark');
                $this->rect(262, 560, 538, 566, 'dark');
                break;
            case 'fleecejacket':
                $this->el(400, 210, 150, 60, 'darker');
                $this->ln(400, 232, 400, 640, 8, 'steelD');
                $this->rr(280, 500, 360, 590, 14, 'dark');
                $this->rr(440, 500, 520, 590, 14, 'dark');
                $this->rr(260, 606, 540, 640, 8, 'darker');
                break;
            case 'hoodie':
                $this->el(400, 210, 170, 70, 'ink2');
                $this->poly([300, 440, 500, 440, 520, 560, 280, 560], 'dark');
                $this->ln(370, 240, 360, 340, 6, 'pale');
                $this->ln(430, 240, 440, 340, 6, 'pale');
                $this->rr(260, 606, 540, 640, 8, 'darker');
                break;
            case 'shirt':
                $this->poly([340, 200, 400, 270, 460, 200, 440, 190, 400, 240, 360, 190], 'lighter');
                $this->ln(400, 270, 400, 640, 4, 'dark');
                foreach ([310, 380, 450, 520, 590] as $y) {
                    $this->el(400, $y, 12, 12, 'lighter');
                }
                $this->rr(290, 330, 350, 390, 8, 'dark');
                $this->rr(450, 330, 510, 390, 8, 'dark');
                break;
            case 'pants':
            case 'leggings':
                break;
        }
    }

    private function k_tee(): void
    {
        $this->garment('tee');
    }

    private function k_pullover(): void
    {
        $this->garment('pullover');
    }

    private function k_jacket(): void
    {
        $this->poly([300, 170, 500, 170, 470, 250, 330, 250], 'darker');
        $this->garment('jacket');
    }

    private function k_fleecejacket(): void
    {
        $this->garment('fleecejacket');
    }

    private function k_hoodie(): void
    {
        $this->garment('hoodie');
    }

    private function k_shirt(): void
    {
        $this->garment('shirt');
    }

    private function k_pants(): void
    {
        $this->poly([280, 160, 520, 160, 540, 640, 420, 640, 400, 330, 380, 640, 260, 640], 'main');
        $this->rect(280, 160, 520, 200, 'dark');
        $this->rr(300, 150, 500, 180, 6, 'ink2');
        // The zip-off line below each knee.
        $this->rect(265, 500, 392, 506, 'darker');
        $this->rect(408, 500, 535, 506, 'darker');
        $this->rr(300, 230, 360, 300, 8, 'dark');
        $this->rr(440, 230, 500, 300, 8, 'dark');
        $this->ln(400, 200, 400, 330, 4, 'darker');
    }

    private function k_leggings(): void
    {
        $this->poly([300, 160, 500, 160, 510, 640, 420, 640, 400, 340, 380, 640, 290, 640], 'ink2');
        $this->rect(300, 160, 500, 215, 'ink');
        $this->rr(430, 250, 490, 330, 10, 'ink');
        $this->ln(330, 300, 320, 600, 5, 'main');
        $this->ln(470, 300, 480, 600, 5, 'main');
    }

    private function k_poncho(): void
    {
        $this->poly([400, 170, 660, 600, 140, 600], 'main');
        $this->poly([400, 170, 520, 380, 280, 380], 'dark');
        $this->el(400, 190, 150, 90, 'darker');
        $this->el(400, 200, 100, 56, 'ink');
        $this->rect(150, 586, 650, 604, 'light');
        $this->ln(380, 260, 380, 360, 5, 'pale');
        $this->ln(420, 260, 420, 360, 5, 'pale');
        $this->rr(340, 450, 460, 540, 18, 'dark');
    }

    private function k_shoe(): void
    {
        $this->rr(120, 560, 700, 640, 34, 'white');
        $this->rect(120, 580, 700, 596, 'steelD');
        $this->poly([150, 570, 150, 400, 250, 330, 330, 370, 520, 420, 690, 520, 690, 580, 150, 580], 'main');
        $this->poly([150, 580, 150, 400, 250, 330, 280, 400, 230, 520, 190, 580], 'dark');
        $this->el(640, 540, 110, 100, 'dark');
        $this->poly([330, 370, 470, 420, 420, 450, 300, 400], 'ink2');
        foreach ([370, 420, 470, 520] as $x) {
            $this->ln($x, 380 + ($x - 370) * 0.3, $x + 24, 420 + ($x - 370) * 0.3, 7, 'white');
        }
        $this->poly([170, 350, 290, 330, 260, 400, 170, 420], 'light');
        $this->rect(150, 560, 690, 568, 'lighter');
    }

    private function k_boot(): void
    {
        $this->rr(120, 570, 690, 640, 22, 'ink');
        $this->poly([160, 575, 170, 160, 360, 160, 380, 380, 560, 440, 690, 520, 690, 575], 'woodD');
        $this->poly([170, 160, 360, 160, 366, 240, 172, 240], 'ink2');
        $this->poly([380, 380, 560, 440, 690, 520, 690, 575, 160, 575, 160, 540, 380, 540], 'wood');
        foreach ([260, 320, 380, 440] as $i => $y) {
            $this->ln(250, $y - 20 + $i * 4, 360, $y + 14 + $i * 8, 8, 'woodL');
        }
        $this->rect(160, 548, 690, 574, 'woodL');
        $this->rr(300, 120, 380, 190, 12, 'ink2');
    }

    private function k_helmet(): void
    {
        imagefilledarc($this->im, 400, 560, 560, 760, 180, 360, $this->c['main'], IMG_ARC_PIE);
        foreach ([-50, -20, 10, 40] as $a) {
            $r = deg2rad($a);
            $this->ln(400 + sin($r) * 200 - 14, 330 - cos($r) * 20 + 90, 400 + sin($r) * 240 + 14, 330 - cos($r) * 20 + 190, 26, 'pale');
        }
        $this->el(400, 560, 560, 70, 'dark');
        $this->rr(120, 556, 680, 584, 12, 'ink');
        $this->arc(400, 590, 340, 190, 20, 160, 10, 'ink2');
        $this->rr(380, 640 - 66, 420, 640 - 36, 8, 'steel');
        $this->poly([200, 440, 300, 380, 280, 440], 'light');
    }

    private function k_bikelight(): void
    {
        $this->rr(110, 470, 450, 600, 36, 'ink');
        $this->rr(110, 470, 200, 600, 36, 'ink2');
        $this->el(310, 535, 180, 120, 'steel');
        $this->el(310, 535, 144, 92, 'pale');
        $this->el(310, 535, 70, 44, $this->hsl(52, 0.95, 0.85));
        $this->rr(240, 600, 380, 640, 10, 'ink2');
        $this->rr(500, 480, 700, 560, 28, 'darker');
        $this->rr(500, 480, 700, 530, 28, 'red');
        foreach ([540, 600, 660] as $x) {
            $this->el($x, 520, 20, 20, $this->hsl(2, 0.9, 0.78));
        }
        $this->rr(560, 560, 640, 640, 10, 'ink2');
    }

    private function k_pump(): void
    {
        $this->rr(360, 220, 440, 580, 14, 'steel');
        $this->rect(370, 240, 386, 570, 'steelL');
        $this->rr(250, 170, 550, 220, 22, 'ink');
        $this->ln(400, 220, 400, 250, 24, 'ink2');
        $this->rr(250, 590, 550, 640, 20, 'ink');
        $this->rr(340, 540, 460, 600, 18, 'ink2');
        $this->el(400, 500, 110, 110, 'pale');
        $this->el(400, 500, 90, 90, 'white');
        $this->ln(400, 500, 430, 470, 5, 'red');
        $this->el(400, 500, 12, 12, 'ink');
        $this->arc(560, 600, 200, 140, 90, 270, 10, 'ink2');
    }

    private function k_lock(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->arc(340, 420, 400 - $i * 52, 280 - $i * 36, 0, 360, 22, $i % 2 ? 'ink' : 'ink2');
        }
        $this->rr(520, 360, 690, 500, 30, 'steel');
        $this->rr(540, 380, 670, 480, 22, 'steelL');
        foreach ([558, 600, 642] as $x) {
            $this->rr($x - 14, 410, $x + 14, 460, 8, 'ink');
            $this->el($x, 435, 12, 12, 'pale');
        }
        $this->rr(570, 330, 640, 370, 10, 'steelD');
    }

    // ------------------------------------------------------------------ personal care

    private function k_dropper(): void
    {
        $this->rr(300, 320, 500, 640, 46, 'amber');
        $this->rr(340, 250, 460, 340, 14, 'amber');
        $this->rr(360, 180, 440, 270, 12, 'ink');
        $this->poly([380, 100, 420, 100, 430, 190, 370, 190], 'ink2');
        $this->el(400, 100, 52, 40, 'ink2');
        $this->rr(330, 410, 470, 580, 8, 'pale');
        $this->rect(350, 440, 450, 448, 'main');
        $this->rect(350, 462, 420, 468, 'steel');
        $this->rect(318, 340, 332, 620, $this->hsl(36, 0.9, 0.55));
    }

    private function k_tub(): void
    {
        $this->rr(240, 410, 560, 640, 36, 'pale');
        $this->rr(230, 340, 570, 430, 24, 'main');
        $this->rect(250, 436, 550, 444, 'dark');
        $this->rr(300, 490, 500, 600, 14, 'white');
        $this->rect(330, 520, 470, 530, 'main');
        $this->rect(330, 548, 430, 556, 'steel');
        $this->rect(256, 440, 274, 626, 'white');
    }

    private function k_tube(): void
    {
        $this->poly([320, 160, 480, 160, 500, 570, 300, 570], 'white');
        $this->poly([300, 570, 500, 570, 490, 580, 310, 580], 'steel');
        $this->rect(300, 160, 500, 188, 'steelL');
        $this->rect(312, 138, 488, 162, 'steel');
        for ($i = 0; $i < 6; $i++) {
            $this->rect(312 + $i * 30, 138, 314 + $i * 30, 160, 'steelD');
        }
        $this->rr(310, 574, 490, 640, 14, 'main');
        $this->poly([330, 280, 470, 280, 476, 460, 326, 460], 'main');
        $this->el(400, 370, 70, 70, 'pale');
        $this->rect(340, 230, 460, 242, 'light');
        $this->rect(326, 200, 336, 560, 'pale');
    }

    private function k_teabox(): void
    {
        $this->poly([230, 270, 570, 270, 600, 200, 260, 200], 'light');
        $this->rr(230, 270, 570, 640, 10, 'dark');
        $this->poly([570, 270, 600, 200, 600, 570, 570, 640], 'darker');
        $this->rr(260, 330, 540, 560, 8, 'pale');
        $this->el(400, 440, 100, 100, 'main');
        $this->poly([360, 470, 400, 400, 450, 420, 410, 480], 'lighter');
        $this->ln(366, 474, 430, 410, 5, 'dark');
        $this->rect(280, 590, 520, 604, 'light');
        $this->rr(620, 540, 700, 640, 8, 'pale');
        $this->ln(660, 540, 670, 470, 3, 'steelD');
        $this->rr(654, 440, 690, 480, 4, 'white');
    }

    private function k_stick(): void
    {
        $this->el(400, 250, 210, 260, 'pale');
        $this->rr(295, 240, 505, 640, 40, 'white');
        $this->rr(290, 380, 510, 640, 30, 'main');
        $this->rect(290, 396, 510, 402, 'dark');
        $this->rr(330, 470, 470, 560, 10, 'pale');
        $this->rect(350, 500, 450, 508, 'dark');
        $this->rect(310, 260, 326, 380, 'steelL');
    }

    private function k_soap(): void
    {
        $this->rr(160, 480, 480, 640, 40, 'ink2');
        $this->poly([160, 500, 480, 500, 520, 450, 200, 450], 'ink2');
        $this->rr(300, 360, 660, 560, 44, 'ink');
        $this->rr(330, 392, 630, 528, 26, 'ink2');
        $this->el(480, 460, 90, 60, 'steel');
        $this->rr(440, 340, 500, 580, 8, 'woodL');
        $this->rect(446, 340, 452, 580, 'wood');
    }

    private function k_lipbalm(): void
    {
        foreach ([[240, 0], [400, 1], [560, 2]] as [$x, $i]) {
            $col = $this->hsl($this->hue + $i * 55, 0.55, 0.5);
            $this->rr($x - 56, 360 + $i * 20, $x + 56, 640, 24, $col);
            $this->rr($x - 56, 300 + $i * 20, $x + 56, 380 + $i * 20, 18, 'white');
            $this->rect($x - 56, 376 + $i * 20, $x + 56, 384 + $i * 20, 'steel');
            $this->rect($x - 40, 400 + $i * 20, $x - 28, 620, 'lighter');
        }
    }

    private function k_mist(): void
    {
        $this->rr(300, 310, 500, 640, 70, 'glass');
        $this->rr(310, 400, 490, 630, 60, $this->hsl(340, 0.5, 0.82));
        $this->rr(360, 250, 440, 330, 14, 'steel');
        $this->rr(340, 190, 500, 250, 18, 'ink');
        $this->rr(480, 196, 570, 224, 8, 'ink');
        foreach ([[600, 190], [640, 160], [620, 230], [660, 205]] as [$x, $y]) {
            $this->el($x, $y, 12, 12, $this->hsl(340, 0.4, 0.8, 30));
        }
        $this->rr(330, 440, 470, 560, 8, 'white');
        $this->rect(350, 480, 450, 488, 'main');
        $this->rect(322, 330, 336, 610, 'glassL');
    }

    private function k_brush(): void
    {
        $this->rr(370, 410, 430, 640, 28, 'wood');
        $this->rect(386, 430, 396, 630, 'woodL');
        $this->el(400, 270, 290, 360, 'woodD');
        $this->el(400, 262, 290, 360, 'wood');
        for ($row = 0; $row < 8; $row++) {
            for ($col = 0; $col < 6; $col++) {
                $x = 330 + $col * 28 + ($row % 2) * 14;
                $y = 140 + $row * 34;
                if (hypot(($x - 400) / 135, ($y - 262) / 170) < 0.86) {
                    $this->el($x, $y, 14, 14, 'ink');
                }
            }
        }
    }

    private function k_oilkit(): void
    {
        foreach ([0, 1, 2, 3, 4, 5] as $i) {
            $x = 215 + $i * 74;
            $this->rr($x - 28, 290, $x + 28, 420, 14, $this->hsl(30 + $i * 8, 0.8, 0.34));
            $this->rr($x - 18, 250, $x + 18, 300, 6, 'ink');
        }
        $this->rr(150, 380, 650, 640, 16, 'main');
        $this->rect(150, 380, 650, 420, 'dark');
        $this->rr(300, 480, 500, 580, 10, 'pale');
        $this->el(400, 530, 50, 50, 'main');
        $this->rect(150, 616, 650, 640, 'darker');
    }

    private function k_pumpbottle(): void
    {
        $this->rr(290, 280, 510, 640, 60, 'white');
        $this->rr(290, 400, 510, 640, 56, 'main');
        $this->rect(290, 400, 510, 410, 'dark');
        $this->rr(370, 210, 430, 290, 10, 'steel');
        $this->rr(350, 160, 450, 220, 14, 'ink2');
        $this->rr(420, 164, 560, 190, 10, 'ink2');
        $this->rr(330, 450, 470, 570, 10, 'white');
        $this->rect(350, 480, 450, 488, 'dark');
        $this->rect(350, 506, 420, 512, 'steelD');
        $this->rect(306, 440, 322, 620, 'lighter');
    }

    private function k_vitamin(): void
    {
        $this->rr(280, 300, 520, 640, 40, 'amber');
        $this->rr(270, 220, 530, 320, 20, 'main');
        $this->rect(270, 302, 530, 312, 'dark');
        $this->rr(300, 380, 500, 580, 14, 'white');
        $this->rect(320, 400, 480, 412, 'main');
        foreach ([[350, 470], [420, 490], [380, 530]] as $i => [$x, $y]) {
            $this->rr($x, $y, $x + 56, $y + 22, 11, $this->hsl(36 + $i * 20, 0.85, 0.55));
        }
        $this->rect(296, 330, 308, 610, $this->hsl(34, 0.8, 0.5));
    }

    private function k_firstaid(): void
    {
        $this->arc(400, 300, 160, 130, 180, 360, 20, 'ink2');
        $this->rr(170, 300, 630, 640, 40, 'white');
        $this->rr(170, 300, 630, 350, 30, 'steelL');
        $this->rect(170, 346, 630, 352, 'steel');
        $this->rect(350, 410, 450, 560, 'red');
        $this->rect(325, 435, 475, 535, 'red');
        $this->rr(190, 330, 230, 380, 6, 'steel');
        $this->rr(570, 330, 610, 380, 6, 'steel');
    }

    // ------------------------------------------------------------------ electronics

    private function k_headphones(): void
    {
        $this->arc(400, 400, 380, 470, 180, 360, 38, 'ink');
        $this->arc(400, 400, 380, 470, 200, 340, 20, 'ink2');
        foreach ([[220, 1], [580, -1]] as [$x, $d]) {
            $this->el($x, 460, 150, 240, 'main');
            $this->el($x + 22 * $d, 460, 92, 190, 'dark');
            $this->el($x + 40 * $d, 460, 50, 120, 'ink');
        }
        $this->rr(190, 390, 260, 440, 14, 'main');
        $this->rr(540, 390, 610, 440, 14, 'main');
    }

    private function k_earbuds(): void
    {
        $this->rr(250, 360, 550, 620, 90, 'white');
        $this->arc(400, 360, 300, 120, 180, 360, 6, 'steel');
        $this->rect(251, 430, 549, 436, 'steelL');
        $this->el(400, 540, 20, 20, $this->hsl(120, 0.7, 0.5));
        foreach ([[330, -1], [470, 1]] as [$x, $d]) {
            $this->ln($x, 300, $x + 12 * $d, 230, 22, 'white');
            $this->el($x, 310, 70, 70, 'pale');
            $this->el($x - 8 * $d, 306, 40, 40, 'main');
        }
        $this->el(400, 420, 6, 6, 'steelD');
    }

    private function k_speaker(): void
    {
        $this->rr(160, 340, 640, 620, 130, 'main');
        $this->rr(160, 340, 250, 620, 130, 'ink');
        $this->rr(550, 340, 640, 620, 130, 'ink');
        for ($x = 270; $x < 540; $x += 24) {
            for ($y = 370; $y < 600; $y += 24) {
                $this->el($x, $y, 8, 8, 'darker');
            }
        }
        $this->arc(400, 340, 180, 150, 180, 360, 16, 'ink');
        $this->el(220, 480, 36, 36, 'steel');
        $this->el(580, 480, 36, 36, 'steel');
        $this->rr(380, 584, 420, 600, 5, 'lighter');
    }

    private function k_charger(): void
    {
        $this->rr(250, 330, 550, 600, 40, 'white');
        $this->rr(250, 330, 550, 400, 40, 'pale');
        $this->rr(330, 530, 400, 560, 10, 'ink');
        $this->rr(420, 530, 490, 560, 10, 'ink');
        $this->rect(340, 450, 460, 458, 'main');
        $this->rr(340, 260, 366, 340, 6, 'steel');
        $this->rr(434, 260, 460, 340, 6, 'steel');
        $this->rect(264, 350, 274, 580, 'white');
        $this->rr(300, 600, 500, 624, 8, 'ink2');
    }

    private function k_powerbank(): void
    {
        $this->rr(280, 170, 520, 640, 50, 'main');
        $this->rr(280, 170, 520, 250, 50, 'dark');
        foreach ([330, 380, 430, 480] as $x) {
            $this->el($x - 20, 320, 14, 14, $x < 420 ? $this->hsl(120, 0.8, 0.6) : 'darker');
        }
        $this->rr(350, 590, 450, 622, 10, 'ink');
        $this->rr(350, 530, 450, 560, 10, 'ink');
        $this->rect(300, 400, 310, 600, 'lighter');
        $this->rr(340, 420, 460, 480, 10, 'light');
    }

    private function k_cable(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->arc(400, 420, 460 - $i * 62, 360 - $i * 50, 0, 360, 18, $i % 2 ? 'ink' : 'main');
        }
        $this->rr(560, 330, 700, 372, 10, 'steel');
        $this->rr(660, 336, 740, 366, 8, 'steelL');
        $this->rr(100, 500, 200, 550, 12, 'ink2');
        $this->rr(60, 510, 110, 540, 6, 'steel');
    }

    private function k_stand(): void
    {
        $this->poly([160, 500, 620, 380, 660, 410, 200, 540], 'steel');
        $this->poly([160, 500, 200, 540, 200, 552, 160, 512], 'steelD');
        $this->poly([200, 540, 660, 410, 660, 422, 200, 552], 'steelD');
        $this->ln(260, 540, 220, 640, 18, 'steelD');
        $this->ln(590, 400, 650, 640, 18, 'steelD');
        $this->ln(220, 640, 300, 640, 14, 'steelD');
        $this->ln(650, 640, 590, 640, 14, 'steelD');
        $this->rect(260, 482, 520, 488, 'steelL');
    }

    private function k_keyboard(): void
    {
        $this->poly([130, 380, 670, 380, 720, 600, 80, 600], 'ink');
        $this->poly([130, 380, 670, 380, 676, 400, 124, 400], 'ink2');
        for ($row = 0; $row < 5; $row++) {
            $t0 = 0.12 + $row * 0.17;
            $y = 380 + 220 * $t0;
            $left = 130 - 50 * $t0;
            $right = 670 + 50 * $t0;
            $n = 14 - ($row === 4 ? 6 : 0);
            $kw = ($right - $left - 24) / 14;
            for ($k = 0; $k < $n; $k++) {
                $x = $left + 12 + $k * $kw + ($row === 4 && $k > 3 ? $kw * 3 : 0);
                $w = $row === 4 && $k === 4 ? $kw * 4.6 : $kw - 5;
                $this->rr((int) $x, (int) $y, (int) ($x + $w), (int) ($y + 28 + $row * 4), 5, $k % 5 === 0 && $row < 2 ? 'main' : 'steelL');
            }
        }
    }

    private function k_mouse(): void
    {
        $this->el(400, 440, 270, 400, 'ink');
        $this->poly([300, 440, 500, 440, 520, 560, 280, 560], 'ink');
        $this->el(400, 420, 250, 380, 'main');
        $this->rect(398, 250, 402, 400, 'darker');
        $this->arc(400, 400, 250, 380, 190, 350, 4, 'darker');
        $this->rr(386, 300, 414, 360, 12, 'ink');
        $this->el(360, 360, 60, 120, 'light');
    }

    private function k_webcam(): void
    {
        $this->rr(250, 240, 550, 430, 70, 'ink');
        $this->el(400, 335, 150, 150, 'ink2');
        $this->el(400, 335, 112, 112, 'steelD');
        $this->el(400, 335, 78, 78, 'screenDark');
        $this->el(400, 335, 32, 32, $this->hsl(210, 0.9, 0.55));
        $this->el(384, 318, 14, 14, 'pale');
        $this->el(500, 270, 14, 14, $this->hsl(120, 0.8, 0.5));
        $this->rr(380, 420, 420, 500, 8, 'ink2');
        $this->rr(310, 490, 490, 530, 14, 'ink');
        $this->poly([300, 530, 500, 530, 520, 560, 280, 560], 'ink2');
    }

    private function k_phonecase(): void
    {
        $this->rr(260, 140, 540, 650, 64, 'glass');
        $this->rr(262, 142, 538, 648, 62, $this->hsl(200, 0.3, 0.96, 85));
        $this->rr(274, 154, 526, 636, 52, 'main');
        $this->rr(300, 180, 410, 300, 28, 'ink');
        $this->el(334, 214, 44, 44, 'steelD');
        $this->el(334, 214, 28, 28, 'screenDark');
        $this->el(376, 262, 44, 44, 'steelD');
        $this->el(376, 262, 28, 28, 'screenDark');
    }

    private function k_plug(): void
    {
        $this->rr(380, 260, 640, 520, 56, 'pale');
        $this->el(510, 390, 120, 120, 'steelL');
        $this->arc(510, 390, 150, 150, 0, 360, 6, 'steel');
        $this->rr(260, 290, 520, 560, 60, 'white');
        $this->el(390, 425, 150, 150, 'pale');
        $this->arc(390, 425, 170, 170, 220, 330, 10, $this->hsl(150, 0.7, 0.5));
        $this->arc(390, 425, 170, 170, 330, 220, 3, 'steelL');
        $this->el(390, 425, 80, 80, 'steelL');
        $this->rect(374, 410, 406, 440, 'steel');
        $this->rr(340, 560, 366, 640, 6, 'steelD');
        $this->rr(414, 560, 440, 640, 6, 'steelD');
    }

    private function k_monitorarm(): void
    {
        $this->rr(200, 560, 380, 610, 10, 'ink');
        $this->rr(260, 610, 320, 640, 6, 'ink2');
        $this->rr(270, 250, 310, 580, 12, 'steel');
        $this->ln(290, 270, 520, 220, 36, 'steelD');
        $this->ln(290, 270, 520, 220, 22, 'steel');
        $this->el(290, 270, 56, 56, 'ink');
        $this->el(520, 220, 52, 52, 'ink');
        $this->rr(520, 130, 580, 320, 12, 'ink2');
        $this->el(550, 175, 14, 14, 'steel');
        $this->el(550, 275, 14, 14, 'steel');
        $this->el(550, 225, 14, 14, 'steel');
    }

    private function k_notebook(): void
    {
        $this->rr(250, 160, 570, 640, 20, 'darker');
        $this->rr(240, 150, 560, 630, 20, 'main');
        $this->rr(240, 150, 290, 630, 16, 'dark');
        $this->rr(470, 150, 500, 630, 6, 'ink');
        for ($y = 200; $y < 600; $y += 32) {
            for ($x = 320; $x < 450; $x += 32) {
                $this->el($x, $y, 5, 5, 'lighter');
            }
        }
        $this->rr(340, 230, 440, 260, 8, 'pale');
        $this->rr(250, 640, 540, 650, 4, 'white');
    }

    private function k_pens(): void
    {
        $this->rr(150, 540, 650, 650, 40, 'ink2');
        $this->rect(160, 548, 640, 552, 'steel');
        for ($i = 0; $i < 12; $i++) {
            $a = -30 + $i * (60 / 11);
            [$tx, $ty] = $this->rot(400, 140, 400, 600, $a);
            [$bx, $by] = $this->rot(400, 580, 400, 600, $a);
            $col = $this->hsl($i * 30, 0.75, 0.5);
            $this->ln($bx, $by, $tx, $ty, 26, $col);
            $this->ln($bx, $by, $tx, $ty, 8, $this->hsl($i * 30, 0.7, 0.72));
            [$nx, $ny] = $this->rot(400, 118, 400, 600, $a);
            $this->ln($tx, $ty, $nx, $ny, 14, 'steel');
        }
        $this->rr(150, 590, 650, 650, 36, 'ink2');
        $this->ln(170, 600, 630, 600, 4, 'steel');
    }

    /** @return array{0: int, 1: int} body and edge colour for a device, by the name's seed: silver, space grey, white or a tinted metal */
    private function deviceTone(): array
    {
        return match (($this->seed >> 9) % 4) {
            0 => [$this->c['steel'], $this->c['steelD']],
            1 => [$this->hsl(220, 0.06, 0.4), $this->hsl(220, 0.06, 0.28)],
            2 => [$this->c['steelL'], $this->c['steel']],
            default => [$this->hsl($this->hue, 0.28, 0.72), $this->hsl($this->hue, 0.3, 0.5)],
        };
    }

    private function k_laptop(): void
    {
        [$body, $edge] = $this->deviceTone();
        $this->rr(190, 190, 610, 500, 22, $this->seed % 3 === 0 ? $edge : 'ink');
        $this->scene(208, 208, 592, 486, ($this->seed >> 4) % 3);
        $this->el(400, 200, 8, 8, 'ink2');
        $this->poly([110, 520, 690, 520, 726, 566, 74, 566], $body);
        $this->poly([110, 520, 690, 520, 696, 530, 104, 530], 'steelL');
        $this->rr(330, 530, 470, 548, 8, $edge);
        $this->poly([74, 566, 726, 566, 716, 576, 84, 576], $edge);
        if ($this->seed % 2 === 0) {
            $this->rect(150, 536, 300, 540, $edge);
            $this->rect(500, 536, 650, 540, $edge);
        }
    }

    private function k_phone(): void
    {
        [$body, $edge] = $this->deviceTone();
        $this->rr(290, 130, 510, 650, 54, $edge);
        $this->rr(298, 138, 502, 642, 48, $body);
        $this->rr(304, 144, 496, 636, 42, 'screenDark');
        $this->scene(308, 158, 492, 622, ($this->seed >> 5) % 3);
        if ($this->seed % 2 === 0) {
            $this->rr(358, 160, 442, 186, 13, 'ink');
        } else {
            $this->el(400, 174, 16, 16, 'ink');
        }
        foreach ([0, 1, 2, 3] as $i) {
            $this->rr(330 + $i * 38, 540, 354 + $i * 38, 564, 8, $this->hsl($this->hue + $i * 60, 0.6, 0.7));
        }
        $this->rr(508, 250, 514, 320, 3, $edge);
    }

    private function k_tablet(): void
    {
        $this->rr(130, 220, 670, 600, 40, 'ink');
        $this->rr(150, 240, 650, 580, 22, 'screenDark');
        $this->scene(156, 246, 644, 574, ($this->seed >> 6) % 3);
        $this->el(400, 230, 8, 8, 'ink2');
        $this->rr(300, 540, 500, 552, 6, 'steel');
    }

    private function k_tv(): void
    {
        $this->rr(80, 170, 720, 530, 10, 'ink');
        $this->scene(90, 180, 710, 520, ($this->seed >> 4) % 3);
        $this->rect(80, 520, 720, 536, 'ink2');
        if ($this->seed % 2 === 0) {
            $this->ln(160, 536, 130, 640, 22, 'ink2');
            $this->ln(640, 536, 670, 640, 22, 'ink2');
            $this->ln(124, 640, 200, 640, 14, 'ink');
            $this->ln(676, 640, 600, 640, 14, 'ink');
        } else {
            $this->poly([350, 536, 450, 536, 470, 620, 330, 620], 'ink2');
            $this->rr(250, 620, 550, 648, 12, 'ink');
        }
        $this->el(400, 528, 10, 10, $this->hsl(2, 0.8, 0.5));
        $this->poly([120, 180, 300, 180, 160, 500, 100, 500], $this->hsl($this->hue, 0.5, 0.7, 108));
    }

    private function k_monitor(): void
    {
        $this->rr(120, 190, 680, 500, 14, 'ink');
        $this->scene(132, 202, 668, 488, ($this->seed >> 4) % 3 + 1);
        $this->rect(120, 482, 680, 506, 'ink2');
        $this->rr(372, 506, 428, 600, 8, 'steelD');
        $this->el(400, 612, 280, 40, 'steelD');
        $this->rr(260, 600, 540, 628, 14, 'steelD');
        $this->rr(260, 592, 540, 616, 12, 'steel');
        $this->el(400, 495, 10, 10, 'steelL');
    }
}
