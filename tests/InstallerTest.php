<?php

namespace ElectronInstaller\Test;

use Composer\Composer;
use Composer\IO\BaseIO;
use Composer\Package\RootPackageInterface;
use ElectronInstaller\Installer;
use ElectronInstaller\ElectronBinary;

/**
 * @backupStaticAttributes enabled
 */
class InstallerTest extends \PHPUnit_Framework_TestCase
{
    /** @var Installer */
    protected $object;

    protected $bakEnvVars = [];

    protected $bakServerVars = [];
    
    protected function setUp()
    {
        parent::setUp();

        $mockComposer = $this->getMockComposer();
        $mockIO = $this->getMockIO();
        $this->object = new Installer($mockComposer, $mockIO);

        // Backup $_ENV and $_SERVER
        $this->bakEnvVars = $_ENV;
        $this->bakServerVars = $_SERVER;
    }

    protected function tearDown()
    {
        // Restore $_ENV and $_SERVER
        $_ENV = $this->bakEnvVars;
        $_SERVER = $this->bakServerVars;
    }

    protected function getMockComposer()
    {
        $mockComposer = $this->getMockBuilder(Composer::class)->getMock();

        return $mockComposer;
    }

    protected function getMockIO()
    {
        $mockIO = $this->getMockBuilder(BaseIO::class)->getMockForAbstractClass();

        return $mockIO;
    }

    public function testInstallElectron()
    {
        // composer testing: mocks.. for nothing
        //InstallElectron(Event $event)
        $this->markTestSkipped('contribute ?');
    }

    public function testCopyElectronBinaryToBinFolder()
    {
        $this->markTestSkipped('contribute ?');
    }

    public function testDropClassWithPathToInstalledBinary()
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
     * @param array $extraConfig mock composer.json 'extra' config with this array
     */
    public function setUpForGetCdnUrl(array $extraConfig = [])
    {
        $object = $this->object;
        $mockComposer = $this->getMockComposer();
        $object->setComposer($mockComposer);
        $mockPackage = $this->getMockBuilder(RootPackageInterface::class)->getMock();
        $mockComposer->method('getPackage')->willReturn($mockPackage);
        $mockPackage->method('getExtra')->willReturn($extraConfig);
    }

    /**
     * @backupGlobals
     */
    public function testCdnUrlTrailingSlash()
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
    public function testSpecialGithubPatternForCdnUrl()
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
    public function testGetCdnUrlConfigPrecedence()
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

    public function testgetURL()
    {
        $this->setUpForGetCdnUrl();
        $version = '1.0.0';
        $url = $this->object->getURL($version);
        $this->assertTrue(is_string($url));
    }

    public function testGetOS()
    {
        $os = $this->object->getOS();
        $this->assertTrue(is_string($os));
    }

    public function testGetArch()
    {
        $arch = $this->object->getArch();
        $this->assertTrue(is_string($arch));
    }
}
