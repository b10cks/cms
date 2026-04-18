<?php
require __DIR__."/../vendor/autoload.php";
use Jcupitt\Vips\Image;

$log = fn($s) => file_put_contents('/tmp/vips_test.log', date('H:i:s')." $s\n", FILE_APPEND);
file_put_contents('/tmp/vips_test.log', "--- start ---\n");

$path = "/Users/mwallner/Development/products/b10cks/cms/storage/app/spaces/01kp0g6rqbwpdgc4rjnmen2y7d/01kp0g6rqbwpdgc4rjnmen2y7d/01kp11p9enjrt0e9y0dtte8av9/animation-baumstamm.gif";

$log("before gifload");
$img = Image::gifload($path, ["n" => -1]);
$log("after gifload, before resize");
$resized = $img->resize(0.1);
$log("after resize, before set page-height");
$resized->set("page-height", 120);
$log("after set page-height, before set n-pages");
$resized->set("n-pages", 39);
$log("after set n-pages, before webpsave_buffer");
$buf = $resized->webpsave_buffer();
$log("after webpsave_buffer - done, buf size=".strlen($buf));
