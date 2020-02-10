<?php

namespace Valet;

use Httpful\Request;

class Valet
{
    private const GITHUB_LATEST_RELEASE_URL = 'https://api.github.com/repos/MarcoFaul/valetPlusReforged/releases/latest';
    private const SUDOERS_PATH = '/etc/sudoers.d';
    public $cli;
    public $files;
    public $valetBin = '/usr/local/bin/valet';

    /**
     * Create a new Valet instance.
     *
     * @param CommandLine $cli
     * @param Filesystem $files
     */
    public function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Symlink the Valet Bash script into the user's local bin.
     *
     * @return void
     */
    public function symlinkToUsersBin()
    {
        $this->cli->quietlyAsUser('rm ' . $this->valetBin);

        $this->cli->runAsUser('ln -s ' . realpath(__DIR__ . '/../../valet') . ' ' . $this->valetBin);
    }

    /**
     * Get the paths to all of the Valet extensions.
     *
     * @return array
     */
    public function extensions()
    {
        if (!$this->files->isDir(VALET_HOME_PATH . '/Extensions')) {
            return [];
        }

        return collect($this->files->scandir(VALET_HOME_PATH . '/Extensions'))
            ->reject(function($file) {
                return \is_dir($file);
            })
            ->map(function($file) {
                return VALET_HOME_PATH . '/Extensions/' . $file;
            })
            ->values()->all();
    }

    /**
     * Create the "sudoers.d" entry for running Valet.
     *
     * @return void
     */
    function createSudoersEntry()
    {
        $this->files->ensureDirExists(self::SUDOERS_PATH);

        $this->files->put(self::SUDOERS_PATH . '/valet', 'Cmnd_Alias VALET = /usr/local/bin/valet *
%admin ALL=(root) NOPASSWD:SETENV: VALET' . PHP_EOL);
    }

    /**
     * Remove the "sudoers.d" entry for running Valet.
     *
     * @return void
     */
    function removeSudoersEntry()
    {
        $this->cli->quietly(sprintf('rm %s/valet', self::SUDOERS_PATH));
    }

    /**
     * Determine if this is the latest version of Valet.
     *
     * @param string $currentVersion
     *
     * @return bool|int
     */
    public function onLatestVersion($currentVersion)
    {
        $response = Request::get(self::GITHUB_LATEST_RELEASE_URL)->send();

        return version_compare($currentVersion->getVersion(), $response->body->tag_name, '>=');
    }
}
