<?php

namespace ElectronInstaller;

use Composer\Composer;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;
use Exception;
use RuntimeException;

class Installer
{
    public const ELECTRON_NAME = 'Electron';

    public const ELECTRON_TARGETDIR = '/uuf6429/electron';

    public const ELECTRON_CHMODE = 0770; // octal !

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
            $os = $this->getOS();

            switch ($os) {
                case 'win32':
                    $binaryPath = $targetDir . DIRECTORY_SEPARATOR . 'electron.exe';
                    break;

                case 'linux':
                    $binaryPath = $targetDir . DIRECTORY_SEPARATOR . 'electron';
                    break;

                case 'darwin':
                    $binaryPath = $targetDir . DIRECTORY_SEPARATOR . 'Electron.app';
                    break;

                default:
                    throw new RuntimeException('Can not detect Electron binary; OS "' . $os . '" not supported.');
            }

            if (!file_exists($binaryPath)) {
                throw new RuntimeException('Can not detect Electron binary; file/path "' . $binaryPath . '" does not exist.');
            }

            @chmod($binaryPath, static::ELECTRON_CHMODE);

            $this->dropClassWithPathToInstalledBinary($binaryPath);
        }
    }

    /**
     * Get Electron application version. Equals running "electron -v" on the CLI.
     *
     * @param string $pathToBinary
     * @return string|null Electron Version
     */
    public function getElectronVersionFromBinary(string $pathToBinary): ?string
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
     * Get path to Electron binary.
     *
     * @param string $binDir
     * @return string|bool Returns false, if file not found, else filepath.
     */
    public function getElectronBinary(string $binDir)
    {
        $os = $this->getOS();

        $binary = $binDir . '/electron';

        if ($os === 'windows') {
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
     * @param string $targetVersion
     * @return boolean
     */
    public function download(string $targetDir, string $targetVersion): bool
    {
        $downloadManager = $this->composer->getDownloadManager();
        $retries = count($this->getElectronVersions());

        while ($retries--) {
            $package = $this->createComposerInMemoryPackage($targetDir, $targetVersion);

            try {
                $downloadManager->download($package, $targetDir);
                return true;
            } catch (TransportException $e) {
                if ($e->getStatusCode() === 404) {
                    $version = $this->getLowerVersion($targetVersion);
                    $this->io->warning('Retrying the download with a lower version number: "' . $version . '"');
                } else {
                    $message = $e->getMessage();
                    $code = $e->getStatusCode();
                    $this->io->error(PHP_EOL . "<error>TransportException: $message. HTTP status code: $code</error>");
                    return false;
                }
            } catch (Exception $e) {
                $message = $e->getMessage();
                $this->io->error(PHP_EOL . "<error>While downloading version $targetVersion the following error occurred: $message</error>");
                return false;
            }
        }

        return false;
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
        static $versions;
        if (!$versions) {
            $releases = (array)json_decode(file_get_contents('https://api.github.com/repos/electron/electron/releases'));
            foreach ($releases as $release) {
                $versions[] = ltrim($release->tag_name ?? '', 'v');
            }
            $versions = array_unique(array_filter($versions));
        }

        return $versions;
    }

    /**
     * Returns the latest Electron Version.
     *
     * @return string Latest Electron Version.
     */
    public function getLatestElectronVersion(): string
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
    public function getLowerVersion(string $oldVersion): ?string
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
     * Returns the Electron version number.
     *
     * Firstly, we search for a version number in the local repository,
     * secondly, in the root package.
     * A version specification of "dev-master#<commit-reference>" is disallowed.
     *
     * @return string Version
     */
    public function getVersion(): ?string
    {
        // try getting the version from the local repository
        $version = null;
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        foreach ($packages as $package) {
            if ($package->getName() === static::PACKAGE_NAME) {
                $version = $package->getPrettyVersion();
                break;
            }
        }

        // let's take a look at the aliases
        $locker = $this->composer->getLocker();
        $aliases = $locker ? $locker->getAliases() : [];
        foreach ($aliases as $alias) {
            if ($alias['package'] === static::PACKAGE_NAME) {
                return $alias['alias'];
            }
        }

        // fallback to the hardcoded latest version, if "dev-master" was set
        if ($version === 'dev-master') {
            return $this->getLatestElectronVersion();
        }

        // grab version from commit-reference, e.g. "dev-master#<commit-ref> as version"
        if (preg_match('/dev-master#(?:.*)(\d.\d.\d)/i', $version, $matches)) {
            return $matches[1];
        }

        // grab version from a Composer patch version tag with a patch level, like "1.9.8-p02"
        if (preg_match('/(\d.\d.\d)(?:(?:-p\d{2})?)/i', $version, $matches)) {
            return $matches[1];
        }

        // let's take a look at the root package
        if (!empty($version)) {
            $version = $this->getRequiredVersion($this->composer->getPackage());
        }

        return $version;
    }

    /**
     * Returns the version for the given package either from the "require" or "require-dev" packages array.
     *
     * @param RootPackageInterface $package
     * @return string|null
     * @throws RuntimeException
     */
    public function getRequiredVersion(RootPackageInterface $package): ?string
    {
        foreach (array($package->getRequires(), $package->getDevRequires()) as $requiredPackages) {
            if (isset($requiredPackages[static::PACKAGE_NAME])) {
                /** @var Link[] $requiredPackages */
                return $requiredPackages[static::PACKAGE_NAME]->getPrettyConstraint();
            }
        }
        throw new RuntimeException('Can not determine required version of ' . static::PACKAGE_NAME);
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
    public function dropClassWithPathToInstalledBinary(string $binaryPath): bool
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
            array('%binary%', '%binary_dir%'),
            array($binaryPath, dirname($binaryPath)),
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
    public function getURL(string $version): string
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
    public function getCdnUrl(string $version): string
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
    public function getOS(): ?string
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
    public function getArch(): ?string
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
