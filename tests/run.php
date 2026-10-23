<?php

/*
 * Off-box checks: php tests/run.php [s3 <endpoint> <ak> <sk> | sftp <host> <port> <user> <keyfile> <dir>]
 * OPNsense classes are stubbed, the providers' own code runs unchanged.
 */

namespace OPNsense\Core {
    class Config
    {
        public static function getInstance()
        {
            return new self();
        }
        public function object()
        {
            return simplexml_load_string('<opnsense><system><hostname>fw</hostname><domain>lan</domain></system></opnsense>');
        }
    }
}

namespace OPNsense\Backup {
    interface IBackupProvider
    {
    }
    abstract class Base
    {
        public function encrypt($data, $pass)
        {
            return "ENC:{$data}";
        }
    }
    class Node
    {
        public function __construct(public $v)
        {
        }
        public function getValue()
        {
            return (string)$this->v;
        }
    }
}

namespace {
    $lib = __DIR__ . '/../src/opnsense/mvc/app/library/OPNsense/Backup';
    require "$lib/RemoteStorage.php";
    require "$lib/S3.php";
    require "$lib/Sftp.php";

    use OPNsense\Backup\Node;
    use OPNsense\Backup\RemoteStorage;

    function ok($cond, $msg)
    {
        echo ($cond ? 'ok   ' : 'FAIL ') . $msg . "\n";
        if (!$cond) {
            exit(1);
        }
    }

    function provider($class, array $settings)
    {
        $p = new $class();
        $mdl = new stdClass();
        foreach ($settings as $k => $v) {
            $mdl->$k = new Node($v);
        }
        (new ReflectionProperty(RemoteStorage::class, 'mdl'))->setValue($p, $mdl);
        return $p;
    }

    $now = gmmktime(12, 0, 0, 10, 22, 2026);
    $names = [];
    for ($d = 9; $d >= 0; $d--) {
        $names[] = 'config-fw.lan-' . gmdate('YmdHis', $now - $d * 86400) . '.xml';
    }
    shuffle($names);
    $sorted = $names;
    sort($sorted);
    ok(RemoteStorage::expired($names, 0, 0, $now) == [], 'no retention keeps everything');
    ok(RemoteStorage::expired($names, 3, 0, $now) == array_slice($sorted, 0, 7), 'keep 3 newest');
    ok(RemoteStorage::expired($names, 20, 0, $now) == [], 'keep more than present');
    ok(RemoteStorage::expired($names, 0, 5, $now) == array_slice($sorted, 0, 4), 'keep 5 days (day 5 is exactly the limit and kept)');
    ok(RemoteStorage::expired($names, 8, 5, $now) == array_slice($sorted, 0, 4), 'both limits: the stricter wins');
    ok(RemoteStorage::expired([$sorted[0]], 0, 1, $now) == [], 'newest is kept even when too old');
    ok(RemoteStorage::expired([], 3, 3, $now) == [], 'empty list');

    $mode = $argv[1] ?? '';
    $common = ['enabled' => '1', 'password_encryption' => '', 'numbackups' => '3', 'numdays' => '5'];
    if ($mode == 's3') {
        $p = provider('OPNsense\Backup\S3', $common + [
            'endpoint' => $argv[2], 'region' => 'eu-west-3', 'bucket' => 'backups',
            'access_key' => $argv[3], 'secret_key' => $argv[4], 'path' => 'opnsense/fw1',
        ]);
    } elseif ($mode == 'sftp') {
        $p = provider('OPNsense\Backup\Sftp', $common + [
            'host' => $argv[2], 'port' => $argv[3], 'user' => $argv[4],
            'private_key' => str_replace("\n", "\r\n", file_get_contents($argv[5])), 'path' => $argv[6],
        ]);
        $p->knownHosts = sys_get_temp_dir() . '/test_known_hosts';
    } else {
        exit(0);
    }
    // the caller pre-seeded 6 old backups (1..6 days old) plus an unrelated file
    $left = $p->backup();
    echo implode("\n", $left) . "\n";
    ok(count($left) == 3, "$mode: 3 backups left after retention");
    ok(substr(end($left), -18, 8) == gmdate('Ymd'), "$mode: the new backup is among them");
}
