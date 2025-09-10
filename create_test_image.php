<?php

// Create a simple test image
$image = imagecreate(100, 100);
$bg = imagecolorallocate($image, 255, 0, 0); // Red background
$text_color = imagecolorallocate($image, 255, 255, 255); // White text

// Add text to the image
imagestring($image, 5, 20, 40, 'TEST', $text_color);

// Save the image
imagepng($image, 'storage/app/public/maps/test.png');
imagedestroy($image);

echo "Test image created successfully!\n";
