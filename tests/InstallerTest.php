<?php

namespace ElectronInstaller\Test;

use Composer\Composer;
use Composer\IO\BaseIO;
use Composer\Package\RootPackageInterface;
use ElectronInstaller\ElectronBinary;
use ElectronInstaller\Installer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
        $this->assertTrue($this->object->dropClassWithPathToInstalledBinary($binaryPath));
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
        $cdnurl = $this->object->getCdnUrl($version);
        $this->assertRegExp('{(?:^|[^/])/$}', $cdnurl, 'CdnUrl should end with one slash.');
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
        $cdnurl = $this->object->getCdnUrl($version);
        $this->assertSame($configuredCdnUrl . '/releases/download/v' . $version . '/', $cdnurl);

        // Test that a longer url is not rewritten
        $configuredCdnUrl = 'https://github.com/electron/electron/releases/download/v1.9.19/';
        $_ENV['ELECTRON_CDNURL'] = $configuredCdnUrl;
        $cdnurl = $this->object->getCdnUrl($version);
        $this->assertSame($configuredCdnUrl, $cdnurl);
    }

    /**
     * @backupGlobals
     */
    public function testGetCdnUrlConfigPrecedence(): void
    {
        $this->setUpForGetCdnUrl();
        $version = '1.0.0';

        // Test default URL is returned when there is no config
        $cdnurlExpected = Installer::ELECTRON_CDNURL_DEFAULT;
        $cdnurl = $this->object->getCdnUrl($version);
        $this->assertStringStartsWith($cdnurlExpected, $cdnurl);

        // Test composer.json extra config overrides the default URL
        $cdnurlExpected = 'scheme://host/extra-url/';
        $extraData = [Installer::PACKAGE_NAME => ['cdnurl' => $cdnurlExpected]];
        $this->setUpForGetCdnUrl($extraData);
        $cdnurl = $this->object->getCdnUrl($version);
        $this->assertSame($cdnurlExpected, $cdnurl);

        // Test $_SERVER var overrides default URL and extra config
        $cdnurlExpected = 'scheme://host/server-var-url/';
        $_SERVER['ELECTRON_CDNURL'] = $cdnurlExpected;
        $cdnurl = $this->object->getCdnUrl($version);
        $this->assertSame($cdnurlExpected, $cdnurl);

        // Test $_ENV var overrides default URL, extra config and $_SERVER var
        $cdnurlExpected = 'scheme://host/env-var-url/';
        $_ENV['ELECTRON_CDNURL'] = $cdnurlExpected;
        $cdnurl = $this->object->getCdnUrl($version);
        $this->assertSame($cdnurlExpected, $cdnurl);
    }

    public function testGetURL(): void
    {
        $this->setUpForGetCdnUrl();
        $version = '1.0.0';
        $url = $this->object->getURL($version);
        $this->assertIsString($url);
    }

    public function testGetOS(): void
    {
        $os = $this->object->getOS();
        $this->assertIsString($os);
    }

    public function testGetArch(): void
    {
        $arch = $this->object->getArch();
        $this->assertIsString($arch);
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
}
