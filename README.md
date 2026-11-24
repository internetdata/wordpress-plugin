# [<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="28"/>](https://internetdata.io/) InternetData WordPress Plugin

[![release](https://img.shields.io/github/v/release/internetdata/wordpress-plugin)](CHANGELOG.md)
[![license](https://img.shields.io/github/license/internetdata/wordpress-plugin)](LICENSE)

The official [InternetData](https://internetdata.io) plugin for [WordPress](https://wordpress.org).

It blocks or allows countries, sends visitors on by country, and catches VPNs, proxies, Tor and hosting. It logs, holds or blocks where you choose: sign-in, sign-ups, password resets, comments, contact forms, WooCommerce checkout and pages.

## Getting Started

Download `internetdata-<version>.zip` from the [latest release](https://github.com/internetdata/wordpress-plugin/releases/latest) and upload it under Plugins > Add New > Upload Plugin, then activate it. It needs WordPress 6.4 or newer and PHP 8.2 or newer.

Open Settings > InternetData and click Connect with InternetData. A tab opens on internetdata.io where you sign in, or sign up for free, and pick one of your API keys; the plugin is handed that key, with nothing to copy. If you'd rather paste a key, the same box takes one from the [console](https://app.internetdata.io), and a site that keeps secrets out of its database can set it in `wp-config.php` instead:

```php
define('INTERNETDATA_API_KEY', 'your-api-key');
```

InternetData's free plan, Unlimited, needs no account and has no cap: it runs country rules, geo redirects and the checkout's billing country check, and checks for Tor, privacy relays and hosting, judged by the network an address belongs to. Connect a free account to try every signal, VPNs and public, residential, mobile and datacenter proxies included; every paid plan checks every signal on every visit, with how often each proxy was seen.

Nothing is checked until you turn an area on.

## What it checks

| Area | Covers | Can |
|---|---|---|
| Sign-in | wp-login.php, front-end login forms that sign in through WordPress (WooCommerce's My Account among them), XML-RPC | log, block |
| New accounts | WordPress's registration page, a network's sign-up, WooCommerce's My Account | log, block |
| Password resets | WordPress's and WooCommerce's | log, block |
| Comments | comments from visitors who aren't signed in | log, hold for moderation, mark as spam, block |
| Contact forms | Contact Form 7 and WPForms | log, block |
| Checkout | WooCommerce, classic and block checkout, on the signals and on a billing country other than the buyer's | log, hold the order for review, refuse the order |
| Pages | the whole site, or the post types and path prefixes you choose, for visitors who aren't signed in | log, block with a message or a redirect, geo redirects |

Start an area on Log only: the Activity tab then lists who it would have caught, or sent on, and one click there puts an address on the allowlist. A held order is paid as usual and then stays On hold instead of going to Processing, with a note saying what was found. Administrators can always sign in, whatever their connection, unless you untick that.

## What to block

Countries, VPNs, Tor, public proxies, residential proxies, mobile proxies, datacenter proxies, hosting and cloud servers, and privacy relays such as iCloud Private Relay. Every area blocks on the same list unless you give it one of its own, so comments can catch hosting servers while sign-in leaves them alone. A privacy relay counts as a relay alone, even where it runs on a hosting network, so iCloud Private Relay visitors aren't refused as hosting. The settings mark each signal your plan doesn't answer, since an absent answer can never match: VPNs and proxies need a paid plan or a free account's trial, and hosting without one is judged by the type of network the address belongs to.

Countries are listed by their two-letter codes, and either blocked or the only ones allowed, so `DE, AT, CH` with Allow only these countries refuses everyone else. A visitor whose address has no known country is let through. A visitor refused for their country alone is told the site isn't available there, rather than to turn off a VPN.

Residential, mobile and datacenter proxies can also be held to a number of sightings: at 3, an address counts only once it has been seen in a proxy pool three times, which spares a home connection that was rented out once.

The allowlist takes addresses, CIDR blocks and whole networks by their AS number (`AS64500`), one per line. An address or block on it is never looked up at all.

## Geo redirects

Pages takes rules, one per line, that send visitors from the countries listed to an address: `FR, BE https://example.fr/`. They apply on the pages the area covers, to visitors it doesn't refuse, and never send a visitor who is already on the rule's page, so a rule can point at a page of the same site. On Log only the Activity tab lists who would have been sent, and nobody is. Search engine crawlers are never sent anywhere.

## Checkout fraud signals

Checkout acts on the signals as every area does, so an order placed through a VPN, a proxy, Tor or a hosting network can be held or refused. Tick "Also catch an order whose billing country is not the country its buyer connects from" and an order billed to one country from an address in another is caught too, on every plan; the order's note names both countries. Travelers trip it as well, so it suits Hold better than Refuse.

## The visitor's address

Behind Cloudflare, the plugin believes `CF-Connecting-IP` from Cloudflare's own addresses. Behind a proxy on your server's private network, such as a load balancer or a local nginx, it reads `X-Forwarded-For` from the right, taking the first address that isn't one of your own hops. Anything else in front of the site, with public addresses of its own, has to be named under Advanced > Visitor address, with the header it writes. Until it is, every visitor looks like that proxy.

The settings page shows the address the plugin sees for you, and says so when your own request carried a forwarding header it didn't believe.

## Page caches

A page cache answers without running WordPress, so a page checked only on the server would reach whoever the cache serves. A protected page therefore carries a small script that asks the site, from the visitor's browser, whether this visitor may see it or where a geo redirect sends them; it works with any cache, but not for a visitor who turns scripts off. For pages that must never reach a refused visitor, tick "Keep these pages out of the page cache" and the server checks every visit. A refusal is never cached either way.

Search engines crawl from cloud addresses, so Google's, Bing's, Apple's and DuckDuckGo's published crawler addresses are never refused from your pages or redirected.

## Costs and failures

Each address is looked up at most once every six hours, in the object cache if the site has one and in transients if not, and only for areas that are on. A lookup waits at most 2.5 seconds. If InternetData can't be reached in that time, or the site's allowance is spent, the visitor is let through and the settings say why; Advanced can switch that to refusing them.

## For developers

`internetdata_visitor()` returns the API's answer for the current visitor as an array, keyed as the [API reference](https://docs.internetdata.io/api) names it, or `null` when there is none:

```php
$visitor = internetdata_visitor();
if (($visitor['location']['country'] ?? '') === 'DE' && !($visitor['is_tor'] ?? false)) {
    // ...
}
```

It is looked up once a request and counts against the site's allowance like any check.

| Hook | Does |
|---|---|
| `internetdata_condition` (filter) | The rule an area blocks on, in the [PHP SDK](https://docs.internetdata.io/integrations/php)'s condition format, with the area and its signals. Return your own to reach the evidence, such as `[['isVpn' => true, 'vpn' => ['provider' => ['nordvpn', 'expressvpn']]]]`. With Allow only these countries, the countries term names the visitor's own country when it isn't on the list |
| `internetdata_verdict` (filter) | A caught visitor, or one a geo redirect would send, before the area acts. Return `null` to let them through, or keep them where they are |
| `internetdata_flagged` (action) | Every visitor an area caught or sent on, as an array: the area, what it did, the signals, the address, the answer, and a geo redirect's `target` or an order's `billing_country` |
| `internetdata_client_ip` (filter) | The visitor's address, for an edge that names it some other way |
| `internetdata_cache_ttl` (filter) | Seconds an answer is kept, 21600 by default |

## What it sends

The plugin calls the InternetData API at `https://api.internetdata.io`. For each area you turn on, it sends the visitor's IP address, and your API key if you added one, when a visitor uses that area, at most once per address every six hours. The settings page also sends your own address the same way and reads your plan and usage. Connect with InternetData asks `https://internetdata.io` for a sign-in code and opens `https://app.internetdata.io` in your browser. Nothing else leaves your site. Our terms are at https://internetdata.io/terms and our privacy policy at https://internetdata.io/privacy, and the plugin adds a suggested paragraph to your own privacy policy under Settings > Privacy.

## Building from source

The plugin vendors the [PHP SDK](https://github.com/internetdata/sdk-php) and its dependencies under its own namespace, so another plugin's copy of Guzzle can never be the one it loads. Building needs Docker and nothing else:

```bash
./scripts/build.sh      # dist/internetdata-<version>.zip
./scripts/test.sh       # the unit suite
./scripts/e2e.sh        # a real WordPress, every area driven over HTTP, and wordpress.org's Plugin Check
```

## Other Libraries

There are official InternetData client libraries available for many languages including PHP, Python, Go, Java, and Ruby. See our GitHub at https://github.com/internetdata for more.

## About InternetData

Geolocation, anonymity, ownership, network, service and domain data for any IP address, AS or domain, by API or as databases you download.

[<picture><source media="(prefers-color-scheme: dark)" srcset="https://s3.internetdata.io/internetdata-public/brand/logo-dark.svg"><img src="https://s3.internetdata.io/internetdata-public/brand/logo.svg" alt="InternetData" height="40"/></picture>](https://internetdata.io/)

## License

This project is licensed under the [MIT License](LICENSE).
