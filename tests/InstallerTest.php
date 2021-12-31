<?php

namespace ElectronInstaller\Test;

use Composer\Composer;
use Composer\IO\BaseIO;
use Composer\Package\RootPackageInterface;
use ElectronInstaller\ElectronBinary;
use ElectronInstaller\Installer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @backupStaticAttributes enabled
 */
class InstallerTest extends TestCase
{
    /** @var Installer */
    private $object;

    private $bakEnvVars = [];

    private $bakServerVars = [];

    /** @var Composer|MockObject */
    private $mockComposer;

    /**
     * @var null|RootPackageInterface|MockObject
     */
    private $mockPackage;

    public function testInstallElectron(): void
    {
        // composer testing: mocks.. for nothing
        //InstallElectron(Event $event)
        $this->markTestSkipped('contribute ?');
    }

    public function testCopyElectronBinaryToBinFolder(): void
    {
        $this->markTestSkipped('contribute ?');
    }

    public function testDropClassWithPathToInstalledBinary(): void
    {
        $binaryPath = __DIR__ . '/a_fake_electron_binary';

        // generate file
        $this->assertTrue($this->callProtectedMethod([$this->object, 'generateElectronBinaryClass'], $binaryPath));
        $this->assertTrue(is_file(dirname(__DIR__) . '/src/ElectronInstaller/ElectronBinary.php'));

        // test the generated file
        require_once dirname(__DIR__) . '/src/ElectronInstaller/ElectronBinary.php';
        $this->assertSame($binaryPath, ElectronBinary::BIN);
        $this->assertSame(dirname($binaryPath), ElectronBinary::DIR);
    }

    /**
     * @backupGlobals
     */
    public function testCdnUrlTrailingSlash(): void
    {
        $this->setUpForGetCdnUrl();
        $version = '1.0.0';
        $configuredCdnUrl = 'scheme://host/path'; // without slash
        $_ENV['ELECTRON_CDNURL'] = $configuredCdnUrl;
        $cdnUrl = $this->callProtectedMethod([$this->object, 'getCdnUrl'], $version);
        $this->assertRegExp('{(?:^|[^/])/$}', $cdnUrl, 'CdnUrl should end with one slash.');
    }

    /**
     * @backupGlobals
     */
    public function testSpecialGithubPatternForCdnUrl(): void
    {
        $this->setUpForGetCdnUrl();
        $version = '1.0.0';

        // Test rewrite for the GitHub url as documented
        $configuredCdnUrl = 'https://github.com/electron/electron';
        $_ENV['ELECTRON_CDNURL'] = $configuredCdnUrl;
        $cdnUrl = $this->callProtectedMethod([$this->object, 'getCdnUrl'], $version);
        $this->assertSame($configuredCdnUrl . '/releases/download/v' . $version . '/', $cdnUrl);

        // Test that a longer url is not rewritten
        $configuredCdnUrl = 'https://github.com/electron/electron/releases/download/v1.9.19/';
        $_ENV['ELECTRON_CDNURL'] = $configuredCdnUrl;
        $cdnUrl = $this->callProtectedMethod([$this->object, 'getCdnUrl'], $version);
        $this->assertSame($configuredCdnUrl, $cdnUrl);
    }

    /**
     * @backupGlobals
     */
    public function testGetCdnUrlConfigPrecedence(): void
    {
        $this->setUpForGetCdnUrl();
        $version = '1.0.0';

        // Test default URL is returned when there is no config
        $cdnUrlExpected = Installer::ELECTRON_CDNURL_DEFAULT;
        $cdnUrl = $this->callProtectedMethod([$this->object, 'getCdnUrl'], $version);
        $this->assertStringStartsWith($cdnUrlExpected, $cdnUrl);

        // Test composer.json extra config overrides the default URL
        $cdnUrlExpected = 'scheme://host/extra-url/';
        $extraData = [Installer::PACKAGE_NAME => ['cdnurl' => $cdnUrlExpected]];
        $this->setUpForGetCdnUrl($extraData);
        $cdnUrl = $this->callProtectedMethod([$this->object, 'getCdnUrl'], $version);
        $this->assertSame($cdnUrlExpected, $cdnUrl);

        // Test $_SERVER var overrides default URL and extra config
        $cdnUrlExpected = 'scheme://host/server-var-url/';
        $_SERVER['ELECTRON_CDNURL'] = $cdnUrlExpected;
        $cdnUrl = $this->callProtectedMethod([$this->object, 'getCdnUrl'], $version);
        $this->assertSame($cdnUrlExpected, $cdnUrl);

        // Test $_ENV var overrides default URL, extra config and $_SERVER var
        $cdnUrlExpected = 'scheme://host/env-var-url/';
        $_ENV['ELECTRON_CDNURL'] = $cdnUrlExpected;
        $cdnUrl = $this->callProtectedMethod([$this->object, 'getCdnUrl'], $version);
        $this->assertSame($cdnUrlExpected, $cdnUrl);
    }

    public function testGetVersionFromExtra(): void
    {
        $expectedVersion = '1.0.0';
        $extraData = [Installer::PACKAGE_NAME => ['electron-version' => $expectedVersion]];
        $this->setUpForGetCdnUrl($extraData);
        $version = $this->object->getVersion();
        $this->assertSame($expectedVersion, $version);
    }

    public function testGetURL(): void
    {
        $this->setUpForGetCdnUrl();
        $version = '1.0.0';
        $url = $this->callProtectedMethod([$this->object, 'getURL'], $version);
        $this->assertIsString($url);
    }

    public function testGetOS(): void
    {
        $os = $this->callProtectedMethod([$this->object, 'getOS']);
        $this->assertIsString($os);
    }

    public function testGetArch(): void
    {
        $arch = $this->callProtectedMethod([$this->object, 'getArch']);
        $this->assertIsString($arch);
    }

    public function testGetElectronVersions(): void
    {
        $versions = $this->callProtectedMethod([$this->object, 'getElectronVersions']);
        $this->assertIsArray($versions);
        $this->assertContains('16.0.0', $versions); // test for some arbitrary version
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockComposer = $this->getMockBuilder(Composer::class)->getMock();
        $mockIO = $this->getMockBuilder(BaseIO::class)->getMockForAbstractClass();
        $this->object = new Installer($this->mockComposer, $mockIO);

        // Backup $_ENV and $_SERVER
        $this->bakEnvVars = $_ENV;
        $this->bakServerVars = $_SERVER;
    }

    protected function tearDown(): void
    {
        // Restore $_ENV and $_SERVER
        $_ENV = $this->bakEnvVars;
        $_SERVER = $this->bakServerVars;
    }

    /**
     * @param array $extraConfig mock composer.json 'extra' config with this array
     */
    private function setUpForGetCdnUrl(array $extraConfig = []): void
    {
        if (!$this->mockPackage) {
            $this->mockComposer->method('getPackage')->willReturnCallback(function () {
                return $this->mockPackage;
            });
        }
        $this->mockPackage = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $this->mockPackage->method('getExtra')->willReturn($extraConfig);
    }

    /**
     * @noinspection PhpUnhandledExceptionInspection
     */
    private function callProtectedMethod(array $callback, ...$arguments)
    {
        $class = new ReflectionClass($callback[2] ?? $callback[0]);
        $method = $class->getMethod($callback[1]);
        $method->setAccessible(true);
        return $method->invokeArgs(is_object($callback[0]) ? $callback[0] : null, $arguments);
    }
}
