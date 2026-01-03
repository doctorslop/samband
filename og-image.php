<?php
// OG Image Generator for Sambandscentralen
// Generates a 1200x630 social preview image

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$width = 1200;
$height = 630;

$img = imagecreatetruecolor($width, $height);

// Colors
$bgColor = imagecolorallocate($img, 10, 22, 40); // #0a1628
$accentColor = imagecolorallocate($img, 252, 211, 77); // #fcd34d
$textColor = imagecolorallocate($img, 226, 232, 240); // #e2e8f0
$mutedColor = imagecolorallocate($img, 148, 163, 184); // #94a3b8
$surfaceColor = imagecolorallocate($img, 15, 31, 56); // #0f1f38

// Fill background
imagefill($img, 0, 0, $bgColor);

// Draw accent gradient rectangle (logo box simulation)
$boxX = 100;
$boxY = 200;
$boxSize = 120;
imagefilledrectangle($img, $boxX, $boxY, $boxX + $boxSize, $boxY + $boxSize, $accentColor);

// Draw police car emoji as text (fallback to symbol)
$fontSize = 60;
$centerX = $boxX + ($boxSize / 2);
$centerY = $boxY + ($boxSize / 2) + 20;

// Title and subtitle
$title = "Sambandscentralen";
$subtitle = "Svenska Polisens handelsenotiser";
$tagline = "Aktuella handelser i realtid";

// Try to use a system font, fallback to built-in
$fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$fontPathRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

if (file_exists($fontPath)) {
    // Title
    imagettftext($img, 56, 0, 260, 260, $textColor, $fontPath, $title);
    // Subtitle
    imagettftext($img, 24, 0, 260, 310, $accentColor, $fontPathRegular, $subtitle);
    // Tagline
    imagettftext($img, 20, 0, 260, 360, $mutedColor, $fontPathRegular, $tagline);
    // Police car in box
    imagettftext($img, 50, 0, $boxX + 30, $boxY + 80, $bgColor, $fontPathRegular, chr(0xF0).chr(0x9F).chr(0x9A).chr(0x94));
} else {
    // Fallback without TrueType fonts
    $largeFont = 5;
    $medFont = 4;
    $smallFont = 3;

    imagestring($img, $largeFont, 260, 230, $title, $textColor);
    imagestring($img, $medFont, 260, 290, $subtitle, $accentColor);
    imagestring($img, $smallFont, 260, 330, $tagline, $mutedColor);
}

// Draw live indicator
$liveX = 100;
$liveY = 500;
imagefilledellipse($img, $liveX + 8, $liveY + 8, 16, 16, imagecolorallocate($img, 16, 185, 129));
if (file_exists($fontPathRegular)) {
    imagettftext($img, 16, 0, $liveX + 25, $liveY + 14, imagecolorallocate($img, 16, 185, 129), $fontPathRegular, "Live");
} else {
    imagestring($img, 3, $liveX + 25, $liveY, "Live", imagecolorallocate($img, 16, 185, 129));
}

// Draw decorative line
imageline($img, 100, 450, 1100, 450, $surfaceColor);

// Bottom URL
if (file_exists($fontPathRegular)) {
    imagettftext($img, 18, 0, 100, 580, $mutedColor, $fontPathRegular, "sambandscentralen.se");
} else {
    imagestring($img, 3, 100, 570, "sambandscentralen.se", $mutedColor);
}

imagepng($img);
imagedestroy($img);
