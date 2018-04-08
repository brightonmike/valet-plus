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
    const IONCUBE_LOADER_EXTENSION = 'ioncube_loader_dar';

    const TAR_GZ_FILE_EXTENSION = '.tar.gz';

    const APCU_BC_ALIAS = 'apc';

    const NORMAL_EXTENSION_TYPE = 'extension';
    const ZEND_EXTENSION_TYPE = 'zend_extension';

    const EXTENSIONS = [
        self::XDEBUG_EXTENSION => [
            '5.6' => '2.2.7',
            'default' => false
        ],
        self::APCU_BC_EXTENSION => [
            '5.6' => false
        ],
        self::APCU_EXTENSION => [
            '7.2' => false,
            '7.1' => false,
            '7.0' => false,
            '5.6' => '4.0.11'
        ],
        self::GEOIP_EXTENSION => [
            '7.2' => '1.1.1',
            '7.1' => '1.1.1',
            '7.0' => '1.1.1'
        ],
        self::IONCUBE_LOADER_EXTENSION => [
            '7.2' => 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_dar_x86-64.tar.gz',
            '7.1' => 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_dar_x86-64.tar.gz',
            '7.0' => 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_dar_x86-64.tar.gz',
            '5.6' => 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_dar_x86-64.tar.gz',
            'file_extension' => self::TAR_GZ_FILE_EXTENSION,
            'packaged_directory' => 'ioncube',
            'custom' => true,
            'default' => false,
            'extension_type' => self::ZEND_EXTENSION_TYPE,
            'extension_php_name' => 'the ionCube PHP Loader'
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


    function getExtensionDirectory()
    {
        return str_replace("\n", '', $this->cli->runAsUser('pecl config-get ext_dir'));
    }

    function getPhpIniPath()
    {
        return str_replace("\n", '', $this->cli->runAsUser('pecl config-get php_ini'));
    }

    function getPhpVersion()
    {
        $version = $this->cli->runAsUser('pecl version | grep PHP');
        $version = str_replace('PHP Version:', '', $version);
        $version = str_replace(' ', '', $version);
        $version = substr($version, 0, 3);
        return $version;
    }

    function isInstalled($extension)
    {
        if ($this->installed($extension)) {
            info("$extension is installed");
        } else {
            info("$extension is not installed");
        }
    }

    function installed($extension)
    {
        if ($this->isCustomExtension($extension)) {
            // Because custom extensions are not managed by pecl check "php -m" for its existence.
            $extensionName = $this->getExtensionName($extension);
            return strpos($this->cli->runAsUser('php -m | grep \'' . $extensionName . '\''), $extensionName) !== false;
        } else {
            //@TODO: Evaluate if using php -m is more stable, package could be installed by PECL but not enabled in the php.ini file.
            return strpos($this->cli->runAsUser('pecl list | grep ' . $extension), $extension) !== false;
        }
    }

    function install($extension, $version = null)
    {
        if ($version === null) {
            $result = $this->cli->runAsUser("pecl install $extension");
        } else {
            $result = $this->cli->runAsUser("pecl install $extension-$version");
        }

        $alias = $this->getExtensionAlias($extension);
        $phpIniPath = $this->getPhpIniPath();
        $phpIniFile = $this->files->get($phpIniPath);
        $phpIniFile = $this->replaceIniDefinition($alias, $phpIniFile, $result);
        $phpIniFile = $this->alternativeInstall($extension, $phpIniFile, $result);
        $this->saveIniFile($phpIniPath, $phpIniFile);
        output("$extension successfully installed");
    }

    function customInstall($extension, $url)
    {

        // Get file name from url
        $urlSplit = explode('/', $url);
        $fileName = $urlSplit[count($urlSplit) - 1];

        // Check if .so is available
        $extensionDirectory = $this->getExtensionDirectory();
        $extensionAlias = $this->getExtensionAlias($extension);
        if ($this->files->exists($extensionDirectory . '/' . $extensionAlias) === false) {
            info("$extension is not available from PECL, downloading from: $url");
            $this->downloadExtension($extension, $url, $fileName, $extensionAlias, $extensionDirectory);
        } else {
            info("$extensionAlias found in $extensionDirectory skipping download..");
        }

        // Install php.ini directive.
        info("Adding $extensionAlias to php.ini...");
        $extensionType = $this->getExtensionType($extension);
        $phpIniPath = $this->getPhpIniPath();
        $directive = $extensionType . '="' . $extensionDirectory . '/' . $extensionAlias . '"';
        $phpIniFile = $this->files->get($phpIniPath);
        $this->saveIniFile($phpIniPath, $directive . "\n" . $phpIniFile);

        output("$extension successfully installed");
    }

    function downloadExtension($extension, $url, $fileName, $extensionAlias, $extensionDirectory)
    {
        $unpackagedDirectory = $this->getPackagedDirectory($extension);

        // Download and unzip
        $this->cli->passthru("cd /tmp && curl -O $url");

        // Unpackage the file using file extension.
        $fileExtension = $this->getFileExtension($extension);
        switch ($fileExtension) {
            case self::TAR_GZ_FILE_EXTENSION:
                info('Unpackaging .tar.gz:');
                $this->cli->passthru("cd /tmp && tar -xvzf $fileName");
                break;
            default:
                throw new DomainException("File extension $fileExtension is not supported yet!");
        }

        // Search for extension file in unpackaged directory using the extension alias.
        $files = $this->files->scandir("/tmp/$unpackagedDirectory");
        if (in_array($extensionAlias, $files)) {
            info("$extensionAlias was found, moving to extension directory: $extensionDirectory");
            $this->cli->runAsUser("cp /tmp/$unpackagedDirectory/$extensionAlias $extensionDirectory");
        } else {
            throw new DomainException("$extensionAlias could not be found!");
        }

        // Remove artifacts from /tmp folder.
        $this->cli->runAsUser("rm /tmp/$fileName");
        $this->cli->runAsUser("rm -r /tmp/$unpackagedDirectory/$extensionAlias $extensionDirectory");
    }

    function uninstall($extension, $version = null)
    {
        // Only call PECL uninstall if package is managed by PECL.
        if ($this->isCustomExtension($extension) === false) {
            if ($version === null || $version === false) {
                $this->cli->passthru("pecl uninstall $extension");
            } else {
                $this->cli->passthru("pecl uninstall $extension-$version");
            }
        }

        $this->removeIniDefinition($extension);
    }

    function uninstallExtensions($phpVersion)
    {
        info("[php@$phpVersion] Removing extensions");
        foreach (self::EXTENSIONS as $extension => $versions) {
            $this->uninstallExtension($extension);
        }
    }

    function uninstallExtension($extension)
    {
        $version = $this->getVersion($extension);
        if ($this->installed($extension)) {
            $this->uninstall($extension, $version);
            return true;
        }
        return false;
    }

    function installExtensions($onlyDefaults = true)
    {
        $phpVersion = $this->getPhpVersion();
        info("[php@$phpVersion] Installing extensions");
        foreach (self::EXTENSIONS as $extension => $versions) {
            if ($onlyDefaults && $this->isDefaultExtension($extension) === false) {
                continue;
            }

            $this->installExtension($extension);
        }
    }

    function installExtension($extension)
    {
        $version = $this->getVersion($extension);
        $isCustom = $this->isCustomExtension($extension);

        if ($this->installed($extension)) {
            return false;
        }

        if ($isCustom) {
            $this->customInstall($extension, $version);
        } elseif ($isCustom === false && $version !== false) {
            $this->install($extension, $version);
        }

        return true;
    }

    private function alternativeInstall($extension, $phpIniFile, $result)
    {
        switch ($extension) {
            case self::APCU_BC_EXTENSION:
                return $this->replaceIniDefinition($this->getExtensionAlias(self::APCU_EXTENSION), $phpIniFile, $result);
            default:
                return $phpIniFile;
        }
    }

    private function removeIniDefinition($extension)
    {
        $phpIniPath = $this->getPhpIniPath();
        info("[$extension] removing extension from: $phpIniPath");
        $alias = $this->getExtensionAlias($extension);
        $phpIniFile = $this->files->get($phpIniPath);
        if ($this->isCustomExtension($extension)) {
            $phpIniFile = preg_replace('/;?(zend_extension|extension)\=".*' . $alias . '"/', '', $phpIniFile);
        } else {
            $phpIniFile = preg_replace('/;?(zend_extension|extension)\=".*' . $alias . '.so"/', '', $phpIniFile);
        }
        $this->saveIniFile($phpIniPath, $phpIniFile);
    }

    private function saveIniFile($phpIniPath, $phpIniFile)
    {
        // Ioncube loader requires to be the first zend_extension loaded from the php.ini
        // before saving the ini file check if ioncube is enabled and move it to the top of the file.
        $ioncubeLoader = $this->getExtensionAlias(self::IONCUBE_LOADER_EXTENSION);
        if (preg_match('/(zend_extension|extension)\="(.*' . $ioncubeLoader . ')"/', $phpIniFile, $matches)) {
            $phpIniFile = preg_replace('/(zend_extension|extension)\="(.*' . $ioncubeLoader . ')"/', '', $phpIniFile);
            $phpIniFile = $matches[1] . '="' . $matches[2] . '"' . "\n" . $phpIniFile;
        }

        $this->files->putAsUser($phpIniPath, $phpIniFile);
    }

    private function replaceIniDefinition($extension, $phpIniFile, $result)
    {
        if (!preg_match("/Installing '(.*$extension.so)'/", $result, $matches)) {
            throw new DomainException('Could not find installation path for: ' . $extension);
        }

        if (!preg_match('/(zend_extension|extension)\="(.*' . $extension . '.so)"/', $phpIniFile, $iniMatches)) {
            throw new DomainException('Could not find ini definition for: ' . $extension);
        }

        $phpIniFile = preg_replace('/(zend_extension|extension)\="(.*' . $extension . '.so)"/', '', $phpIniFile);

        return $iniMatches[1] . '="' . $matches[1] . '"' . $phpIniFile;
    }

    private function isDefaultExtension($extension)
    {
        if (array_key_exists('default', self::EXTENSIONS[$extension])) {
            return false;
        } elseif (array_key_exists('default', self::EXTENSIONS[$extension]) === false) {
            return true;
        } else {
            return false;
        }
    }

    private function isCustomExtension($extension)
    {
        if (array_key_exists('custom', self::EXTENSIONS[$extension])) {
            return true;
        } elseif (array_key_exists('custom', self::EXTENSIONS[$extension]) === false) {
            return false;
        } else {
            return false;
        }
    }

    private function getExtensionAlias($extension)
    {
        switch ($extension) {
            case self::APCU_BC_EXTENSION:
                return self::APCU_BC_ALIAS;
            case self::IONCUBE_LOADER_EXTENSION:
                return self::IONCUBE_LOADER_EXTENSION . '_' . $this->getPhpVersion() . '.so';
            default:
                return $extension;
        }
    }

    private function getVersion($extension)
    {
        $phpVersion = $this->getPhpVersion();
        if (array_key_exists($phpVersion, self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension][$phpVersion];
        }
        return null;
    }

    private function getFileExtension($extension)
    {
        if (array_key_exists('file_extension', self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension]['file_extension'];
        }
        throw new DomainException('file_extension key is required for custom PECL packages');
    }

    private function getPackagedDirectory($extension)
    {
        if (array_key_exists('packaged_directory', self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension]['packaged_directory'];
        }
        throw new DomainException('packaged_directory key is required for custom PECL packages');
    }

    private function getExtensionType($extension)
    {
        if (array_key_exists('extension_type', self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension]['extension_type'];
        }
        throw new DomainException('extension_type key is required for custom PECL packages');
    }

    private function getExtensionName($extension)
    {
        if (array_key_exists('extension_php_name', self::EXTENSIONS[$extension])) {
            return self::EXTENSIONS[$extension]['extension_php_name'];
        }
        throw new DomainException('extension_php_name key is required for custom PECL packages');
    }

}