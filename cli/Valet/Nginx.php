<?php

namespace Valet;

use DomainException;
use Illuminate\Support\Collection;
use Valet\Os\Installer;

class Nginx
{
    public string $nginxDir;
    public string $nginxLogDir;
    public string $nginxConf;

    public function __construct(public CommandLine $cli, public Filesystem $files, public Configuration $configuration, public Site $site, public Installer $installer)
    {
        $this->nginxDir = $this->installer->os()->etcDir().'/nginx';
        $this->nginxLogDir = $this->installer->os()->logDir().'/nginx';
        $this->nginxConf = $this->nginxDir.'/nginx.conf';
    }

    /**
     * Install the configuration files for Nginx.
     */
    public function install(): void
    {
        if (! $this->installer->hasInstalledNginx()) {
            $this->installer->installOrFail('nginx');
        }

        $this->installConfiguration();
        $this->installServer();
        $this->installNginxDirectory();
    }

    /**
     * Install the Nginx configuration file.
     */
    public function installConfiguration(): void
    {
        info('Installing nginx configuration...');

        $contents = $this->files->getStub('nginx.conf');

        $this->files->putAsUser(
            $this->nginxConf,
            str_replace(['VALET_USER', 'VALET_HOME_PATH'], [user(), VALET_HOME_PATH], $contents)
        );
    }

    /**
     * Install the Valet Nginx server configuration file.
     */
    public function installServer(): void
    {
        $this->files->ensureDirExists($this->nginxDir.'/valet');

        $this->files->putAsUser(
            $this->nginxDir.'/valet/valet.conf',
            str_replace(
                ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX'],
                [VALET_HOME_PATH, VALET_SERVER_PATH, VALET_STATIC_PREFIX],
                $this->site->replaceLoopback($this->files->getStub('valet.conf'))
            )
        );

        $this->files->putAsUser(
            $this->nginxDir.'/fastcgi_params',
            $this->files->getStub('fastcgi_params')
        );
    }

    /**
     * Install the Nginx configuration directory to the ~/.config/valet directory.
     *
     * This directory contains all site-specific Nginx servers.
     */
    public function installNginxDirectory(): void
    {
        info('Installing nginx directory...');

        if (! $this->files->isDir($nginxDirectory = VALET_HOME_PATH.'/Nginx')) {
            $this->files->mkdirAsUser($nginxDirectory);
        }

        $this->files->putAsUser($nginxDirectory.'/.keep', PHP_EOL);

        $this->rewriteSecureNginxFiles();
    }

    /**
     * Check nginx.conf for errors.
     */
    private function lint(): void
    {
        $this->cli->run(
            'sudo nginx -c '.$this->nginxConf.' -t',
            function ($exitCode, $outputMessage) {
                throw new DomainException("Nginx cannot start; please check your nginx.conf [$exitCode: $outputMessage].");
            }
        );
    }

    /**
     * Generate fresh Nginx servers for existing secure sites.
     */
    public function rewriteSecureNginxFiles(): void
    {
        $tld = $this->configuration->read()['tld'];
        $loopback = $this->configuration->read()['loopback'];

        if ($loopback !== VALET_LOOPBACK) {
            $this->site->aliasLoopback(VALET_LOOPBACK, $loopback);
        }

        $config = compact('tld', 'loopback');

        $this->site->resecureForNewConfiguration($config, $config);
    }

    /**
     * Restart the Nginx service.
     */
    public function restart(): void
    {
        $this->lint();

        $this->installer->restartService($this->installer->nginxServiceName());
    }

    /**
     * Stop the Nginx service.
     */
    public function stop(): void
    {
        $this->installer->stopService('nginx');
    }

    /**
     * Forcefully uninstall Nginx.
     */
    public function uninstall(): void
    {
        $this->installer->stopService(['nginx', 'nginx-full']);
        $this->installer->uninstallFormula('nginx nginx-full');
        $this->cli->quietly('rm -rf '.$this->nginxDir.' '.$this->nginxLogDir);
    }

    /**
     * Return a list of all sites with explicit Nginx configurations.
     */
    public function configuredSites(): Collection
    {
        return collect($this->files->scandir(VALET_HOME_PATH.'/Nginx'))
            ->reject(function ($file) {
                return starts_with($file, '.');
            });
    }
}
