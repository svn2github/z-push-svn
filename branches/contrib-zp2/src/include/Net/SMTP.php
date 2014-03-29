<?php
/* vim: set expandtab softtabstop=4 tabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2003 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 2.02 of the PHP license,      |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/2_02.txt.                                 |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Chuck Hagenbuch <chuck@horde.org>                           |
// |          Jon Parise <jon@php.net>                                    |
// |          Damian Alejandro Fernandez Sosa <damlists@cnba.uba.ar>      |
// +----------------------------------------------------------------------+


/**
 * Z-Push changes
 *
 * removed PEAR dependency by implementing own raiseError()
 *
 * Reference implementation used:
 * http://download.pear.php.net/package/Net_SMTP-1.6.2.tgz
 *
 *
 */

//require_once 'PEAR.php';
require_once 'include/Net/Socket.php';

/**
 * Provides an implementation of the SMTP protocol using PEAR's
 * Net_Socket:: class.
 *
 * @package Net_SMTP
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jon Parise <jon@php.net>
 * @author  Damian Alejandro Fernandez Sosa <damlists@cnba.uba.ar>
 *
 * @example basic.php   A basic implementation of the Net_SMTP package.
 */
class Net_SMTP
{
    /**
     * The server to connect to.
     * @var string
     * @access public
     */
    var $host = 'localhost';

    /**
     * The port to connect to.
     * @var int
     * @access public
     */
    var $port = 25;

    /**
     * The value to give when sending EHLO or HELO.
     * @var string
     * @access public
     */
    var $localhost = 'localhost';

    /**
     * List of supported authentication methods, in preferential order.
     * @var array
     * @access public
     */
    var $auth_methods = array();

    /**
     * Use SMTP command pipelining (specified in RFC 2920) if the SMTP
     * server supports it.
     *
     * When pipeling is enabled, rcptTo(), mailFrom(), sendFrom(),
     * somlFrom() and samlFrom() do not wait for a response from the
     * SMTP server but return immediately.
     *
     * @var bool
     * @access public
     */
    var $pipelining = false;

    /**
     * Number of pipelined commands.
     * @var int
     * @access private
     */
    var $_pipelined_commands = 0;

    /**
     * Should debugging output be enabled?
     * @var boolean
     * @access private
     */
    var $_debug = false;

    /**
     * Debug output handler.
     * @var callback
     * @access private
     */
    var $_debug_handler = null;

    /**
     * The socket resource being used to connect to the SMTP server.
     * @var resource
     * @access private
     */
    var $_socket = null;

    /**
     * Array of socket options that will be passed to Net_Socket::connect().
     * @see stream_context_create()
     * @var array
     * @access private
     */
    var $_socket_options = null;

    /**
     * The socket I/O timeout value in seconds.
     * @var int
     * @access private
     */
    var $_timeout = 0;

    /**
     * The most recent server response code.
     * @var int
     * @access private
     */
    var $_code = -1;

    /**
     * The most recent server response arguments.
     * @var array
     * @access private
     */
    var $_arguments = array();

    /**
     * Stores the SMTP server's greeting string.
     * @var string
     * @access private
     */
    var $_greeting = null;

    /**
     * Stores detected features of the SMTP server.
     * @var array
     * @access private
     */
    var $_esmtp = array();

    /**
     * Instantiates a new Net_SMTP object, overriding any defaults
     * with parameters that are passed in.
     *
     * If you have SSL support in PHP, you can connect to a server
     * over SSL using an 'ssl://' prefix:
     *
     *   // 465 is a common smtps port.
     *   $smtp = new Net_SMTP('ssl://mail.host.com', 465);
     *   $smtp->connect();
     *
     * @param string  $host       The server to connect to.
     * @param integer $port       The port to connect to.
     * @param string  $localhost  The value to give when sending EHLO or HELO.
     * @param boolean $pipeling   Use SMTP command pipelining
     * @param integer $timeout    Socket I/O timeout in seconds.
     * @param array   $socket_options Socket stream_context_create() options.
     *
     * @access  public
     * @since   1.0
     */
    function Net_SMTP($host = null, $port = null, $localhost = null,
        $pipelining = false, $timeout = 0, $socket_options = null)
    {
        if (isset($host)) {
            $this->host = $host;
        }
        if (isset($port)) {
            $this->port = $port;
        }
        if (isset($localhost)) {
            $this->localhost = $localhost;
        }
        $this->pipelining = $pipelining;

        $this->_socket = new Net_Socket();
        $this->_socket_options = $socket_options;
        $this->_timeout = $timeout;

        /* Include the Auth_SASL package.  If the package is available, we
         * enable the authentication methods that depend upon it. */
        if (@include_once 'include/Auth/SASL.php') {
            $this->setAuthMethod('CRAM-MD5', array($this, '_authCram_MD5'));
            $this->setAuthMethod('DIGEST-MD5', array($this, '_authDigest_MD5'));
        }

        /* These standard authentication methods are always available. */
        $this->setAuthMethod('LOGIN', array($this, '_authLogin'), false);
        $this->setAuthMethod('PLAIN', array($this, '_authPlain'), false);
    }

    /**
     * Set the socket I/O timeout value in seconds plus microseconds.
     *
     * @param   integer $seconds        Timeout value in seconds.
     * @param   integer $microseconds   Additional value in microseconds.
     *
     * @access  public
     * @since   1.5.0
     */
    function setTimeout($seconds, $microseconds = 0) {
        return $this->_socket->setTimeout($seconds, $microseconds);
    }

    /**
     * Set the value of the debugging flag.
     *
     * @param   boolean $debug      New value for the debugging flag.
     *
     * @access  public
     * @since   1.1.0
     */
    function setDebug($debug, $handler = null)
    {
        $this->_debug = $debug;
        $this->_debug_handler = $handler;
    }

    /**
     * Write the given debug text to the current debug output handler.
     *
     * @param   string  $message    Debug mesage text.
     *
     * @access  private
     * @since   1.3.3
     */
    function _debug($message)
    {
        if ($this->_debug) {
            if ($this->_debug_handler) {
                call_user_func_array($this->_debug_handler,
                                     array(&$this, $message));
            } else {
                echo "DEBUG: $message\n";
            }
        }
    }

    /**
     * Send the given string of data to the server.
     *
     * @param   string  $data       The string of data to send.
     *
     * @return  mixed   The number of bytes that were actually written,
     *                  or a PEAR_Error object on failure.
     *
     * @access  private
     * @since   1.1.0
     */
    function _send($data)
    {
        $this->_debug("Send: $data");

        $result = $this->_socket->write($data);
        //if (!$result || PEAR::isError($result)) {
        if ($result === false) {
            $msg = ($result) ? $result->getMessage() : "unknown error";
            return Net_SMTP::raiseError("Failed to write to socket: $msg",
                                    null, PEAR_ERROR_RETURN);
        }

        return $result;
    }

    /**
     * Send a command to the server with an optional string of
     * arguments.  A carriage return / linefeed (CRLF) sequence will
     * be appended to each command string before it is sent to the
     * SMTP server - an error will be thrown if the command string
     * already contains any newline characters. Use _send() for
     * commands that must contain newlines.
     *
     * @param   string  $command    The SMTP command to send to the server.
     * @param   string  $args       A string of optional arguments to append
     *                              to the command.
     *
     * @return  mixed   The result of the _send() call.
     *
     * @access  private
     * @since   1.1.0
     */
    function _put($command, $args = '')
    {
        if (!empty($args)) {
            $command .= ' ' . $args;
        }

        if (strcspn($command, "\r\n") !== strlen($command)) {
            return Net_SMTP::raiseError('Commands cannot contain newlines',
                                    null, PEAR_ERROR_RETURN);
        }

        return $this->_send($command . "\r\n");
    }

    /**
     * Read a reply from the SMTP server.  The reply consists of a response
     * code and a response message.
     *
     * @param   mixed   $valid      The set of valid response codes.  These
     *                              may be specified as an array of integer
     *                              values or as a single integer value.
     * @param   bool    $later      Do not parse the response now, but wait
     *                              until the last command in the pipelined
     *                              command group
     *
     * @return  mixed   True if the server returned a valid response code or
     *                  a PEAR_Error object is an error condition is reached.
     *
     * @access  private
     * @since   1.1.0
     *
     * @see     getResponse
     */
    function _parseResponse($valid, $later = false)
    {
        $this->_code = -1;
        $this->_arguments = array();

        if ($later) {
            $this->_pipelined_commands++;
            return true;
        }

        for ($i = 0; $i <= $this->_pipelined_commands; $i++) {
            while ($line = $this->_socket->readLine()) {
                $this->_debug("Recv: $line");

                /* If we receive an empty line, the connection was closed. */
                if (empty($line)) {
                    $this->disconnect();
                    return Net_SMTP::raiseError('Connection was closed',
                                            null, PEAR_ERROR_RETURN);
                }

                /* Read the code and store the rest in the arguments array. */
                $code = substr($line, 0, 3);
                $this->_arguments[] = trim(substr($line, 4));

                /* Check the syntax of the response code. */
                if (is_numeric($code)) {
                    $this->_code = (int)$code;
                } else {
                    $this->_code = -1;
                    break;
                }

                /* If this is not a multiline response, we're done. */
                if (substr($line, 3, 1) != '-') {
                    break;
                }
            }
        }

        $this->_pipelined_commands = 0;

        /* Compare the server's response code with the valid code/codes. */
        if (is_int($valid) && ($this->_code === $valid)) {
            return true;
        } elseif (is_array($valid) && in_array($this->_code, $valid, true)) {
            return true;
        }

        return Net_SMTP::raiseError('Invalid response code received from server',
                                $this->_code, PEAR_ERROR_RETURN);
    }

    /**
     * Issue an SMTP command and verify its response.
     *
     * @param   string  $command    The SMTP command string or data.
     * @param   mixed   $valid      The set of valid response codes.  These
     *                              may be specified as an array of integer
     *                              values or as a single integer value.
     *
     * @return  mixed   True on success or a PEAR_Error object on failure.
     *
     * @access  public
     * @since   1.6.0
     */
    function command($command, $valid)
    {
        //if (PEAR::isError($error = $this->_put($command))) {
        if (($error = $this->_put($command)) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse($valid))) {
        if (($error = $this->_parseResponse($valid)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Return a 2-tuple containing the last response from the SMTP server.
     *
     * @return  array   A two-element array: the first element contains the
     *                  response code as an integer and the second element
     *                  contains the response's arguments as a string.
     *
     * @access  public
     * @since   1.1.0
     */
    function getResponse()
    {
        return array($this->_code, join("\n", $this->_arguments));
    }

    /**
     * Return the SMTP server's greeting string.
     *
     * @return  string  A string containing the greeting string, or null if a
     *                  greeting has not been received.
     *
     * @access  public
     * @since   1.3.3
     */
    function getGreeting()
    {
        return $this->_greeting;
    }

    /**
     * Attempt to connect to the SMTP server.
     *
     * @param   int     $timeout    The timeout value (in seconds) for the
     *                              socket connection attempt.
     * @param   bool    $persistent Should a persistent socket connection
     *                              be used?
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function connect($timeout = null, $persistent = false)
    {
        $this->_greeting = null;
        $result = $this->_socket->connect($this->host, $this->port,
                                          $persistent, $timeout,
                                          $this->_socket_options);
        //if (PEAR::isError($result)) {
        if ($result === false) {
            return Net_SMTP::raiseError('Failed to connect socket: ' .
                                    $result->getMessage());
        }

        /*
         * Now that we're connected, reset the socket's timeout value for
         * future I/O operations.  This allows us to have different socket
         * timeout values for the initial connection (our $timeout parameter)
         * and all other socket operations.
         */
        if ($this->_timeout > 0) {
            //if (PEAR::isError($error = $this->setTimeout($this->_timeout))) {
            if (($error = $this->setTimeout($this->_timeout)) === false) {
                return $error;
            }
        }

        //if (PEAR::isError($error = $this->_parseResponse(220))) {
        if (($error = $this->_parseResponse(220)) === false) {
            return $error;
        }

        /* Extract and store a copy of the server's greeting string. */
        list(, $this->_greeting) = $this->getResponse();

        //if (PEAR::isError($error = $this->_negotiate())) {
        if (($error = $this->_negotiate()) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Attempt to disconnect from the SMTP server.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function disconnect()
    {
        //if (PEAR::isError($error = $this->_put('QUIT'))) {
        if (($error = $this->_put('QUIT')) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(221))) {
        if (($error = $this->_parseResponse(221)) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_socket->disconnect())) {
        if (($error = $this->_socket->disconnect()) === false) {
            return Net_SMTP::raiseError('Failed to disconnect socket: ' .
                                    $error->getMessage());
        }

        return true;
    }

    /**
     * Attempt to send the EHLO command and obtain a list of ESMTP
     * extensions available, and failing that just send HELO.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     *
     * @access private
     * @since  1.1.0
     */
    function _negotiate()
    {
        //if (PEAR::isError($error = $this->_put('EHLO', $this->localhost))) {
        if (($error = $this->_put('EHLO', $this->localhost)) === false) {
            return $error;
        }

        //if (PEAR::isError($this->_parseResponse(250))) {
        if (($this->_parseResponse(250)) === false) {
            /* If the EHLO failed, try the simpler HELO command. */
            //if (PEAR::isError($error = $this->_put('HELO', $this->localhost))) {
            if (($error = $this->_put('HELO', $this->localhost)) === false) {
                return $error;
            }
            //if (PEAR::isError($this->_parseResponse(250))) {
            if (($this->_parseResponse(250)) === false) {
                return Net_SMTP::raiseError('HELO was not accepted: ', $this->_code,
                                        PEAR_ERROR_RETURN);
            }

            return true;
        }

        foreach ($this->_arguments as $argument) {
            $verb = strtok($argument, ' ');
            $arguments = substr($argument, strlen($verb) + 1,
                                strlen($argument) - strlen($verb) - 1);
            $this->_esmtp[$verb] = $arguments;
        }

        if (!isset($this->_esmtp['PIPELINING'])) {
            $this->pipelining = false;
        }

        return true;
    }

    /**
     * Returns the name of the best authentication method that the server
     * has advertised.
     *
     * @return mixed    Returns a string containing the name of the best
     *                  supported authentication method or a PEAR_Error object
     *                  if a failure condition is encountered.
     * @access private
     * @since  1.1.0
     */
    function _getBestAuthMethod()
    {
        $available_methods = explode(' ', $this->_esmtp['AUTH']);

        foreach ($this->auth_methods as $method => $callback) {
            if (in_array($method, $available_methods)) {
                return $method;
            }
        }

        return Net_SMTP::raiseError('No supported authentication methods',
                                null, PEAR_ERROR_RETURN);
    }

    /**
     * Attempt to do SMTP authentication.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     * @param string The requested authentication method.  If none is
     *               specified, the best supported method will be used.
     * @param bool   Flag indicating whether or not TLS should be attempted.
     * @param string An optional authorization identifier.  If specified, this
     *               identifier will be used as the authorization proxy.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function auth($uid, $pwd , $method = '', $tls = true, $authz = '')
    {
        /* We can only attempt a TLS connection if one has been requested,
         * we're running PHP 5.1.0 or later, have access to the OpenSSL
         * extension, are connected to an SMTP server which supports the
         * STARTTLS extension, and aren't already connected over a secure
         * (SSL) socket connection. */
        if ($tls && version_compare(PHP_VERSION, '5.1.0', '>=') &&
            extension_loaded('openssl') && isset($this->_esmtp['STARTTLS']) &&
            strncasecmp($this->host, 'ssl://', 6) !== 0) {
            /* Start the TLS connection attempt. */
            //if (PEAR::isError($result = $this->_put('STARTTLS'))) {
            if (($result = $this->_put('STARTTLS')) === false) {
                return $result;
            }
            //if (PEAR::isError($result = $this->_parseResponse(220))) {
            if (($result = $this->_parseResponse(220)) === false) {
                return $result;
            }
            //if (PEAR::isError($result = $this->_socket->enableCrypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT))) {
            if (($result = $this->_socket->enableCrypto(true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) === false) {
                return $result;
            } elseif ($result !== true) {
                return Net_SMTP::raiseError('STARTTLS failed');
            }

            /* Send EHLO again to recieve the AUTH string from the
             * SMTP server. */
            $this->_negotiate();
        }

        if (empty($this->_esmtp['AUTH'])) {
            return Net_SMTP::raiseError('SMTP server does not support authentication');
        }

        /* If no method has been specified, get the name of the best
         * supported method advertised by the SMTP server. */
        if (empty($method)) {
            //if (PEAR::isError($method = $this->_getBestAuthMethod())) {
            if (($method = $this->_getBestAuthMethod()) === false) {
                /* Return the PEAR_Error object from _getBestAuthMethod(). */
                return $method;
            }
        } else {
            $method = strtoupper($method);
            if (!array_key_exists($method, $this->auth_methods)) {
                return Net_SMTP::raiseError("$method is not a supported authentication method");
            }
        }

        if (!isset($this->auth_methods[$method])) {
            return Net_SMTP::raiseError("$method is not a supported authentication method");
        }

        if (!is_callable($this->auth_methods[$method], false)) {
            return Net_SMTP::raiseError("$method authentication method cannot be called");
        }

        if (is_array($this->auth_methods[$method])) {
            list($object, $method) = $this->auth_methods[$method];
            $result = $object->{$method}($uid, $pwd, $authz, $this);
        } else {
            $func =  $this->auth_methods[$method];
            $result = $func($uid, $pwd, $authz, $this);
         }

        /* If an error was encountered, return the PEAR_Error object. */
        //if (PEAR::isError($result)) {
        if ($result === false) {
            return $result;
        }

        return true;
    }

    /**
     * Add a new authentication method.
     *
     * @param string    The authentication method name (e.g. 'PLAIN')
     * @param mixed     The authentication callback (given as the name of a
     *                  function or as an (object, method name) array).
     * @param bool      Should the new method be prepended to the list of
     *                  available methods?  This is the default behavior,
     *                  giving the new method the highest priority.
     *
     * @return  mixed   True on success or a PEAR_Error object on failure.
     *
     * @access public
     * @since  1.6.0
     */
    function setAuthMethod($name, $callback, $prepend = true)
    {
        if (!is_string($name)) {
            return Net_SMTP::raiseError('Method name is not a string');
        }

        if (!is_string($callback) && !is_array($callback)) {
            return Net_SMTP::raiseError('Method callback must be string or array');
        }

        if (is_array($callback)) {
            if (!is_object($callback[0]) || !is_string($callback[1]))
                return Net_SMTP::raiseError('Bad mMethod callback array');
        }

        if ($prepend) {
            $this->auth_methods = array_merge(array($name => $callback),
                                              $this->auth_methods);
        } else {
            $this->auth_methods[$name] = $callback;
        }

        return true;
    }

    /**
     * Authenticates the user using the DIGEST-MD5 method.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     * @param string The optional authorization proxy identifier.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access private
     * @since  1.1.0
     */
    function _authDigest_MD5($uid, $pwd, $authz = '')
    {
        //if (PEAR::isError($error = $this->_put('AUTH', 'DIGEST-MD5'))) {
        if (($error = $this->_put('AUTH', 'DIGEST-MD5')) === false) {
            return $error;
        }
        /* 334: Continue authentication request */
        //if (PEAR::isError($error = $this->_parseResponse(334))) {
        if (($error = $this->_parseResponse(334)) === false) {
            /* 503: Error: already authenticated */
            if ($this->_code === 503) {
                return true;
            }
            return $error;
        }

        $challenge = base64_decode($this->_arguments[0]);
        $digest = &Auth_SASL::factory('digest-md5');
        $auth_str = base64_encode($digest->getResponse($uid, $pwd, $challenge,
                                                       $this->host, "smtp",
                                                       $authz));

        //if (PEAR::isError($error = $this->_put($auth_str))) {
        if (($error = $this->_put($auth_str)) === false) {
            return $error;
        }
        /* 334: Continue authentication request */
        //if (PEAR::isError($error = $this->_parseResponse(334))) {
        if (($error = $this->_parseResponse(334)) === false) {
            return $error;
        }

        /* We don't use the protocol's third step because SMTP doesn't
         * allow subsequent authentication, so we just silently ignore
         * it. */
        //if (PEAR::isError($error = $this->_put(''))) {
        if (($error = $this->_put('')) === false) {
            return $error;
        }
        /* 235: Authentication successful */
        //if (PEAR::isError($error = $this->_parseResponse(235))) {
        if (($error = $this->_parseResponse(235)) === false) {
            return $error;
        }
    }

    /**
     * Authenticates the user using the CRAM-MD5 method.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     * @param string The optional authorization proxy identifier.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access private
     * @since  1.1.0
     */
    function _authCRAM_MD5($uid, $pwd, $authz = '')
    {
        //if (PEAR::isError($error = $this->_put('AUTH', 'CRAM-MD5'))) {
        if (($error = $this->_put('AUTH', 'CRAM-MD5')) === false) {
            return $error;
        }
        /* 334: Continue authentication request */
        //if (PEAR::isError($error = $this->_parseResponse(334))) {
        if (($error = $this->_parseResponse(334)) === false) {
            /* 503: Error: already authenticated */
            if ($this->_code === 503) {
                return true;
            }
            return $error;
        }

        $challenge = base64_decode($this->_arguments[0]);
        $cram = &Auth_SASL::factory('cram-md5');
        $auth_str = base64_encode($cram->getResponse($uid, $pwd, $challenge));

        //if (PEAR::isError($error = $this->_put($auth_str))) {
        if (($error = $this->_put($auth_str)) === false) {
            return $error;
        }

        /* 235: Authentication successful */
        //if (PEAR::isError($error = $this->_parseResponse(235))) {
        if (($error = $this->_parseResponse(235)) === false) {
            return $error;
        }
    }

    /**
     * Authenticates the user using the LOGIN method.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     * @param string The optional authorization proxy identifier.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access private
     * @since  1.1.0
     */
    function _authLogin($uid, $pwd, $authz = '')
    {
        //if (PEAR::isError($error = $this->_put('AUTH', 'LOGIN'))) {
        if (($error = $this->_put('AUTH', 'LOGIN')) === false) {
            return $error;
        }
        /* 334: Continue authentication request */
        //if (PEAR::isError($error = $this->_parseResponse(334))) {
        if (($error = $this->_parseResponse(334)) === false) {
            /* 503: Error: already authenticated */
            if ($this->_code === 503) {
                return true;
            }
            return $error;
        }

        //if (PEAR::isError($error = $this->_put(base64_encode($uid)))) {
        if (($error = $this->_put(base64_encode($uid))) === false) {
            return $error;
        }
        /* 334: Continue authentication request */
        //if (PEAR::isError($error = $this->_parseResponse(334))) {
        if (($error = $this->_parseResponse(334)) === false) {
            return $error;
        }

        //if (PEAR::isError($error = $this->_put(base64_encode($pwd)))) {
        if (($error = $this->_put(base64_encode($pwd))) === false) {
            return $error;
        }

        /* 235: Authentication successful */
        //if (PEAR::isError($error = $this->_parseResponse(235))) {
        if (($error = $this->_parseResponse(235)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Authenticates the user using the PLAIN method.
     *
     * @param string The userid to authenticate as.
     * @param string The password to authenticate with.
     * @param string The optional authorization proxy identifier.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access private
     * @since  1.1.0
     */
    function _authPlain($uid, $pwd, $authz = '')
    {
        //if (PEAR::isError($error = $this->_put('AUTH', 'PLAIN'))) {
        if (($error = $this->_put('AUTH', 'PLAIN')) === false) {
            return $error;
        }
        /* 334: Continue authentication request */
        //if (PEAR::isError($error = $this->_parseResponse(334))) {
        if (($error = $this->_parseResponse(334)) === false) {
            /* 503: Error: already authenticated */
            if ($this->_code === 503) {
                return true;
            }
            return $error;
        }

        $auth_str = base64_encode($authz . chr(0) . $uid . chr(0) . $pwd);

        //if (PEAR::isError($error = $this->_put($auth_str))) {
        if (($error = $this->_put($auth_str)) === false) {
            return $error;
        }

        /* 235: Authentication successful */
        //if (PEAR::isError($error = $this->_parseResponse(235))) {
        if (($error = $this->_parseResponse(235)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Send the HELO command.
     *
     * @param string The domain name to say we are.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function helo($domain)
    {
        //if (PEAR::isError($error = $this->_put('HELO', $domain))) {
        if (($error = $this->_put('HELO', $domain)) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(250))) {
        if (($error = $this->_parseResponse(250)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Return the list of SMTP service extensions advertised by the server.
     *
     * @return array The list of SMTP service extensions.
     * @access public
     * @since 1.3
     */
    function getServiceExtensions()
    {
        return $this->_esmtp;
    }

    /**
     * Send the MAIL FROM: command.
     *
     * @param string $sender    The sender (reverse path) to set.
     * @param string $params    String containing additional MAIL parameters,
     *                          such as the NOTIFY flags defined by RFC 1891
     *                          or the VERP protocol.
     *
     *                          If $params is an array, only the 'verp' option
     *                          is supported.  If 'verp' is true, the XVERP
     *                          parameter is appended to the MAIL command.  If
     *                          the 'verp' value is a string, the full
     *                          XVERP=value parameter is appended.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function mailFrom($sender, $params = null)
    {
        $args = "FROM:<$sender>";

        /* Support the deprecated array form of $params. */
        if (is_array($params) && isset($params['verp'])) {
            /* XVERP */
            if ($params['verp'] === true) {
                $args .= ' XVERP';

            /* XVERP=something */
            } elseif (trim($params['verp'])) {
                $args .= ' XVERP=' . $params['verp'];
            }
        } elseif (is_string($params) && !empty($params)) {
            $args .= ' ' . $params;
        }

        //if (PEAR::isError($error = $this->_put('MAIL', $args))) {
        if (($error = $this->_put('MAIL', $args)) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(250, $this->pipelining))) {
        if (($error = $this->_parseResponse(250, $this->pipelining)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Send the RCPT TO: command.
     *
     * @param string $recipient The recipient (forward path) to add.
     * @param string $params    String containing additional RCPT parameters,
     *                          such as the NOTIFY flags defined by RFC 1891.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     *
     * @access public
     * @since  1.0
     */
    function rcptTo($recipient, $params = null)
    {
        $args = "TO:<$recipient>";
        if (is_string($params)) {
            $args .= ' ' . $params;
        }

        //if (PEAR::isError($error = $this->_put('RCPT', $args))) {
        if (($error = $this->_put('RCPT', $args)) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(array(250, 251), $this->pipelining))) {
        if (($error = $this->_parseResponse(array(250, 251), $this->pipelining)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Quote the data so that it meets SMTP standards.
     *
     * This is provided as a separate public function to facilitate
     * easier overloading for the cases where it is desirable to
     * customize the quoting behavior.
     *
     * @param string $data  The message text to quote. The string must be passed
     *                      by reference, and the text will be modified in place.
     *
     * @access public
     * @since  1.2
     */
    function quotedata(&$data)
    {
        /* Because a single leading period (.) signifies an end to the
         * data, legitimate leading periods need to be "doubled" ('..'). */
        $data = preg_replace('/^\./m', '..', $data);

        /* Change Unix (\n) and Mac (\r) linefeeds into CRLF's (\r\n). */
        $data = preg_replace('/(?:\r\n|\n|\r(?!\n))/', "\r\n", $data);
    }

    /**
     * Send the DATA command.
     *
     * @param mixed $data     The message data, either as a string or an open
     *                        file resource.
     * @param string $headers The message headers.  If $headers is provided,
     *                        $data is assumed to contain only body data.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function data($data, $headers = null)
    {
        /* Verify that $data is a supported type. */
        if (!is_string($data) && !is_resource($data)) {
            return Net_SMTP::raiseError('Expected a string or file resource');
        }

        /* Start by considering the size of the optional headers string.  We
         * also account for the addition 4 character "\r\n\r\n" separator
         * sequence. */
        $size = (is_null($headers)) ? 0 : strlen($headers) + 4;

        if (is_resource($data)) {
            $stat = fstat($data);
            if ($stat === false) {
                return Net_SMTP::raiseError('Failed to get file size');
            }
            $size += $stat['size'];
        } else {
            $size += strlen($data);
        }

        /* RFC 1870, section 3, subsection 3 states "a value of zero indicates
         * that no fixed maximum message size is in force".  Furthermore, it
         * says that if "the parameter is omitted no information is conveyed
         * about the server's fixed maximum message size". */
        $limit = (isset($this->_esmtp['SIZE'])) ? $this->_esmtp['SIZE'] : 0;
        if ($limit > 0 && $size >= $limit) {
            $this->disconnect();
            return Net_SMTP::raiseError('Message size exceeds server limit');
        }

        /* Initiate the DATA command. */
        //if (PEAR::isError($error = $this->_put('DATA'))) {
        if (($error = $this->_put('DATA')) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(354))) {
        if (($error = $this->_parseResponse(354)) === false) {
            return $error;
        }

        /* If we have a separate headers string, send it first. */
        if (!is_null($headers)) {
            $this->quotedata($headers);
            //if (PEAR::isError($result = $this->_send($headers . "\r\n\r\n"))) {
            if (($result = $this->_send($headers . "\r\n\r\n")) === false) {
                return $result;
            }
        }

        /* Now we can send the message body data. */
        if (is_resource($data)) {
            /* Stream the contents of the file resource out over our socket
             * connection, line by line.  Each line must be run through the
             * quoting routine. */
            while (strlen($line = fread($data, 8192)) > 0) {
                /* If the last character is an newline, we need to grab the
                 * next character to check to see if it is a period. */
                while (!feof($data)) {
                    $char = fread($data, 1);
                    $line .= $char;
                    if ($char != "\n") {
                        break;
                    }
                }
                $this->quotedata($line);
                //if (PEAR::isError($result = $this->_send($line))) {
                if (($result = $this->_send($line)) === false) {
                    return $result;
                }
            }
        } else {
            /*
             * Break up the data by sending one chunk (up to 512k) at a time.
             * This approach reduces our peak memory usage.
             */
            for ($offset = 0; $offset < $size;) {
                $end = $offset + 512000;

                /*
                 * Ensure we don't read beyond our data size or span multiple
                 * lines.  quotedata() can't properly handle character data
                 * that's split across two line break boundaries.
                 */
                if ($end >= $size) {
                    $end = $size;
                } else {
                    for (; $end < $size; $end++) {
                        if ($data[$end] != "\n") {
                            break;
                        }
                    }
                }

                /* Extract our chunk and run it through the quoting routine. */
                $chunk = substr($data, $offset, $end - $offset);
                $this->quotedata($chunk);

                /* If we run into a problem along the way, abort. */
                //if (PEAR::isError($result = $this->_send($chunk))) {
                if (($result = $this->_send($chunk)) === false) {
                    return $result;
                }

                /* Advance the offset to the end of this chunk. */
                $offset = $end;
            }
        }

        /* Finally, send the DATA terminator sequence. */
        //if (PEAR::isError($result = $this->_send("\r\n.\r\n"))) {
        if (($result = $this->_send("\r\n.\r\n")) === false) {
            return $result;
        }

        /* Verify that the data was successfully received by the server. */
        //if (PEAR::isError($error = $this->_parseResponse(250, $this->pipelining))) {
        if (($error = $this->_parseResponse(250, $this->pipelining)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Send the SEND FROM: command.
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.2.6
     */
    function sendFrom($path)
    {
        //if (PEAR::isError($error = $this->_put('SEND', "FROM:<$path>"))) {
        if (($error = $this->_put('SEND', "FROM:<$path>")) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(250, $this->pipelining))) {
        if (($error = $this->_parseResponse(250, $this->pipelining)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Backwards-compatibility wrapper for sendFrom().
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     *
     * @access      public
     * @since       1.0
     * @deprecated  1.2.6
     */
    function send_from($path)
    {
        return sendFrom($path);
    }

    /**
     * Send the SOML FROM: command.
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.2.6
     */
    function somlFrom($path)
    {
        //if (PEAR::isError($error = $this->_put('SOML', "FROM:<$path>"))) {
        if (($error = $this->_put('SOML', "FROM:<$path>")) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(250, $this->pipelining))) {
        if (($error = $this->_parseResponse(250, $this->pipelining)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Backwards-compatibility wrapper for somlFrom().
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     *
     * @access      public
     * @since       1.0
     * @deprecated  1.2.6
     */
    function soml_from($path)
    {
        return somlFrom($path);
    }

    /**
     * Send the SAML FROM: command.
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.2.6
     */
    function samlFrom($path)
    {
        //if (PEAR::isError($error = $this->_put('SAML', "FROM:<$path>"))) {
        if (($error = $this->_put('SAML', "FROM:<$path>")) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(250, $this->pipelining))) {
        if (($error = $this->_parseResponse(250, $this->pipelining)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Backwards-compatibility wrapper for samlFrom().
     *
     * @param string The reverse path to send.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     *
     * @access      public
     * @since       1.0
     * @deprecated  1.2.6
     */
    function saml_from($path)
    {
        return samlFrom($path);
    }

    /**
     * Send the RSET command.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function rset()
    {
        //if (PEAR::isError($error = $this->_put('RSET'))) {
        if (($error = $this->_put('RSET')) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(250, $this->pipelining))) {
        if (($error = $this->_parseResponse(250, $this->pipelining)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Send the VRFY command.
     *
     * @param string The string to verify
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function vrfy($string)
    {
        /* Note: 251 is also a valid response code */
        //if (PEAR::isError($error = $this->_put('VRFY', $string))) {
        if (($error = $this->_put('VRFY', $string)) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(array(250, 252)))) {
        if (($error = $this->_parseResponse(array(250, 252))) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Send the NOOP command.
     *
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @access public
     * @since  1.0
     */
    function noop()
    {
        //if (PEAR::isError($error = $this->_put('NOOP'))) {
        if (($error = $this->_put('NOOP')) === false) {
            return $error;
        }
        //if (PEAR::isError($error = $this->_parseResponse(250))) {
        if (($error = $this->_parseResponse(250)) === false) {
            return $error;
        }

        return true;
    }

    /**
     * Backwards-compatibility method.  identifySender()'s functionality is
     * now handled internally.
     *
     * @return  boolean     This method always return true.
     *
     * @access  public
     * @since   1.0
     */
    function identifySender()
    {
        return true;
    }

    /**
     * Z-Push helper for error logging
     * removing PEAR dependency
     *
     * @param  string  debug message
     * @return boolean always false as there was an error
     * @access private
     */
    static function raiseError($message) {
        ZLog::Write(LOGLEVEL_ERROR, "Net_SMTP error: ". $message);
        return false;
    }
}
