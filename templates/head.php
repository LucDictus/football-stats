<?php

declare(strict_types=1);

use App\View;

/** @var string $pageTitle */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;700&family=Barlow:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
