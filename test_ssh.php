<?php
require 'vendor/autoload.php';

use phpseclib3\Net\SSH2;

$host = '72.61.247.242';
$port = 65002;
$username = 'u321483967';
$password = 'Laayra@2004';
$timeout = 30;

echo "Connecting to \$host...\n";

try {
    $ssh = new SSH2($host, $port, $timeout);
    
    if (!$ssh->login($username, $password)) {
        throw new \Exception("SSH connection failed: Invalid password or authentication rejected by \$host\n" . print_r($ssh->getErrors(), true));
    }
    
    echo "Connected perfectly via phpseclib.\n";
    echo $ssh->exec('echo "Hello World"');
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
