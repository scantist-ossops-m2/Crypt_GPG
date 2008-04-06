<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Crypt_GPG is a package to use GPG from PHP
 *
 * This file contains a native PHP implementation of the GPG object class. PHP's
 * process manipulation functions are used to handle the GPG process.
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * This library is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation; either version 2.1 of the
 * License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 *
 * @category  Encryption
 * @package   Crypt_GPG
 * @author    Nathan Fredrickson <nathan@silverorange.com>
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2005-2008 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 * @version   CVS: $Id$
 * @link      http://pear.php.net/package/Crypt_GPG
 * @link      http://www.gnupg.org/
 */

/**
 * Crypt_GPG driver base class
 */
require_once 'Crypt/GPG.php';

/**
 * GPG signature helper class
 */
require_once 'Crypt/GPG/Signature.php';

/**
 * GPG key class
 */
require_once 'Crypt/GPG/Key.php';

/**
 * GPG sub-key class
 */
require_once 'Crypt/GPG/SubKey.php';

/**
 * GPG user id class
 */
require_once 'Crypt/GPG/UserId.php';

/**
 * GPG exception classes
 */
require_once 'Crypt/GPG/Exceptions.php';

// {{{ class Crypt_GPG_Driver_Php

/**
 * Native PHP Crypt_GPG driver
 *
 * This driver uses PHP's native process control functions to directly control
 * the GPG process. The GPG executable is required to be on the system.
 *
 * For most systems, all data is passed to the GPG subprocess using file
 * descriptors. This is the most secure method of passing data to the GPG
 * subprocess.
 *
 * If the operating system is Windows, this driver will use temporary files as
 * a fallback for file descriptors above 2. Windows cannot use file descriptors
 * above 2 with proc_open(). The {@link Crypt_GPG_Driver_Php::FD_STATUS} and
 * {@link Crypt_GPG_Driver_Php::FD_MESSAGE} file descriptors are emulated
 * using temporary files. All temporary files are deleted when a method call
 * finishes or when the {@link Crypt_GPG_Driver_Php::__destruct()} method is
 * called by PHP.
 *
 * @category  Encryption
 * @package   Crypt_GPG
 * @author    Nathan Fredrickson <nathan@silverorange.com>
 * @author    Michael Gauthier <mike@silverorange.com>
 * @copyright 2005-2008 silverorange
 * @license   http://www.gnu.org/copyleft/lesser.html LGPL License 2.1
 * @link      http://pear.php.net/package/Crypt_GPG
 * @link      http://www.gnupg.org/
 */
class Crypt_GPG_Driver_Php extends Crypt_GPG
{
    // {{{ class constants for IPC file descriptors

    /**
     * Standard input file descriptor. This is used to pass data to the GPG
     * process.
     */
    const FD_INPUT   = 0;

    /**
     * Standard output file descriptor. This is used to receive normal output
     * from the GPG process.
     */
    const FD_OUTPUT  = 1;

    /**
     * Standard output file descriptor. This is used to receive error output
     * from the GPG process.
     */
    const FD_ERROR   = 2;

    /**
     * GPG status output file descriptor. The status file descriptor outputs
     * detailed information for many GPG commands. See the second section of
     * the file doc/DETAILS in the
     * {@link http://www.gnupg.org/download/ GPG package} for a detailed
     * description of GPG status output.
     *
     * If the operating system is Windows, the status file descriptor is
     * emulated using a regular file.
     */
    const FD_STATUS  = 3;

    /**
     * Extra message input file descriptor. This is used for methods requiring
     * a passphrase and for passing signed data when verifying a detached
     * signature.
     *
     * If the operating system is Windows, the message file descriptor is
     * emulated using a regular file.
     */
    const FD_MESSAGE = 4;

    // }}}
    // {{{ public class properties

    /**
     * Whether or not to use debugging mode
     *
     * When set to true, every GPG command is echoed before it is run. Sensitive
     * data is always handled using pipes and is not specified as part of the
     * command. As a result, sensitive data is never displayed when debug is
     * enabled. Sensitive data includes private key data and passphrases.
     *
     * Debugging is off by default.
     *
     * @var boolean
     */
    public $debug = false;

    // }}}
    // {{{ private class properties

    /**
     * Location of GPG binary
     *
     * @var string
     * @see Crypt_GPG::__construct()
     * @see Crypt_GPG::_getBinary()
     */
    private $_gpg_binary = '';

    /**
     * Directory containing the GPG key files
     *
     * This property only contains the path when the <i>$homedir</i> parameter
     * is specified in the constructor.
     *
     * @var string
     * @see Crypt_GPG::__construct()
     */
    private $_homedir = '';

    /**
     * Array of pipes used for communication with the GPG binary
     *
     * This is an array of file descriptor resources.
     *
     * @var array
     */
    private $_pipes = array();

    /**
     * Array of currently opened pipes
     *
     * This array is used to keep track of remaining opened pipes so they can
     * be closed when the GPG subprocess is finished. This array is a subset of
     * the {@link Crypt_GPG::_pipes} array and contains opened file descriptor
     * resources.
     *
     * @var array
     * @see Crypt_GPG::_closePipe()
     */
    private $_open_pipes = array();

    /**
     * Array of temporary file filenames
     *
     * This array is only populated when {@link Crypt_GPG_Driver_Php::$is_win}
     * is true. Temporary files are used as a fallback for file descriptors
     * above 2 in Windows. Windows cannot use file descriptors above 2 with
     * proc_open(). The {@link Crypt_GPG_Driver_PHP::STATUS_FD} and
     * {@link Crypt_GPG_Driver_PHP::MESSAGE_FD} file descriptors are emulated
     * using temporary files. All temporary files are deleted when the
     * subprocess is closed.
     *
     * @var array
     *
     * @see Crypt_GPG::_createTempFile()
     * @see Crypt_GPG::_deleteTempFile()
     */
    private $_temp_files = array();

    /**
     * Status output from the GPG subprocess
     *
     * Access this using {@link Crypt_GPG::_getStatus()}. If there is no status
     * output, this will be a blank string. This gets the contents of the
     * FD_STATUS file descriptor while the GPG subprocess is open.
     *
     * @var string
     * @see Crypt_GPG::_getStatus()
     */
    private $_status = '';

    /**
     * Error output from the GPG subprocess
     *
     * Access this using {@link Crypt_GPG::_getError()}. If there is no error
     * output, this will be a blank string. This gets the contents of the
     * FD_ERROR file descriptor while the GPG subprocess is open.
     *
     * @var string
     * @see Crypt_GPG::_getError()
     */
    private $_error = '';

    /**
     * A handle for the GPG process
     *
     * @var resource
     */
    private $_process = null;

    /**
     * Whether or not the operating system is Windows
     *
     * If Windows is detected, this driver falls back to a file-based
     * implementation for some features.
     *
     * @var boolean
     */
    private $_is_win = false;

    /**
     * Whether or not the operating system is Darwin (OS X)
     *
     * @var boolean
     */
    private $_is_darwin = false;

    // }}}
    // {{{ __construct()

    /**
     * Creates a new GPG object that uses PHP's native process manipulation
     * functions to control the GPG process
     *
     * Use the {@link Crypt_GPG::factory()} method to instantiate this class.
     *
     * Available options for this driver are:
     *
     * - string  homedir:    The directory where the GPG keyring files are
     *                       stored. If not specified, GPG uses the default of
     *                       $HOME/.gnupg, where $HOME is the present user's
     *                       home directory. This option only needs to be
     *                       specified when $HOME/.gnupg is inappropriate.
     *
     * - string  gpg_binary: The location of the GPG binary. If not specified,
     *                       the driver attempts to auto-detect the GPG binary
     *                       location using a list of known default locations
     *                       for the current operating system.
     *
     * - boolean debug:      Whether or not to use debug mode. See
     *                       {@link Crypt_GPG_Driver_Php::$debug}.
     *
     * @param array $options optional. An array of options used to create the
     *                       GPG object. All options must be optional and are
     *                       represented as key-value pairs.
     *
     * @throws PEAR_Exception if the provided 'gpg_binary' is invalid; or if no
     *         'gpg_binary' is provided and no suitable binary could be found.
     */
    protected function __construct(array $options = array())
    {
        $this->_is_win    = (strncmp(strtoupper(PHP_OS), 'WIN', 3) === 0);
        $this->_is_darwin = (strncmp(strtoupper(PHP_OS), 'DARWIN', 6) === 0);

        if (array_key_exists('homedir', $options)) {
            $this->_homedir = (string)$options['homedir'];
        }

        if (array_key_exists('gpg_binary', $options)) {
            $this->_gpg_binary = (string)$options['gpg_binary'];
        } else {
            $this->_gpg_binary = $this->_getBinary();
        }

        if ($this->_gpg_binary == '' || !is_executable($this->_gpg_binary)) {
            throw new PEAR_Exception('GPG binary not found. If you are sure '.
                'the GPG binary is installed, please specify the location of '.
                'the GPG binary using the \'gpg_binary\' driver option.');
        }

        if (array_key_exists('debug', $options)) {
            $this->debug = (boolean)$options['debug'];
        }
    }

    // }}}
    // {{{ __destruct()

    /**
     * Closes open GPG subprocesses when this object is destroyed
     *
     * Subprocesses should never be left open by this class unless there is
     * an unknown error and unexpected script termination occurs.
     */
    public function __destruct()
    {
        $this->_closeSubprocess();

        // make sure temp files are deleted
        foreach ($this->_temp_files as $key => $filename) {
            $this->_deleteTempFile($key);
        }
    }

    // }}}
    // {{{ importKey()

    /**
     * Imports a public or private key into the keyring
     *
     * Keys may be removed from the keyring using
     * {@link Crypt_GPG::deletePublicKey()} or
     * {@link Crypt_GPG::deletePrivateKey()}.
     *
     * Calls GPG with the --import command and provides GPG the key data to be
     * imported.
     *
     * @param string $data the key data to be imported.
     *
     * @return array an associative array containing the following elements:
     *               - fingerprint: the key fingerprint of the imported key,
     *               - public_imported: the number of public keys imported,
     *               - public_unchanged: the number of unchanged public keys,
     *               - private_imported: the number of private keys imported,
     *               - private_unchanged: the number of unchanged private keys.
     *
     * @throws Crypt_GPG_NoDataException if the key data is missing or if the
     *         data is is not valid key data.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     */
    public function importKey($data)
    {
        $args = array('--import');
        $this->_openSubprocess($args);

        fwrite($this->_pipes[self::FD_INPUT], $data);
        $this->_closePipe(self::FD_INPUT);

        if (!$this->_is_win) {
            $status = $this->_getStatus();
        }

        $code = $this->_closeSubprocess();

        if ($this->_is_win) {
            $status = $this->_getStatus();
        }

        $result = $this->_parseImportStatus($status);

        // ignore duplicate key import errors
        if ($code !== null && $code !== Crypt_GPG::ERROR_DUPLICATE_KEY) {
            switch ($code) {
            case Crypt_GPG::ERROR_NO_DATA:
                throw new Crypt_GPG_NoDataException(
                    'No valid GPG key data found.', $code);

                break;
            default:
                throw new Crypt_GPG_Exception(
                    'Unknown error importing GPG key.', $code);

                break;
            }
        }

        return $result;
    }

    // }}}
    // {{{ exportPublicKey()

    /**
     * Exports a public key from the keyring
     *
     * The exported key remains on the keyring. To delete the public key, use
     * {@link Crypt_GPG::deletePublicKey()}.
     *
     * If more than one key fingerprint is available for the specified
     * <i>$key_id</i> (for example, if you use a non-unique uid) only the first
     * public key is exported.
     *
     * Calls GPG with the --export command.
     *
     * @param string  $key_id either the full uid of the public key, the email
     *                        part of the uid of the public key or the key id of
     *                        the public key. For example,
     *                        "Test User (example) <test@example.com>",
     *                        "test@example.com" or a hexadecimal string.
     * @param boolean $armor  optional. If true, ASCII armored data is returned;
     *                        otherwise, binary data is returned. Defaults to
     *                        true.
     *
     * @return string the public key data.
     *
     * @throws Crypt_GPG_KeyNotFoundException if a public key with the given
     *         <i>$key_id</i> is not found.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     */
    public function exportPublicKey($key_id, $armor = true)
    {
        $fingerprint = $this->getFingerprint($key_id);

        if ($fingerprint === null) {
            throw new Crypt_GPG_KeyNotFoundException(
                'Public key not found: ' . $key_id,
                Crypt_GPG::ERROR_KEY_NOT_FOUND, $key_id);
        }

        $args = array();

        if ($armor) {
            $args[] = '--armor';
        }

        $args[] = '--export ' . escapeshellarg($fingerprint);

        $this->_openSubprocess($args);

        $key_data = '';
        while (!feof($this->_pipes[self::FD_OUTPUT])) {
            $key_data .= fread($this->_pipes[self::FD_OUTPUT], 1024);
        }

        $code = $this->_closeSubprocess();
        if ($code !== null) {
            throw new Crypt_GPG_Exception(
                'Unknown error exporting public key.', $code);
        }

        return $key_data;
    }

    // }}}
    // {{{ deletePublicKey()

    /**
     * Deletes a public key from the keyring
     *
     * If more than one key fingerprint is available for the specified
     * <i>$key_id</i> (for example, if you use a non-unique uid) only the first
     * public key is deleted.
     *
     * The private key must be deleted first or an exception will be thrown.
     * See {@link Crypt_GPG::deletePrivateKey()}.
     *
     * Calls GPG with the --delete-key command.
     *
     * @param string $key_id either the full uid of the public key, the email
     *                       part of the uid of the public key or the key id of
     *                       the public key. For example,
     *                       "Test User (example) <test@example.com>",
     *                       "test@example.com" or a hexadecimal string.
     *
     * @return void
     *
     * @throws Crypt_GPG_KeyNotFoundException if a public key with the given
     *         <i>$key_id</i> is not found.
     *
     * @throws Crypt_GPG_DeletePrivateKeyException if the specified public key
     *         has an associated private key on the keyring. The private key
     *         must be deleted first.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     */
    public function deletePublicKey($key_id)
    {
        $fingerprint = $this->getFingerprint($key_id);

        if ($fingerprint === null) {
            throw new Crypt_GPG_KeyNotFoundException(
                'Public key not found: ' . $key_id,
                Crypt_GPG::ERROR_KEY_NOT_FOUND, $key_id);
        }

        $args = array(
            '--batch',
            '--yes',
            '--delete-key ' . escapeshellarg($fingerprint)
        );

        $this->_openSubprocess($args);
        $code = $this->_closeSubprocess();
        if ($code !== null) {
            switch ($code) {
            case Crypt_GPG::ERROR_DELETE_PRIVATE_KEY:
                throw new Crypt_GPG_DeletePrivateKeyException(
                    'Private key must be deleted before public key can be ' .
                    'deleted.', $code, $key_id);

                break;
            default:
                throw new Crypt_GPG_Exception(
                    'Unknown error deleting public key.', $code);
            }
        }
    }

    // }}}
    // {{{ deletePrivateKey()

    /**
     * Deletes a private key from the keyring
     *
     * If more than one key fingerprint is available for the specified
     * <i>$key_id</i> (for example, if you use a non-unique uid) only the first
     * private key is deleted.
     *
     * Calls GPG with the --delete-secret-key command.
     *
     * @param string $key_id either the full uid of the private key, the email
     *                       part of the uid of the private key or the key id of
     *                       the private key. For example,
     *                       "Test User (example) <test@example.com>",
     *                       "test@example.com" or a hexadecimal string.
     *
     * @return void
     *
     * @throws Crypt_GPG_KeyNotFoundException if a private key with the given
     *         <i>$key_id</i> is not found.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     */
    public function deletePrivateKey($key_id)
    {
        $fingerprint = $this->getFingerprint($key_id);

        if ($fingerprint === null) {
            throw new Crypt_GPG_KeyNotFoundException(
                'Private key not found: ' . $key_id,
                Crypt_GPG::ERROR_KEY_NOT_FOUND, $key_id);
        }

        $args = array(
            '--batch',
            '--yes',
            '--delete-secret-key ' . escapeshellarg($fingerprint)
        );

        $this->_openSubprocess($args);
        $code = $this->_closeSubprocess();
        if ($code !== null) {
            switch ($code) {
            case Crypt_GPG::ERROR_KEY_NOT_FOUND:
                throw new Crypt_GPG_KeyNotFoundException(
                    'Private key not found: ' . $key_id,
                    $code, $key_id);

                break;
            default:
                throw new Crypt_GPG_Exception(
                    'Unknown error deleting private key.', $code);
            }
        }
    }

    // }}}
    // {{{ getKeys()

    /**
     * Gets the available keys in the keyring
     *
     * Calls GPG with the --list-keys command and grabs keys. See the first
     * section of doc/DETAILS in the
     * {@link http://www.gnupg.org/download/ GPG package} for a detailed
     * description of how the GPG command output is parsed.
     *
     * @param string $key_id optional. Only keys with that match the specified
     *                       pattern are returned. The pattern may be part of
     *                       a user id, a key id or a key fingerprint. If not
     *                       specified, all keys are returned.
     *
     * @return array an array of {@link Crypt_GPG_Key} objects.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     *
     * @see Crypt_GPG_Key
     */
    public function getKeys($key_id = '')
    {
        // get private key fingerprints
        $args = array(
            '--with-colons',
            '--with-fingerprint',
            '--with-fingerprint',
            '--fixed-list-mode'
        );

        if ($key_id == '') {
            $args[] = '--list-secret-keys';
        } else {
            $args[] = '--list-secret-keys ' . escapeshellarg($key_id);
        }

        $this->_openSubprocess($args);

        $private_key_fingerprints = array();
        while (!feof($this->_pipes[self::FD_OUTPUT])) {
            $line = fgets($this->_pipes[self::FD_OUTPUT]);
            $exp_line = explode(':', $line);

            if ($exp_line[0] == 'fpr') {
                $private_key_fingerprints[] = $exp_line[9];
            }
        }

        $code = $this->_closeSubprocess();
        // ignore not found key errors
        if ($code !== null && $code !== Crypt_GPG::ERROR_KEY_NOT_FOUND) {
            throw new Crypt_GPG_Exception(
                'Unknown error getting keys.', $code);
        }

        // get public keys
        array_pop($args);

        if ($key_id == '') {
            $args[] = '--list-public-keys';
        } else {
            $args[] = '--list-public-keys ' . escapeshellarg($key_id);
        }

        $this->_openSubprocess($args);

        $keys = array();

        $key     = null; // current key
        $sub_key = null; // current sub-key

        while (!feof($this->_pipes[self::FD_OUTPUT])) {
            $line = fgets($this->_pipes[self::FD_OUTPUT]);
            $exp_line = explode(':', $line);

            if ($exp_line[0] == 'pub') {

                // new primary key means last key should be added to the array
                if ($key !== null) {
                    $keys[] = $key;
                }

                $key = new Crypt_GPG_Key();

                $sub_key = $this->_parseSubKey($exp_line);
                $key->addSubKey($sub_key);

            } elseif ($exp_line[0] == 'sub') {

                $sub_key = $this->_parseSubKey($exp_line);
                $key->addSubKey($sub_key);

            } elseif ($exp_line[0] == 'fpr') {

                $fingerprint = $exp_line[9];

                // set current sub-key fingerprint
                $sub_key->setFingerprint($fingerprint);

                // if private key exists, set has private to true
                if (in_array($fingerprint, $private_key_fingerprints)) {
                    $sub_key->setHasPrivate(true);
                }

            } elseif ($exp_line[0] == 'uid') {

                $string = stripcslashes($exp_line[9]); // as per documentation
                $key->addUserId($this->_parseUserId($string));

            }
        }

        // add last key
        if ($key !== null) {
            $keys[] = $key;
        }

        $code = $this->_closeSubprocess();
        // ignore not found key errors
        if ($code !== null && $code !== Crypt_GPG::ERROR_KEY_NOT_FOUND) {
            throw new Crypt_GPG_Exception(
                'Unknown error getting keys.', $code);
        }

        return $keys;
    }

    // }}}
    // {{{ getFingerprint()

    /**
     * Gets a key fingerprint from the keyring
     *
     * If more than one key fingerprint is available (for example, if you use
     * a non-unique user id) only the first key fingerprint is returned.
     *
     * Calls the GPG --list-keys command with the --with-fingerprint option to
     * retrieve a public key fingerprint.
     *
     * @param string  $key_id either the full user id of the key, the email
     *                        part of the user id of the key, or the key id of
     *                        the key. For example,
     *                        "Test User (example) <test@example.com>",
     *                        "test@example.com" or a hexadecimal string.
     * @param integer $format optional. How the fingerprint should be formatted.
     *                        Use {@link Crypt_GPG::FORMAT_X509} for X.509
     *                        certificate format,
     *                        {@link Crypt_GPG::FORMAT_CANONICAL} for the format
     *                        used by GnuPG output and
     *                        {@link Crypt_GPG::FORMAT_NONE} for no formatting.
     *                        Defaults to Crypt_GPG::FORMAT_NONE.
     *
     * @return string the fingerprint of the key, or null if no fingerprint
     *                is found for the given <i>$key_id</i>.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     */
    public function getFingerprint($key_id, $format = Crypt_GPG::FORMAT_NONE)
    {
        $args = array(
            '--with-colons',
            '--with-fingerprint',
            '--list-keys ' . escapeshellarg($key_id)
        );

        $this->_openSubprocess($args);

        $fingerprint = null;

        while (!feof($this->_pipes[self::FD_OUTPUT])) {
            $line = fgets($this->_pipes[self::FD_OUTPUT]);
            if (substr($line, 0, 3) == 'fpr') {
                $line_exp = explode(':', $line);
                $fingerprint = $line_exp[9];

                switch ($format) {
                case Crypt_GPG::FORMAT_CANONICAL:
                    $fingerprint_exp = str_split($fingerprint, 4);
                    $format = '%s %s %s %s %s  %s %s %s %s %s';
                    $fingerprint = vsprintf($format, $fingerprint_exp);
                    break;

                case Crypt_GPG::FORMAT_X509:
                    $fingerprint_exp = str_split($fingerprint, 2);
                    $fingerprint = implode(':', $fingerprint_exp);
                    break;
                }

                break;
            }
        }

        $code = $this->_closeSubprocess();
        // ignore not found key errors
        if ($code !== null && $code !== Crypt_GPG::ERROR_KEY_NOT_FOUND) {
            throw new Crypt_GPG_Exception(
                'Unknown error getting key fingerprint.', $code);
        }

        return $fingerprint;
    }

    // }}}
    // {{{ encrypt()

    /**
     * Encrypts string data
     *
     * Data is ASCII armored by default but may optionally be returned as
     * binary.
     *
     * Calls GPG with the --encrypt command.
     *
     * @param string  $key_id the full uid of the public key to use for
     *                        encryption. For example,
     *                        "Test User (example) <test@example.com>".
     * @param string  $data   the data to be encrypted.
     * @param boolean $armor  optional. If true, ASCII armored data is returned;
     *                        otherwise, binary data is returned. Defaults to
     *                        true.
     *
     * @return string the encrypted data.
     *
     * @throws Crypt_GPG_KeyNotFoundException if the a key with the given
     *         <i>$key_id</i> is not found.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     *
     * @sensitive $data
     */
    public function encrypt($key_id, $data, $armor = true)
    {
        $data = (string)$data;
        $encrypted_data = null;

        $args = array('--recipient ' . escapeshellarg($key_id));

        if ($armor) {
            $args[] = '--armor';
        }

        $args[] = '--encrypt';

        $this->_openSubprocess($args);

        fwrite($this->_pipes[self::FD_INPUT], $data);
        $this->_closePipe(self::FD_INPUT);

        $encrypted_data = '';

        while (!feof($this->_pipes[self::FD_OUTPUT])) {
            $encrypted_data .= fread($this->_pipes[self::FD_OUTPUT], 1024);
        }

        $code = $this->_closeSubprocess();

        if ($code !== null) {
            switch ($code) {
            case Crypt_GPG::ERROR_KEY_NOT_FOUND:
                throw new Crypt_GPG_KeyNotFoundException(
                    "Data could not be encrypted because key '" . $key_id .
                    "' was not found.", $code, $key_id);

                break;
            default:
                throw new Crypt_GPG_Exception(
                    'Unknown error encrypting data.', $code);
            }
        }

        return $encrypted_data;
    }

    // }}}
    // {{{ decrypt()

    /**
     * Decrypts string data using the given passphrase
     *
     * This method assumes the required private key is available in the keyring
     * and throws an exception if the private key is not available. To add a
     * private key to the keyring, use the {@link Crypt_GPG::importKey()}
     * method.
     *
     * Calls GPG with the --decrypt command and passes the passphrase and
     * encrypted data.
     *
     * @param string $encrypted_data the data to be decrypted.
     * @param string $passphrase     optional. The passphrase of the private
     *                               key used to encrypt the data. Only
     *                               required if the private key requires a
     *                               passphrase.
     *
     * @return string the decrypted data.
     *
     * @throws Crypt_GPG_KeyNotFoundException if the private key needed to
     *         decrypt the data is not in the user's keyring.
     *
     * @throws Crypt_GPG_NoDataException if specified data does not contain
     *         GPG encrypted data.
     *
     * @throws Crypt_GPG_BadPassphraseException if specified passphrase is
     *         incorrect or if a required passphrase is not specified.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     *
     * @sensitive $passphrase
     */
    public function decrypt($encrypted_data, $passphrase = null)
    {
        $args = array();

        if ($this->_is_win) {
            $this->_writeMessageFile($passphrase);
        }

        if ($passphrase !== null) {
            if ($this->_is_win) {
                $args[] = '--passphrase-file ' .
                    escapeshellarg($this->_temp_files[self::FD_MESSAGE]);
            } else {
                $args[] = '--passphrase-fd ' . escapeshellarg(self::FD_MESSAGE);
            }
        }

        $args[] = '--decrypt';

        $this->_openSubprocess($args);

        $data = $this->_processWithPassphrase($encrypted_data, $passphrase);

        $code = $this->_closeSubprocess();
        if ($code !== null) {
            switch ($code) {
            case Crypt_GPG::ERROR_KEY_NOT_FOUND:
                throw new Crypt_GPG_KeyNotFoundException(
                    'Cannot decrypt data. Private key required for decryption '.
                    'is not in the keyring. Import the private key before '.
                    'trying to decrypt this data.', $code);

            case Crypt_GPG::ERROR_NO_DATA:
                throw new Crypt_GPG_NoDataException(
                    'Cannot decrypt data. No GPG encrypted data was found in '.
                    'the provided data.', $code);

            case Crypt_GPG::ERROR_BAD_PASSPHRASE:
                throw new Crypt_GPG_BadPassphraseException(
                    'Cannot decrypt data. Incorrect passphrase provided.',
                    $code);

                break;
            case Crypt_GPG::ERROR_MISSING_PASSPHRASE:
                throw new Crypt_GPG_BadPassphraseException(
                    'Cannot decrypt data. No passphrase provided.', $code);

                break;
            default:
                throw new Crypt_GPG_Exception(
                    'Unknown error decrypting data.', $code);

                break;
            }
        }

        return $data;
    }

    // }}}
    // {{{ sign()

    /**
     * Signs data using the given key and passphrase
     *
     * Data may be signed using any one of the three available signing modes:
     * - {@link Crypt_GPG::SIGN_MODE_NORMAL}
     * - {@link Crypt_GPG::SIGN_MODE_CLEAR}
     * - {@link Crypt_GPG::SIGN_MODE_DETACHED}
     *
     * Calls GPGP with the --sign, --clearsign or --detach-sign commands.
     *
     * @param string  $key_id     either the full uid of the private key, the
     *                            email part of the uid of the private key or
     *                            the key id of the private key. For example,
     *                            "Test User (example) <test@example.com>",
     *                            "test@example.com" or a hexadecimal string.
     * @param string  $data       the data to be signed.
     * @param string  $passphrase optional. The passphrase of the private key
     *                            used to sign the data. Only required if the
     *                            private key requires a passphrase. Specify
     *                            null for no passphrase.
     * @param boolean $mode       optional. The data signing mode to use. Should
     *                            be one of {@link Crypt_GPG::SIGN_MODE_NORMAL},
     *                            {@link Crypt_GPG::SIGN_MODE_CLEAR} or
     *                            {@link Crypt_GPG::SIGN_MODE_DETACHED}. If not
     *                            specified, defaults to
     *                            Crypt_GPG::SIGN_MODE_NORMAL.
     * @param boolean $armor      optional. If true, ASCII armored data is
     *                            returned; otherwise, binary data is returned.
     *                            Defaults to true. This has no effect if the
     *                            mode Crypt_GPG::SIGN_MODE_CLEAR is used.
     *
     * @return string the signed data, or the signature data if a detached
     *                signature is requested.
     *
     * @throws Crypt_GPG_KeyNotFoundException if the private key is not in the
     *         user's keyring. Signing data requires the private key.
     *
     * @throws Crypt_GPG_BadPassphraseException if specified passphrase is
     *         incorrect or if a required passphrase is not specified.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     *
     * @sensitive $passphrase
     */
    public function sign($key_id, $data, $passphrase = null,
        $mode = Crypt_GPG::SIGN_MODE_NORMAL, $armor = true)
    {
        $args = array(
            '--local-user ' . escapeshellarg($key_id)
        );

        if ($this->_is_win) {
            $this->_writeMessageFile($passphrase);
        }

        if ($passphrase !== null) {
            if ($this->_is_win) {
                $args[] = '--passphrase-file ' .
                    escapeshellarg($this->_temp_files[self::FD_MESSAGE]);
            } else {
                $args[] = '--passphrase-fd ' . escapeshellarg(self::FD_MESSAGE);
            }
        }

        if ($armor) {
            $args[] = '--armor';
        }

        switch ($mode) {
        case Crypt_GPG::SIGN_MODE_DETACHED:
            $args[] = '--detach-sign';
            break;
        case Crypt_GPG::SIGN_MODE_CLEAR:
            $args[] = '--clearsign';
            break;
        case Crypt_GPG::SIGN_MODE_NORMAL:
        default:
            $args[] = '--sign';
            break;
        }

        $this->_openSubprocess($args);

        $signed_data = $this->_processWithPassphrase($data, $passphrase);

        $code = $this->_closeSubprocess();
        if ($code !== null) {
            switch ($code) {
            case Crypt_GPG::ERROR_KEY_NOT_FOUND:
                throw new Crypt_GPG_KeyNotFoundException(
                    'Cannot sign data. Private key not found. Import the '.
                    'private key before trying to sign data.', $code);

                break;
            case Crypt_GPG::ERROR_BAD_PASSPHRASE:
                throw new Crypt_GPG_BadPassphraseException(
                    'Cannot sign data. Incorrect passphrase provided.', $code);

                break;
            case Crypt_GPG::ERROR_MISSING_PASSPHRASE:
                throw new Crypt_GPG_BadPassphraseException(
                    'Cannot sign data. No passphrase provided.', $code);

                break;
            default:
                throw new Crypt_GPG_Exception(
                    'Unknown error signing data.', $code);

                break;
            }
        }

        return $signed_data;
    }

    // }}}
    // {{{ verify()

    /**
     * Verifies signed data
     *
     * The {@link Crypt_GPG::decrypt()} method may be used to get the original
     * message if the signed data is not clearsigned and does not have a
     * detached signature.
     *
     * Calls GPG with the --verify command to verify signature data.
     *
     * @param string $signed_data the signed data to be verified.
     * @param string $signature   optional. If verifying data signed using a
     *                            detached signature, this must be the detached
     *                            signature data. The data that was signed is
     *                            specified in <i>$signed_data</i>.
     *
     * @return Crypt_GPG_Signature the signature details of the signed data. If
     *                             the signature is valid, the <i>$valid</i>
     *                             property of the returned object will be true.
     *
     * @throws Crypt_GPG_NoDataException if the provided data is not signed
     *         data.
     *
     * @throws Crypt_GPG_Exception if an unknown or unexpected error occurs.
     *         Use {@link Crypt_GPG_Driver_Php::$debug} and file a bug report
     *         if these exceptions occur.
     *
     * @see Crypt_GPG_Signature
     */
    public function verify($signed_data, $signature = '')
    {
        if ($signature == '') {
            $args = array('--verify');
        } else {
            if ($this->_is_win) {
                $this->_writeMessageFile($signed_data);
                $args = array(
                    '--enable-special-filenames',
                    '--verify - ' .
                        escapeshellarg($this->_temp_files[self::FD_MESSAGE])
                );
            } else {
                // signed data goes in fd 4, detached signature data goes in
                // stdin
                $args = array(
                    '--enable-special-filenames',
                    '--verify - "-&' . self::FD_MESSAGE . '"'
                );
            }
        }

        $this->_openSubprocess($args);

        if ($signature == '') {
            // signed or clearsigned data

            // write the signed data to the GPG subprocess in stdin
            fwrite($this->_pipes[self::FD_INPUT], $signed_data);
            $this->_closePipe(self::FD_INPUT);
        } else {
            // detached signature

            // write signature data to stdin
            fwrite($this->_pipes[self::FD_INPUT], $signature);
            $this->_closePipe(self::FD_INPUT);

            if (!$this->_is_win) {
                // write signed data to fd 4
                fwrite($this->_pipes[self::FD_MESSAGE], $signed_data);
                $this->_closePipe(self::FD_MESSAGE);
            }
        }

        if (!$this->_is_win) {
            $status = $this->_getStatus();
        }

        $code = $this->_closeSubprocess();
        if ($code !== null) {
            switch ($code) {
            case Crypt_GPG::ERROR_NO_DATA:
                throw new Crypt_GPG_NoDataException(
                    'No valid signature data found.', $code);

                break;
            default:
                throw new Crypt_GPG_Exception(
                    'Unknown error validating signature details.', $code);

                break;
            }
        }

        if ($this->_is_win) {
            $status = $this->_getStatus();
        }

        // get the response information
        $resp = $this->_parseVerifyStatus($status);

        // create an object to return, and fill it with data
        $sig = new Crypt_GPG_Signature();

        // get key id and user id
        $return_codes = array('GOODSIG', 'EXPSIG', 'EXPKEYSIG', 'REVSIG',
            'BADSIG');

        foreach ($return_codes as $code) {
            if (array_key_exists($code, $resp)) {
                $pos    = strpos($resp[$code], ' ');
                $string = substr($resp[$code], $pos + 1);
                $string = rawurldecode($string);
                $sig->setUserId($this->_parseUserId($string));
                break;
            }
        }

        // get signature fingerprint, creation date and expiration date and
        // set signature as valid
        if (array_key_exists('VALIDSIG', $resp)) {
            $resp_valid_exp = explode(' ', $resp['VALIDSIG']);
            $sig->setIsValid(true);
            $sig->setKeyFingerprint($resp_valid_exp[0]);

            if (strpos($resp_valid_exp[2], 'T') === false) {
                $sig->setCreationDate($resp_valid_exp[2]);
            } else {
                $sig->setCreationDate(strtotime($resp_valid_exp[2]));
            }

            if (strpos($resp_valid_exp[3], 'T') === false) {
                $sig->setExpirationDate($resp_valid_exp[3]);
            } else {
                $sig->setExpirationDate(strtotime($resp_valid_exp[3]));
            }
        }

        // get signature id (may not exist for some signature types)
        if (array_key_exists('SIG_ID', $resp)) {
            $pos = strpos($resp['SIG_ID'], ' ');
            $sig->setId(substr($resp['SIG_ID'], 0, $pos - 1));
        }


        return $sig;
    }

    // }}}
    // {{{ _parseUserId()

    /**
     * Parses a user id object from a user id string
     *
     * Used by {@link Crypt_GPG_Driver_Php::getKeys()}.
     *
     * A user id string is of the form: 'name (comment) &lt;email-address&gt;'
     * with the comment and email-address being optional.
     *
     * @param string $string the user id string to parse.
     *
     * @return Crypt_GPG_UserId the user id object parsed from the string.
     */
    private function _parseUserId($string)
    {
        $user_id = new Crypt_GPG_UserId();

        $email   = '';
        $comment = '';

        $matches = array();
        if (preg_match('/^(.+?) <([^>]+)>$/', $string, $matches) == 1) {
            $string = $matches[1];
            $email  = $matches[2];
        }

        $matches = array();
        if (preg_match('/^(.+?) \(([^\)]+)\)$/', $string, $matches) == 1) {
            $string  = $matches[1];
            $comment = $matches[2];
        }

        $name = $string;

        $user_id->setName($name);
        $user_id->setComment($comment);
        $user_id->setEmail($email);

        return $user_id;
    }

    // }}}
    // {{{ _parseSubKey()

    /**
     * Parses a sub-key object from sub-key string fields
     *
     * Used by {@link Crypt_GPG_Driver_Php::getKeys()}. See doc/DETAILS in the
     * {@link http://www.gnupg.org/download/ GPG distribution} for info on
     * how the fields are parsed.
     *
     * @param string $exp_line the sub-key string fields.
     *
     * @return Crypt_GPG_SubKey the sub-key object parsed from the string parts.
     */
    private function _parseSubKey(array $exp_line)
    {
        $sub_key = new Crypt_GPG_SubKey();

        $sub_key->setId($exp_line[4]);
        $sub_key->setLength($exp_line[2]);
        $sub_key->setAlgorithm($exp_line[3]);

        if (strpos($exp_line[5], 'T') === false) {
            $sub_key->setCreationDate($exp_line[5]);
        } else {
            $sub_key->setCreationDate(strtotime($exp_line[5]));
        }

        if (strpos($exp_line[6], 'T') === false) {
            $sub_key->setExpirationDate($exp_line[6]);
        } else {
            $sub_key->setExpirationDate(strtotime($exp_line[6]));
        }

        if (strpos($exp_line[11], 's') !== false) {
            $sub_key->setCanSign(true);
        }

        if (strpos($exp_line[11], 'e') !== false) {
            $sub_key->setCanEncrypt(true);
        }

        return $sub_key;
    }

    // }}}
    // {{{ _processWithPassphrase()

    /**
     * Performs internal operations requiring a passphrase
     *
     * Performs operations that require a passphrase. For example,
     * decryption, signigning and clearsigning.
     *
     * @param string $data       the data to process. If there is no data to
     *                           process, use null.
     * @param string $passphrase the passphrase of the user's private key.
     *
     * @return string the processed data.
     *
     * @sensitive $passphrase
     */
    private function _processWithPassphrase($data, $passphrase)
    {
        $result = null;

        if ($data !== null) {
            fwrite($this->_pipes[self::FD_INPUT], $data);
            $this->_closePipe(self::FD_INPUT);
        }

        if (!$this->_is_win && $passphrase !== null) {
            fwrite($this->_pipes[self::FD_MESSAGE], $passphrase);
            $this->_closePipe(self::FD_MESSAGE);
        }

        $result = '';
        while (!feof($this->_pipes[self::FD_OUTPUT])) {
            $result .= fread($this->_pipes[self::FD_OUTPUT], 1024);
        }

        return $result;
    }

    // }}}
    // {{{ _openSubprocess()

    /**
     * Opens an internal GPG subprocess
     *
     * Opens a GPG subprocess, then connects the subprocess to some pipes. Sets
     * the private class property {@link Crypt_GPG::$_process} to the new
     * subprocess.
     *
     * @param array $args an array of command line arguments to pass the GPG
     *                    process.
     * @param array $env  optional. An array of shell environment variables.
     *                    Defaults to $_ENV if not specified.
     *
     * @return void
     *
     * @throws Crypt_GPG_OpenSubprocessException if the subprocess could not be
     *                                           opened.
     *
     * @see Crypt_GPG::_closeSubprocess()
     * @see Crypt_GPG::$_process
     */
    private function _openSubprocess(array $args, array $env = null)
    {
        if ($env === null) {
            $env = $_ENV;
        }

        $command = $this->_gpg_binary;

        if ($this->_is_win) {
            $this->_status = '';
            $this->_createTempFile(self::FD_STATUS);
            array_unshift($args, '--status-file ' .
                escapeshellarg($this->_temp_files[self::FD_STATUS]));
        } else {
            array_unshift($args,
                '--status-fd ' . escapeshellarg(self::FD_STATUS));
        }

        $args = array_merge(array(
            '--no-secmem-warning',
            '--no-permission-warning',
            '--no-tty',
            '--trust-model always'
        ), $args);

        if ($this->_homedir) {
            array_unshift($args,
                '--homedir ' . escapeshellarg($this->_homedir));
        }

        $command .= ' ' . implode(' ', $args);

        $descriptor_spec = array(
            self::FD_INPUT   => array('pipe', 'r'), // stdin
            self::FD_OUTPUT  => array('pipe', 'w'), // stdout
            self::FD_ERROR   => array('pipe', 'w'), // stderr
        );

        if (!$this->_is_win) {
            // extra output (status)
            $descriptor_spec[self::FD_STATUS]  = array('pipe', 'w');
            // extra input
            $descriptor_spec[self::FD_MESSAGE] = array('pipe', 'r');
        }

        $this->_debug('Opening subprocess with the following command:');
        $this->_debug($command);

        $this->_process = proc_open($command, $descriptor_spec, $this->_pipes,
            null, $env);

        if (!is_resource($this->_process)) {
            throw new Crypt_GPG_OpenSubprocessException(
                'Unable to open GPG subprocess.', 0, $command);
        }

        $this->_open_pipes = $this->_pipes;
    }

    // }}}
    // {{{ _closeSubprocess()

    /**
     * Closes an internal GPG subprocess
     *
     * Closes an internal GPG subprocess. Sets the private class property
     * {@link Crypt_GPG::$_process} to null.
     *
     * @return integer the error code of the internal process or null if no
     *                 error occurred.
     *
     * @see Crypt_GPG::_openSubprocess()
     * @see Crypt_GPG::$_process
     */
    private function _closeSubprocess()
    {
        $return = null;

        if (is_resource($this->_process)) {

            $error = $this->_getError();
            if (!$this->_is_win) {
                $status = $this->_getStatus();
            }

            // close remaining open pipes
            foreach (array_keys($this->_open_pipes) as $pipe_number) {
                $this->_closePipe($pipe_number);
            }

            $exit_code = proc_close($this->_process);

            if ($this->_is_win) {
                $status = $this->_getStatus();
            }

            // delete any remaining temp files
            foreach (array_keys($this->_temp_files) as $file_number) {
                $this->_deleteTempFile($file_number);
            }

            if ($exit_code != 0) {
                $this->_debug('Subprocess returned an unexpected exit code: ' .
                    $exit_code);

                $this->_debug("Error text is:\n" . $error);
                $this->_debug("Status text is:\n" . $status);

                $return = $this->_getErrorCode($exit_code, $error, $status);
            }

            $this->_process    = null;
            $this->_pipes      = array();
            $this->_temp_files = array();
            $this->_error      = '';

            if (!$this->_is_win) {
                $this->_status = '';
            }
        }

        return $return;
    }

    // }}}
    // {{{ _closePipe()

    /**
     * Closes an opened pipe used to communicate with the GPG subprocess
     *
     * If the pipe is already closed, it is ignored. If the pipe is open, it
     * is flushed and then closed.
     *
     * @param integer $pipe_number the file descriptor number of the pipe to
     *                             close.
     *
     * @return void
     */
    private function _closePipe($pipe_number)
    {
        $pipe_number = intval($pipe_number);
        if (array_key_exists($pipe_number, $this->_open_pipes)) {
            fflush($this->_open_pipes[$pipe_number]);
            fclose($this->_open_pipes[$pipe_number]);
            unset($this->_open_pipes[$pipe_number]);
        }
    }

    // }}}
    // {{{ _parseVerifyStatus()

    /**
     * Processes the status output from GPG for the verifiy operation
     *
     * Processes the status output from GPG --verify, taking notice only of
     * lines that begin with the magic [GNUPG:] prefix. See doc/DETAILS in the
     * {@link http://www.gnupg.org/download/ GPG distribution} for info on
     * GPG's output when --status-fd is specified.
     *
     * @param string $status the status text to process.
     *
     * @return array an array with a key for each GPG status command, and value
     *               containing the GPG status command arguments.
     */
    private function _parseVerifyStatus($status)
    {
        $resp = array();

        foreach (explode("\n", $status) as $line) {
            $line = rtrim($line);
            if (substr($line, 0, 9) == '[GNUPG:] ') {
                $line    = substr($line, 9);
                $words   = explode(' ', $line, 2);
                $keyword = $words[0];

                // set the value to the rest of the line
                if (count($words) > 1) {
                    $resp[$keyword] = $words[1];
                } else {
                    $resp[$keyword] = '';
                }
            }
        }

        return $resp;
    }

    // }}}
    // {{{ _parseImportStatus()

    /**
     * Processes the status output from GPG for the import operation
     *
     * Processes the status output from GPG --import, taking notice only of
     * lines that begin with the magic [GNUPG:] prefix. See doc/DETAILS in the
     * {@link http://www.gnupg.org/download/ GPG distribution} for info on
     * GPG's output when --status-fd is specified.
     *
     * @param string $status the status text to process.
     *
     * @return array an associative array containing the following elements:
     *               - fingerprint: the key fingerprint of the imported key,
     *               - public_imported: the number of public keys imported,
     *               - public_unchanged: the number of unchanged public keys,
     *               - private_imported: the number of private keys imported,
     *               - private_unchanged: the number of unchanged private keys.
     */
    private function _parseImportStatus($status)
    {
        $result = array();

        foreach (explode("\n", $status) as $line) {
            $line = rtrim($line);
            if (substr($line, 0, 9) == '[GNUPG:] ') {
                $line    = substr($line, 9);
                $values  = explode(' ', $line);
                $keyword = $values[0];

                switch ($keyword) {
                case 'IMPORT_OK':
                    $result['fingerprint'] = $values[2];
                    break;

                case 'IMPORT_RES':
                    $result['public_imported']   = intval($values[3]);
                    $result['public_unchanged']  = intval($values[5]);
                    $result['private_imported']  = intval($values[11]);
                    $result['private_unchanged'] = intval($values[12]);
                    break;
                }
            }
        }

        return $result;
    }

    // }}}
    // {{{ _getStatus()

    /**
     * Gets the status output from the GPG subprocess
     *
     * If the operating system is Windows, this reads and caches the contents
     * of the status file after the subprocess has been closed. Otherwise, this
     * caches the content of the status file descriptor while the GPG
     * subprocess is open.
     *
     * @return string the status output from the open GPG subprocess. If there
     *                is no status output or there is no open GPG subprocess,
     *                a blank string is returned.
     */
    private function _getStatus()
    {
        if ($this->_status == '') {
            if ($this->_is_win) {
                $this->_status = $this->_readStatusFile();
            } else {
                if (array_key_exists(self::FD_STATUS, $this->_open_pipes)) {
                    while (!feof($this->_pipes[self::FD_STATUS])) {
                        $this->_status .= fread($this->_pipes[self::FD_STATUS],
                            8192);
                    }
                }
            }
        }

        return $this->_status;
    }

    // }}}
    // {{{ _getError()

    /**
     * Gets the error output from the GPG subprocess
     *
     * This helper method caches the content of the error file descriptor while
     * the GPG subprocess is open.
     *
     * @return string the error output from the open GPG subprocess. If there
     *                is no error output or there is no open GPG subprocess,
     *                a blank string is returned.
     */
    private function _getError()
    {
        if ($this->_error == '' &&
            array_key_exists(self::FD_ERROR, $this->_open_pipes)) {
            while (!feof($this->_pipes[self::FD_ERROR])) {
                $this->_error .= fread($this->_pipes[self::FD_ERROR], 8192);
            }
        }

        return $this->_error;
    }

    // }}}
    // {{{ _getErrorCode()

    /**
     * Gets a specific error code when the GPG subprocess terminates with
     * an error
     *
     * The error code is determined by parsing the output on the --status-fd
     * file descriptor. See doc/DETAILS in the
     * {@link http://www.gnupg.org/download/ GPG distribution} for info on
     * GPG's output when --status-fd is specified.
     *
     * @param integer $exit_code the error code returned by the GPG
     *                           subprocess.
     * @param string  $error     the GPG subprocess output to stderr.
     * @param string  $status    the GPG subprocess output to the --status-fd
     *                           file descriptor.
     *
     * @return integer the specific error code for the GPG subprocess error.
     *                 If no specific exception is known,
     *                 {@link Crypt_GPG::ERROR_UNKNOWN} is returned.
     */
    private function _getErrorCode($exit_code, $error, $status)
    {
        $error_code = Crypt_GPG::ERROR_UNKNOWN;

        $status = explode("\n", $status);
        $need_passphrase = false;
        foreach ($status as $line) {
            $tokens = explode(' ', trim($line));
            if ($tokens[0] == '[GNUPG:]') {
                switch ($tokens[1]) {
                case 'BAD_PASSPHRASE':
                    $error_code = Crypt_GPG::ERROR_BAD_PASSPHRASE;
                    break 2;

                case 'MISSING_PASSPHRASE':
                    $error_code = Crypt_GPG::ERROR_MISSING_PASSPHRASE;
                    break 2;

                case 'IMPORT_OK':
                    $pattern = '/already in secret keyring/';
                    if (preg_match($pattern, $error) == 1) {
                        $error_code = Crypt_GPG::ERROR_DUPLICATE_KEY;
                    }
                    break 2;

                case 'NODATA':
                    $error_code = Crypt_GPG::ERROR_NO_DATA;
                    break 2;

                case 'DELETE_PROBLEM':
                    if ($tokens[2] == '1') {
                        $error_code = Crypt_GPG::ERROR_KEY_NOT_FOUND;
                        break 2;
                    } elseif ($tokens[2] == '2') {
                        $error_code = Crypt_GPG::ERROR_DELETE_PRIVATE_KEY;
                        break 2;
                    }
                    break;

                case 'NEED_PASSPHRASE':
                    $need_passphrase = true;
                    break;

                case 'GOOD_PASSPHRASE':
                    $need_passphrase = false;
                    break;

                }
            }
        }

        if ($error_code == Crypt_GPG::ERROR_UNKNOWN && $need_passphrase) {
            $error_code = Crypt_GPG::ERROR_MISSING_PASSPHRASE;
        }

        if ($error_code == Crypt_GPG::ERROR_UNKNOWN) {
            $pattern = '/no valid OpenPGP data found/';
            if (preg_match($pattern, $error) == 1) {
                $error_code = Crypt_GPG::ERROR_NO_DATA;
            }
        }

        if ($error_code == Crypt_GPG::ERROR_UNKNOWN) {
            $pattern = '/secret key not available/';
            if (preg_match($pattern, $error) == 1) {
                $error_code = Crypt_GPG::ERROR_KEY_NOT_FOUND;
            }
        }

        if ($error_code == Crypt_GPG::ERROR_UNKNOWN) {
            $pattern = '/public key not found/';
            if (preg_match($pattern, $error) == 1) {
                $error_code = Crypt_GPG::ERROR_KEY_NOT_FOUND;
            }
        }

        return $error_code;
    }

    // }}}
    // {{{ _writeMessageFile()

    /**
     * Writes a message to the temporary message file
     *
     * This method should be called before the subprocess is opened.
     *
     * This is used when the operating system is Windows and file-based
     * fallbacks are used. A new temporary file is created by this method.
     *
     * @param string $message the message to write.
     *
     * @return void
     *
     * @sensitive $message
     */
    private function _writeMessageFile($message)
    {
        $this->_createTempFile(self::FD_MESSAGE);

        $message_file = fopen($this->_temp_files[self::FD_MESSAGE], 'wb');
        fwrite($message_file, $message);
        fflush($message_file);
        fclose($message_file);
    }

    // }}}
    // {{{ _readStatusFile()

    /**
     * Reads the content of the temporary status file
     *
     * This method should be called after the subprocess is closed.
     *
     * This is used when the operating system is Windows and file-based
     * fallbacks are used. After the temporary status file is read, it is
     * deleted.
     *
     * @return string the contents of the temporary status file.
     */
    private function _readStatusFile()
    {
        $status = '';

        $filename = $this->_temp_files[self::FD_STATUS];
        if (file_exists($filename) && is_readable($filename)) {
            $status = file_get_contents($filename);
        }

        $this->_deleteTempFile(self::FD_STATUS);

        return $status;
    }

    // }}}
    // {{{ _createTempFile()

    /**
     * Creates a temporary file
     *
     * If a temporary file for the given file number already exists, the old
     * file is deleted before the new file is created.
     *
     * @param integer $file_number the file number. Should be one of the
     *                             Crypt_GPG_Driver_Php::FD_* constants.
     *
     * @return void
     *
     * @see Crypt_GPG_Driver_Php::_deleteTemporaryFile()
     */
    private function _createTempFile($file_number)
    {
        $this->_deleteTempFile($file_number);
        $filename = tempnam(sys_get_temp_dir(), 'Crypt_GPG-');
        $this->_temp_files[$file_number] = $filename;
    }

    // }}}
    // {{{ _deleteTempFile()

    /**
     * Deletes a temporary file
     *
     * If no temporary file exists for the given file number, nothing is done.
     *
     * @param integer $file_number the file number. Should be one of the
     *                             Crypt_GPG_Driver_Php::FD_* constants.
     *
     * @return void
     *
     * @see Crypt_GPG_Driver_Php::_createTemporaryFile()
     */
    private function _deleteTempFile($file_number)
    {
        if (array_key_exists($file_number, $this->_temp_files)) {
            $filename = $this->_temp_files[$file_number];
            if (file_exists($filename) && is_writeable($filename)) {
                unlink($filename);
            }
            unset($this->_temp_files[$file_number]);
        }
    }

    // }}}
    // {{{ _getBinary()

    /**
     * Gets the name of the GPG binary for the current operating system
     *
     * This method is called if the 'gpg_binary' option is <i>not</i> specified
     * when creating this driver.
     *
     * @return string the name of the GPG binary for the current operating
     *                system. If no suitable binary could be found, an empty
     *                string is returned.
     */
    private function _getBinary()
    {
        $bin = '';

        if ($this->_is_win) {
            $bin_files = array(
                'c:/progra~1/gnu/gnupg/gpg.exe'
            );
        } elseif ($this->_is_darwin) {
            $bin_files = array(
                '/opt/local/bin/gpg', // MacPorts
                '/usr/local/bin/gpg', // Mac GPG
                '/sw/bin/gpg',        // Fink
                '/usr/bin/gpg'
            );
        } else {
            $bin_files = array(
                '/usr/bin/gpg',
                '/usr/local/bin/gpg'
            );
        }

        foreach ($bin_files as $bin_file) {
            if (is_executable($bin_file)) {
                $bin = $bin_file;
                break;
            }
        }

        return $bin_file;
    }

    // }}}
    // {{{ _debug()

    /**
     * Displays debug text if debugging is turned on
     *
     * Debugging text is prepended with a debug identifier and echoed to stdout.
     *
     * @param string $text the debugging text to display.
     *
     * @return void
     */
    private function _debug($text)
    {
        if ($this->debug) {
            foreach (explode("\n", $text) as $line) {
                echo "Crypt_GPG DEBUG: ", $line, "\n";
            }
        }
    }

    // }}}
}

// }}}

?>
