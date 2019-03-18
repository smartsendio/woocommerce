# WooCommerce
Smart Send module for WooCommerce

## Development
When developing then it can sometimes be relevant to use Smart Send's _staging_ environment. This is done by implementing the following [filter](https://developer.wordpress.org/reference/functions/add_filter/):
```
function smart_send_api_endpoint_callback( $endpoint ) {
  	if ($endpoint == 'https://app.smartsend.io/api/v1/') {
	  $endpoint = 'https://staging.smartsend.io/api/v1/';
	}
    return $endpoint;
}
add_filter( 'smart_send_api_endpoint', 'smart_send_api_endpoint_callback' );
```
An easy way to implement this is using the [Code Snippets plugin](https://wordpress.org/plugins/code-snippets/) and select _Run snippet everywhere_