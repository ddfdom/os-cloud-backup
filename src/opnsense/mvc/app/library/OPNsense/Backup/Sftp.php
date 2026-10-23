<?php

/*
 * Copyright (c) 2026 ddfdom
 * SPDX-License-Identifier: MIT, see LICENSE
 */

namespace OPNsense\Backup;

/**
 * Backup to an SFTP server using the base system OpenSSH client and key authentication
 * @package OPNsense\Backup
 */
class Sftp extends RemoteStorage implements IBackupProvider
{
    protected $modelClass = 'OPNsense\Backup\SftpSettings';

    /** @var string host keys are pinned on first connection (trust on first use) */
    public $knownHosts = '/conf/sftp_backup_known_hosts';

    public function getName()
    {
        return gettext('SFTP');
    }

    protected function providerFields()
    {
        return [
            ['name' => 'host', 'type' => 'text', 'label' => gettext('Server')],
            ['name' => 'port', 'type' => 'text', 'label' => gettext('Port')],
            ['name' => 'user', 'type' => 'text', 'label' => gettext('User Name')],
            [
                'name' => 'private_key',
                'type' => 'passwordarea',
                'label' => gettext('Private key'),
                'help' => gettext('OpenSSH private key without passphrase, its public key must be in authorized_keys on the server. The server host key is accepted on first connection, then checked.'),
            ],
            [
                'name' => 'path',
                'type' => 'text',
                'label' => gettext('Directory (optional)'),
                'help' => gettext('Existing directory, relative to the login directory unless it starts with /'),
            ],
        ];
    }

    protected function put($name, $data)
    {
        $local = tempnam(sys_get_temp_dir(), 'sftp-backup-');
        try {
            file_put_contents($local, $data);
            $this->sftp([sprintf('put "%s" "%s"', $local, $this->remotePath($name))]);
        } finally {
            @unlink($local);
        }
    }

    protected function listFiles($prefix)
    {
        $dir = $this->mdl()->path->getValue();
        $files = [];
        foreach ($this->sftp([$dir === '' ? 'ls -1' : sprintf('ls -1 "%s"', $dir)]) as $line) {
            // sftp -b echoes "sftp> ls ..." too, the caller filters on the backup name pattern
            $files[] = basename(trim($line));
        }
        return $files;
    }

    protected function remove($name)
    {
        $this->sftp([sprintf('rm "%s"', $this->remotePath($name))]);
    }

    /**
     * run sftp batch commands (paths are restricted by the model masks, no quoting issues)
     * @param array $commands sftp batch commands
     * @return array output lines
     * @throws \Exception when sftp fails
     */
    private function sftp(array $commands)
    {
        $mdl = $this->mdl();
        $key = tempnam(sys_get_temp_dir(), 'sftp-key-');
        $batch = tempnam(sys_get_temp_dir(), 'sftp-batch-');
        try {
            chmod($key, 0600);
            // textarea input comes with CRLF, OpenSSH wants LF and a final newline
            file_put_contents($key, trim(str_replace("\r", '', $mdl->private_key->getValue())) . "\n");
            file_put_contents($batch, implode("\n", $commands) . "\n");
            exec(sprintf(
                '/usr/bin/sftp -b %s -i %s -P %d -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=30 ' .
                '-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=%s %s 2>&1',
                escapeshellarg($batch),
                escapeshellarg($key),
                (int)$mdl->port->getValue(),
                escapeshellarg($this->knownHosts),
                escapeshellarg($mdl->user->getValue() . '@' . $mdl->host->getValue())
            ), $output, $retval);
        } finally {
            @unlink($key);
            @unlink($batch);
        }
        if ($retval != 0) {
            throw new \Exception('SFTP failed: ' . implode(' ', $output));
        }
        return $output;
    }
}
