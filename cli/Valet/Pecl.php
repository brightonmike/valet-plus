<?php

namespace Valet;

use Exception;
use DomainException;

class Pecl
{

    const XDEBUG_EXTENSION = 'xdebug';
    const APCU_EXTENSION = 'apcu';
    const APCU_BC_EXTENSION = 'apcu_bc';
    const GEOIP_EXTENSION = 'geoip';

    const APCU_BC_ALIAS = 'apc';

    const EXTENSIONS = [
        self::XDEBUG_EXTENSION => [
            '5.6' => '2.2.7'
        ],
        self::APCU_BC_EXTENSION => [
            '5.6' => false
        ],
        self::APCU_EXTENSION => [
            '7.1' => false,
            '7.2' => false
        ],
        self::GEOIP_EXTENSION => [
            '7.1' => '1.1.1',
            '7.2' => '1.1.1'
        ]
    ];

    var $cli, $files;

    /**
     * Create a new Brew instance.
     *
     * @param  CommandLine $cli
     * @param  Filesystem $files
     */
    function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    function installed($extension)
    {
        return strpos($this->cli->runAsUser('pecl list | grep ' . $extension), $extension) !== false;
    }

    function install($extension, $iniPath, $version = null)
    {
        if ($version === null) {
            $result = $this->cli->runAsUser("pecl install $extension");
        } else {
            $result = $this->cli->runAsUser("pecl install $extension-$version");
        }

        $alias = $this->getExtensionAlias($extension);
        if (preg_match("/Installing '(.*$alias.so)'/", $result, $matches)) {
            $phpIniFile = $this->files->get($iniPath);
            $phpIniFile = preg_replace('/(zend_extension|extension)\="(.*' . $alias . '.so)"/', '$1="' . $matches[1] . '"', $phpIniFile);
            $phpIniFile = $this->alternativeInstall($extension, $phpIniFile, $result);
            $this->files->putAsUser($iniPath, $phpIniFile);
            output("$extension successfully installed");
        } else {
            throw new DomainException('Could not find installation path for: ' . $extension);
        }
    }

    function uninstall($extension, $iniPath, $version = null)
    {
        if ($version === null || $version === false) {
            $this->cli->passthru("pecl uninstall $extension");
        } else {
            $this->cli->passthru("pecl uninstall $extension-$version");
        }

        info("[$extension] removing extension from: $iniPath");
        $alias = $this->getExtensionAlias($extension);
        $phpIniFile = $this->files->get($iniPath);
        $phpIniFile = preg_replace('/;?(zend_extension|extension)\=".*' . $alias . '.so"/', '', $phpIniFile);
        $this->files->putAsUser($iniPath, $phpIniFile);
    }

    function uninstallExtensions($phpVersion, $iniPath)
    {
        info("[php@$phpVersion] Removing extensions");
        foreach (self::EXTENSIONS as $extension => $versions) {
            $version = $this->getVersion($extension, $phpVersion);
            if ($this->installed($extension)) {
                $this->uninstall($extension, $iniPath, $version);
            }
        }
    }

    function installExtensions($phpVersion, $iniPath)
    {
        info("[php@$phpVersion] Installing extensions");
        foreach (self::EXTENSIONS as $extension => $versions) {
            $version = $this->getVersion($extension, $phpVersion);
            if (!$this->installed($extension) && $version !== false) {
                $this->install($extension, $iniPath, $version);
            }
        }
    }

    private function alternativeInstall($extension, $phpIniFile, $result){
        switch ($extension){
            case self::APCU_BC_EXTENSION:
                return $this->replaceIniDefinition($this->getExtensionAlias(self::APCU_EXTENSION), $phpIniFile, $result);
            default:
                return $phpIniFile;
        }
    }

    private function replaceIniDefinition($extension, $phpIniFile, $result){
        if (!preg_match("/Installing '(.*apcu.so)'/", $result, $matches)) {
            throw new DomainException('Could not find installation path for: ' . $extension);
        }

        if(!preg_match('/(zend_extension|extension)\="(.*'.$extension.'.so)"/', $phpIniFile, $iniMatches)){
            throw new DomainException('Could not find ini definition for: ' . $extension);
        }

        $phpIniFile = preg_replace('/(zend_extension|extension)\="(.*'.$extension.'.so)"/', '', $phpIniFile);

        return $iniMatches[1].'="' . $matches[1] . '"'.$phpIniFile;
    }

    private function getExtensionAlias($extension){
        switch ($extension){
            case self::APCU_BC_EXTENSION:
                return self::APCU_BC_ALIAS;
            default:
                return $extension;
        }
    }

    private function getVersion($extension, $phpVersion)
    {
        if (array_key_exists($phpVersion, self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension][$phpVersion];
        }
        return null;
    }

}