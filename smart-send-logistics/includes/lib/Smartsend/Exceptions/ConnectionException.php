<?php

namespace Smartsend\Exceptions;

require_once __DIR__ . '/HttpClientException.php';

/**
 * The HTTP exchange with the Smart Send API never completed: the request
 * failed at the transport level (DNS, TLS, timeout, refused connection)
 * before any HTTP response was received. There is no Response attached.
 */
class ConnectionException extends HttpClientException
{
    /**
     * Build a ConnectionException from the WP_Error returned by the
     * WordPress HTTP API, classifying the failure into a human-readable,
     * actionable message. The raw WP_Error code and message are appended
     * for support/debugging.
     *
     * @param   \WP_Error $wp_error
     * @return  static
     */
    public static function fromWpError($wp_error)
    {
        $wp_error_code = $wp_error->get_error_code();
        $wp_error_message = $wp_error->get_error_message();

        if (self::isTimeoutError($wp_error_message)) {
            $message = 'The connection to the Smart Send API timed out. Please try again. If the problem persists, ask your host whether outgoing requests to app.smartsend.io are blocked or slow.';
        } elseif (self::isSslError($wp_error_message)) {
            $message = 'A secure (SSL/TLS) connection to the Smart Send API could not be established. Please ask your host to update the server\'s SSL/TLS libraries and CA certificates.';
        } elseif (self::isConnectionError($wp_error_message)) {
            $message = 'Could not connect to the Smart Send API. Please check that the server can reach app.smartsend.io (DNS and outgoing HTTPS connections) and try again.';
        } else {
            $message = 'The request to the Smart Send API failed before a response was received: '.$wp_error_message;
        }

        return new static($message.' ('.$wp_error_code.': '.$wp_error_message.')');
    }

    /**
     * Whether a WP_Error message describes a timed-out request.
     *
     * @param   string $message
     * @return  bool
     */
    private static function isTimeoutError($message)
    {
        return self::messageContainsAny($message, array(
            'timed out',
            'timeout',
            'curl error 28',
        ));
    }

    /**
     * Whether a WP_Error message describes an SSL/TLS failure.
     *
     * @param   string $message
     * @return  bool
     */
    private static function isSslError($message)
    {
        return self::messageContainsAny($message, array(
            'ssl',
            'certificate',
            'curl error 35:',
            'curl error 51:',
            'curl error 58:',
            'curl error 60:',
        ));
    }

    /**
     * Whether a WP_Error message describes a DNS/connection failure.
     *
     * @param   string $message
     * @return  bool
     */
    private static function isConnectionError($message)
    {
        return self::messageContainsAny($message, array(
            'could not resolve',
            "couldn't resolve",
            'name or service not known',
            'connection refused',
            'failed to connect',
            'network is unreachable',
            'curl error 6:',
            'curl error 7:',
        ));
    }

    /**
     * Case-insensitive check whether a message contains any of the needles.
     *
     * @param   string $message
     * @param   array $needles
     * @return  bool
     */
    private static function messageContainsAny($message, $needles)
    {
        foreach ($needles as $needle) {
            if (stripos((string) $message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
