<?php
include __DIR__ . '/nat_mod.php';
echo "|" . nat_mod_tag("main") . "|";
echo implode(",", array_map("nat_mod_tag", ["a", "bb"])) . "|";
