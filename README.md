# ACF Social Network Connection Field

Display social network posts on your WordPress site.

I’m publishing this here because it might be useful to others,
but USE OF THIS SCRIPT IS ENTIRELY AT YOUR OWN RISK. I accept no liability from its use.

This WordPress *mu-plugin* adds an ACF field type for connecting social networks
and retrieving last posts from social networks. **It is aimed at website developers.**

As I’m using this on lots of my clients websites, I’m really willing to improve its security,
reliability and its features. Contact me if you need support using it, I’d be pleased
to help and get feedback.

Supported networks :

* Facebook
* Twitter
* Instagram
* LinkedIn

Each field asks for connection information needed to retrieve the posts,
for example the Facebook field asks for :

* API version
* App ID
* App Secret
* Target

This plugin does not provide any front-end rendering.

A call to `get_field` returns an associative array of the retrieved last posts,
with each item containing following keys :

* network: Network name, `facebook`, `twitter`, etc.
* url: URL of the post on the social network
* date: Publication date of the post (`DateTime` object)
* thumb: URL of the post picture
* caption: Associated text of the post

## Installation

### Composer


### Manual


## Configuration

Configuration needed for each social network.

### Facebook

1. Create an App on [developer.facebook.com](https://developers.facebook.com/), the
account you use doesn’t matter
2. Create test apps as needed (for `dev` or `preprod` environments)

For each App:

1. Set website domain in App Settings (*App Domains* field)
2. Click *+ Add Platform* / *Website* and set *Site URL*
2. Click *+ App Product* / *Facebook Login*
3. In Facebook Login *Settings* set *Valid OAuth redirect URIs*
 (`/wp-admin/` URL of the website)
4. On App *Dashboard*, get *App ID* and *App Secret* and fill the corresponding inputs
of your *Social Network* field

### Twitter

1. Create an App on [apps.twitter.com](https://apps.twitter.com/app/new), the
account you use doesn’t matter
2. Set *Callback URL* (`/wp-admin/` URL of the website)
3. In *Keys and Access Tokens* tab, get *API Key* and *API Secret* and fill
the corresponding inputs of your *Social Network* field


## Todo

* Improve this doc with installation and configuration information
* Translate plugin
* Add support for Google+

## Compatibility

This ACF field type is compatible with ACF 5 only.
