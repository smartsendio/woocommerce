# WooCommerce
Smart Send module for WooCommerce

## SVN

Wordpress Plugin releases are managed by [SVN](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/#starting-a-new-plugin) and to sync the plugin to a local folder run:

```bash
svn co https://plugins.svn.wordpress.org/smart-send-logistics smart-send-logistics
```

To release a new version of the plugin:

1. Update all mentions of the `Version`:
  - `smart-send-logistics/smart-send-logistics.php`: Header
  - `smart-send-logistics/smart-send-logistics.php`: private property `$version`
  - `smart-send-logistics/readme.txt`: _Stable tag_-tag
2. Add changelog entry in `smart-send-logistics/readme.txt`
3. Copy folder `smart-send-logistics` to the `trunk` svn folder
4. Copy the `trunk` folder content to a new tagged release using the command `svn cp trunk tags/8.0.0` (replace `8.0.0` with the new version number)
5. Commit the work using the command `svn ci -m "tagging version 8.0.0"`

Note that the following command can be used to check which files are modified/added/deleted:

```bash
svn stat
```

## Zip

To create a plugin zip file of a given branch/tag use:

```bash
git archive v8.1.0b4 --output="smart-send-shipping-woocommerce-v810b4.zip" "smart-send-logistics"
```

## Development
When developing then it can sometimes be relevant to use Smart Send's _development_ environment. This is done by implementing the following [filter](https://developer.wordpress.org/reference/functions/add_filter/):
```
function smart_send_api_endpoint_callback( $endpoint ) {
  	if ($endpoint == 'https://app.smartsend.io/api/v1/') {
	  $endpoint = 'https://app.smartsend.dev/api/v1/';
	}
    return $endpoint;
}
add_filter( 'smart_send_api_endpoint', 'smart_send_api_endpoint_callback' );
```
An easy way to implement this is using the [Code Snippets plugin](https://wordpress.org/plugins/code-snippets/) and select _Run snippet everywhere_
