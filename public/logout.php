<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Bootstrap.php';
use App\Auth;use App\ServiceLayerClient;
try{$sap=Auth::sapSession();if($sap)(new ServiceLayerClient())->logout($sap['cookie']);}catch(Throwable $e){}finally{Auth::destroy();}
header('Location: login.php');exit;
