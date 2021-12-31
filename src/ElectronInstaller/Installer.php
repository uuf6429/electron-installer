<?php

namespace ElectronInstaller;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;
use Exception;
use RuntimeException;

class Installer
{
    public const ELECTRON_NAME = 'Electron';

    public const ELECTRON_TARGETDIR = '/uuf6429/electron';

    public const PACKAGE_NAME = 'uuf6429/electron-installer';

    /**
     * Default CDN URL
     */
    public const ELECTRON_CDNURL_DEFAULT = 'https://github.com/electron/electron/';

    /** @var Composer */
    protected $composer;

    /** @var IOInterface */
    protected $io;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * installElectron is the main function of the install-script.
     *
     * It installs Electron into the defined /bin folder,
     * taking operating system dependent archives into account.
     *
     * You need to invoke it from the "scripts" section of your
     * "composer.json" file as "post-install-cmd" or "post-update-cmd".
     *
     * @param Event $event
     * @api
     */
    public static function installElectron(Event $event): void
    {
        (new static($event->getComposer(), $event->getIO()))->__invoke();
    }

    public function __invoke()
    {
        $version = $this->getVersion();

        $config = $this->composer->getConfig();

        $binDir = $config->get('bin-dir');

        // the installation folder depends on the vendor-dir (default prefix is './vendor')
        $targetDir = $config->get('vendor-dir') . static::ELECTRON_TARGETDIR;

        // do not install a lower or equal version
        $electronBinary = $this->getElectronBinary($binDir);
        if ($electronBinary) {
            $installedVersion = $this->getElectronVersionFromBinary($electronBinary);
            if (version_compare($version, $installedVersion) !== 1) {
                $this->io->write('   - Electron v' . $installedVersion . ' is already installed. Skipping the installation.');
                return;
            }
        }

        // download the archive & install
        if ($this->download($targetDir, $version)) {
            $this->copyElectronBinaryToBinFolder($targetDir, $electronBinary);
        }
    }

    /**
     * Returns a Composer Package, which was created in memory.
     *
     * @param string $targetDir
     * @param string $version
     * @return Package
     */
    public function createComposerInMemoryPackage(string $targetDir, string $version): Package
    {
        $url = $this->getURL($version);

        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);

        $package = new Package(static::ELECTRON_NAME, $normVersion, $version);
        $package->setTargetDir($targetDir);
        $package->setInstallationSource('dist');
        $package->setDistType(pathinfo($url, PATHINFO_EXTENSION) === 'zip' ? 'zip' : 'tar'); // set zip, tarball
        $package->setDistUrl($url);

        return $package;
    }

    /**
     * Returns an array with Electron version numbers.
     *
     * @return array Electron version numbers
     */
    public function getElectronVersions(): array
    {
        static $versions = null;

        if ($versions === null) {
            // TODO allow using a custom version provider
            $versions = $this->getElectronVersionsFromGithub();
            $versions = array_unique(array_filter($versions));
            usort($versions, 'version_compare');
            $versions = array_reverse($versions);
        }

        return $versions;
    }

    /**
     * Get Electron application version. Equals running "electron -v" on the CLI.
     *
     * @param string $pathToBinary
     * @return string|null Electron Version
     */
    protected function getElectronVersionFromBinary(string $pathToBinary): ?string
    {
        try {
            $cmd = escapeshellarg($pathToBinary) . ' -v';
            exec($cmd, $stdout);
            return $stdout[0];
        } catch (Exception $e) {
            $this->io->warning("Caught exception while checking Electron version:\n" . $e->getMessage());
            $this->io->notice('Re-downloading Electron');
            return null;
        }
    }

    /**
     * Returns the Electron version number.
     *
     * Search order for version number:
     *  1. $_ENV
     *  2. $_SERVER
     *  3. composer.json extra section
     *  4. fallback to the latest version from {@see getElectronVersions}
     *
     * @return string Version
     */
    protected function getVersion(): ?string
    {
        $extraData = $this->composer->getPackage()->getExtra();

        return $_ENV['ELECTRON_VERSION']
            ?? $_SERVER['ELECTRON_VERSION']
            ?? $extraData[static::PACKAGE_NAME]['electron-version']
            ?? $this->getLatestElectronVersion();
    }

    protected function copyElectronBinaryToBinFolder(string $sourceDir, string $targetFileName): void
    {
        static $sourceNames = [
            'win32' => DIRECTORY_SEPARATOR . 'electron.exe',
            'linux' => DIRECTORY_SEPARATOR . 'electron',
            'darwin' => DIRECTORY_SEPARATOR . 'Electron.app',
        ];

        $binDir = dirname($targetFileName);
        if (!is_dir($binDir) && !mkdir($binDir) && !is_dir($binDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $binDir));
        }

        $os = $this->getOS();

        if (!isset($sourceNames[$os])) {
            throw new RuntimeException("Can not find Electron binary; $os OS not supported.");
        }

        $sourceName = $sourceNames[$os];
        if (file_exists($sourceDir . $sourceName)) {
            throw new RuntimeException("Can not find Electron binary; file/path $sourceDir$sourceName does not exist.");
        }

        if ($os !== null) {
            $tempTargetName = tempnam($binDir, 'electron_temp_');
            copy($sourceDir . $sourceName, $tempTargetName);
            chmod($tempTargetName, 0777 & ~umask());
            rename($tempTargetName, $targetFileName);
        }

        if ($os === 'win32') {
            // slash fix (not needed, but looks better on the generated php file)
            $targetFileName = str_replace('/', '\\', $targetFileName);
        }

        $this->generateElectronBinaryClass($targetFileName);
    }

    protected function getElectronVersionsFromGithub(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: PHP/file_get_contents\r\n"
            ]
        ]);
        $data = file_get_contents('https://api.github.com/repos/electron/electron/releases', false, $ctx);

        $versions = [];
        $releases = (array)json_decode($data, false);
        foreach ($releases as $release) {
            $versions[] = ltrim($release->tag_name ?? '', 'v');
        }
        return $versions;
    }

    /**
     * Returns the latest Electron Version.
     *
     * @return string Latest Electron Version.
     */
    protected function getLatestElectronVersion(): string
    {
        $versions = $this->getElectronVersions();

        return $versions[0];
    }

    /**
     * Returns a lower version for a version number.
     *
     * @param string $oldVersion Version number
     * @return string Lower version number.
     */
    protected function getLowerVersion(string $oldVersion): ?string
    {
        foreach ($this->getElectronVersions() as $version) {
            // if $old_version is bigger than $version from versions array, return $version
            if (version_compare($oldVersion, $version) === 1) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Get path to Electron binary.
     *
     * @param string $binDir
     * @return string|bool Returns false, if file not found, else filepath.
     */
    protected function getElectronBinary(string $binDir)
    {
        $os = $this->getOS();

        $binary = $binDir . '/electron';

        if ($os === 'win32') {
            // the suffix for binaries on Windows is ".exe"
            $binary .= '.exe';
        }

        return realpath($binary);
    }

    /**
     * The main download function.
     *
     * The package to download is created on the fly.
     * For downloading Composer\DownloadManager is used.
     * Downloads are automatically retried with a lower version number,
     * when the resource it not found (404).
     *
     * @param string $targetDir
     * @param string $version
     * @return boolean
     */
    protected function download(string $targetDir, string $version): bool
    {
        if (defined('Composer\Composer::RUNTIME_API_VERSION') && version_compare(Composer::RUNTIME_API_VERSION, '2.0', '<')) {
            return $this->downloadUsingComposerVersion1($targetDir, $version);
        }

        return $this->downloadUsingComposerVersion2($targetDir, $version);
    }

    protected function tryDownload(callable $callback, string $targetVersion): array
    {
        try {
            $callback();
            return [true, null];
        } catch (TransportException $e) {
            if ($e->getStatusCode() === 404) {
                $retryVersion = $this->getLowerVersion($targetVersion);
                $this->io->warning("Retrying the download with a lower version number: $retryVersion");
                return [false, $retryVersion];
            }

            $message = $e->getMessage();
            $code = $e->getStatusCode();
            $this->io->error(PHP_EOL . "<error>TransportException (Status $code): $message</error>");
        } catch (Exception $e) {
            $message = $e->getMessage();
            $this->io->error(PHP_EOL . "<error>Error while downloading version $targetVersion: $message</error>");
        }
        return [false, null];
    }

    protected function downloadUsingComposerVersion1(string $targetDir, string $targetVersion): bool
    {
        $downloadManager = $this->composer->getDownloadManager();
        $retries = count($this->getElectronVersions());

        while ($retries--) {
            $package = $this->createComposerInMemoryPackage($targetDir, $targetVersion);

            [$success, $retryVersion] = $this->tryDownload(
                static function () use ($targetDir, $package, $downloadManager) {
                    $downloadManager->download($package, $targetDir);
                },
                $targetVersion
            );

            if ($retryVersion === null) {
                return $success;
            }

            $targetVersion = $retryVersion;
        }

        return false;
    }

    protected function downloadUsingComposerVersion2(string $targetDir, string $targetVersion): bool
    {
        $downloadManager = $this->composer->getDownloadManager();
        $retries = count($this->getElectronVersions());

        while ($retries--) {
            $package = $this->createComposerInMemoryPackage($targetDir, $targetVersion);

            [$success, $retryVersion] = $this->tryDownload(
                function () use ($targetDir, $package, $downloadManager) {
                    $loop = $this->composer->getLoop();
                    $promise = $downloadManager->download($package, $targetDir);
                    if ($promise) {
                        $loop->wait(array($promise));
                    }
                    $promise = $downloadManager->prepare('install', $package, $targetDir);
                    if ($promise) {
                        $loop->wait(array($promise));
                    }
                    $promise = $downloadManager->install($package, $targetDir);
                    if ($promise) {
                        $loop->wait(array($promise));
                    }
                    $promise = $downloadManager->cleanup('install', $package, $targetDir);
                    if ($promise) {
                        $loop->wait(array($promise));
                    }
                },
                $targetVersion
            );

            if ($retryVersion === null) {
                return $success;
            }

            $targetVersion = $retryVersion;
        }

        return false;
    }

    /**
     * Drop php class with path to installed electron binary for easier usage.
     *
     * Usage:
     *
     * use ElectronInstaller\ElectronBinary;
     *
     * $bin = ElectronInstaller\ElectronBinary::BIN;
     * $dir = ElectronInstaller\ElectronBinary::DIR;
     *
     * $bin = ElectronInstaller\ElectronBinary::getBin();
     * $dir = ElectronInstaller\ElectronBinary::getDir();
     *
     * @param string $binaryPath full path to binary
     *
     * @return bool True, if file dropped. False, otherwise.
     */
    protected function generateElectronBinaryClass(string $binaryPath): bool
    {
        $code = "<?php\n";
        $code .= "\n";
        $code .= "namespace ElectronInstaller;\n";
        $code .= "\n";
        $code .= "class ElectronBinary\n";
        $code .= "{\n";
        $code .= "    public const BIN = '%binary%';\n";
        $code .= "    public const DIR = '%binary_dir%';\n";
        $code .= "\n";
        $code .= "    public static function getBin() {\n";
        $code .= "        return self::BIN;\n";
        $code .= "    }\n";
        $code .= "\n";
        $code .= "    public static function getDir() {\n";
        $code .= "        return self::DIR;\n";
        $code .= "    }\n";
        $code .= "}\n";

        // binary      = full path to the binary
        // binary_dir  = the folder the binary resides in
        $fileContent = str_replace(
            ['%binary%', '%binary_dir%'],
            [$binaryPath, dirname($binaryPath)],
            $code
        );

        return (bool)file_put_contents(__DIR__ . '/ElectronBinary.php', $fileContent);
    }

    /**
     * Returns the URL of the Electron distribution for the installing OS.
     *
     * @param string $version
     * @return string Download URL
     */
    protected function getURL(string $version): string
    {
        $cdn_url = $this->getCdnUrl($version);

        if (($os = $this->getOS()) && ($arch = $this->getArch())) {
            $file = sprintf('electron-v%s-%s-%s.zip', $version, $os, $arch);
        } else {
            throw new RuntimeException(
                'The Installer could not select a Electron package for this OS.
                Please install Electron manually into the /bin folder of your project.'
            );
        }

        return $cdn_url . $file;
    }

    /**
     * Returns the base URL for downloads.
     *
     * Checks (order by highest precedence on top):
     * - ENV var "ELECTRON_CDNURL"
     * - SERVER var "ELECTRON_CDNURL"
     * - $['extra']['uuf6429/electron-installer']['cdnurl'] in composer.json
     * - default location (github)
     *
     * @param string $version
     * @return string URL
     */
    protected function getCdnUrl(string $version): string
    {
        $url = '';
        $extraData = $this->composer->getPackage()->getExtra();

        // override the detection of the default URL
        // by checking for an env var and returning early
        if (isset($_ENV['ELECTRON_CDNURL'])) {
            $url = $_ENV['ELECTRON_CDNURL'];
        } elseif (isset($_SERVER['ELECTRON_CDNURL'])) {
            $url = $_SERVER['ELECTRON_CDNURL'];
        } elseif (isset($extraData[static::PACKAGE_NAME]['cdnurl'])) {
            $url = $extraData[static::PACKAGE_NAME]['cdnurl'];
        }

        if ($url === '') {
            $url = static::ELECTRON_CDNURL_DEFAULT;
        }

        // add slash at the end of the URL, if missing
        if ($url[strlen($url) - 1] !== '/') {
            $url .= '/';
        }

        // add version to URL when using "github.com/electron/electron"
        if (strtolower(substr($url, -29)) === 'github.com/electron/electron/') {
            $url .= 'releases/download/v' . $version . '/';
        }

        return $url;
    }

    /**
     * Returns the Operating System.
     *
     * @return string|null OS, e.g. darwin, win32, linux.
     */
    protected function getOS(): ?string
    {
        // override the detection of the operating system
        // by checking for an env var and returning early
        if (isset($_ENV['ELECTRON_PLATFORM'])) {
            return strtolower($_ENV['ELECTRON_PLATFORM']);
        }

        if (isset($_SERVER['ELECTRON_PLATFORM'])) {
            return strtolower($_SERVER['ELECTRON_PLATFORM']);
        }

        $uname = strtolower(php_uname());

        switch (true) {
            case strpos($uname, 'darwin') !== false:
            case strpos($uname, 'openbsd') !== false:
            case strpos($uname, 'freebsd') !== false:
                return 'darwin';

            case strpos($uname, 'win') !== false:
                return 'win32';

            case strpos($uname, 'linux') !== false:
                return 'linux';

            default:
                return null;
        }
    }

    /**
     * Returns the architecture.
     *
     * @return string|null Architecture, e.g. ia32, x64, arm.
     */
    protected function getArch(): ?string
    {
        // override the detection of the architecture by checking for an env var and returning early
        if (isset($_ENV['ELECTRON_ARCHITECTURE'])) {
            return strtolower($_ENV['ELECTRON_ARCHITECTURE']);
        }

        if (isset($_SERVER['ELECTRON_ARCHITECTURE'])) {
            return strtolower($_SERVER['ELECTRON_ARCHITECTURE']);
        }

        switch (true) {
            case PHP_INT_SIZE === 4:
                return 'ia32';

            case PHP_INT_SIZE === 8:
                return 'x64';

            default:
                return null;
        }
    }
}
