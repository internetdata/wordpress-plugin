=== InternetData ===
Contributors: internetdata
Tags: block countries, geo redirect, geolocation, vpn, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Block or allow countries, send visitors to the right site for theirs, and catch sign-ups, comments and WooCommerce orders from VPNs, Tor and hosting.

== Description ==

Where is that visitor really connecting from, and through what? InternetData looks up each visitor's address and lets you decide what happens next, area by area: block or allow whole countries, send visitors on to the site for their country, and hold orders that don't add up.

= 🌍 Countries =

* Block the countries you list, or allow only those and refuse everyone else. It reads where the visitor's address is, not what their browser claims.
* Geo redirects: send visitors from the countries you choose to another address, such as your German store or a page in their language, one rule per line.
* A refused visitor is told the site isn't available in their country.
* All of it works without an account, on every visit, with no cap.

= 🛒 WooCommerce fraud signals =

* Hold an order for review when its buyer connects through a VPN, a proxy, Tor or a hosting network, or refuse it at checkout.
* Catch an order whose billing country isn't the country its buyer connects from.
* A held order is paid as usual and then waits On hold instead of going to Processing, with a note on the order saying what was found.

= 🚪 Where it checks =

* Sign-in: the login page, front-end login forms such as WooCommerce's, and XML-RPC. Administrators can always sign in, so you can't lock yourself out.
* New accounts: WordPress's registration page, a network's sign-up and WooCommerce's My Account.
* Password resets, on WordPress and WooCommerce.
* Comments: block them, hold them for moderation or mark them as spam.
* Contact forms: Contact Form 7 and WPForms.
* WooCommerce checkout, classic and block.
* Pages: the whole site, or the post types and paths you choose, with a message, a redirect or a geo redirect.

= 👀 Watch before it blocks =

Start each area on Log only and the Activity tab lists who it would have caught, or sent on, before it acts on anyone. One click there puts an address on the allowlist, which also takes blocks and whole networks by their AS number.

= 🎯 What it can tell apart =

Countries, VPNs, Tor, public proxies, residential proxies, mobile proxies, datacenter proxies, hosting and cloud servers, and privacy relays such as iCloud Private Relay. Every area uses the same list unless you give it one of its own, so comments can catch hosting servers while sign-in leaves them alone. A privacy relay counts as a relay alone, even where it runs on a hosting network, so iCloud Private Relay visitors aren't refused as hosting.

= 🎁 Free to start =

* No account: InternetData's free plan, Unlimited, runs your country rules, geo redirects and the billing country check, and checks for Tor, privacy relays and hosting, judged by the network an address belongs to, on every visit, with no cap.
* Free account: try every signal, VPNs and public, residential, mobile and datacenter proxies included.
* Paid plan: every signal on every visit, with how often each proxy was seen.

Connecting takes one click: sign in on internetdata.io in the tab that opens and pick a key.

= 🚀 Built to stay out of the way =

* It checks only the areas you turn on, and asks about each address at most once every six hours.
* If InternetData can't be reached within 2.5 seconds, the visitor is let through. You can choose to refuse them instead.
* It works with page caches. A cached page is checked, and sent on, from the visitor's browser, or you can keep protected pages out of the cache so the server checks every visit. A refusal is never cached.
* Google's, Bing's, Apple's and DuckDuckGo's crawlers are never refused from your pages or sent anywhere by a geo redirect. On a paid plan or the trial, neither is any other search engine's crawler whose operator vouches for its address.
* It finds the visitor's real address behind Cloudflare, a load balancer or another proxy, and believes a forwarding header only from the proxy that writes it.
* It sends its requests through WordPress's own HTTP API, so your proxy settings and debugging tools see them.

= 🔧 For developers =

`internetdata_visitor()` returns the API's answer for the current visitor as an array. The `internetdata_condition` filter changes the rule an area blocks on, in the PHP SDK's condition format, `internetdata_verdict` can let a caught visitor through or keep one where they are, and the `internetdata_flagged` action fires for every visitor an area catches or sends on. Set `INTERNETDATA_API_KEY` in wp-config.php to keep the key out of the database. The source is at https://github.com/internetdata/wordpress-plugin.

== Installation ==

1. Install InternetData from Plugins > Add New, and activate it.
2. Open Settings > InternetData and click Connect with InternetData, or paste an API key from https://app.internetdata.io. You can skip this: country rules, geo redirects and the billing country check need no account.
3. Tick what to block, list your countries, set each area you want checked to Log only, and watch the Activity tab for a few days.
4. Switch the areas you're happy with to Block, Hold or Spam.

== Frequently Asked Questions ==

= Will I lock myself out? =

Not through the sign-in area while "Administrators can always sign in" is ticked, as it is by default. The settings page also shows what your own connection looks like, so you can add your address to the allowlist before you turn anything on. If you ever need to, deleting the plugin's folder from wp-content/plugins turns it off.

= How do I block or allow countries? =

Tick Countries under What to block, choose Block these countries or Allow only these countries, and list them by their two-letter codes, such as DE, AT, CH. It reads where the visitor's address is rather than where their browser says they are. VPNs and proxies are how visitors get round a country rule, so it works best beside them, which needs a paid plan or a free account's trial.

= How do geo redirects work? =

Turn Pages on, choose which pages it covers, and add a rule per line under Geo redirects: the two-letter codes, then the address, such as `FR, BE https://example.fr/`. A visitor from a listed country who opens a covered page is sent there, unless they're already on it. Visitors the area refuses are refused first, and on Log only the Activity tab lists who would have been sent without sending anyone.

= What does the billing country check catch? =

An order whose billing address is in another country than the one its buyer connects from, which is common in card fraud. Travelers and people living abroad trip it too, so it suits holding orders for a look better than refusing them.

= My whole site sits behind Cloudflare or a load balancer. Does that matter? =

Cloudflare and a proxy on your server's private network are recognized automatically. For anything else, set Visitor address under Advanced to the proxy in front of you, or every visitor looks like the proxy. The "Your connection" box on the settings page shows which address the plugin sees.

= How many checks will my site use? =

One per address per six hours, only in the areas you turn on. Sign-in, sign-up and comments use few; protecting every page, or redirecting by country, uses one for each new visitor.

= Does it slow pages down? =

An address already looked up costs no request at all, and a new one waits at most 2.5 seconds for InternetData before the visitor is let through. Areas you leave off cost nothing.

= Is it GDPR friendly? =

It sends visitors' IP addresses to InternetData for the areas you turn on, keeps the answers for six hours, and records the visitors it acts on, with their address, for 30 days by default. It adds a suggested paragraph to your privacy policy under Settings > Privacy.

== Screenshots ==

1. What to block, and where.
2. The Activity tab.
3. A held WooCommerce order and its note.

== Changelog ==

= 1.1.2 =
* The settings page says a paid plan checks every signal on every visit, and answers a signal marked Paid plan or trial: InternetData sells one, at the quota you pick.

= 1.1.1 =
* On a free key, the account card counts the trial's checks with every signal until the day it ends, and says when it has ended, since the trial now runs once rather than by the month.

= 1.1.0 =
* On a paid plan or the trial, any search engine's crawler whose operator vouches for its address is never refused from your pages or sent anywhere by a geo redirect.
* Built on the newest InternetData PHP library.

= 1.0.8 =
* On a free key, the account card counts this month's checks with every signal, and the day the count resets.
* Built on the newest InternetData PHP library.

= 1.0.7 =
* Built on the newest InternetData PHP library.

= 1.0.6 =
* When your organization reaches the overage cap it set, the admin screens say so, and that ticked signals block nobody until the billing period ends or the cap is raised.

= 1.0.5 =
* The settings screen and its signal badges say a connected free account tries every signal during its trial.
* Built on the newest InternetData PHP library.

= 1.0.4 =
* Built on the newest InternetData PHP library.

= 1.0.3 =
* Built on the newest InternetData PHP library.

= 1.0.2 =
* The account card names your plan as InternetData does, so a renamed plan reads right without an update.

= 1.0.1 =
* A visitor on iCloud Private Relay or another privacy relay is no longer refused as hosting where an area blocks hosting networks: a relay counts as a relay alone.

= 1.0.0 =
* First release.

== External services ==

This plugin uses the InternetData API, run by InternetData (https://internetdata.io), to tell where an IP address is and what kind of network it belongs to.

* When a visitor uses an area you turned on (signs in, creates an account, asks for a password reset, comments, sends a contact form, places an order or opens a protected page), the plugin sends the visitor's IP address, with your API key if you added one, to https://api.internetdata.io. The answer is kept on your site for six hours, so the same address is not sent again within that time.
* When you open the plugin's settings page, it sends your own IP address the same way, and reads your plan and usage with your API key.
* When you click Connect with InternetData, the plugin asks https://internetdata.io for a sign-in code and opens https://app.internetdata.io in your browser, where you sign in and pick an API key, which is then sent back to your site.

InternetData's terms of service: https://internetdata.io/terms. Its privacy policy: https://internetdata.io/privacy.
