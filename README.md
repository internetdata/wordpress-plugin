# [<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="28"/>](https://internetdata.io/) InternetData WordPress Plugin

[![release](https://img.shields.io/github/v/release/internetdata/wordpress-plugin)](CHANGELOG.md)
[![license](https://img.shields.io/github/license/internetdata/wordpress-plugin)](LICENSE)

The official [InternetData](https://internetdata.io) plugin for [WordPress](https://wordpress.org).

## Getting Started

Download `internetdata-<version>.zip` from the [latest release](https://github.com/internetdata/wordpress-plugin/releases/latest) and upload it under Plugins > Add New > Upload Plugin, then activate it. It needs WordPress 6.4 or newer and PHP 8.2 or newer.

## What it sends

The plugin calls the InternetData API at `https://internetdata.io`. Our terms are at https://internetdata.io/terms and our privacy policy at https://internetdata.io/privacy.

## Building from source

The plugin vendors the [PHP SDK](https://github.com/internetdata/sdk-php) and its dependencies under its own namespace, so another plugin's copy of Guzzle can never be the one it loads. Building needs Docker and nothing else:

```bash
./scripts/build.sh      # dist/internetdata-<version>.zip
./scripts/test.sh       # the unit suite
./scripts/e2e.sh        # a real WordPress, and wordpress.org's Plugin Check
```

## About InternetData

Geolocation, anonymity, ownership and network databases for IP addresses and AS numbers, licensed as files you download and query yourself.

[<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="64"/>](https://internetdata.io/)

## License

This project is licensed under the [MIT License](LICENSE).
