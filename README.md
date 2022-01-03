electron-installer
==================

[![Packagist](https://img.shields.io/packagist/v/uuf6429/electron-installer.svg)](https://packagist.org/packages/uuf6429/electron-installer)
[![Build Status](https://api.travis-ci.com/uuf6429/electron-installer.png)](https://app.travis-ci.com/github/uuf6429/electron-installer)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://raw.githubusercontent.com/uuf6429/electron-installer/master/LICENSE)

A Composer package which installs the Electron binary (Linux, Windows, Mac) into `/bin` of your project.

##### Table of Contents

- [Installation](#installation)
- [How to require a specific version of Electron?](#how-to-require-specific-versions-of-electron)
- [How does this work internally?](#how-does-this-work-internally)
- [How to access the binary easily by using ElectronInstaller\ElectronBinary?](#electronbinary)
- [How to package for another platform by overriding platform requirements?](#override-platform-requirements)
- [How to use a Mirror or a custom CDN URL for downloading?](#downloading-from-a-mirror)

## Installation

To install Electron as a local, per-project dependency to your project, simply add a dependency
on `uuf6429/electron-installer` to your project's `composer.json` file.

```json
{
  "require": {
    "uuf6429/electron-installer": "^2"
  },
  "scripts": {
    "post-install-cmd": [
      "ElectronInstaller\\Installer::installElectron"
    ],
    "post-update-cmd": [
      "ElectronInstaller\\Installer::installElectron"
    ]
  }
}
```

For a development dependency, change `require` to `require-dev`.

The default download source used is: https://github.com/electron/electron/releases/
You might change it by setting a custom CDN URL, which is explained in the
section "[Downloading from a mirror](#downloading-from-a-mirror)".

By setting the Composer configuration directive `bin-dir`,
the [vendor binaries](https://getcomposer.org/doc/articles/vendor-binaries.md#can-vendor-binaries-be-installed-somewhere-other-than-vendor-bin-)
will be installed into the defined folder.
**Important! Composer will install the binaries into `vendor\bin` by default.**

The `scripts` section is necessary, because currently Composer does not pass events to the handler scripts of
dependencies. If you leave it away, you might execute the installer manually.

Now, assuming that the scripts section is set up as required, the Electron binary will be installed into the `/bin`
folder and updated alongside the project's Composer dependencies.

## How to require specific versions of Electron?

The environment and server variable `ELECTRON_VERSION` enables you specify the version requirement at the time of
packaging.

You can also set the `electron-version` in the `extra` section of your `composer.json`:

```json
{
  "extra": {
    "uuf6429/electron-installer": {
      "electron-version": "16.0.0"
    }
  }
}
```

The search order for the version is `$_ENV`, `$_SERVER`, `composer.json` (extra section), fallback to the latest version
on GitHub.

## How does this work internally?

### Fetching the Electron Installer

In your composer.json you require the package "electron-installer". The package is fetched by composer and stored
into `./vendor/uuf6429/electron-installer`. It contains only one file the `ElectronInstaller\\Installer`.

### Platform-specific download of Electron

The `ElectronInstaller\\Installer` is run as a "post-install-cmd". That's why you need the "scripts" section in your "
composer.json". The installer creates a new composer in-memory package "electron", detects your OS and downloads the
correct Electron version to the folder `./vendor/uuf6429/electron`. All Electron files reside there.

### Installation into `/bin` folder

The binary is linked from `./vendor/uuf6429/electron` to your composer configured `bin-dir` folder.

### Generation of ElectronBinary

The installer generates a PHP file `ElectronInstaller/ElectronBinary` and inserts the path to the binary.

## ElectronBinary

To access information about the binary, the class `ElectronBinary` is created automatically during installation.

The class defines the following constants:

- `BIN` is the full-path to the Electron binary file, e.g. `/your_project/bin/electron`
- `DIR` is the folder of the binary, e.g. `/your_project/bin`
- `VERSION` the version used during the installation, e.g. `16.1.2`

Usage:

```php
<?php 

use ElectronInstaller\ElectronBinary;

$bin = ElectronBinary::BIN;
$dir = ElectronBinary::DIR;
$version = ElectronBinary::VERSION;
```

## Override platform requirements

The environment and server variables `ELECTRON_PLATFORM` and `ELECTRON_ARCHITECTURE` enable you to override the platform
requirements at the time of packaging. This decouples the packaging system from the target system. It allows to package
on Linux for MacOSX or on Windows for Linux.

Possible values for

- `ELECTRON_PLATFORM` are: `darwin`, `win32`, `linux`.
- `ELECTRON_ARCHITECTURE` are: `ia32`or `x64`.

## Downloading from a mirror

You can override the default download location of the Electron binary file by setting it in one of these locations:

- The environment variable `ELECTRON_CDNURL`
- The server variable `ELECTRON_CDNURL`
- In your `composer.json` by using `$['extra']['uuf6429/electron-installer']['cdnurl']`:

 ```json
{
  "extra": {
    "uuf6429/electron-installer": {
      "cdnurl": "https://github.com/company/electron/releases/download/v16.0.0/"
    }
  }
}
```

**Default Download Location**

The default download location is GitHub: `https://github.com/electron/electron/releases/`. You don't need to set it
explicitly. It's used when `ELECTRON_CDNURL` is not set.

## Automatic download retrying with version lowering on 404

In case downloading an archive fails with HttpStatusCode 404 (resource not found), the downloader will automatically
lower the version to the next available version and retry.

A couple of notes on this behaviour:

- The number of retries is determined by the number of Electron versions in `getElectronVersions()`.
- Since you can only request one specific version, it is possible to fall back to a very old version, if all others
  failed for some reason.
- On the next composer update/install, it will go through this process again. Therefore, it is possible that having a
  lot of broken versions could slow the installation process.
