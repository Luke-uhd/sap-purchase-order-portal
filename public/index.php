<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Bootstrap.php';
use App\Auth;
header('Location: '.(Auth::loggedIn()?'orders.php':'login.php'));exit;
