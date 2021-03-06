<?php

namespace Valet;

use DomainException;

class PhpFpm
{
    const PHP_FORMULA_NAME = 'valet-php@';
    const PHP_V56_VERSION = '5.6';
    const PHP_V70_VERSION = '7.0';
    const PHP_V71_VERSION = '7.1';
    const PHP_V72_VERSION = '7.2';
    const PHP_V73_VERSION = '7.3';
    const PHP_V74_VERSION = '7.4';
    const PHP_DEFAULT_VERSION = self::PHP_V74_VERSION;

    const SUPPORTED_PHP_FORMULAE = [
        self::PHP_V56_VERSION => self::PHP_FORMULA_NAME . self::PHP_V56_VERSION,
        self::PHP_V70_VERSION => self::PHP_FORMULA_NAME . self::PHP_V70_VERSION,
        self::PHP_V71_VERSION => self::PHP_FORMULA_NAME . self::PHP_V71_VERSION,
        self::PHP_V72_VERSION => self::PHP_FORMULA_NAME . self::PHP_V72_VERSION,
        self::PHP_V73_VERSION => self::PHP_FORMULA_NAME . self::PHP_V73_VERSION,
        self::PHP_V74_VERSION => self::PHP_FORMULA_NAME . self::PHP_V74_VERSION
    ];

    const EOL_PHP_VERSIONS = [
        self::PHP_V56_VERSION,
        self::PHP_V70_VERSION,
        self::PHP_V71_VERSION,
        self::PHP_V72_VERSION
    ];

    const LOCAL_PHP_FOLDER = '/usr/local/etc/valet-php/';
    const DEPRECATED_PHP_VERSIONS = self::EOL_PHP_VERSIONS;

    public $brew;
    public $cli;
    public $files;
    public $pecl;
    public $peclCustom;

    const DEPRECATED_PHP_TAP = 'homebrew/php';
    const VALET_PHP_BREW_TAP = 'henkrehorst/php';

    /**
     * Create a new PHP FPM class instance.
     *
     * @param  Brew $brew
     * @param  CommandLine $cli
     * @param  Filesystem $files
     */
    public function __construct(Brew $brew, CommandLine $cli, Filesystem $files, Pecl $pecl, PeclCustom $peclCustom)
    {
        $this->cli = $cli;
        $this->brew = $brew;
        $this->files = $files;
        $this->pecl = $pecl;
        $this->peclCustom = $peclCustom;
    }

    /**
     * Install and configure DnsMasq.
     *
     * @return void
     * @throws \Exception
     */
    public function install(): void
    {
        if (!$this->hasInstalledPhp()) {
            $this->brew->ensureInstalled($this->getFormulaName(self::PHP_DEFAULT_VERSION));
        }

        if (!$this->brew->hasTap(self::VALET_PHP_BREW_TAP)) {
            info("[BREW TAP] Installing " . self::VALET_PHP_BREW_TAP);
            $this->brew->tap(self::VALET_PHP_BREW_TAP);
        } else {
            info("[BREW TAP] " . self::VALET_PHP_BREW_TAP . " already installed");
        }

        $version = $this->linkedPhp();

        $this->files->ensureDirExists('/usr/local/var/log', user());
        $this->updateConfiguration();
        $this->pecl->updatePeclChannel();
        $this->pecl->installExtensions($version);
        $this->peclCustom->installExtensions($version);
        $this->restart();
    }

    /**
     * @return string
     */
    public function iniPath(): string
    {
        $destFile = dirname($this->fpmConfigPath());
        $destFile = str_replace('/php-fpm.d', '', $destFile);
        $destFile = $destFile . '/conf.d/';

        return $destFile;
    }

    /**
     * Restart the currently linked PHP FPM process.
     *
     * @return void
     */
    public function restart(): void
    {
        $this->brew->restartService(self::SUPPORTED_PHP_FORMULAE[$this->linkedPhp()]);
    }

    /**
     * Stop all the PHP FPM processes.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->brew->stopService(self::SUPPORTED_PHP_FORMULAE);
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    public function fpmConfigPath(): string
    {
        $confLookup = [
            self::PHP_V74_VERSION => self::LOCAL_PHP_FOLDER . '7.4/php-fpm.d/www.conf',
            self::PHP_V73_VERSION => self::LOCAL_PHP_FOLDER . '7.3/php-fpm.d/www.conf',
            self::PHP_V72_VERSION => self::LOCAL_PHP_FOLDER . '7.2/php-fpm.d/www.conf',
            self::PHP_V71_VERSION => self::LOCAL_PHP_FOLDER . '7.1/php-fpm.d/www.conf',
            self::PHP_V70_VERSION => self::LOCAL_PHP_FOLDER . '7.0/php-fpm.d/www.conf',
            self::PHP_V56_VERSION => self::LOCAL_PHP_FOLDER . '5.6/php-fpm.conf',
        ];

        return $confLookup[$this->linkedPhp()];
    }

    /**
     * Get the formula name for a PHP version.
     *
     * @param string $version
     * @return string Formula name
     */
    public function getFormulaName(string $version): string
    {
        return self::SUPPORTED_PHP_FORMULAE[$version];
    }

    /**
     * Switch between versions of installed PHP. Switch to the provided version.
     *
     * @param $version
     *
     * @return void
     */
    public function switchTo(string $version): void
    {
        $currentVersion = $this->linkedPhp();

        if (!array_key_exists($version, self::SUPPORTED_PHP_FORMULAE)) {
            throw new DomainException("This version of PHP not available. The following versions are available: " . implode(
                ' ',
                array_keys(self::SUPPORTED_PHP_FORMULAE)
            ));
        }

        // If the current version equals that of the current PHP version, do not switch.
        if ($version === $currentVersion) {
            info('Already on this version');
            return;
        }

        if (in_array($version, self::EOL_PHP_VERSIONS)) {
            warning('Caution! The PHP version you\'re switching to is EOL.');
            warning('Please check http://php.net/supported-versions.php for more information.');
        }

        $installed = $this->brew->installed(self::SUPPORTED_PHP_FORMULAE[$version]);
        if (!$installed) {
            $this->brew->ensureInstalled(self::SUPPORTED_PHP_FORMULAE[$version]);
        }

        // Unlink the current PHP version.
        if (!$this->unlinkPhp($currentVersion)) {
            return;
        }

        // Relink libjpeg
        info('[libjpeg] Relinking');
        $this->cli->passthru('sudo ln -fs /usr/local/Cellar/jpeg/8d/lib/libjpeg.8.dylib /usr/local/opt/jpeg/lib/libjpeg.8.dylib');

        if (!$this->linkPHP($version, $currentVersion)) {
            return;
        }

        $this->stop();
        $this->install();
        info("Valet is now using " . self::SUPPORTED_PHP_FORMULAE[$version]);
    }

    /**
     * @deprecated Deprecated in favor of Pecl#installExtension();
     *
     * @param string $extension
     * @return bool
     */
    public function enableExtension(string $extension): bool
    {
        $currentPhpVersion = $this->linkedPhp();

        if (!$this->brew->installed($currentPhpVersion . '-' . $extension)) {
            $this->brew->ensureInstalled($currentPhpVersion . '-' . $extension);
        }

        $iniPath = $this->iniPath();

        if ($this->files->exists($iniPath . 'ext-' . $extension . '.ini')) {
            info($extension . ' was already enabled.');
            return false;
        }

        if ($this->files->exists($iniPath . 'ext-' . $extension . '.ini.disabled')) {
            $this->files->move(
                $iniPath . 'ext-' . $extension . '.ini.disabled',
                $iniPath . 'ext-' . $extension . '.ini'
            );
        }

        info('Enabled ' . $extension);
        return true;
    }

    /**
     * @deprecated Deprecated in favor of Pecl#uninstallExtesnion();
     *
     * @param string $extension
     * @return bool
     */
    public function disableExtension(string $extension): bool
    {
        $iniPath = $this->iniPath();
        if ($this->files->exists($iniPath . 'ext-' . $extension . '.ini.disabled')) {
            info($extension . ' was already disabled.');
            return false;
        }

        if ($this->files->exists($iniPath . 'ext-' . $extension . '.ini')) {
            $this->files->move(
                $iniPath . 'ext-' . $extension . '.ini',
                $iniPath . 'ext-' . $extension . '.ini.disabled'
            );
        }

        info('Disabled ' . $extension);
        return true;
    }

    /**
     * @deprecated Deprecated in favor of Pecl#installed();
     *
     * @param string $extension
     * @return bool
     */
    public function isExtensionEnabled(string $extension): bool
    {
        $currentPhpVersion = $this->brew->linkedPhp();

        if (!$this->brew->installed($currentPhpVersion . '-' . $extension)) {
            $this->brew->ensureInstalled($currentPhpVersion . '-' . $extension);
        }

        $iniPath = $this->iniPath();

        if ($this->files->exists($iniPath . 'ext-' . $extension . '.ini')) {
            info($extension . ' is enabled.');
        } else {
            info($extension . ' is disabled.');
        }

        return true;
    }

    /**
     * @return bool
     */
    public function enableAutoStart(): bool
    {
        $iniPath = $this->iniPath();
        if ($this->files->exists($iniPath . 'z-performance.ini')) {
            $this->cli->passthru('sed -i "" "s/xdebug.remote_autostart=0/xdebug.remote_autostart=1/g" ' . $iniPath . 'z-performance.ini');
            info('xdebug.remote_autostart is now enabled.');
            return true;
        }
        warning('Cannot find z-performance.ini, please re-install Valet+');
        return false;
    }

    /**
     * @return bool
     */
    public function disableAutoStart(): bool
    {
        $iniPath = $this->iniPath();
        if ($this->files->exists($iniPath . 'z-performance.ini')) {
            $this->cli->passthru('sed -i "" "s/xdebug.remote_autostart=1/xdebug.remote_autostart=0/g" ' . $iniPath . 'z-performance.ini');
            info('xdebug.remote_autostart is now disabled.');
            return true;
        }
        warning('Cannot find z-performance.ini, please re-install Valet+');
        return false;
    }

    /**
     * Determine which version of PHP is linked with Homebrew.
     *
     * @return string
     * @internal param bool $asFormula
     */
    public function linkedPhp(): string
    {
        if (!$this->files->isLink('/usr/local/bin/php')) {
            throw new DomainException("Unable to determine linked PHP.");
        }

        $resolvedPath = $this->files->readLink('/usr/local/bin/php');

        $versions = self::SUPPORTED_PHP_FORMULAE;

        foreach ($versions as $version => $brewname) {
            if (strpos($resolvedPath, '/' . $brewname . '/') !== false) {
                return $version;
            }
        }

        throw new DomainException("Unable to determine linked PHP.");
    }

    /**
     * Determine if a compatible PHP version is installed through Homebrew.
     *
     * @return bool
     */
    public function hasInstalledPhp(): bool
    {
        foreach (self::SUPPORTED_PHP_FORMULAE as $version => $brewName) {
            if ($this->brew->installed($brewName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update the PHP FPM configuration.
     *
     * @return void
     */
    public function updateConfiguration(): void
    {
        $contents = $this->files->get($this->fpmConfigPath());

        $contents = preg_replace('/^user = .+$/m', 'user = ' . user(), $contents);
        $contents = preg_replace('/^group = .+$/m', 'group = staff', $contents);
        $contents = preg_replace('/^listen = .+$/m', 'listen = ' . VALET_HOME_PATH . '/valet.sock', $contents);
        $contents = preg_replace('/^;?listen\.owner = .+$/m', 'listen.owner = ' . user(), $contents);
        $contents = preg_replace('/^;?listen\.group = .+$/m', 'listen.group = staff', $contents);
        $contents = preg_replace('/^;?listen\.mode = .+$/m', 'listen.mode = 0777', $contents);
        $contents = preg_replace(
            '/^;?php_admin_value\[error_log\] = .+$/m',
            'php_admin_value[error_log] = ' . VALET_HOME_PATH . '/Log/php.log',
            $contents
        );
        $this->files->put($this->fpmConfigPath(), $contents);

        $this->writePerformanceConfiguration();

        // Get php.ini file.
        $extensionDirectory = $this->pecl->getExtensionDirectory();
        $phpIniPath = $this->pecl->getPhpIniPath();
        $contents = $this->files->get($phpIniPath);

        // Replace all extension_dir directives with nothing. And place extension_dir directive for valet+
        $contents = preg_replace(
            "/ *extension_dir = \"(.*)\"\n/",
            '',
            $contents
        );
        $contents = "extension_dir = \"$extensionDirectory\"\n" . $contents;

        // Save php.ini file.
        $this->files->putAsUser($phpIniPath, $contents);
    }

    /**
     * @return void
     */
    public function writePerformanceConfiguration(): void
    {
        $path = $this->iniPath() . 'z-performance.ini';

        if (file_exists($path)) {
            return;
        }

        $systemZoneName = readlink('/etc/localtime');
        // All versions below High Sierra
        $systemZoneName = str_replace('/usr/share/zoneinfo/', '', $systemZoneName);
        // macOS High Sierra has a new location for the timezone info
        $systemZoneName = str_replace('/var/db/timezone/zoneinfo/', '', $systemZoneName);
        $contents = $this->files->get(__DIR__ . '/../stubs/z-performance.ini');
        $contents = str_replace('TIMEZONE', $systemZoneName, $contents);

        $iniPath = $this->iniPath();
        $this->files->ensureDirExists($iniPath, user());
        $this->files->putAsUser($path, $contents);
    }

    /**
     * @return void
     */
    public function checkInstallation(): void
    {
        // Check for errors within the installation of php.
        info('[php] Checking for errors within the php installation...');
        if ($this->brew->installed('php56') ||
            $this->brew->installed('php70') ||
            $this->brew->installed('php71') ||
            $this->brew->installed('php72') ||
            $this->brew->installed('n98-magerun') ||
            $this->brew->installed('n98-magerun2') ||
            $this->brew->installed('drush') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '5.6/ext-intl.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '5.6/ext-mcrypt.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '5.6/ext-apcu.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.0/ext-intl.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.0/ext-mcrypt.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.0/ext-apcu.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.1/ext-intl.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.1/ext-mcrypt.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.1/ext-apcu.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.2/ext-intl.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.2/ext-mcrypt.ini') ||
            $this->files->exists(self::LOCAL_PHP_FOLDER . '7.2/ext-apcu.ini') ||
            $this->brew->hasTap(self::DEPRECATED_PHP_TAP)
        ) {
            // Errors found - prompt to run fix logic
            throw new DomainException("[php] Valet+ found errors within the installation.\n
            run: valet fix for valet to try and resolve these errors");
        }
    }

    /**
     * Fixes common problems with php installations from Homebrew.
     *
     * @param bool $reinstall
     */
    public function fix(bool $reinstall = false): void
    {
        // Remove old homebrew/php tap packages.
        info('Removing all old php56- packages from homebrew/php tap');
        output($this->cli->runAsUser('brew list | grep php56- | xargs brew uninstall'));
        info('Removing all old php70- packages from homebrew/php tap');
        output($this->cli->runAsUser('brew list | grep php70- | xargs brew uninstall'));
        info('Removing all old php71- packages from homebrew/php tap');
        output($this->cli->runAsUser('brew list | grep php71- | xargs brew uninstall'));
        info('Removing all old php72- packages from homebrew/php tap');
        output($this->cli->runAsUser('brew list | grep php72- | xargs brew uninstall'));

        // Remove deprecated n98-magerun packages.
        info('Removing all old n98-magerun packages from homebrew/php tap');
        output($this->cli->runAsUser('brew list | grep n98-magerun | xargs brew uninstall'));

        // Remove homebrew/php tap.
        info('Removing drush package from homebrew/php tap');
        output($this->cli->runAsUser('brew list | grep drush | xargs brew uninstall'));

        // Disable extensions that are not managed by the PECL manager or within php core.
        $deprecatedExtensions = ['apcu', 'intl', 'mcrypt'];
        foreach (self::DEPRECATED_PHP_VERSIONS as $phpVersion) {
            info('[php' . $phpVersion . '] Disabling modules: ' . implode(', ', $deprecatedExtensions));
            foreach ($deprecatedExtensions as $extension) {
                if ($this->files->exists(self::LOCAL_PHP_FOLDER . "$phpVersion/ext-$extension.ini")) {
                    $this->files->move(
                        self::LOCAL_PHP_FOLDER . "$phpVersion/ext-$extension.ini",
                        self::LOCAL_PHP_FOLDER . "$phpVersion/ext-$extension.ini.disabled"
                    );
                }
            }
        }

        // If full reinstall is required remove PHP formulae. This will also uninstall formulae in the following format:
        // php@{version}.
        if ($reinstall) {
            info('Trying to remove php56...');
            output($this->cli->runAsUser('brew uninstall php56'));
            info('Trying to remove php70...');
            output($this->cli->runAsUser('brew uninstall php70'));
            info('Trying to remove php71...');
            output($this->cli->runAsUser('brew uninstall php71'));
            info('Trying to remove php72...');
            output($this->cli->runAsUser('brew uninstall php72'));
            info('Trying to remove php73...');
            output($this->cli->runAsUser('brew uninstall php73'));
            info('Trying to remove php74...');
            output($this->cli->runAsUser('brew uninstall php74'));
        }

        // set default php version
        // If the current php is not the given default, link it.
        info('Installing and linking new PHP homebrew/core version.');
        output($this->cli->runAsUser('brew uninstall ' . self::SUPPORTED_PHP_FORMULAE[self::PHP_DEFAULT_VERSION]));
        output($this->cli->runAsUser('brew install ' . self::SUPPORTED_PHP_FORMULAE[self::PHP_DEFAULT_VERSION]));
        output($this->cli->runAsUser('brew unlink ' . self::SUPPORTED_PHP_FORMULAE[self::PHP_DEFAULT_VERSION]));
        output($this->cli->runAsUser('brew link ' . self::SUPPORTED_PHP_FORMULAE[self::PHP_DEFAULT_VERSION] . ' --force --overwrite'));

        if ($this->brew->hasTap(self::DEPRECATED_PHP_TAP)) {
            info('[brew] untapping formulae ' . self::DEPRECATED_PHP_TAP);
            $this->brew->unTap(self::DEPRECATED_PHP_TAP);
        }

        warning(\sprintf("Please check your linked php version, you might need to restart your terminal! \nLinked PHP should be php %s:", self::PHP_DEFAULT_VERSION));
        output($this->cli->runAsUser('php -v'));
    }

    /**
     * Link a PHP version to be used as binary.
     *
     * @param string $version
     * @param null $currentVersion
     *
     * @return bool
     */
    private function linkPhp(string $version, $currentVersion = null): bool
    {
        $isLinked = true;
        info("[php@$version] Linking");
        $output = $this->cli->runAsUser('brew link ' . self::SUPPORTED_PHP_FORMULAE[$version] . ' --force --overwrite', function () use (&$isLinked) {
            $isLinked = false;
        });

        // The output is about how many symlinks were created.
        // Sanitize the second half to prevent users from being confused.
        // So the only output would be:
        // Linking /usr/local/Cellar/valet-php@7.3/7.3.8... 25 symlinks created
        // Without the directions to create exports pointing towards the binaries.
        if (strpos($output, 'symlinks created')) {
            $output = substr($output, 0, strpos($output, 'symlinks created') + 8);
        }
        output($output);

        if ($isLinked === false) {
            warning("Could not link PHP version!" . PHP_EOL .
                "There appears to be an issue with your PHP $version installation!" . PHP_EOL .
                "See the output above for more information." . PHP_EOL);
        }

        if ($currentVersion !== null && $isLinked === false) {
            info("Linking back to previous version to prevent broken installation!");
            $this->linkPhp($currentVersion);
        }

        return $isLinked;
    }

    /**
     * Unlink a PHP version, removing the binary symlink.
     *
     * @param $version
     * @return bool
     */
    private function unlinkPhp(string $version): bool
    {
        $isUnlinked = true;
        info("[php@$version] Unlinking");
        output($this->cli->runAsUser('brew unlink ' . self::SUPPORTED_PHP_FORMULAE[$version], function () use (&$isUnlinked) {
            $isUnlinked = false;
        }));
        if ($isUnlinked === false) {
            warning("Could not unlink PHP version!" . PHP_EOL .
                "There appears to be an issue with your PHP $version installation!" . PHP_EOL .
                "See the output above for more information.");
        }

        return $isUnlinked;
    }
}
