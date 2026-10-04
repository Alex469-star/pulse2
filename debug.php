<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/models/Segment.php';

echo '<h3>Auth OK</h3>';
echo '<h3>Segment OK</h3>';

$segment = Segment::findById(1);
var_dump($segment);