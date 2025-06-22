# WooCommerce
The Smart Send plugin for WooCommerce

- [Setup](#setup-locally)
  - [Install WP CLI](#install-wp-cli)
  - [Install WordPress](#install-wordpress)
  - [Install WooCommerce](#install-woocommerce)
  - [Install Storefront theme](#install-storefront-theme)
  - [Import Sample data](#import-sample-data)
  - [Install plugin](#install-plugin)
  - [Setup WooCommerce](#setup-woocommerce)
  - [Go to admin](#go-to-admin)
- [Development](#development)
  - [SVN](#svn)
  - [Release a new version](#release-a-new-version)
  - [Exporting to a zip file](#exporting-to-a-zip-file)
  - [Sandbox environment](#sandbox-environment)

## Setup locally

[WP CLI]([url](https://make.wordpress.org/cli/)) and [WooCommerce CLI]([url](https://developer.woocommerce.com/docs/category/wc-cli/)) can be used to setup a fresh WooCommerce installation for testing.

### Install WP CLI

Either as a [global composer package]([url](https://make.wordpress.org/cli/handbook/guides/installing/#installing-via-composer)):

```bash
composer global require "wp-cli/wp-cli-bundle:*"
```

or by [Downloading the Phar file](https://wp-cli.org/#installing) (recommended in eg CI/CD pipelines):

```bash
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar --info
```

Note there is also a [Github Actio]([url](https://github.com/marketplace/actions/setup-wp-cli)) for installing WP CLI.

### Install WordPress

```bash
# Download wordpress
wp core download --path=wordpress

# Go to new installation
cd wordpress

# Generate a config file
wp config create --dbhost="127.0.0.1" --dbname=wordpress --dbuser=root --dbpass=""

# Remove any previous database if needed
# wp db drop --yes

# Create the database
wp db create

# Reset DB if ever needed
# wp db reset --yes

# Install WordPress
wp core install --url=wordpress.test --title="WordPress Demo" --admin_user=wp --admin_password=wp --admin_email=wp@smartsend.io

# Install admin command
wp package install wp-cli/admin-command

# Update all plugins
wp plugin update --all
````

### Install WooCommerce

[WooCommerce CLI](https://developer.woocommerce.com/docs/category/wc-cli/) is part of WooCommerce since version 3, so simply install WooCommerce using WP Cli:

```bash
wp plugin install woocommerce --activate
```

### Install Storefront theme

The official [storefront theme](https://wordpress.org/themes/storefront/) should be used for development and testing:

```bash
wp theme install storefront --activate
```

### Import Sample data

Installing the [WooCommerce Sample Data](https://woocommerce.com/document/importing-woocommerce-sample-data/) serves as a good starting point:

```bash
# Install the required plugin for importing
wp plugin install wordpress-importer --activate

# Import the WooCommerce sample data
wp import "wp-content/plugins/woocommerce/sample-data/sample_products.xml" --authors=create
```

### Install plugin

During development then it makes sense symlinking the working plugin folder `./smart-send-logistics` into the wordpress pluigns folder `wp-content/plugins`:

```bash
# Assuming that the repo is stored locally inside the folder ~/github.com/smartsendio/woocommerce
ln -s ~/github.com/smartsendio/woocommerce/smart-send-logistics "wp-content/plugins/smart-send-logistics" 
```

After which the plugin can be activated

```bash
wp plugin activate smart-send-logistics
```

### Setup WooCommerce

A few modifications must be made to the default WooCommerce setup

#### Finishing Setup Wizard

We have not found a way to finish the Setup Wizard through CLI yet. This Wizard sets a few settings like vat settings.

#### Add shipping zones

```bash
wp wc shipping_zone create --user=wp --name="Denmark"
wp wc shipping_zone create --user=wp --name="Nordics"
wp wc shipping_zone create --user=wp --name="EU"
```

configuring the countries for each shipping zone [cannot be done via CLI](https://github.com/woocommerce/woocommerce/issues/28576#issuecomment-1279203299), so doing via DB Query:

```bash
wp db query "INSERT INTO wp_woocommerce_shipping_zone_locations (zone_id, location_code, location_type) VALUES (1, 'DK', 'country')"
wp db query "INSERT INTO wp_woocommerce_shipping_zone_locations (zone_id, location_code, location_type) VALUES (2, 'SE', 'country')"
wp db query "INSERT INTO wp_woocommerce_shipping_zone_locations (zone_id, location_code, location_type) VALUES (3, 'EU', 'continent')"
```

Adding Smart Send shipping methods

```bash
wp wc shipping_zone_method create 1 --enabled=true --settings='{"title":"Smart Send Demo"}' --method_id=smart_send_shipping --user=wp
```

#### Enable payments

```bash
wp wc payment_gateway update bacs --user=wp --enabled=true
wp wc payment_gateway update cod --user=wp --enabled=true
```

### Go to admin

```bash
wp admin --user=wp
```

## Development

### SVN

Wordpress Plugin releases are managed by [SVN](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/#starting-a-new-plugin) and to sync the plugin to a local folder run:

```bash
svn co https://plugins.svn.wordpress.org/smart-send-logistics smart-send-logistics
```

#### Seeing a status of version controlled files

Note that the following command can be used to check which files are modified/added/deleted:

```bash
svn stat
```

#### Reverting local changes

Simply run the command from within the svn folder to revert all local changes:

```bash
svn revert -R .
```

### Release a new version

The easiest way to release a new version of the plugin is by running the deploy script in the root of the repository:

```bash
sh scripts/svn-deploy.sh
```

Alternative do this manually by following these steps:

1. Update all mentions of the `Version` in the following files:
  - `smart-send-logistics/smart-send-logistics.php`: Header
  - `smart-send-logistics/smart-send-logistics.php`: private property `$version`
  - `smart-send-logistics/readme.txt`: _Stable tag_-tag
2. Add changelog entry in `smart-send-logistics/readme.txt`
3. Copy folder `smart-send-logistics` to the `trunk` svn folder
4. Copy the `trunk` folder content to a new tagged release using the command `svn cp trunk tags/8.0.0` (replace `8.0.0` with the new version number)
5. Commit the work using the command `svn ci -m "tagging version 8.0.0"`

### Exporting to a zip file

To create a plugin zip file of a given branch/tag use:

```bash
git archive v8.1.0b4 --output="smart-send-shipping-woocommerce-v810b4.zip" "smart-send-logistics"
```

### Sandbox environment
When developing then it can sometimes be relevant to use Smart Send's _sandbox_ environment or a local server. This is done by implementing the following [filter](https://developer.wordpress.org/reference/functions/add_filter/):

```php
function smart_send_api_endpoint_callback( $endpoint ) {
  	if ($endpoint == 'https://app.smartsend.io/api/v1/') {
	  $endpoint = 'https://app.smartsend.dev/api/v1/';
	}
    return $endpoint;
}
add_filter( 'smart_send_api_endpoint', 'smart_send_api_endpoint_callback' );
```

An easy way to implement this is using the [Code Snippets plugin](https://wordpress.org/plugins/code-snippets/) and select _Run snippet everywhere_
