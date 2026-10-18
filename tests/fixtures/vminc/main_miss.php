<?php
@include __DIR__ . '/no_such_file.php';
echo "after|";
$r = @include __DIR__ . '/no_such_file.php';
echo $r . "|";
