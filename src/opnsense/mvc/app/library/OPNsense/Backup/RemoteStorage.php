<?php

/*
 * Copyright (c) 2026 ddfdom
 * SPDX-License-Identifier: MIT, see LICENSE
 */

namespace OPNsense\Backup;

use OPNsense\Core\Config;

/**
 * Shared logic for the S3 and SFTP providers: settings, upload of config.xml and retention.
 * Does not implement IBackupProvider itself so BackupFactory skips it.
 * @package OPNsense\Backup
 */
abstract class RemoteStorage extends Base
{
    /** @var string model class holding the provider settings */
    protected $modelClass;

    private $mdl = null;

    /**
     * @return array provider specific fields (enable and common fields are added here)
     */
    abstract protected function providerFields();

    /**
     * store one file remotely
     * @param string $name file name (without directory)
     * @param string $data file content
     */
    abstract protected function put($name, $data);

    /**
     * @param string $prefix file name prefix
     * @return array remote file names (without directory) starting with $prefix
     */
    abstract protected function listFiles($prefix);

    /**
     * @param string $name file name (without directory) to delete
     */
    abstract protected function remove($name);

    protected function mdl()
    {
        if ($this->mdl === null) {
            $this->mdl = new $this->modelClass();
        }
        return $this->mdl;
    }

    /**
     * @param string $name file name
     * @return string path relative to the configured remote directory
     */
    protected function remotePath($name)
    {
        $dir = $this->mdl()->path->getValue();
        return $dir === '' ? $name : "{$dir}/{$name}";
    }

    public function getConfigurationFields()
    {
        $fields = array_merge(
            [['name' => 'enabled', 'type' => 'checkbox', 'label' => gettext('Enable')]],
            $this->providerFields(),
            [
                [
                    'name' => 'password_encryption',
                    'type' => 'password',
                    'label' => gettext('Encryption Password (Optional)'),
                    'help' => gettext('A password to encrypt your configuration'),
                ],
                [
                    'name' => 'numbackups',
                    'type' => 'text',
                    'label' => gettext('Number of backups to keep'),
                    'help' => gettext('Leave empty to disable. Combined with the number of days, a backup is removed as soon as one of both limits is exceeded. The latest backup is always kept.'),
                ],
                [
                    'name' => 'numdays',
                    'type' => 'text',
                    'label' => gettext('Number of days to keep backups'),
                    'help' => gettext('Leave empty to disable.'),
                ],
            ]
        );
        foreach ($fields as &$field) {
            // UpdateOnlyTextField renders as "" so secrets never reach the page
            $field['value'] = (string)$this->mdl()->getNodeByReference($field['name']);
        }
        return $fields;
    }

    public function setConfiguration($conf)
    {
        $mdl = $this->mdl();
        $this->setModelProperties($mdl, $conf);
        $validation_messages = $this->validateModel($mdl);
        if (empty($validation_messages)) {
            $mdl->serializeToConfig();
            Config::getInstance()->save();
        }
        return $validation_messages;
    }

    public function isEnabled()
    {
        return $this->mdl()->enabled->getValue() == '1';
    }

    /**
     * upload /conf/config.xml, then prune old copies of this host
     * @return array remaining remote files
     * @throws \Exception on transfer errors (shown in the GUI / remote backup cron output)
     */
    public function backup()
    {
        $mdl = $this->mdl();
        $cnf = Config::getInstance()->object();
        $prefix = 'config-' . $cnf->system->hostname . '.' . $cnf->system->domain . '-';

        $data = file_get_contents('/conf/config.xml');
        $password = $mdl->password_encryption->getValue();
        if ($password !== '') {
            $data = $this->encrypt($data, $password);
            if ($data === null) {
                throw new \Exception(gettext('Failed to encrypt the configuration'));
            }
        }
        $this->put($prefix . gmdate('YmdHis') . '.xml', $data);

        $pattern = '/^' . preg_quote($prefix, '/') . '\d{14}\.xml$/';
        $files = preg_grep($pattern, $this->listFiles($prefix));
        $expired = self::expired($files, (int)$mdl->numbackups->getValue(), (int)$mdl->numdays->getValue(), time());
        foreach ($expired as $name) {
            $this->remove($name);
        }
        return array_values(array_diff($files, $expired));
    }

    /**
     * select backups to delete, names end with -YYYYmmddHHMMSS.xml (UTC)
     * @param array $files backup names of one host
     * @param int $keep number of backups to keep, 0 for unlimited
     * @param int $days maximum age in days, 0 for unlimited
     * @param int $now current unix time
     * @return array names to delete, never the most recent one
     */
    public static function expired(array $files, $keep, $days, $now)
    {
        sort($files);
        array_pop($files); // the newest backup is never a candidate
        $expired = [];
        if ($keep > 0) {
            $expired = array_slice($files, 0, max(0, count($files) + 1 - $keep));
        }
        if ($days > 0) {
            $limit = gmdate('YmdHis', $now - $days * 86400);
            foreach ($files as $name) {
                if (substr($name, -18, 14) < $limit) {
                    $expired[] = $name;
                }
            }
        }
        return array_values(array_unique($expired));
    }
}
