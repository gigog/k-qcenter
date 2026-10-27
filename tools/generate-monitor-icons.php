<?php
declare(strict_types=1);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required\n");
    exit(1);
}

function roundedRect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

$canvas = imagecreatetruecolor(512, 512);
imageantialias($canvas, true);
$teal = imagecolorallocate($canvas, 11, 102, 100);
$green = imagecolorallocate($canvas, 25, 183, 159);
$mint = imagecolorallocate($canvas, 223, 247, 241);
$white = imagecolorallocate($canvas, 244, 255, 252);
$light = imagecolorallocate($canvas, 215, 255, 244);
$active = imagecolorallocate($canvas, 56, 213, 141);
imagefill($canvas, 0, 0, $teal);
roundedRect($canvas, 64, 72, 448, 388, 54, $white);
roundedRect($canvas, 64, 72, 448, 154, 41, $green);
imagefilledrectangle($canvas, 64, 113, 448, 154, $green);
foreach ([120, 164, 208] as $x) imagefilledellipse($canvas, $x, 113, 28, 28, $light);
roundedRect($canvas, 111, 194, 401, 306, 30, $mint);
imagefilledellipse($canvas, 256, 250, 82, 82, $teal);
imagefilledellipse($canvas, 256, 250, 44, 44, $mint);
imagefilledpolygon($canvas, [276, 270, 306, 294, 289, 306, 263, 278], $teal);
foreach ([178, 256, 334] as $x) imagefilledellipse($canvas, $x, 346, 34, 34, $active);
roundedRect($canvas, 192, 388, 320, 422, 17, $white);

$iconDir = dirname(__DIR__) . '/dist/icons';
imagepng($canvas, $iconDir . '/registration-monitor-icon-512.png', 9);
$small = imagecreatetruecolor(192, 192);
imagecopyresampled($small, $canvas, 0, 0, 0, 0, 192, 192, 512, 512);
imagepng($small, $iconDir . '/registration-monitor-icon-192.png', 9);
imagedestroy($small);
imagedestroy($canvas);
echo "Monitor icons generated\n";
